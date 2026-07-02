-- =============================================================
-- 008_shipping_note.sql
-- Real vendor replies answer shipping with multi-line prose (carrier,
-- timeframe, weight-tiered cost breaks), not a single number — a DECIMAL
-- column can't hold that. Widen to free text and rename to match.
-- =============================================================

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pc_vendors' AND COLUMN_NAME = 'shipping_price'
);
SET @sql := IF(@exists > 0,
  'ALTER TABLE pc_vendors CHANGE COLUMN shipping_price shipping_note TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
