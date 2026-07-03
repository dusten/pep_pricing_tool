---
title: Phase Roadmap
type: entity
tags: [roadmap, phases, planning, backlog]
created: 2026-06-29
sources: [phase1-framework]
---

# Phase Roadmap

## Phase 1 — ✅ Complete + Deployed (2026-06-29)

Auth, quota, design system, SPA shell, deployment scripts (~52 files). Live at https://price.themightygroupbuy.com.

Built:
- Register / login / logout / email verify / forgot+reset password
- Opaque bearer token sessions (`pc_sessions`)
- Waitlist gate + referral codes
- Free-tier query quota (3 per 72h rolling window, keyed by filter_hash)
- Navy/gold Japanese-woodblock design system (CSS custom properties, light/dark/system theme)
- Vue 3 SPA with router, Pinia stores, AppLayout, Sidebar, TopBar
- Deployment: `setup.sh` (fresh EC2) + `add-price-site.sh` (add-on vhost)
- DB schema with full domain model (products, specs, vendors, prices, billing tables)

## Phase 2 — ✅ Mostly built (2026-07-01), Stripe intentionally excluded

- ~~Stripe checkout + billing portal~~ — **not built, backlogged on purpose** (user directive: no billing/Stripe work until later)
- ~~Webhook handler~~ / ~~Referral credit grant on first paid invoice~~ — depends on Stripe, same backlog
- Admin: users ✅, waitlist invite ✅, subscription list ✅ (read-only, no billing actions since Stripe isn't built), performance metrics ✅, backup ✅ (mysqldump + vendor_files → zip)
- Waitlist join endpoint (`/api/waitlist/join`) — already existed from 2026-06-30 session

## Phase 3 — ✅ Built (2026-07-01)

- Vendor file upload UI (admin) ✅ — `VendorsTab.vue`
- Claude extraction pipeline ✅ — PDF via document block, XLSX/CSV via a hand-rolled ZipArchive/SimpleXML text converter (no new dependency); model `claude-sonnet-5` default, `claude-opus-4-8` override
- Comparison table view (`/comparison`) ✅ — filter/sort/$-per-unit highlighting, free-tier query metering enforced
- CSV/XLSX export (Pro tier) — **not built** (backlog #5)
- Full raw data export (Expert tier) — **not built** (backlog #5)
- Admin: vendor management ✅, product catalogue + alias merge tool ✅, file processing queue ✅
- **Bonus, not originally scoped**: Calendar/price-history view (`/calendar`), mobile bottom nav, `/settings` page

## Backlog — SOURCE OF TRUTH (priority order)

> **This is the canonical project backlog.** `CLAUDE.md` points here — add, re-prioritize,
> and mark items done *here*, not there. Item numbers are stable references (commit messages,
> memory, and analyses cite "#2", "#10", etc.), so retire an item by striking it, not renumbering.
> The wiki is local-only (gitignored), so this list is **not** in the pushed repo — this machine's
> vault is the only copy.

1. ~~**Spec sorting**~~ — **RESOLVED 2026-07-03** (see Resolved below).
2. **`price_per_unit` kit-vial-count factor — write-path fix shipped 2026-07-03; one-time all-vendors backfill still pending.** All three write paths (`price_import.php`, `prices/update.php`, `products/spec_update.php`) compute $/mg through a shared `pricePerUnit()` helper in `helpers.php` = `price_usd / (kit_vial_count * numeric_value)`, zero-denominator guarded; `prices/update.php` also recomputes when `kit_vial_count` alone changes. An admin per-vendor **Recalculate $/unit** button (`POST /vendors/{id}/recalc-prices`) is live on the Inventory tab. **Remaining:** every price row written before the fix still holds the old inflated $/unit until its vendor is recalculated, or run once: `UPDATE pc_prices pr JOIN pc_specifications s ON s.id = pr.specification_id SET pr.price_per_unit = ROUND(pr.price_usd / (GREATEST(pr.kit_vial_count,1) * s.numeric_value), 6) WHERE s.numeric_value > 0`. (User is handling the backfill.) See [[wiki/analyses/2026-07-03-blueprint-vs-actual]].
3. **Price history isn't real** — a new upload's matching price row is overwritten in place (`ON DUPLICATE KEY UPDATE`, `created_at` bumped to `NOW()`), so past prices are lost once they change. The Calendar view reflects current-row `created_at`, not a real ledger. Planned to start "after this first import".
4. **Stripe billing** — annual tiers $50 / $140 / $340. Before billing is built: referral credits (`pc_referral_credits.amount_usd`) must change from a dollar payout to crediting the referrer N months of service at their current tier. Settings page.
5. **Export — tied to paid tiers.** Pro: Excel/CSV (navy header rows, green lowest-$/unit highlight, avg/median stat columns, frozen panes — exact spec in [[wiki/sources/original-project-blueprint]]). Expert: full data export as JSON ("all the data", broader than the comparison table). No `/api/comparison/export/*` routes or `php_xlsxwriter` exist yet.
6. ~~**Claude extraction pipeline**~~ — **built** (`backend/api/files/process.php`, `backend/lib/claude.php`).
7. **Post-deploy health check** — a `/api/health` endpoint exercising DB (query), Memcached (set/get), and email config (reachability only); `deploy.sh` prints a per-component pass/fail (replaces the current 2-endpoint curl smoke check).
8. **ClamAV scan — built, but disabled and broken in prod.** `backend/lib/malware_scan.php` is wired into `backend/api/vendors/files.php` behind `MALWARE_SCAN_ENABLED`. As of 2026-07-02 the flag is `false` on prod and `clamd@scan.service` is crash-looping (exit 1, systemd gave up — the "clamd@scan restart failed" warning deploy.sh keeps printing). Uploads are currently unscanned. Root-cause the daemon before re-enabling.
9. **Full vendor purge** — vendors with file/price history can only be deactivated (`is_active=0`), never hard-deleted (would cascade-destroy price history + anything on the comparison table). Likely a "hidden" flag excluding the vendor from every query (comparison, admin lists, exports) while keeping the row for audit, not a real DB delete. Needs a decision on what "fully gone" means.
10. **Kit/tier sizes above 255 not storable** — both `pc_prices.tier_kit_size` and `pc_prices.kit_vial_count` are `TINYINT UNSIGNED` (max 255), and the write paths clamp via `min(255, max(1, ...))`. A genuine 1000-kit tier or a >255-vial kit silently clamps to 255. One migration widens **both** columns (e.g. `SMALLINT UNSIGNED`, max 65,535).
11. **Comparison table only ever shows the 1-kit tier** — `comparison_query.php` hardcodes `pr.tier_kit_size = 1`. Every tier a vendor quotes is stored, but only the 1-kit price surfaces; bulk pricing is invisible. Needs a UI-shape decision (tier selector? one column per tier?) — touches the customer-facing query/UI, not just ingestion.
12. **Vendor file storage coverage is partial** — only `price_list` and `coa` categories are handled end to end; other per-vendor file types/purposes still need coverage (categories not yet specified).
13. **`perf.php` has no rate limit** — `/api/perf` is the only public write endpoint (unauthenticated by design, for logged-out pageview timing) with no `rateLimit()` call; every other public write is limited. A script can flood `pc_perf_metrics` unbounded. One-line fix: `rateLimit('perf_' . ($_SERVER['REMOTE_ADDR'] ?? ''), ...)`. Surfaced by the 2026-07-03 security review.

## Resolved

- **`price_per_unit` write-path fix (2026-07-03)** — shared `pricePerUnit()` helper (`helpers.php`) + per-vendor **Recalculate $/unit** button routed through all three write paths; corrected the missing `kit_vial_count` factor and added a zero-denominator guard. Deployed live. The one-time backfill of pre-fix rows remains open — see backlog item 2.
- **Spec sorting bug (2026-07-03)** — `vendors/show.php`'s price query (Inventory tab + Vendors-tab price list) sorted by `spec_label` (string) instead of `numeric_value` (decimal), producing lexicographic order (10, 15, 20, 30, 5, 60mg). The public Comparison table and Products tab were already correct. Fixed and verified against live data same day. Full writeup: [[wiki/analyses/2026-07-03-blueprint-vs-actual]].

## Related

- [[wiki/sources/phase1-framework|Phase 1 Framework Reference]]
- [[wiki/sources/original-project-blueprint|Original Project Blueprint — Founding Prompt]]
- [[wiki/concepts/subscription-tiers|Subscription Tiers]]
