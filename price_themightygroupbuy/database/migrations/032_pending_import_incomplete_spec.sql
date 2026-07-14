-- =============================================================
-- 032_pending_import_incomplete_spec.sql (2026-07-14)
-- vendor_file_processor.php silently discarded any extracted row missing a
-- usable spec_label/numeric_value (e.g. vendor only gave a vial count, no
-- per-vial mg breakdown) instead of surfacing it for review — real vendor
-- prices vanished with no trace but a note. New match_type routes these to
-- the Review Queue instead so an admin can supply the correct spec.
-- =============================================================

ALTER TABLE pc_pending_imports
  MODIFY COLUMN match_type ENUM('new_product','new_spec','name_mismatch','incomplete_spec') NOT NULL;
