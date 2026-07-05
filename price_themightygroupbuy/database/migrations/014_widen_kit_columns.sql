-- =============================================================
-- 014_widen_kit_columns.sql
-- pc_prices.kit_vial_count and tier_kit_size were TINYINT UNSIGNED (max 255),
-- silently clamped in every write path. A genuine >255-vial kit or a
-- >255-kit bulk tier would corrupt data. Widen both to SMALLINT UNSIGNED
-- (max 65,535). Re-running MODIFY COLUMN with the same definition is a
-- no-op, so this is safe to apply more than once.
-- =============================================================

ALTER TABLE pc_prices
  MODIFY COLUMN kit_vial_count SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  MODIFY COLUMN tier_kit_size  SMALLINT UNSIGNED NOT NULL DEFAULT 1;
