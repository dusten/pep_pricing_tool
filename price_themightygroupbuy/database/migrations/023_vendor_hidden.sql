-- Backlog #9: full vendor "purge" as hide-not-delete. A hidden vendor keeps
-- every row (prices, files, history — audit trail intact) but disappears from
-- the admin vendor list too, not just user-facing queries. Hiding also forces
-- is_active = 0, which is what every user-facing query already filters on.
ALTER TABLE pc_vendors ADD COLUMN is_hidden BOOLEAN NOT NULL DEFAULT FALSE AFTER is_active;
