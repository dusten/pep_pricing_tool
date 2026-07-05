-- =============================================================
-- 022_claude_call_log.sql
-- Backlog #24: persist every raw Claude API call (extraction +
-- vendor-contact-parse), not just the outcome. Motivated by a real
-- debugging session where a JSON-parse failure required a fresh, costly
-- API call just to see the raw output — only the first 300 characters of a
-- failed response were ever kept (in pc_vendor_files.processing_notes).
-- =============================================================

CREATE TABLE IF NOT EXISTS pc_claude_call_log (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_file_id              INT UNSIGNED NULL,          -- null for vendor_contact_parse calls (not file-based)
  call_type                   ENUM('extraction','vendor_contact_parse') NOT NULL,
  model                       VARCHAR(50) NOT NULL,
  http_status                 SMALLINT UNSIGNED NULL,
  stop_reason                 VARCHAR(50) NULL,
  input_tokens                INT UNSIGNED NULL,
  output_tokens                INT UNSIGNED NULL,
  cache_creation_input_tokens INT UNSIGNED NULL,
  cache_read_input_tokens     INT UNSIGNED NULL,
  raw_response_text           LONGTEXT NULL,               -- the text block content, pre-cleanup — exactly what Claude said
  parsed_ok                   BOOLEAN NOT NULL DEFAULT FALSE,
  error_message                TEXT NULL,
  created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_file_id) REFERENCES pc_vendor_files(id) ON DELETE SET NULL,
  INDEX (vendor_file_id),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
