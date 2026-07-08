-- One-off (2026-07-08), backlog #2: all-vendors price_per_unit backfill.
-- The write-path fix (shared pricePerUnit() helper) shipped 2026-07-03, but
-- rows written before it — or whose price_usd/spec numeric_value was later
-- corrected by direct SQL (June-Sale fix, CALLA mg/ml spec corrections) —
-- still held a stale $/unit. Recomputes every active-or-not price row from
-- the canonical formula. Dry-run found exactly 30 stale rows (Premipeptides
-- June-Sale remnants + CALLA rows predating their spec numeric_value fix);
-- post-run check confirmed 0 remain.
UPDATE pc_prices pr JOIN pc_specifications s ON s.id = pr.specification_id
SET pr.price_per_unit = ROUND(pr.price_usd / (GREATEST(pr.kit_vial_count,1) * s.numeric_value), 6)
WHERE s.numeric_value > 0;
