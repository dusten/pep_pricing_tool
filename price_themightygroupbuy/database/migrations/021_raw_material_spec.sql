-- =============================================================
-- 021_raw_material_spec.sql
-- Raw/bulk powder pricing (backlog #22, follow-up): "raw-ness" belongs on
-- the spec, not on the product's classification tags. A classification is
-- inherently product-level (one product can have many specs), so tagging
-- the whole PRODUCT as "Raw Material" made the Comparison table's raw-only
-- filter surface every spec of that product (finished vial sizes included),
-- not just its actual raw-powder row. Moving the flag onto pc_specifications
-- fixes that at the source.
-- =============================================================

ALTER TABLE pc_specifications
  ADD COLUMN IF NOT EXISTS is_raw_material BOOLEAN NOT NULL DEFAULT FALSE;

-- Backfill: every "1g" spec that exists so far came from the Scarlett
-- raw-powder import this session — safe to mark all of them now. Future raw
-- specs get this set at creation time via findOrCreateSpec()'s new parameter.
UPDATE pc_specifications SET is_raw_material = 1 WHERE spec_label = '1g';

-- Retire the now-redundant "Raw Material" classification tag (first-pass
-- approach, same day) — a product-level tag can't distinguish "this one
-- spec is raw" from "this product also happens to have a raw form", which
-- is exactly the confusion this migration fixes.
DELETE FROM pc_product_classifications
  WHERE classification_id = (SELECT id FROM pc_classifications WHERE name = 'Raw Material' LIMIT 1);
DELETE FROM pc_classifications WHERE name = 'Raw Material';
