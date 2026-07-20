-- =============================================================
-- 040_referral_reward_months.sql (2026-07-20)
-- Referral rewards convert from unused dollar credit to real free-month
-- grants (backlog #4 pre-Stripe correction): pc_referral_credits.amount_usd
-- was never written by any code path — replaced with months_granted,
-- matching pc_app_settings.annual_discount_months_free's own unit.
-- =============================================================

ALTER TABLE pc_referral_credits
  DROP COLUMN amount_usd,
  ADD COLUMN months_granted SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER referee_id;

DELETE FROM pc_app_settings WHERE `key` = 'referral_credit_usd';
INSERT IGNORE INTO pc_app_settings (`key`, value) VALUES ('referral_months_free', '2');
