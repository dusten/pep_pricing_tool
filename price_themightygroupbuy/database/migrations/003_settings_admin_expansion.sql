-- =============================================================
-- 003_settings_admin_expansion.sql
-- User settings (timezone, push, session versioning, test-account
-- flag) + admin System/Ops tab (maintenance log) + comparison
-- query logging/replay (perf.php device_type, pc_comparison_log).
-- schema.sql already reflects this as the target state for fresh installs.
-- =============================================================

-- IF NOT EXISTS (MariaDB 10.0.2+) makes this safe to re-run even when
-- schema.sql's CREATE TABLE already defines these columns (fresh installs) —
-- without it, this fails with "duplicate column" the moment schema.sql and
-- this migration both define the same end state.
ALTER TABLE pc_users
  ADD COLUMN IF NOT EXISTS timezone       VARCHAR(64)  NOT NULL DEFAULT 'UTC'   AFTER theme,
  ADD COLUMN IF NOT EXISTS push_enabled   BOOLEAN      NOT NULL DEFAULT FALSE   AFTER timezone,
  ADD COLUMN IF NOT EXISTS test_account   BOOLEAN      NOT NULL DEFAULT FALSE   AFTER is_admin,
  ADD COLUMN IF NOT EXISTS pending_email  VARCHAR(254) NULL                     AFTER email,
  ADD COLUMN IF NOT EXISTS email_change_token VARCHAR(64) NULL                  AFTER pending_email;

CREATE TABLE IF NOT EXISTS pc_login_history (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_comparison_log (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  selection_params JSON       NOT NULL,
  duration_ms    INT UNSIGNED NOT NULL,
  result_count   INT UNSIGNED NOT NULL,
  slow_flag      BOOLEAN      NOT NULL DEFAULT FALSE,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

ALTER TABLE pc_perf_metrics
  ADD COLUMN IF NOT EXISTS device_type ENUM('desktop','mobile','tablet','other') NOT NULL DEFAULT 'other' AFTER page;
