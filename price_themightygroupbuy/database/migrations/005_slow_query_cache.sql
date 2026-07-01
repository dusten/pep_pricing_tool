-- =============================================================
-- 005_slow_query_cache.sql
-- Per-database slow-query capture. mysql.slow_log is server-wide (shared
-- with the grp app on this box) and log_output=TABLE/log_queries_not_using_
-- indexes were already turned on server-side (see /etc/my.cnf.d/tmgb-perf.cnf).
-- grp already had an hourly EVENT draining ALL of mysql.slow_log into its
-- own cache table regardless of db, then wiping it — which silently ate
-- tmgb_price's rows too. Fixed on the grp side by scoping its event to
-- WHERE db = 'themightygroupbuy' on both the SELECT and the DELETE (done
-- directly via root SQL on 2026-07-01, not tracked here since grp isn't
-- this project's schema). This migration adds the tmgb_price-side twin,
-- scoped to WHERE db = 'tmgb_price', so the two events consume disjoint
-- rows and never race.
--
-- Requires SELECT, DELETE on mysql.slow_log granted to the tmgb_price DB
-- user (also done via root SQL on 2026-07-01) — without it this event's
-- INSERT...SELECT FROM mysql.slow_log fails silently every hour.
--
-- schema.sql already reflects this as the target state for fresh installs.
-- =============================================================

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
  -- Feedback-loop tracking: an admin marks a query acknowledged/resolved from
  -- the System tab. If a "resolved" query starts occurring again, the import
  -- event automatically flips it back to 'new' — a fix that didn't stick
  -- should re-surface, not stay silently marked resolved.
  status            ENUM('new','acknowledged','resolved') NOT NULL DEFAULT 'new',
  status_note       TEXT NULL,
  status_updated_at DATETIME NULL,
  INDEX (status, last_seen_at),
  INDEX (query_time_secs)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

CREATE EVENT IF NOT EXISTS pc_import_slow_queries
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Aggregate this db''s rows out of mysql.slow_log into pc_slow_query_cache; clears only this db''s rows from slow_log'
DO
BEGIN

  INSERT INTO pc_slow_query_cache
    (query_hash, query_time_secs, lock_time_secs, rows_sent, rows_examined,
     query_sql, first_seen_at, last_seen_at, occurrence_count)
  SELECT
    SHA2(sql_text, 256),
    TIME_TO_SEC(query_time),
    TIME_TO_SEC(lock_time),
    rows_sent,
    rows_examined,
    sql_text,
    start_time,
    start_time,
    1
  FROM mysql.slow_log
  WHERE db = 'tmgb_price'
    AND sql_text IS NOT NULL
    AND TRIM(sql_text) != ''
  ON DUPLICATE KEY UPDATE
    query_time_secs  = GREATEST(pc_slow_query_cache.query_time_secs, VALUES(query_time_secs)),
    occurrence_count = pc_slow_query_cache.occurrence_count + 1,
    last_seen_at     = VALUES(last_seen_at),
    status           = IF(pc_slow_query_cache.status = 'resolved', 'new', pc_slow_query_cache.status);

  DELETE FROM pc_slow_query_cache
  WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

  DELETE FROM mysql.slow_log WHERE db = 'tmgb_price';

  INSERT INTO pc_maintenance_runs (job, status) VALUES ('import_slow_queries', 'ok');

END$$

DELIMITER ;
