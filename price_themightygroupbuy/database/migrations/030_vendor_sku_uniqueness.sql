-- 030_vendor_sku_uniqueness.sql (2026-07-12)
-- pc_prices' uniqueness constraint (vendor_id, product_id, specification_id,
-- tier_kit_size) doesn't include vendor_sku. A vendor whose price list
-- genuinely lists the same product/spec/tier twice under two different
-- catalog codes (e.g. Purelypep Factory's KPV 10mg: sku "KPV10" at
-- $46/$38/$29 and sku "KP10" at $42/$35/$26 -- both real, both in the same
-- extraction) has the second one silently overwrite the first via
-- `ON DUPLICATE KEY UPDATE`, discarding one real listing and logging a fake
-- "price change" in pc_price_history every time the file gets reimported.
-- Found via 115 colliding price-slot pairs across 26 distinct extraction
-- runs -- not isolated to this one vendor/product.
--
-- vendor_sku must be NOT NULL for this to work correctly: MySQL treats each
-- NULL in a UNIQUE index as distinct from every other NULL, so simply adding
-- a nullable column to the key would let vendors with no SKU (the majority)
-- insert a brand-new row on every reimport instead of updating the existing
-- one. Standardizing on '' for "no sku" keeps that dedup behavior intact.

UPDATE pc_prices SET vendor_sku = '' WHERE vendor_sku IS NULL;
ALTER TABLE pc_prices MODIFY COLUMN vendor_sku VARCHAR(50) NOT NULL DEFAULT '';

-- uq_price is the only index covering vendor_id, so it's load-bearing for
-- pc_prices_ibfk_1 -- MariaDB won't let it be dropped outright ("needed in a
-- foreign key constraint"). Add the replacement first, then drop/rename so a
-- valid supporting index exists at every point.
ALTER TABLE pc_prices ADD UNIQUE KEY uq_price_v2 (vendor_id, product_id, specification_id, tier_kit_size, vendor_sku);
ALTER TABLE pc_prices DROP INDEX uq_price;
ALTER TABLE pc_prices RENAME INDEX uq_price_v2 TO uq_price;
