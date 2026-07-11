-- User activity/audit log (2026-07-11). Regular users had no audit trail —
-- only admin actions were logged (pc_admin_audit_log). This records
-- user-initiated events worth auditing, starting with all data exports
-- (comparison CSV/XLSX, full export, personal-data export). CASCADE on user
-- delete, matching pc_login_history — a deleted account's own activity log
-- goes with it (me/export.php is the account-deletion reference set).
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
