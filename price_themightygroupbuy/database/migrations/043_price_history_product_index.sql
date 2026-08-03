-- =============================================================
-- 043_price_history_product_index.sql (2026-08-03)
-- pc_price_history's only index is (vendor_id, product_id, specification_id,
-- changed_at) -- any query that needs "history for this product+spec across
-- ALL vendors" (no vendor_id in the WHERE clause) can't use it at all and
-- falls back to a near-full-table scan. Found via a slow-query-log audit:
-- Calendar's monthly all-time-low milestone computation (getCalendarMilestones()
-- in backend/lib/calendar_featured.php) hits this on every distinct
-- (product, spec) pair that changed price in a given month, and the featured-
-- product delta lookup (getCalendarFeatured()) hits it once per featured day.
-- Pure additive index, no existing query/schema semantics change.
-- =============================================================

ALTER TABLE pc_price_history
  ADD INDEX idx_product_spec_tier (product_id, specification_id, tier_kit_size, changed_at);
