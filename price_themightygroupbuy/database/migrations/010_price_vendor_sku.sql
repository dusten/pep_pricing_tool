-- =============================================================
-- 010_price_vendor_sku.sql
-- Vendor's own catalog code/SKU per price row (e.g. "TR5", "NJ100") — varies
-- by vendor AND by spec, so it belongs on pc_prices (the vendor+product+spec
-- grain), not pc_products.abbreviation (a single admin-curated value shared
-- across every vendor, already used for a different purpose in ProductsTab).
-- =============================================================

ALTER TABLE pc_prices
  ADD COLUMN IF NOT EXISTS vendor_sku VARCHAR(50) NULL AFTER tier_kit_size;
