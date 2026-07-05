---
title: Price History Spec
type: analysis
tags: [spec, price-history, backlog, pc_prices, calendar]
created: 2026-07-03
sources: [phase1-framework]
---

# Price History Spec

Design for backlog item #3 in [[wiki/entities/phase-roadmap|Phase Roadmap]]: *"Price history isn't real."*

**Status: built and deployed 2026-07-03**, same day as this spec. Everything below was the agreed design before implementation; it matches what shipped. Verified live: a real manual price bump-and-revert logged two accurate history rows, and the import path (`commitPriceRow()`) was verified via a rolled-back transaction directly on the server covering the new-line, real-change, and no-op-resubmit cases. Calendar UI rebuild (the one explicit non-goal below) is tracked separately as backlog #15.

## Problem

`pc_prices` has one row per `(vendor_id, product_id, specification_id, tier_kit_size)` (`uq_price`). Every re-import or manual edit goes through `commitPriceRow()`'s `INSERT ... ON DUPLICATE KEY UPDATE`, which overwrites `price_usd`, `price_per_unit`, `kit_vial_count`, etc. **in place** and bumps `created_at = NOW()`. The old value is gone the instant a new one lands — there is no ledger.

[[wiki/analyses/2026-07-03-blueprint-vs-actual|Calendar view]] (`backend/api/calendar.php`, `CalendarView.vue`) reads `pr.created_at` directly and presents "N price change(s)" per day — but today that's really "N rows were (re-)written on this day," not "N prices actually changed." A no-op reimport with an identical price still refreshes `created_at` and shows up as a false "change."

## Decisions

1. **Storage**: new append-only `pc_price_history` table. `pc_prices` is untouched structurally — every existing query (comparison, inventory, vendor show, spec merge/move) keeps working exactly as today. Before an overwrite would destroy the old value, snapshot it.
2. **What's logged**: both the vendor-file import path and admin manual edits (Inventory tab), tagged with a `source` column. Formula-only corrections are explicitly **excluded** (see below) — they're not real market price changes.
3. **New price lines** (first time a vendor prices a given spec) also log an event, with `old_price_usd = NULL` — distinguishable from a change, and it makes the ledger a complete timeline rather than one with invisible starting points.
4. **Scope for this pass**: ledger table + the two write paths only. Calendar UI stays exactly as it is today (still reads `pc_prices.created_at`) — rebuilding it against the new table is an explicit, separate follow-up once the ledger has real data to show.

## Schema

```sql
CREATE TABLE IF NOT EXISTS pc_price_history (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id          INT UNSIGNED NOT NULL,
  product_id         INT UNSIGNED NOT NULL,
  specification_id   INT UNSIGNED NOT NULL,
  old_price_usd      DECIMAL(10,2) NULL,           -- NULL = brand-new price line, no prior value
  old_price_per_unit DECIMAL(12,6) NULL,
  old_kit_vial_count SMALLINT UNSIGNED NULL,
  new_price_usd      DECIMAL(10,2) NOT NULL,
  new_price_per_unit DECIMAL(12,6) NOT NULL,
  new_kit_vial_count SMALLINT UNSIGNED NOT NULL,
  source             ENUM('import','manual_edit') NOT NULL,
  changed_by         INT UNSIGNED NULL,             -- admin id for manual_edit, NULL for import
  changed_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (vendor_id, product_id, specification_id, changed_at),
  INDEX (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Deliberately no foreign keys.** `pc_prices` rows get cascade-deleted today by spec merges/moves and product merges (normal admin operations, not edge cases), and backlog #9 (full vendor purge) will delete vendor/product rows outright once built. History's entire point is to survive the live row disappearing — a hard FK with `ON DELETE CASCADE` would silently erase the audit trail exactly when it matters most (a purged vendor). IDs are stored denormalized, same pattern `calendar.php` already uses (join at read time; a future purge just means that join won't resolve a name, an accepted v1 limitation, not solved now).

`tier_kit_size` isn't stored on the history row: within one `(vendor, product, spec, tier)` match, the tier is what makes it *the same row* — it can't be the thing that changed in an update to that row. A tier reassignment shows up as a different `uq_price` match, not a history event on this row.

## Write-path changes

**`commitPriceRow()` in `backend/lib/price_import.php`** (single choke point — all three import call sites route through it):
1. Before the upsert, `SELECT price_usd, price_per_unit, kit_vial_count FROM pc_prices WHERE vendor_id=? AND product_id=? AND specification_id=? AND tier_kit_size=?` (the exact `uq_price` match).
2. Run the existing upsert unchanged.
3. If no prior row existed → insert a history row, `old_* = NULL`, `source = 'import'`.
4. If a prior row existed and `price_usd` or `kit_vial_count` actually differ from the new values → insert a history row with the old snapshot, `source = 'import'`. Identical reimports (the common case — most hourly reimports repeat yesterday's price) insert nothing.

One extra `SELECT` per price row on import — negligible next to the Claude extraction call already made per file.

**`backend/api/prices/update.php`** (manual edit): already `SELECT`s the existing row before building the `UPDATE`, so no extra query needed. After a successful update, if the new `price_usd` or `kit_vial_count` differ from what was fetched at the top, insert a history row with `source = 'manual_edit'`, `changed_by = $admin['id']`. A `tier_kit_size`-only or `vendor_sku`-only edit stays silent — same reasoning as above, not a price change.

## Explicitly excluded (not history events)

- **`backend/api/vendors/recalc_prices.php`** — bulk `price_per_unit` recompute is a formula correction (the kit-vial-count-factor bugfix backfill), not a market price change.
- **`backend/api/products/spec_update.php`**'s `price_per_unit` recompute when a spec's `numeric_value` (mg amount) is corrected — same reasoning, a data-correction, not a price event.
- **`spec_merge.php` / `spec_move.php` / `products/merge.php`** — these reassign `product_id`/`specification_id` on existing price rows; they don't change the price itself.

## Non-goals for this pass

- **Calendar UI rebuild** — real before→after display per day is the actual payoff of this table, but it's a separate follow-up once the ledger has data. Current Calendar behavior is unaffected.
- **Retention/pruning** — no cron, no TTL. Table grows indefinitely for now; revisit once there's real growth data to size against, not speculatively.
- **Backfill of pre-existing history** — nothing to backfill; every prior overwrite already destroyed the old value. The ledger starts clean from the next real price change after this ships.

## Migration

Next in sequence: `database/migrations/015_price_history.sql` (creates the table), plus adding it to `database/schema.sql` for fresh installs.

## Related

- [[wiki/entities/phase-roadmap|Phase Roadmap]] — backlog item #3
- [[wiki/analyses/2026-07-03-blueprint-vs-actual|Original Blueprint vs. Actual App]] — where the price-history gap was first surfaced
