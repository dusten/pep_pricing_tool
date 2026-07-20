-- =============================================================
-- 039_search_log_and_activity_scope.sql (2026-07-20)
-- Real search-activity logging for the admin Activity dashboard.
-- pc_query_log is free-tier quota bookkeeping only (admins/paid users never
-- write to it, and free users only log once per distinct filter) — it can't
-- serve as a usage-analytics source. This table is an unconditional,
-- undeduped log of every /api/comparison request, written alongside (not
-- instead of) the existing quota logic.
-- =============================================================

CREATE TABLE IF NOT EXISTS pc_search_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE CASCADE,
  INDEX (created_at),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
