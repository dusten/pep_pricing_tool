-- =============================================================
-- 002_widen_referral_code.sql
-- referral_code widened from VARCHAR(20) to CHAR(24) — codes are now
-- a full 24-char random hex hash (generateToken(12)) instead of an
-- 8-char truncated one, per security review before launch.
-- schema.sql already reflects this as the target state for fresh installs.
-- =============================================================

ALTER TABLE pc_users    MODIFY referral_code CHAR(24) NOT NULL;
ALTER TABLE pc_waitlist MODIFY referral_code CHAR(24) NULL;
