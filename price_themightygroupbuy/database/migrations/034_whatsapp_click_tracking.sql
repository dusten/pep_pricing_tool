-- =============================================================
-- 034_whatsapp_click_tracking.sql (2026-07-14)
-- Backlog: admin activity dashboard (signups/logins/searches/WhatsApp
-- clicks, Daily/Weekly/Monthly). Signups (pc_users.created_at), logins
-- (pc_login_history), and searches (pc_query_log) are already tracked;
-- WhatsApp link clicks (VendorCard.vue's WhatsApp row) were not tracked
-- anywhere, so this adds a table for them.
-- =============================================================

CREATE TABLE IF NOT EXISTS pc_whatsapp_clicks (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES pc_users(id) ON DELETE SET NULL,
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
