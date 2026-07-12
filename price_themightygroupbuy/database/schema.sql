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
  pending_email          VARCHAR(254) NULL,          -- awaiting confirmation via email_change_token
  email_change_token     VARCHAR(64)  NULL,
  password_hash          VARCHAR(255) NOT NULL,
  display_name           VARCHAR(100) NOT NULL,
  email_token            VARCHAR(64)  NULL,          -- plain hex; cleared on verify
  email_verified_at      DATETIME     NULL,
  referral_code          CHAR(24)     NOT NULL UNIQUE,  -- 24 hex chars, random.random_bytes(12)
  referred_by_id         INT UNSIGNED NULL,
  is_admin               BOOLEAN      NOT NULL DEFAULT FALSE,
  test_account           BOOLEAN      NOT NULL DEFAULT FALSE,  -- excluded from real notifications/stats
  theme                  ENUM('system','light','dark') NOT NULL DEFAULT 'system',
  timezone               VARCHAR(64)  NOT NULL DEFAULT 'UTC',
  push_enabled           BOOLEAN      NOT NULL DEFAULT FALSE,
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

CREATE TABLE IF NOT EXISTS pc_login_history (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  INDEX (user_id, created_at)
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
  admin_id   INT UNSIGNED NULL,             -- SET NULL on admin delete — audit rows outlive their admin (#33)
  action     VARCHAR(200) NOT NULL,
  details    JSON         NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id) REFERENCES pc_users(id) ON DELETE SET NULL,
  INDEX (admin_id),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User activity/audit log (exports, etc.) — the user-side twin of the admin
-- audit log; surfaced per-user in the admin Users tab. See migrations/027.
CREATE TABLE IF NOT EXISTS pc_user_audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(80)  NOT NULL,
  details    JSON         NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  INDEX (user_id, created_at),
  INDEX (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_feedback (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  type       ENUM('general','ui_ux','feature','bug','performance','other') NOT NULL DEFAULT 'general',
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
  device_type   ENUM('desktop','mobile','tablet','other') NOT NULL DEFAULT 'other',
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
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  canonical_name    VARCHAR(200) NOT NULL UNIQUE,
  cas_number        VARCHAR(20) NULL,
  molecular_weight  DECIMAL(10,3) NULL,
  notes             TEXT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_product_aliases (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  alias      VARCHAR(200) NOT NULL,
  UNIQUE KEY (alias),
  FOREIGN KEY (product_id) REFERENCES pc_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multi-select therapeutic/use-case tags (backlog #16) — replaces the
-- single-select pc_products.category enum (dropped; see migration 017).
CREATE TABLE IF NOT EXISTS pc_classifications (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_product_classifications (
  product_id        INT UNSIGNED NOT NULL,
  classification_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, classification_id),
  FOREIGN KEY (product_id)        REFERENCES pc_products(id)        ON DELETE CASCADE,
  FOREIGN KEY (classification_id) REFERENCES pc_classifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_specifications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL,
  spec_label      VARCHAR(50)  NOT NULL,        -- display string e.g. "5mg"
  numeric_value   DECIMAL(10,4) NOT NULL,       -- normalized value (mcg→mg, g→mg)
  unit            ENUM('mg','iu','ml','other') NOT NULL,
  is_raw_material BOOLEAN NOT NULL DEFAULT FALSE, -- true for bulk/raw powder specs (e.g. "1g") vs a finished vial dose — lives on the spec, not a product-level classification, since one product can have both forms
  UNIQUE KEY (product_id, spec_label),
  FOREIGN KEY (product_id) REFERENCES pc_products(id) ON DELETE CASCADE,
  INDEX (product_id, numeric_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shopping cart (backlog #16, phase 2) — one row per (user, product, spec),
-- presence not quantity; v1 is "1 kit of this spec" only.
CREATE TABLE IF NOT EXISTS pc_cart_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  specification_id  INT UNSIGNED NOT NULL,
  added_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_item (user_id, product_id, specification_id),
  FOREIGN KEY (user_id)          REFERENCES pc_users(id)          ON DELETE CASCADE,
  FOREIGN KEY (product_id)       REFERENCES pc_products(id)       ON DELETE CASCADE,
  FOREIGN KEY (specification_id) REFERENCES pc_specifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Buy This Stack" (backlog #16, phase 3) — admin-curated bundles that
-- bulk-add their components to a user's cart in one click.
CREATE TABLE IF NOT EXISTS pc_stacks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  description TEXT NULL,
  is_active   BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_stack_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stack_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  specification_id  INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_stack_item (stack_id, product_id, specification_id),
  FOREIGN KEY (stack_id)          REFERENCES pc_stacks(id)          ON DELETE CASCADE,
  FOREIGN KEY (product_id)        REFERENCES pc_products(id)        ON DELETE CASCADE,
  FOREIGN KEY (specification_id)  REFERENCES pc_specifications(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_vendors (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name   VARCHAR(100) NOT NULL,
  contact_name   VARCHAR(100) NULL,
  email          VARCHAR(200) NULL,
  whatsapp       VARCHAR(50)  NULL,
  discord        VARCHAR(100) NULL,
  telegram       VARCHAR(100) NULL,
  website        VARCHAR(300) NULL,
  shipping_note  TEXT         NULL,
  notes          TEXT         NULL,
  is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
  is_hidden      BOOLEAN      NOT NULL DEFAULT FALSE, -- hide-not-delete purge (backlog #9); hiding also forces is_active=0
  is_verified    BOOLEAN      NOT NULL DEFAULT FALSE,  -- manual admin toggle, not auto-computed
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
                  'zelle','cashapp','credit_card','remitly') NOT NULL,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  UNIQUE KEY (vendor_id, method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_vendor_files (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id         INT UNSIGNED NOT NULL,
  original_filename VARCHAR(300) NOT NULL,
  stored_path       VARCHAR(500) NOT NULL,
  file_type         ENUM('pdf','xlsx','csv','image','zip') NOT NULL,
  category          ENUM('price_list','coa','other') NOT NULL DEFAULT 'price_list',
  file_size_bytes   INT UNSIGNED NULL,
  content_hash      CHAR(64) NULL,          -- SHA-256 of the raw file bytes, per-vendor dedup (backlog #14)
  uploaded_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at      DATETIME NULL,
  processing_status ENUM('pending','processing','complete','failed','skipped_duplicate') NOT NULL DEFAULT 'pending',
  processing_notes  TEXT NULL,
  is_current        BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  INDEX (vendor_id, is_current),
  INDEX (vendor_id, category),
  INDEX (processing_status),
  INDEX (vendor_id, content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full history of every raw Claude API call (backlog #24) — a JSON-parse
-- failure (or just auditing what Claude actually said) never needs a fresh,
-- costly API call again to inspect the output.
CREATE TABLE IF NOT EXISTS pc_claude_call_log (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_file_id              INT UNSIGNED NULL,
  call_type                   ENUM('extraction','vendor_contact_parse') NOT NULL,
  model                       VARCHAR(50) NOT NULL,
  http_status                 SMALLINT UNSIGNED NULL,
  stop_reason                 VARCHAR(50) NULL,
  input_tokens                INT UNSIGNED NULL,
  output_tokens                INT UNSIGNED NULL,
  cache_creation_input_tokens INT UNSIGNED NULL,
  cache_read_input_tokens     INT UNSIGNED NULL,
  raw_response_text           LONGTEXT NULL,
  parsed_ok                   BOOLEAN NOT NULL DEFAULT FALSE,
  error_message                TEXT NULL,
  created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_file_id) REFERENCES pc_vendor_files(id) ON DELETE SET NULL,
  INDEX (vendor_file_id),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_prices (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id        INT UNSIGNED NOT NULL,
  product_id       INT UNSIGNED NOT NULL,
  specification_id INT UNSIGNED NOT NULL,
  price_usd        DECIMAL(10,2) NOT NULL,
  price_per_unit   DECIMAL(12,6) NOT NULL,   -- = price_usd / (kit_vial_count * specifications.numeric_value); computed in PHP via pricePerUnit()
  kit_vial_count   SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  tier_kit_size    SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- minimum kit qty for this tiered-pricing column; vendor-defined, not fixed to 1/10/100
  vendor_sku       VARCHAR(50) NULL,                     -- vendor's own catalog code for this row, e.g. "TR5", "NJ100"
  non_standard_kit BOOLEAN NOT NULL DEFAULT FALSE,
  source_file_id   INT UNSIGNED NULL,
  is_active        BOOLEAN NOT NULL DEFAULT TRUE,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_price (vendor_id, product_id, specification_id, tier_kit_size),
  FOREIGN KEY (vendor_id)        REFERENCES pc_vendors(id)       ON DELETE CASCADE,
  FOREIGN KEY (product_id)       REFERENCES pc_products(id)      ON DELETE CASCADE,
  FOREIGN KEY (specification_id) REFERENCES pc_specifications(id) ON DELETE CASCADE,
  INDEX (product_id, specification_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only price-change ledger. No FKs on purpose — must survive a
-- pc_prices row being cascade-deleted (spec merge/move) or a future vendor
-- purge; IDs are denormalized and joined at read time instead.
CREATE TABLE IF NOT EXISTS pc_price_history (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id          INT UNSIGNED NOT NULL,
  product_id         INT UNSIGNED NOT NULL,
  specification_id   INT UNSIGNED NOT NULL,
  tier_kit_size      SMALLINT UNSIGNED NULL,        -- which kit-size tier changed; NULL only for pre-migration rows that can't be safely attributed
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

-- Admin-picked featured product for the public price calendar (backlog #18).
CREATE TABLE IF NOT EXISTS pc_calendar_features (
  feature_date     DATE         NOT NULL PRIMARY KEY,
  product_id       INT UNSIGNED NOT NULL,
  specification_id INT UNSIGNED NULL,
  note             VARCHAR(200) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id)       REFERENCES pc_products(id)       ON DELETE CASCADE,
  FOREIGN KEY (specification_id) REFERENCES pc_specifications(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by)       REFERENCES pc_users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_pending_imports (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_file_id       INT UNSIGNED NOT NULL,
  vendor_id            INT UNSIGNED NOT NULL,
  raw_json             JSON NOT NULL,        -- the single extracted price row as Claude returned it
  match_type           ENUM('new_product','new_spec','name_mismatch') NOT NULL,
  candidate_product_id INT UNSIGNED NULL,    -- best-guess existing product, if any (fuzzy match)
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
  product_id           INT UNSIGNED NULL,          -- set for standard (dropdown) path
  custom_product_name  VARCHAR(200) NULL,          -- set for custom-blend path
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

CREATE TABLE IF NOT EXISTS pc_comparison_log (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  selection_params JSON         NOT NULL,   -- products/vendors/specs/category/multi_only, as sent
  duration_ms      INT UNSIGNED NOT NULL,
  result_count     INT UNSIGNED NOT NULL,
  slow_flag        BOOLEAN      NOT NULL DEFAULT FALSE,  -- duration_ms over the configured budget
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  INDEX (duration_ms),
  INDEX (slow_flag, created_at),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_maintenance_runs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job        VARCHAR(100) NOT NULL,
  status     ENUM('ok','failed') NOT NULL DEFAULT 'ok',
  details    TEXT NULL,
  ran_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-database slow-query capture — see migrations/005_slow_query_cache.sql
-- for why this exists (mysql.slow_log is server-wide, shared with the grp
-- app on this box) and the CREATE EVENT that feeds this table hourly.
-- migrations/026 refined that event: it no longer ingests the slow-query
-- plumbing's own queries, and only keeps rows that are genuinely slow
-- (>=0.5s) or heavy (>=5000 rows examined) — not fast full-scans of tiny tables.
CREATE TABLE IF NOT EXISTS pc_slow_query_cache (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  query_hash        CHAR(64) NOT NULL UNIQUE,
  query_time_secs   DECIMAL(10,3) NOT NULL,
  lock_time_secs    DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  rows_sent         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rows_examined     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  query_sql         MEDIUMTEXT NOT NULL,
  first_seen_at     DATETIME NOT NULL,
  last_seen_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  occurrence_count  INT UNSIGNED NOT NULL DEFAULT 1,
  status            ENUM('new','acknowledged','resolved') NOT NULL DEFAULT 'new',
  status_note       TEXT NULL,
  status_updated_at DATETIME NULL,
  INDEX (status, last_seen_at),
  INDEX (query_time_secs)
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

INSERT IGNORE INTO pc_classifications (name) VALUES
  ('Mitochondrial'), ('Weight Management'), ('Fat Loss'), ('Healing & Recovery'), ('Bioregulator'),
  ('Growth Hormone'), ('Anti-Aging'), ('Skin & Hair'), ('Sleep & Recovery'), ('Sexual Health'),
  ('Cognitive'), ('Cosmetic'), ('Neuroprotective'), ('Clinical'), ('Hormone Support'),
  ('Antimicrobial'), ('Immune'), ('Growth Factors'), ('Stack'), ('Lab Supplies'),
  ('GLP / Metabolic'), ('Repair / Healing'), ('Neuro / Mood'), ('Social / Sexual'), ('Longevity'),
  ('GH Secretagogue (Non-HGH)'), ('Metabolic & Performance Support');

-- Placeholder product so the schema ships with something to test against
INSERT IGNORE INTO pc_products (id, canonical_name, notes) VALUES
  (1, 'BPC-157',   'Body Protection Compound 157. Placeholder — update as needed.'),
  (2, 'TB-500',    'Thymosin Beta 4 fragment. Placeholder — update as needed.'),
  (3, 'Semaglutide','GLP-1 agonist. Placeholder — update as needed.');

INSERT IGNORE INTO pc_specifications (product_id, spec_label, numeric_value, unit) VALUES
  (1, '2mg',   2.0000, 'mg'),
  (1, '5mg',   5.0000, 'mg'),
  (1, '10mg', 10.0000, 'mg'),
  (2, '5mg',   5.0000, 'mg'),
  (2, '10mg', 10.0000, 'mg'),
  (3, '2mg',   2.0000, 'mg'),
  (3, '5mg',   5.0000, 'mg');

-- Only 001 is pre-seeded here — it's the migration that created these tables
-- in the first place, so schema.sql's CREATE TABLE already IS its end state.
-- Never add later migrations to this seed list: schema.sql's CREATE TABLE
-- IF NOT EXISTS is a no-op on an existing install, so pre-marking a later
-- migration "applied" here means migrate.sh skips its real ALTER TABLE work
-- against that install and it silently never gets the new columns.
INSERT IGNORE INTO pc_migrations (filename) VALUES ('001_initial.sql');
