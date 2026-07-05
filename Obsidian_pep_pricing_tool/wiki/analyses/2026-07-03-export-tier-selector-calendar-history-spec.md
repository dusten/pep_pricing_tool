---
title: Export, Comparison Tier Selector, and Calendar Price History Specs
type: analysis
tags: [spec, export, xlsx, csv, tier, calendar, backlog]
created: 2026-07-03
sources: [original-project-blueprint, price-history-spec]
---

# Export, Tier Selector, and Calendar History — Three Specs

Design for backlog items #5 (Export), #11 (Comparison table 1-kit-tier limit), and #15 (Calendar real price history), requested together.

**Status: #11 and #15 built and deployed 2026-07-03** (same day as this spec) — see their sections below. **#5 (Export) deliberately excluded from that pass**, still not built. User confirmed export should cover all three formats — CSV, Excel, and JSON — when it is built; whether the Pro-vs-Expert tier split by format (below) still holds, or all three formats should be available at whichever tier gates export at all, is still open and worth confirming before implementation. XLSX remains blocked on the user sourcing `php_xlsxwriter` regardless.

## #5 — Export

### Decisions

1. **CSV ships first**, zero new dependencies (stdlib `fputcsv()`). **XLSX is deferred** until you supply the `php_xlsxwriter` library file(s) into `backend/lib/` — CLAUDE.md already names it as a "deps placed manually" library, but it was never actually added to the repo (confirmed by `find`). I don't fetch third-party code into the repo on my own judgment.
2. Pro tier gets CSV (and XLSX once sourced) of the **current filtered comparison view**. Expert tier additionally gets a full raw-data JSON dump — broader than the comparison view, the whole live dataset. `requireTier()` already cascades (Expert ⊇ Pro), so Expert gets both without extra code.

### CSV export (buildable now)

`GET /api/comparison/export/csv` — `requireTier('pro')`, same filter params as `GET /api/comparison` (`classification_ids`, `products`, `vendors`, `specs`, `multi_only`, `verified_only`), runs the existing `runComparisonQuery()`, streams via `fputcsv()`.

Column shape, per the blueprint: `Product | Specification | Vendor_A | Vendor_B | ...` — one column per vendor. **Flagging an ambiguity in the original spec text**: it doesn't say whether each vendor column is price alone or price+$/unit. I'm reading it as **price alone** (CSV is deliberately the simpler of the two formats; $/unit + highlighting is what the richer XLSX format is for) — flag if that's wrong before I build it.

### XLSX export (blocked on the library)

Once `php_xlsxwriter` is in `backend/lib/`: `GET /api/comparison/export/xlsx`, same filters, full formatting verbatim from the blueprint —

- Row 1: merged vendor-name headers (2 cols each), navy `#2F5496`, white bold Arial 9
- Row 2: sub-headers "Price ($)" / "$/unit", blue `#4472C4`, white bold Arial 8
- Data rows: alternating white / light blue `#EEF2F9`, $/unit column slightly darker, italic Arial 8
- Green highlight `#C6EFCE` (dark green text `#276221`) on the lowest-$/unit cell and its paired price cell — reuses the `is_lowest` flag `runComparisonQuery()` already computes, no new logic needed
- Two stat columns after the last vendor: Avg Price, Median Price — reuses `stats.avg`/`stats.median`, already computed
- Column widths A=32, B=13, vendor price=10, $/unit=9, stat=13/14; freeze panes at C3; row 1 height 22, row 2 height 16; thin borders `#D0D0D0`

### Expert full-data export

`GET /api/export/full` — `requireTier('expert')`, no filters, one JSON payload of every active product/vendor/spec/price row (the whole live dataset, not scoped by Comparison filters). Given current scale (74 products, ~800 price rows) a single synchronous response is fine — no pagination or background job needed.

### Frontend

- Comparison page: an "Export" control (CSV / Excel once available), tier-gated — reuses the existing 402/`upgrade_to` handling pattern already built for the free-tier quota block.
- Expert tier: a "Download full dataset" action, likely on Settings (matches the tier-capability table's existing wording) or Dashboard.

### Non-goals

Scheduled/emailed exports, export history/audit log, any format beyond CSV/XLSX/JSON.

## #11 — Comparison table tier selector — BUILT

Shipped exactly as designed below, no deviations. Verified live: `GET /api/comparison/filters` returns `tiers: [1, 10, 50]`; `?tier=1/10/50` returns 174/115/92 rows respectively; clicking the tier control in the real UI reproduced the same row-count change. The tier control only renders when `comparison.tiers.length > 1`, so a dataset with a single tier doesn't show a pointless one-option selector.

### Problem

`comparison_query.php` hardcodes `pr.tier_kit_size = 1`. Real production data has **exactly three tiers** — confirmed live: 593 rows at tier 1, 116 at tier 10, 92 at tier 50, and most vendor+spec combos populate all three. Bulk pricing is completely invisible today.

### Decision

A **tier selector** (segmented control) above the Comparison table — not one-column-per-tier-per-vendor, which would triple the width of an already-wide table. Selecting a tier re-runs the same query at that `tier_kit_size`; the table itself doesn't change shape. **Cart and "Buy This Stack" stay tier=1-only** — extending tier-awareness into cart totals is real additional scope (a per-cart-item tier choice) and is an explicit follow-up, not bundled into this pass.

### Design

- `runComparisonQuery()` gains a `$tierKitSize` parameter (default `1`, preserving current behavior for any caller that doesn't pass it — e.g. `query_log_rerun.php` replaying an old logged query).
- `comparison/index.php` reads `$_GET['tier']`, cast to int, no fixed-list validation (a new tier value appearing in the data shouldn't require a code change — the query just returns nothing for a tier nobody's priced at).
- `filterHash`/`pc_comparison_log` params gain `tierKitSize` so caching and query-log replay stay correct per tier.
- `comparison/filters.php` gains a `tiers` field: `SELECT DISTINCT tier_kit_size FROM pc_prices WHERE is_active = 1 ORDER BY tier_kit_size` — the frontend selector is driven by what's actually in the data, not a hardcoded `[1, 10, 50]` array that would silently miss a future tier.

### Frontend

`ComparisonView.vue` gets a tier segmented control (options from `comparison.filters.tiers`, defaulting to `1`), wired into `runSearch()` and the existing `watch` array. `stores/comparison.js` passes `tier` through to the query params.

### Non-goals

Cart/stack tier-awareness (explicit follow-up); a combined "all tiers at once" table view.

## #15 — Calendar real price history — BUILT (+ same-day follow-up)

Shipped exactly as designed below. Verified live against real historical data (an earlier session's manual bump-and-revert test on BPC-157 5mg) — both the API response and the actual rendered day-detail table showed the correct `$46.01 → $46.00` / `$46.00 → $46.01` deltas with `source: manual_edit`.

**Real regression caught same day, fixed.** Switching the Calendar's sole data source to `pc_price_history` meant it could only ever show what happened *after* the ledger started existing — a large batch of Review Queue approvals earlier that same day (before the ledger was built) had no history rows and vanished from the Calendar entirely, even though the underlying price data was always intact. Fixed by adding `pc_pending_imports` (grouped by `DATE(reviewed_at)`, `status='approved'`) as a second, independent signal — it has always recorded approval timestamps regardless of the ledger's age. Rendered as a second calendar dot (green, left side, vs. the accent-colored price-change dot on the right) and a separate day-detail section, not merged into the price-change count — keeping that count's accuracy (the whole point of this backlog item) intact rather than reintroducing the original conflation. Verified live: today's cell correctly showed 379 approved items alongside 2 real price changes, matching a direct audit-log check of the review-queue approval spree.

### Problem

`calendar.php`/`CalendarView.vue` still read `pc_prices.created_at` directly, exactly as flagged when `pc_price_history` was built (backlog #3) — every reimport, even a no-op with an identical price, shows as a false "price change" that day, and no before→after value is ever shown. The ledger now exists and has real data (confirmed live during the cart-testing session); this rebuild was always the intended follow-up.

### Design

`calendar.php` rewritten to query `pc_price_history` instead, filtered by `changed_at` within the requested month:

```sql
SELECT ph.changed_at, v.display_name AS vendor, p.canonical_name AS product, s.spec_label AS spec,
       ph.old_price_usd, ph.new_price_usd, ph.source
FROM pc_price_history ph
LEFT JOIN pc_vendors v        ON v.id = ph.vendor_id
LEFT JOIN pc_products p       ON p.id = ph.product_id
LEFT JOIN pc_specifications s ON s.id = ph.specification_id
WHERE DATE_FORMAT(ph.changed_at, '%Y-%m') = ?
ORDER BY ph.changed_at DESC
```

`LEFT JOIN` (not `JOIN`) since `pc_price_history` deliberately has no FKs and must survive a vendor/product being deleted later — a historical event for a since-purged vendor still shows, just without a resolvable name (accepted limitation, stated in the original price-history spec, not solved here).

Per-day response: each entry carries `old_price_usd` (nullable — `NULL` means a brand-new price line), `new_price_usd`, and `source` (`import`/`manual_edit`), instead of today's single `price`/`price_per_unit`. The "N price changes" badge is now **genuinely accurate** — the ledger only has rows for real changes, so a day with three no-op reimports and one real price bump shows "1", not "4".

### Frontend

`CalendarView.vue`'s day-detail table gets a "Change" column showing `$46.00 → $46.01` (or a "New" badge when `old_price_usd` is `NULL`), and a small source tag (imported vs. manually corrected) — cheap to show since the data already carries it.

### Non-goals

Any change to the ledger itself; retention/pruning (already an accepted open item from the original price-history spec).

## Related

- [[wiki/analyses/2026-07-03-price-history-spec|Price History Spec]] — the ledger this Calendar rebuild finally uses
- [[wiki/sources/original-project-blueprint|Original Project Blueprint]] — verbatim Excel formatting spec
- [[wiki/entities/phase-roadmap|Phase Roadmap]] — backlog #5, #11, #15
