---
title: Code Map (Feature → Key Files)
type: concept
tags: [code-map, navigation, backend, frontend]
created: 2026-08-05
sources: []
---

# Code Map (Feature → Key Files)

Lookup table so a future session can find the file(s) responsible for a feature without
grepping/find-ing the filesystem first. All paths are relative to the repo root
(`pep_pricing_tool/`); the app itself lives under `price_themightygroupbuy/`.

This is a starting point, not ground truth — verify a file still exists before trusting it,
since code moves and this table won't always keep up automatically.

| Feature area | Key files |
|---|---|
| Admin: backups | `price_themightygroupbuy/backend/api/admin/backup.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/BackupTab.vue` |
| App settings / feature flags | `price_themightygroupbuy/backend/api/app_settings.php`, `price_themightygroupbuy/frontend/src/stores/settings.js`, `price_themightygroupbuy/frontend/src/views/admin/tabs/SettingsTab.vue` |
| Auth & accounts (login, register, password reset, email verify) | `price_themightygroupbuy/backend/api/auth/`, `price_themightygroupbuy/backend/email.php`, `price_themightygroupbuy/frontend/src/stores/auth.js`, `price_themightygroupbuy/frontend/src/views/LoginView.vue` |
| Billing / subscription tiers | `price_themightygroupbuy/backend/api/me.php`, `price_themightygroupbuy/frontend/src/views/PricingView.vue`, `price_themightygroupbuy/frontend/src/views/admin/tabs/SubscriptionsTab.vue` |
| Cart & quote-building | `price_themightygroupbuy/backend/api/cart/index.php`, `price_themightygroupbuy/backend/lib/cart.php`, `price_themightygroupbuy/frontend/src/views/CartView.vue`, `price_themightygroupbuy/frontend/src/stores/cart.js` |
| Claude API usage / cost tracking (admin) | `price_themightygroupbuy/backend/api/admin/claude_log.php`, `price_themightygroupbuy/backend/api/admin/claude_spend.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/ClaudeApiTab.vue` |
| COA submissions | `price_themightygroupbuy/backend/api/coa/submit.php`, `price_themightygroupbuy/backend/api/admin/coa_queue.php`, `price_themightygroupbuy/frontend/src/views/SubmitCoaView.vue` |
| Comparison table (main product search/list) | `price_themightygroupbuy/backend/api/comparison/index.php`, `price_themightygroupbuy/backend/lib/comparison_query.php`, `price_themightygroupbuy/frontend/src/views/ComparisonView.vue` |
| Feedback | `price_themightygroupbuy/backend/api/feedback.php`, `price_themightygroupbuy/backend/api/admin/feedback.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/FeedbackTab.vue` |
| Price calendar / notable changes | `price_themightygroupbuy/backend/api/calendar.php`, `price_themightygroupbuy/backend/lib/calendar_featured.php`, `price_themightygroupbuy/frontend/src/views/CalendarView.vue`, `price_themightygroupbuy/frontend/src/views/admin/tabs/CalendarTab.vue` |
| Products & specs (catalog CRUD) | `price_themightygroupbuy/backend/api/products/index.php`, `price_themightygroupbuy/backend/api/products/show.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/ProductsTab.vue` |
| Referral rewards | `price_themightygroupbuy/backend/api/admin/user_referrals.php`, `price_themightygroupbuy/backend/api/me/referral_stats.php`, `price_themightygroupbuy/frontend/src/views/SettingsView.vue` |
| Stacks (curated bundles, "Buy This Stack") | `price_themightygroupbuy/backend/api/stacks.php`, `price_themightygroupbuy/backend/api/admin/stacks/index.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/StacksTab.vue` |
| System & performance monitoring (admin) | `price_themightygroupbuy/backend/api/perf.php`, `price_themightygroupbuy/backend/api/admin/system.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/SystemTab.vue`, `price_themightygroupbuy/frontend/src/views/admin/tabs/PerformanceTab.vue` |
| Users (admin) | `price_themightygroupbuy/backend/api/admin/users.php`, `price_themightygroupbuy/backend/api/admin/users_show.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/UsersTab.vue` |
| Vendor price-list ingestion pipeline | `price_themightygroupbuy/backend/api/files/process.php`, `price_themightygroupbuy/backend/lib/vendor_file_processor.php`, `price_themightygroupbuy/backend/cron/process_async_queue.php` (background job), `price_themightygroupbuy/frontend/src/views/admin/tabs/FilesTab.vue` |
| Vendor prices / inventory editing | `price_themightygroupbuy/backend/api/prices/update.php`, `price_themightygroupbuy/backend/api/vendors/add_price.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/InventoryTab.vue` |
| Vendor review queue (novel product matches) | `price_themightygroupbuy/backend/api/vendors/pending_imports.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/ReviewQueueTab.vue` |
| Vendor suggestions (user-submitted vendors) | `price_themightygroupbuy/backend/api/vendor_suggestions/index.php`, `price_themightygroupbuy/backend/lib/vendor_suggestions.php`, `price_themightygroupbuy/frontend/src/views/SuggestVendorView.vue`, `price_themightygroupbuy/frontend/src/views/admin/tabs/VendorSuggestionsTab.vue` |
| Vendors (directory, contact, merge) | `price_themightygroupbuy/backend/api/vendors/index.php`, `price_themightygroupbuy/backend/api/vendors/show.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/VendorsTab.vue` |
| Waitlist / registration gating | `price_themightygroupbuy/backend/api/waitlist/join.php`, `price_themightygroupbuy/backend/api/admin/waitlist.php`, `price_themightygroupbuy/frontend/src/views/admin/tabs/WaitlistTab.vue` |
| **Meta: route registration** | `price_themightygroupbuy/public/index.php` |
| **Meta: schema source of truth** | `price_themightygroupbuy/database/schema.sql`, `price_themightygroupbuy/database/migrations/` |
| **Meta: deploy script** | `price_themightygroupbuy/deploy.sh` |

## Related

- [[../entities/phase-roadmap|Phase Roadmap]]
