-- =============================================================
-- 004_feedback_categories.sql
-- Widen pc_feedback.type to match the new category-pill UI
-- (General/UI-UX/Feature/Bug/Performance). 'other' kept in the enum
-- so existing rows tagged 'other' don't get silently truncated by
-- MODIFY — it's just not offered as a selectable category anymore.
-- schema.sql already reflects this as the target state for fresh installs.
-- =============================================================

ALTER TABLE pc_feedback
  MODIFY COLUMN type ENUM('general','ui_ux','feature','bug','performance','other') NOT NULL DEFAULT 'general';
