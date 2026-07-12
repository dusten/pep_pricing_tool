-- 028_product_cas_mw.sql (2026-07-12)
-- Per-product (not per-spec) CAS registry number and molecular weight, for
-- linking a product straight to its PubChem entry.

ALTER TABLE pc_products
  ADD COLUMN cas_number       VARCHAR(20)    NULL AFTER canonical_name,
  ADD COLUMN molecular_weight DECIMAL(10,3)  NULL AFTER cas_number;
