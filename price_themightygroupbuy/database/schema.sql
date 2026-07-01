-- =============================================================
-- TheMightyGroupBuy Price Comparison — schema.sql
-- DB: tmgb_price   Prefix: pc_   Charset: utf8mb4
-- This file is the source of truth. Every migration must patch it.
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------
-- Infrastructure
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pc_migrations (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename    VARCHAR(200) NOT NULL UNIQUE,
  applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Auth
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pc_users (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email                  VARCHAR(254) NOT NULL UNIQUE,
  password_hash          VARCHAR(255) NOT NULL,
  display_name           VARCHAR(100) NOT NULL,
  email_token            VARCHAR(64)  NULL,          -- plain hex; cleared on verify
  email_verified_at      DATETIME     NULL,
  referral_code          CHAR(24)     NOT NULL UNIQUE,  -- 24 hex chars, random.random_bytes(12)
  referred_by_id         INT UNSIGNED NULL,
  is_admin               BOOLEAN      NOT NULL DEFAULT FALSE,
  theme                  ENUM('system','light','dark') NOT NULL DEFAULT 'system',
  -- Subscription (populated by Stripe webhooks)
  tier                   ENUM('free','advanced','pro','expert') NOT NULL DEFAULT 'free',
  stripe_customer_id     VARCHAR(64)  NULL,
  stripe_subscription_id VARCHAR(64)  NULL,
  tier_status            ENUM('active','past_due','canceled','trialing','none') NOT NULL DEFAULT 'none',
  tier_renews_at         DATETIME     NULL,
  account_credit_usd     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  -- Meta
  last_login_at          DATETIME     NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (referred_by_id) REFERENCES pc_users(id) ON DELETE SET NULL,
  INDEX (email_token),
  INDEX (referral_code),
  INDEX (stripe_customer_id),
  INDEX (stripe_subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_sessions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  token_hash   CHAR(64)     NOT NULL UNIQUE,  -- sha256 of the bearer token
  expires_at   DATETIME     NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_agent   VARCHAR(500) NULL,
  ip           VARCHAR(45)  NULL,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  INDEX (token_hash),
  INDEX (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL UNIQUE,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  INDEX (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Waitlist & Referrals
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pc_waitlist (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(254) NOT NULL UNIQUE,
  name          VARCHAR(100) NULL,
  referral_code CHAR(24)     NULL,         -- code used to get invited
  invite_token  VARCHAR(64)  NULL UNIQUE,  -- token emailed to invitee
  invited_at               DATETIME     NULL,
  joined_at                DATETIME     NULL,         -- set when they complete registration
  confirmation_emails_sent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (invite_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_referrals (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id INT UNSIGNED NOT NULL,
  referee_id  INT UNSIGNED NOT NULL UNIQUE,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (referrer_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  FOREIGN KEY (referee_id)  REFERENCES pc_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Admin infrastructure
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pc_admin_audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id   INT UNSIGNED NOT NULL,
  action     VARCHAR(200) NOT NULL,
  details    JSON         NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  INDEX (admin_id),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_feedback (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  type       ENUM('bug','feature','other') NOT NULL DEFAULT 'other',
  message    TEXT NOT NULL,
  url        VARCHAR(500) NULL,
  is_read    BOOLEAN NOT NULL DEFAULT FALSE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE SET NULL,
  INDEX (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_perf_metrics (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL,
  page          VARCHAR(200) NULL,
  dns_ms        SMALLINT UNSIGNED NULL,
  connect_ms    SMALLINT UNSIGNED NULL,
  ttfb_ms       SMALLINT UNSIGNED NULL,
  dom_load_ms   SMALLINT UNSIGNED NULL,
  load_ms       SMALLINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE SET NULL,
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_app_settings (
  `key`      VARCHAR(100) NOT NULL PRIMARY KEY,
  value      TEXT         NOT NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Domain: Products & Pricing
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pc_products (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  canonical_name VARCHAR(200) NOT NULL UNIQUE,
  category       ENUM('glp1','peptide','hormone','blend','consumable','other') NOT NULL DEFAULT 'peptide',
  notes          TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_product_aliases (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  alias      VARCHAR(200) NOT NULL,
  UNIQUE KEY (alias),
  FOREIGN KEY (product_id) REFERENCES pc_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_specifications (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    INT UNSIGNED NOT NULL,
  spec_label    VARCHAR(50)  NOT NULL,        -- display string e.g. "5mg"
  numeric_value DECIMAL(10,4) NOT NULL,       -- normalized value (mcg→mg, g→mg)
  unit          ENUM('mg','iu','ml','other') NOT NULL,
  UNIQUE KEY (product_id, spec_label),
  FOREIGN KEY (product_id) REFERENCES pc_products(id) ON DELETE CASCADE,
  INDEX (product_id, numeric_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_vendors (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(100) NOT NULL,
  contact_name VARCHAR(100) NULL,
  email        VARCHAR(200) NULL,
  whatsapp     VARCHAR(50)  NULL,
  website      VARCHAR(300) NULL,
  notes        TEXT         NULL,
  is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_vendor_files (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id         INT UNSIGNED NOT NULL,
  original_filename VARCHAR(300) NOT NULL,
  stored_path       VARCHAR(500) NOT NULL,
  file_type         ENUM('pdf','xlsx','csv') NOT NULL,
  file_size_bytes   INT UNSIGNED NULL,
  uploaded_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at      DATETIME NULL,
  processing_status ENUM('pending','processing','complete','failed') NOT NULL DEFAULT 'pending',
  processing_notes  TEXT NULL,
  is_current        BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  INDEX (vendor_id, is_current),
  INDEX (processing_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_prices (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id        INT UNSIGNED NOT NULL,
  product_id       INT UNSIGNED NOT NULL,
  specification_id INT UNSIGNED NOT NULL,
  price_usd        DECIMAL(10,2) NOT NULL,
  price_per_unit   DECIMAL(12,6) NOT NULL,   -- = price_usd / specifications.numeric_value; computed in PHP
  kit_vial_count   TINYINT UNSIGNED NOT NULL DEFAULT 10,
  non_standard_kit BOOLEAN NOT NULL DEFAULT FALSE,
  source_file_id   INT UNSIGNED NULL,
  is_active        BOOLEAN NOT NULL DEFAULT TRUE,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (vendor_id, product_id, specification_id),
  FOREIGN KEY (vendor_id)        REFERENCES pc_vendors(id)       ON DELETE CASCADE,
  FOREIGN KEY (product_id)       REFERENCES pc_products(id)      ON DELETE CASCADE,
  FOREIGN KEY (specification_id) REFERENCES pc_specifications(id) ON DELETE CASCADE,
  INDEX (product_id, specification_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Subscription & Metering
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pc_query_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  filter_hash CHAR(40)     NOT NULL,    -- sha1 of normalized filter params
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_referral_credits (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id  INT UNSIGNED NOT NULL,
  referee_id   INT UNSIGNED NOT NULL UNIQUE,
  amount_usd   DECIMAL(10,2) NOT NULL,
  granted_at   DATETIME NULL,           -- set when referee's first paid invoice settles
  stripe_credit_id VARCHAR(64) NULL,
  FOREIGN KEY (referrer_id) REFERENCES pc_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_billing_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stripe_event_id VARCHAR(64) NOT NULL UNIQUE,
  type            VARCHAR(80) NOT NULL,
  user_id         INT UNSIGNED NULL,
  payload         JSON NOT NULL,
  received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE SET NULL,
  INDEX (type),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------
-- Seed data
-- ------------------------------------------------------------------

INSERT IGNORE INTO pc_app_settings (`key`, value) VALUES
  ('waitlist_mode',              '1'),
  ('maintenance_mode',           '0'),
  ('referral_credit_usd',        '5.00'),
  ('free_tier_query_limit',      '3'),
  ('free_tier_window_hours',     '72'),
  ('annual_discount_months_free','2'),
  ('session_lifetime_days',      '30');

-- Placeholder product so the schema ships with something to test against
INSERT IGNORE INTO pc_products (id, canonical_name, category, notes) VALUES
  (1, 'BPC-157',   'peptide', 'Body Protection Compound 157. Placeholder — update as needed.'),
  (2, 'TB-500',    'peptide', 'Thymosin Beta 4 fragment. Placeholder — update as needed.'),
  (3, 'Semaglutide','glp1',   'GLP-1 agonist. Placeholder — update as needed.');

INSERT IGNORE INTO pc_specifications (product_id, spec_label, numeric_value, unit) VALUES
  (1, '2mg',   2.0000, 'mg'),
  (1, '5mg',   5.0000, 'mg'),
  (1, '10mg', 10.0000, 'mg'),
  (2, '5mg',   5.0000, 'mg'),
  (2, '10mg', 10.0000, 'mg'),
  (3, '2mg',   2.0000, 'mg'),
  (3, '5mg',   5.0000, 'mg');

INSERT IGNORE INTO pc_migrations (filename) VALUES ('001_initial.sql');
