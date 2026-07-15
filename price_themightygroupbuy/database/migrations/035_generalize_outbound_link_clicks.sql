-- =============================================================
-- 035_generalize_outbound_link_clicks.sql (2026-07-15)
-- pc_whatsapp_clicks (migration 034, shipped same week) only covered one
-- outbound-link type. User asked to also track the vendor Website link and
-- the per-product CAS/PubChem link — rather than add two more near-duplicate
-- tables, generalize the one that exists:
--   - renamed to pc_outbound_link_clicks
--   - link_type VARCHAR(20) added (backfilled 'whatsapp' for existing rows —
--     every row so far came from the WhatsApp link, the only kind that existed)
--   - vendor_id made nullable (CAS clicks aren't tied to a vendor, only a product)
--   - product_id added, nullable (CAS clicks use this instead of vendor_id)
-- VARCHAR over ENUM for link_type so a future link type doesn't need another
-- migration just to widen an enum.
--
-- Guarded: schema.sql already defines pc_outbound_link_clicks directly (the
-- fresh-install target shape), and migrate.sh runs every migration file
-- regardless of install age. On a fresh install pc_outbound_link_clicks
-- already exists by the time this file runs, so a plain RENAME would collide
-- with it — only alter+rename when pc_whatsapp_clicks still holds real data
-- to migrate; otherwise drop it if it's a leftover empty duplicate.
-- =============================================================

SET @whatsapp_exists := (SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE() AND table_name = 'pc_whatsapp_clicks');
SET @outbound_exists := (SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE() AND table_name = 'pc_outbound_link_clicks');

SET @sql := IF(@whatsapp_exists > 0 AND @outbound_exists = 0,
  'ALTER TABLE pc_whatsapp_clicks
     ADD COLUMN link_type VARCHAR(20) NOT NULL DEFAULT ''whatsapp'' AFTER id,
     ADD COLUMN product_id INT UNSIGNED NULL AFTER vendor_id,
     MODIFY COLUMN vendor_id INT UNSIGNED NULL,
     ADD FOREIGN KEY (product_id) REFERENCES pc_products(id) ON DELETE CASCADE,
     ADD INDEX (link_type, created_at)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@whatsapp_exists > 0 AND @outbound_exists = 0,
  'RENAME TABLE pc_whatsapp_clicks TO pc_outbound_link_clicks',
  IF(@whatsapp_exists > 0, 'DROP TABLE pc_whatsapp_clicks', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
