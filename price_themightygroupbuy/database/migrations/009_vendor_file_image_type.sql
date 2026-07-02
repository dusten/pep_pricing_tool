-- =============================================================
-- 009_vendor_file_image_type.sql
-- Vendors sometimes send a phone screenshot of a spreadsheet instead of a
-- real file. Add 'image' to pc_vendor_files.file_type so jpg/jpeg/png
-- uploads can be sent to Claude as a vision content block. Re-running
-- MODIFY COLUMN with the same definition is a no-op, so this is safe to
-- apply more than once.
-- =============================================================

ALTER TABLE pc_vendor_files
  MODIFY COLUMN file_type ENUM('pdf','xlsx','csv','image') NOT NULL;
