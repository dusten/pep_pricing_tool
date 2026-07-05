-- =============================================================
-- 019_stacks.sql
-- "Buy This Stack" (backlog #16, phase 3) — admin-curated bundles of
-- (product, spec) components that bulk-add to a user's cart in one click.
-- Components-only totals; no pre-mixed-SKU matching (see spec decision #5).
-- =============================================================

CREATE TABLE IF NOT EXISTS pc_stacks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  description TEXT NULL,
  is_active   BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_stack_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stack_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  specification_id  INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_stack_item (stack_id, product_id, specification_id),
  FOREIGN KEY (stack_id)          REFERENCES pc_stacks(id)          ON DELETE CASCADE,
  FOREIGN KEY (product_id)        REFERENCES pc_products(id)        ON DELETE CASCADE,
  FOREIGN KEY (specification_id)  REFERENCES pc_specifications(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
