-- =============================================================
-- 017_drop_product_category.sql
-- Final step of backlog #16's classification cutover. pc_classifications +
-- pc_product_classifications (migration 016) fully replaced this column —
-- every backend/frontend touch point was cut over and verified live before
-- this migration was written, per the spec's rollback-safety sequencing.
-- =============================================================

ALTER TABLE pc_products DROP COLUMN IF EXISTS category;
