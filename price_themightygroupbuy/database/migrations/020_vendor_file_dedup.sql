-- =============================================================
-- 020_vendor_file_dedup.sql
-- Backlog #14, layer 1 — per-vendor file-hash pre-check. A byte-identical
-- re-upload of a price_list file that was already successfully processed
-- gets cataloged (never silently dropped) but skips Claude extraction
-- entirely, via the new 'skipped_duplicate' status.
-- =============================================================

ALTER TABLE pc_vendor_files
  ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL AFTER file_size_bytes,
  MODIFY COLUMN processing_status ENUM('pending','processing','complete','failed','skipped_duplicate') NOT NULL DEFAULT 'pending',
  ADD INDEX IF NOT EXISTS idx_vendor_content_hash (vendor_id, content_hash);
