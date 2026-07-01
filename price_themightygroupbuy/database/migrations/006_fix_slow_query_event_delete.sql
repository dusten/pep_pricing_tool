-- =============================================================
-- 006_fix_slow_query_event_delete.sql
-- mysql.slow_log is a "log table" (log_output=TABLE) — MariaDB rejects ANY
-- DELETE against it, filtered or unconditional ("You can't use locks with
-- log tables", error 1556). Discovered when pc_import_slow_queries (added
-- in 005) failed on every run at its `DELETE FROM mysql.slow_log WHERE
-- db = 'tmgb_price'` step. Confirmed live: even a plain unconditional
-- DELETE FROM mysql.slow_log fails the same way — only TRUNCATE TABLE
-- works, and TRUNCATE can't be scoped to one db's rows.
--
-- Fix: this event no longer touches mysql.slow_log's contents at all —
-- it only SELECTs from it (unrestricted) to import this db's rows. A
-- separate, root-owned, server-level daily cron (not part of either app's
-- deploy — see /etc/cron.d/mysql-slowlog-truncate on the server) runs
-- `TRUNCATE TABLE mysql.slow_log` once a day, well after both apps' hourly
-- imports have had many chances to run, clearing it for both databases at
-- once. grp's equivalent event was fixed the same way directly via root
-- SQL on 2026-07-01 (not tracked here — themightygroupbuy isn't this
-- project's schema).
--
-- schema.sql already reflects this as the target state for fresh installs.
-- =============================================================

DELIMITER $$

ALTER EVENT pc_import_slow_queries
COMMENT 'Aggregate this db''s rows out of mysql.slow_log into pc_slow_query_cache. Does not clear slow_log itself — log tables reject DELETE entirely; a separate shared daily job truncates it.'
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

  INSERT INTO pc_maintenance_runs (job, status) VALUES ('import_slow_queries', 'ok');

END$$

DELIMITER ;
