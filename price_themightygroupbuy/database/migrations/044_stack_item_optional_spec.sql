-- =============================================================
-- 044_stack_item_optional_spec.sql (2026-08-06)
-- Lets a stack component skip picking a specific size ("just BPC-157", not
-- "BPC-157 20mg") — specification_id becomes nullable, same as
-- pc_cart_items (migration 042). NULL means "any size of this product";
-- add-stack bulk-inserts it straight into pc_cart_items, so it's priced by
-- getCartSnapshot()'s existing any-size resolution: cheapest $/unit across
-- every vendor/spec, excluding raw material and tablet specs unless that
-- exact spec was explicitly picked instead. The existing UNIQUE KEY
-- (stack_id, product_id, specification_id) is left as-is: MariaDB treats
-- every NULL as distinct in a unique index, so it won't catch a duplicate
-- "any size" component for the same product — the API layer
-- (backend/api/admin/stacks/items.php) checks for an existing NULL-spec
-- row itself before inserting, same pattern as pc_cart_items.
-- =============================================================

ALTER TABLE pc_stack_items MODIFY COLUMN specification_id INT UNSIGNED NULL;
