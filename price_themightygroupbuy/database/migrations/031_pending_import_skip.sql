-- 031_pending_import_skip.sql (2026-07-13)
-- Review Queue only had Approve/Reject — no way to defer a decision on a
-- row without either committing it or discarding it. "Skip" needs to move
-- the row to the back of the FIFO queue (status stays 'pending', so it
-- isn't lost and still counts toward "remaining"), not just do nothing --
-- otherwise the single-card GET (ORDER BY created_at ASC LIMIT 1) would
-- just show the identical row again on the next fetch.

ALTER TABLE pc_pending_imports
  ADD COLUMN last_skipped_at DATETIME NULL AFTER created_at;
