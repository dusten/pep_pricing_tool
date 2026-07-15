-- =============================================================
-- 037_vendor_suggestion_approval.sql (2026-07-15)
-- Admin-approval gate for Claude extraction (backlog #69): non-template
-- files now land at 'awaiting_approval' instead of 'pending_parse' — an
-- admin must explicitly queue them (admin/vendor_suggestions.php action
-- 'queue') before the cron will touch them. Also adds content_hash so a
-- user can't burn repeat Claude calls resubmitting the same file.
-- =============================================================

ALTER TABLE pc_vendor_suggestions
  MODIFY COLUMN status ENUM('pending_parse','awaiting_approval','processing','scored','parse_failed','virus_detected','accepted','rejected')
              NOT NULL DEFAULT 'pending_parse';

ALTER TABLE pc_vendor_suggestions
  ADD COLUMN content_hash CHAR(64) NULL AFTER file_size_bytes,
  ADD INDEX (user_id, content_hash);
