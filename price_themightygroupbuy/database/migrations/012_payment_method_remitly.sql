-- =============================================================
-- 012_payment_method_remitly.sql
-- Add Remitly as a vendor payment method. Re-running MODIFY COLUMN with the
-- same definition is a no-op, so this is safe to apply more than once.
-- =============================================================

ALTER TABLE pc_vendor_payment_methods
  MODIFY COLUMN method ENUM('usdt_sol','usdc_sol','usdt_trc20','usdc_trc20','usdt_erc20','usdc_erc20',
                             'btc','eth','sol','paypal','wise','alipay','alibaba','wire','western_union',
                             'zelle','cashapp','credit_card','remitly') NOT NULL;
