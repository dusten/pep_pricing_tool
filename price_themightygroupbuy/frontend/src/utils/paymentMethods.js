// Shared payment-method value/label pairs — pc_vendor_payment_methods.method
// enum. Used by the admin vendor edit form (VendorsTab.vue) and the
// customer-facing vendor contact card (VendorCard.vue) so labels can't drift.
export const PAYMENT_METHODS = [
  { value: 'usdt_sol', label: 'USDT (Solana)' }, { value: 'usdc_sol', label: 'USDC (Solana)' },
  { value: 'usdt_trc20', label: 'USDT (Tron)' }, { value: 'usdc_trc20', label: 'USDC (Tron)' },
  { value: 'usdt_erc20', label: 'USDT (ERC20)' }, { value: 'usdc_erc20', label: 'USDC (ERC20)' },
  { value: 'btc', label: 'BTC' }, { value: 'eth', label: 'ETH' }, { value: 'sol', label: 'SOL' },
  { value: 'paypal', label: 'PayPal' }, { value: 'wise', label: 'Wise' }, { value: 'alipay', label: 'Alipay' },
  { value: 'alibaba', label: 'Alibaba' }, { value: 'wire', label: 'Wire transfer' },
  { value: 'western_union', label: 'Western Union' }, { value: 'zelle', label: 'Zelle' },
  { value: 'cashapp', label: 'CashApp' }, { value: 'credit_card', label: 'Credit card' },
  { value: 'remitly', label: 'Remitly' }, { value: 'pyusd', label: 'PayPal(PYUSD)' },
]

const LABELS_BY_VALUE = Object.fromEntries(PAYMENT_METHODS.map(m => [m.value, m.label]))
export function paymentMethodLabel(value) { return LABELS_BY_VALUE[value] || value }
