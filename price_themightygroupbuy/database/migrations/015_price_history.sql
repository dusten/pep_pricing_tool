-- =============================================================
-- 015_price_history.sql
-- Real price-change ledger (backlog #3). pc_prices stays a single live row
-- per (vendor, product, spec, tier) — this table is append-only, snapshotted
-- right before an overwrite would destroy the old value.
--
-- Deliberately NO foreign keys: pc_prices rows are cascade-deleted today by
-- spec merges/moves and product merges (normal admin operations), and a
-- future full vendor purge (backlog #9) will delete vendor/product rows
-- outright. History's entire point is to survive the live row disappearing.
-- IDs are stored denormalized and joined at read time, same pattern
-- calendar.php already uses.
-- =============================================================

CREATE TABLE IF NOT EXISTS pc_price_history (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id          INT UNSIGNED NOT NULL,
  product_id         INT UNSIGNED NOT NULL,
  specification_id   INT UNSIGNED NOT NULL,
  old_price_usd      DECIMAL(10,2) NULL,           -- NULL = brand-new price line, no prior value
  old_price_per_unit DECIMAL(12,6) NULL,
  old_kit_vial_count SMALLINT UNSIGNED NULL,
  new_price_usd      DECIMAL(10,2) NOT NULL,
  new_price_per_unit DECIMAL(12,6) NOT NULL,
  new_kit_vial_count SMALLINT UNSIGNED NOT NULL,
  source             ENUM('import','manual_edit') NOT NULL,
  changed_by         INT UNSIGNED NULL,             -- admin id for manual_edit, NULL for import
  changed_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (vendor_id, product_id, specification_id, changed_at),
  INDEX (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
