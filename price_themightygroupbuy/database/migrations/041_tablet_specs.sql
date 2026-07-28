-- =============================================================
-- 041_tablet_specs.sql (2026-07-27)
-- Adds is_tablet to pc_specifications, mirroring is_raw_material — same
-- problem, a product can have more than one form and the form is a property
-- of the dose (spec), not the product. The unique key must widen to include
-- is_tablet (not just add the column): unlike raw-material specs, whose
-- weight-based label ("1g") virtually never collides with a finished-dose
-- label, a tablet spec plausibly shares the EXACT same dose label as an
-- existing injectable spec of the same product (a 5mg oral tablet and a 5mg
-- injectable vial are both legitimately labeled "5mg"). Without widening the
-- key, the second one couldn't exist as a distinct row.
-- =============================================================

ALTER TABLE pc_specifications
  ADD COLUMN is_tablet BOOLEAN NOT NULL DEFAULT FALSE AFTER is_raw_material,
  DROP INDEX product_id,
  ADD UNIQUE KEY product_id (product_id, spec_label, is_tablet);
