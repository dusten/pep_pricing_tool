-- =============================================================
-- 038_vendor_suggestion_claude_cost.sql (2026-07-17)
-- Per-suggestion Claude cost tracking (backlog #69): links call-log rows
-- back to the suggestion that triggered them, so admin can see
-- estimated_cost_usd per suggestion (processSuggestion() in
-- backend/lib/vendor_suggestions.php). Mirrors vendor_file_id's own
-- FK/index shape on this table exactly.
-- =============================================================

ALTER TABLE pc_claude_call_log
  ADD COLUMN vendor_suggestion_id INT UNSIGNED NULL AFTER vendor_file_id,
  ADD FOREIGN KEY (vendor_suggestion_id) REFERENCES pc_vendor_suggestions(id) ON DELETE SET NULL,
  ADD INDEX (vendor_suggestion_id);
