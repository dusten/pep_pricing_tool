-- =============================================================
-- 011_drop_product_abbreviation.sql
-- pc_products.abbreviation was a manual, product-level field that never got
-- used (100% NULL in production) and duplicated the purpose of the new,
-- actually-populated pc_prices.vendor_sku (per vendor+spec, not per
-- product — see 010_price_vendor_sku.sql). Removing to stop the confusion
-- between the two.
-- =============================================================

ALTER TABLE pc_products
  DROP COLUMN IF EXISTS abbreviation;
