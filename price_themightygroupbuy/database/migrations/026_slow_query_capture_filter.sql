-- =============================================================
-- 026_slow_query_capture_filter.sql  (backlog #7 follow-up, 2026-07-10)
--
-- pc_slow_query_cache had become 99% noise. Two problems, both fixed here by
-- replacing the pc_import_slow_queries event:
--
--   1. Self-ingestion: the hourly event's own INSERT...SELECT FROM slow_log
--      and its DELETE against pc_slow_query_cache were themselves logged to
--      mysql.slow_log, then imported on the next run — recorded at a phantom
--      ~12.4s and frozen there forever by the GREATEST() on query_time. Those
--      were the only two rows in the whole table over 1 second, and both were
--      the janitor logging itself.
--   2. Fast no-index scans: everything else (1500+ rows) was sub-second
--      queries flagged only by log_queries_not_using_indexes — but a full scan
--      of a 200-row table is the correct, fast plan, not something to fix.
--
-- New WHERE: never capture queries that touch the slow-query plumbing itself,
-- and only keep rows that are genuinely slow (>=0.5s) OR examined a lot of
-- rows (>=5000). Then TRUNCATE the accumulated noise so it repopulates clean.
-- Everything else about the event is unchanged from migration 005.
--
-- Deliberately NOT touching my.cnf min_examined_row_limit: that's server-wide
-- and shared with the grp app on this box, and the event-level filter plus the
-- existing hourly `DELETE FROM mysql.slow_log WHERE db='tmgb_price'` already
-- keep this table clean without it.
-- =============================================================

DROP EVENT IF EXISTS pc_import_slow_queries;

TRUNCATE TABLE pc_slow_query_cache;

DELIMITER $$

CREATE EVENT IF NOT EXISTS pc_import_slow_queries
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Aggregate this db''s genuinely-slow rows out of mysql.slow_log into pc_slow_query_cache; skips the slow-query plumbing''s own queries; clears only this db''s rows from slow_log'
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
    -- Don't ingest the importer's own INSERT/DELETE, or the DELETE against
    -- mysql.slow_log — that self-reference was the whole 12.4s phantom.
    AND sql_text NOT LIKE '%pc_slow_query_cache%'
    AND sql_text NOT LIKE '%slow_log%'
    -- Only genuinely slow, or genuinely heavy — not "scanned a 200-row table".
    AND (TIME_TO_SEC(query_time) >= 0.5 OR rows_examined >= 5000)
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
