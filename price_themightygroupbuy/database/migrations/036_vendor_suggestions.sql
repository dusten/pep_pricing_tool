-- =============================================================
-- 036_vendor_suggestions.sql (2026-07-15)
-- User-suggested vendors (backlog #69), Phase 1. Users (vendor reps +
-- customers) submit contact details + a pricing file; strict CSV template
-- parses + scores inline, anything else lands at pending_parse (Phase 2
-- wires up the Claude-pipeline fallback + cron loop). Admin accept creates
-- a real catalog vendor via the existing commitExtractionResult() machinery.
-- Test-gated (pc_users.test_account) during the build — see
-- requireSuggestionAccess() in backend/lib/vendor_suggestions.php.
-- =============================================================

CREATE TABLE IF NOT EXISTS pc_vendor_suggestions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  relationship      ENUM('vendor_rep','customer','other') NOT NULL,
  display_name      VARCHAR(100) NOT NULL,
  contact_name      VARCHAR(100) NULL,
  email             VARCHAR(200) NULL,
  whatsapp          VARCHAR(50)  NULL,
  discord           VARCHAR(100) NULL,
  telegram          VARCHAR(100) NULL,
  website           VARCHAR(300) NULL,      -- safeHttpUrl() at write time
  phones            VARCHAR(300) NULL,      -- comma-separated; normalized only at accept
  payment_methods   VARCHAR(500) NULL,      -- comma-separated enum values
  notes             TEXT NULL,
  original_filename VARCHAR(300) NOT NULL,
  stored_path       VARCHAR(500) NOT NULL,  -- relative to backend/storage/
  file_type         ENUM('pdf','xlsx','csv','image','zip') NOT NULL,
  file_size_bytes   INT UNSIGNED NULL,
  is_template_csv   BOOLEAN NOT NULL DEFAULT FALSE,
  status ENUM('pending_parse','processing','scored','parse_failed','virus_detected','accepted','rejected')
         NOT NULL DEFAULT 'pending_parse',
  extracted_json    JSON NULL,              -- {contact, warnings, prices} — never touches pc_prices until accept
  score_json        JSON NULL,
  duplicate_of_vendor_id INT UNSIGNED NULL,
  admin_note        TEXT NULL,
  vendor_id         INT UNSIGNED NULL,      -- set on accept
  reviewed_by       INT UNSIGNED NULL,
  reviewed_at       DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES pc_users(id)   ON DELETE CASCADE,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE SET NULL,
  INDEX (status, created_at),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
