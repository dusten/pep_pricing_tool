-- 029_price_history_tier.sql (2026-07-12)
-- pc_price_history never recorded which tier_kit_size a price change applied
-- to, so a vendor selling multiple kit-size tiers for the same (vendor,
-- product, spec) -- e.g. Purelypep Factory's KPV 10mg at 1/10/50-kit -- had
-- all tiers' price changes interleaved into one commingled stream. Every
-- reader (comparison-page history icon/popover, calendar milestones) then
-- either mixed tiers together or picked an arbitrary one.
--
-- Backfill is only safe where it's unambiguous: a (vendor, product,
-- specification) combo that has ever had exactly ONE distinct tier_kit_size
-- in pc_prices (checked across active AND inactive rows) can only ever have
-- meant that tier -- ~90% of combos (2,724 of 3,032 active today). The
-- remaining ~10% that sell multiple tiers have no way to retroactively
-- attribute old history rows to the right tier, so those stay NULL --
-- honest "unknown," not guessed. New history rows are tagged correctly by
-- the app from this migration forward regardless.

ALTER TABLE pc_price_history
  ADD COLUMN tier_kit_size SMALLINT UNSIGNED NULL AFTER specification_id;

UPDATE pc_price_history ph
JOIN (
  SELECT vendor_id, product_id, specification_id, MIN(tier_kit_size) AS only_tier
  FROM pc_prices
  GROUP BY vendor_id, product_id, specification_id
  HAVING COUNT(DISTINCT tier_kit_size) = 1
) single_tier
  ON single_tier.vendor_id = ph.vendor_id
 AND single_tier.product_id = ph.product_id
 AND single_tier.specification_id = ph.specification_id
SET ph.tier_kit_size = single_tier.only_tier;
