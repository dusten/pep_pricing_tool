-- =============================================================
-- 007_vendor_intake_and_coa.sql
-- Vendor contact/payment expansion, product abbreviation, tiered
-- pricing, hard review queue for new/mismatched extraction data,
-- vendor file categorization (price_list/coa/other), and
-- user-submitted COA verification queue + manual vendor verified flag.
-- See Obsidian_pep_pricing_tool/wiki/analyses/2026-07-01-vendor-upload-spec.md
-- schema.sql already reflects this as the target state for fresh installs.
-- =============================================================

ALTER TABLE pc_products
  ADD COLUMN IF NOT EXISTS abbreviation VARCHAR(50) NULL AFTER canonical_name;

ALTER TABLE pc_vendors
  ADD COLUMN IF NOT EXISTS discord        VARCHAR(100)  NULL AFTER whatsapp,
  ADD COLUMN IF NOT EXISTS telegram       VARCHAR(100)  NULL AFTER discord,
  ADD COLUMN IF NOT EXISTS shipping_price DECIMAL(8,2)  NULL AFTER website,
  ADD COLUMN IF NOT EXISTS is_verified    BOOLEAN NOT NULL DEFAULT FALSE AFTER is_active;

CREATE TABLE IF NOT EXISTS pc_vendor_phones (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  phone     VARCHAR(30) NOT NULL,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  INDEX (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_vendor_payment_methods (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  method    ENUM('usdt_sol','usdc_sol','usdt_trc20','usdc_trc20','usdt_erc20','usdc_erc20',
                  'btc','eth','sol','paypal','wise','alipay','alibaba','wire','western_union',
                  'zelle','cashapp','credit_card') NOT NULL,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  UNIQUE KEY (vendor_id, method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pc_vendor_files
  ADD COLUMN IF NOT EXISTS category ENUM('price_list','coa','other') NOT NULL DEFAULT 'price_list' AFTER file_type,
  ADD INDEX IF NOT EXISTS vendor_id_2 (vendor_id, category);

-- Tiered pricing: fold tier_kit_size into the uniqueness key so 1/10/100-kit
-- rows for the same vendor+product+spec can all coexist.
ALTER TABLE pc_prices
  ADD COLUMN IF NOT EXISTS tier_kit_size TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER kit_vial_count;

-- Add the new 4-column key BEFORE dropping the old one: the old composite
-- unique key (vendor_id, product_id, specification_id) is currently the only
-- index covering vendor_id, which the FOREIGN KEY (vendor_id) constraint
-- needs — dropping it first would fail (or leave a gap) without a
-- replacement index already in place.
ALTER TABLE pc_prices ADD UNIQUE KEY IF NOT EXISTS uq_price (vendor_id, product_id, specification_id, tier_kit_size);

-- The old key's name was never set explicitly in schema.sql (MariaDB
-- auto-names it after the first column), so its exact name on this specific
-- database isn't guaranteed — look it up by its column signature rather than
-- guessing a literal name, so this can't silently no-op and leave a stale
-- 3-column unique key that would corrupt tiered-pricing inserts (a tier=10
-- row would collide with tier=1 on the old key and overwrite it, since
-- tier_kit_size isn't part of that key's ON DUPLICATE KEY UPDATE clause).
SET @old_key := (
  SELECT k.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE k
  WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'pc_prices'
    AND k.CONSTRAINT_NAME NOT IN ('PRIMARY', 'uq_price')
    AND k.REFERENCED_TABLE_NAME IS NULL
  GROUP BY k.CONSTRAINT_NAME
  HAVING GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION SEPARATOR ',') = 'vendor_id,product_id,specification_id'
  LIMIT 1
);
SET @drop_sql := IF(@old_key IS NOT NULL, CONCAT('ALTER TABLE pc_prices DROP KEY `', @old_key, '`'), 'SELECT 1');
PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS pc_pending_imports (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_file_id       INT UNSIGNED NOT NULL,
  vendor_id            INT UNSIGNED NOT NULL,
  raw_json             JSON NOT NULL,
  match_type           ENUM('new_product','new_spec','name_mismatch') NOT NULL,
  candidate_product_id INT UNSIGNED NULL,
  status               ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by          INT UNSIGNED NULL,
  reviewed_at          DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_file_id) REFERENCES pc_vendor_files(id) ON DELETE CASCADE,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  INDEX (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_coa_submissions (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id              INT UNSIGNED NOT NULL,
  vendor_id            INT UNSIGNED NOT NULL,
  product_id           INT UNSIGNED NULL,
  custom_product_name  VARCHAR(200) NULL,
  coa_url              VARCHAR(500) NOT NULL,
  status               ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by          INT UNSIGNED NULL,
  reviewed_at          DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES pc_products(id) ON DELETE CASCADE,
  INDEX (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
