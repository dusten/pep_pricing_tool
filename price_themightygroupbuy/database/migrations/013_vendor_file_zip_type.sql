-- =============================================================
-- 013_vendor_file_zip_type.sql
-- Vendors sometimes zip a handful of shared images into one download
-- (WhatsApp does this automatically) — add 'zip' to pc_vendor_files.file_type
-- so those uploads route through zip_reader.php's multi-page extraction.
-- Re-running MODIFY COLUMN with the same definition is a no-op, so this is
-- safe to apply more than once.
-- =============================================================

ALTER TABLE pc_vendor_files
  MODIFY COLUMN file_type ENUM('pdf','xlsx','csv','image','zip') NOT NULL;
