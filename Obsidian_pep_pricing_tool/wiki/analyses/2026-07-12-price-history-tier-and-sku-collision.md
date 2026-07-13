---
title: Price-History Tier Backfill + Vendor SKU Collision Bug
type: analysis
tags: [price-history, vendor-sku, data-integrity, backlog-59]
created: 2026-07-12
sources: []
---

# Price-History Tier Backfill + Vendor SKU Collision Bug

Follow-up to backlog #59 (`pc_price_history` never recording which kit-size tier
changed — see [[wiki/entities/phase-roadmap]] #59 for the original fix). User asked
two things: whether the ~1,379 rows left with an unknown tier could be rebuilt from
source, and — after being told about a reimport — flagged that reimporting unchanged
data shouldn't produce spurious history lines. Both questions led to the same deeper bug.

## Rebuilding tier history

Every one of the 1,379 NULL-tier rows had `source = 'import'` (zero `manual_edit` rows
affected), meaning each one traces back to a Claude extraction still sitting in
`pc_claude_call_log.raw_response_text`, or — for rows committed via the manual
Review Queue approval path instead of auto-commit — `pc_pending_imports.raw_json`
(kept even after approval, `status = 'approved'`). Both still carry the original
per-price `tier_kit_size` Claude extracted.

Matching strategy: for each NULL-tier row, find candidate price entries from
`pc_claude_call_log` (vendor + `created_at` within 3s of `changed_at`) or, if none,
from `pc_pending_imports` (vendor + `reviewed_at` within 3s — needed because an
approval can happen long after the original extraction, decoupling the timestamp from
`changed_at` entirely). Within the candidates, match on exact `price_usd`, plus
`numeric_value`/`unit` when the specification hasn't since been deleted by one of this
session's earlier product-merge passes (728 of 1,379 rows reference an since-deleted
spec — a LEFT JOIN, not INNER, was needed to still count them, just without the
dose/unit cross-check).

**Result**: 1,087 of 1,379 resolved (79%) — total `pc_price_history` tier coverage now
92% (3,490 of 3,782 rows). 42 stayed genuinely ambiguous (multiple different tiers
matched the same price within the candidate set), 250 had no candidate source within
the time window at all. Both left `NULL` rather than guessed, per this project's
standing convention. Applied via
`migration_scripts/2026-07-12-backfill_price_history_tier.php`.

## The real bug underneath: `vendor_sku` isn't part of the uniqueness key

While tracing why Purelypep Factory's KPV 10mg price oscillated 42→46→42→46 across
what should have been a single reimport event, decoded the raw extraction JSON
directly:

```
canonical_name=KPV, spec_label=10mg, tier=1,  vendor_sku=KPV10, price=46
canonical_name=KPV, spec_label=10mg, tier=10, vendor_sku=KPV10, price=38
canonical_name=KPV, spec_label=10mg, tier=50, vendor_sku=KPV10, price=29
canonical_name=KPV, spec_label=10mg, tier=1,  vendor_sku=KP10,  price=42
canonical_name=KPV, spec_label=10mg, tier=10, vendor_sku=KP10,  price=35
canonical_name=KPV, spec_label=10mg, tier=50, vendor_sku=KP10,  price=26
```

This isn't Claude re-reading the same listing inconsistently — the vendor's price
sheet genuinely lists KPV 10mg **twice**, under two different catalog codes. Claude
read both correctly, in the same single extraction. The bug: `pc_prices`' uniqueness
constraint (`vendor_id, product_id, specification_id, tier_kit_size`) doesn't include
`vendor_sku`, so committing `KPV10` first and `KP10` second (array order) makes the
second silently overwrite the first via `ON DUPLICATE KEY UPDATE` — discarding one
real listing and logging a fake "price changed from $46 to $42" in
`pc_price_history`, every single time this file gets reimported (confirmed: it
recurred identically across both of this file's two real reprocessing events, at
04:43:59 and 05:25:25 the same day — matching the user's own recollection that no new
file was uploaded, just reprocessed).

Checked how widespread this is (`diagnostic_scripts/2026-07-12-check-sku-collision-scope.php`,
scanning every parsed call-log response for same-slot/different-SKU collisions):
**115 colliding price-slot pairs across 26 distinct extraction runs** — not isolated
to this one vendor or product.

### Decision and fix

Asked the user: fix the root cause (make `vendor_sku` part of the uniqueness key) or
leave it as a documented quirk. Chose the fix.

`database/migrations/030_vendor_sku_uniqueness.sql`:
- `vendor_sku` standardized to `NOT NULL DEFAULT ''`. Required, not cosmetic: MySQL/
  MariaDB treats every `NULL` in a UNIQUE index as distinct from every other `NULL`,
  so simply adding a nullable `vendor_sku` to the key would let the majority of
  vendors (who don't use SKUs at all) insert a brand-new row on every reimport instead
  of updating the existing one — trading one data-integrity bug for a worse one.
- `uq_price` widened to `(vendor_id, product_id, specification_id, tier_kit_size,
  vendor_sku)`. Had to add the new index first and rename it into place rather than
  drop-then-add: MariaDB refused to drop the original `uq_price` outright
  ("needed in a foreign key constraint" — it was the only index covering the leading
  column of `pc_prices_ibfk_1`'s `vendor_id` FK).
- 4 call sites were collapsing `''` back to `null` before this fix (defeating the new
  column default the moment code touched it): `vendor_file_processor.php`,
  `pending_imports.php` (×2 — the direct-approval path and the auto-approve-matching-
  vendors path), `prices/update.php`. All now pass the trimmed string through as-is.
- `commitPriceRow()`'s "prior row" lookup (used to detect if a price genuinely
  changed, for history logging) wasn't scoped by `vendor_sku` either — fixed
  alongside, so the "old price" comparison is now correctly per-SKU too, not just the
  INSERT's own uniqueness.

Verified in a rolled-back transaction: both `KPV10` and `KP10` rows now insert
successfully side by side with no constraint violation.

**Scope note**: this fixes the database — two real SKU'd listings no longer
destructively clobber each other on import. It does **not** yet fix display: the
Comparison page's `byVendor` mapping (`ComparisonView.vue`) is keyed by `vendor_id`
alone, one column per vendor, so a vendor with 2 real SKU'd listings at the same tier
will still only show one (deterministically, by SQL row order, rather than by
accidental overwrite order as before). Showing both would need a UI redesign (e.g.
stacked sub-rows per vendor) that wasn't part of this fix.

**Not done, left as an option**: 26 extraction runs have a "lost" SKU that could be
restored from the raw JSON now that the schema supports it (like Purelypep's `KPV10`
row). Not done automatically here since it would change what currently displays for
those vendors (which of two SKUs shows first is somewhat arbitrary today) without an
explicit ask to restore historical data specifically.
