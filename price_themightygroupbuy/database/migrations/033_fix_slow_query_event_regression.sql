-- =============================================================
-- 033_fix_slow_query_event_regression.sql (2026-07-14)
--
-- Migration 026 (2026-07-10) recreated pc_import_slow_queries from scratch to
-- add a noise filter, and in doing so accidentally reintroduced the exact
-- `DELETE FROM mysql.slow_log` statement that migration 006 had already
-- removed — MariaDB rejects any DELETE against a log table ("You can't use
-- locks with log tables", error 1556). Confirmed live in the MariaDB error
-- log: this event has failed on every single hourly run since ~2026-07-11,
-- silently — the earlier INSERT...SELECT (which populates
-- pc_slow_query_cache) still succeeds before hitting the broken DELETE, so
-- the table looked like it was still working, but occurrence_count/
-- last_seen_at were being inflated by re-importing the same not-yet-cleared
-- slow_log rows every hour, and the pc_maintenance_runs 'ok' heartbeat for
-- this job stopped updating entirely (last real success: 2026-07-11 00:39).
--
-- Fix: same filter logic as 026, minus the DELETE against mysql.slow_log —
-- back to exactly 006's proven approach (SELECT-only; the log table is
-- cleared by a separate, unrelated daily root cron shared with the other
-- app on this box, per 006's own comment).
--
-- Also purges the accumulated cache: all 886 existing rows were captured
-- while this bug was live, so their occurrence_count/last_seen_at are not
-- trustworthy, and — separately — every one of them was flagged purely via
-- "rows_examined >= 5000", not genuine slowness (max recorded query_time
-- across the whole table was 0.082s). Starts clean so the table only ever
-- reflects data captured under the fixed, correct event.
-- =============================================================

DROP EVENT IF EXISTS pc_import_slow_queries;

TRUNCATE TABLE pc_slow_query_cache;

DELIMITER $$

CREATE EVENT IF NOT EXISTS pc_import_slow_queries
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Aggregate this db''s genuinely-slow rows out of mysql.slow_log into pc_slow_query_cache; skips the slow-query plumbing''s own queries. Does not clear slow_log itself — log tables reject DELETE entirely; a separate shared daily job truncates it.'
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
    AND sql_text NOT LIKE '%pc_slow_query_cache%'
    AND sql_text NOT LIKE '%slow_log%'
    AND (TIME_TO_SEC(query_time) >= 0.5 OR rows_examined >= 5000)
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
