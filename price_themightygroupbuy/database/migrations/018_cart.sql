-- =============================================================
-- 018_cart.sql
-- Shopping cart (backlog #16, phase 2). One row per (user, product, spec) —
-- presence, not quantity; v1 is "1 kit of this spec" only, matching the
-- comparison table's existing 1-kit-tier-only limitation (backlog #11).
-- =============================================================

CREATE TABLE IF NOT EXISTS pc_cart_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  specification_id  INT UNSIGNED NOT NULL,
  added_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_item (user_id, product_id, specification_id),
  FOREIGN KEY (user_id)          REFERENCES pc_users(id)          ON DELETE CASCADE,
  FOREIGN KEY (product_id)       REFERENCES pc_products(id)       ON DELETE CASCADE,
  FOREIGN KEY (specification_id) REFERENCES pc_specifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
