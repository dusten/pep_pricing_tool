-- =============================================================
-- 042_cart_item_optional_spec.sql (2026-08-02)
-- Lets a cart item skip picking a specific size ("just BPC-157", not
-- "BPC-157 20mg") — specification_id becomes nullable. NULL means "any
-- size of this product"; priced by whichever listing has the cheapest
-- $/unit (not raw kit price), since comparing kit prices across
-- different doses isn't a fair "cheapest" signal. The existing UNIQUE
-- KEY (user_id, product_id, specification_id) is left as-is: MariaDB
-- treats every NULL as distinct in a unique index, so it won't catch a
-- duplicate "any size" row for the same product — the API layer
-- (backend/api/cart/index.php) checks for an existing NULL-spec row
-- itself before inserting.
-- =============================================================

ALTER TABLE pc_cart_items MODIFY COLUMN specification_id INT UNSIGNED NULL;
