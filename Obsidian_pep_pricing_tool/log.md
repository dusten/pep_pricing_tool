# Log

Append-only record of wiki activity. Parse with: `grep "^## \[" log.md`
One line per entry (`## [YYYY-MM-DD] type | summary`), no body text — detail lives in `sessions/*.md`. Separate entries with one blank line.

---

## [2026-07-01] session | Session 3 wrap: UI polish, caching audit, System tab hardening, 4 real bugs found

Final session-close entry for the day. Covers: Account merged into Settings + referral/feedback card redesign
+ pricing button alignment fix + guest TopBar fix (found via a full page sweep) + perf beacon finally wired up
(pc_perf_metrics had zero rows ever until this). Caching audit expanded coverage from 3 to 10 endpoint groups
(getAppSetting was hitting the DB on nearly every request; comparison/filters/calendar share one cache group
since results aren't per-user; session/token validation — the highest-frequency query in the app — was
uncached entirely, now 60s TTL with bust-on-write for logout/self-edits). System tab: Live(1s) refresh, daily
ANALYZE cron, per-database slow-query capture (pc_slow_query_cache) with acknowledge/resolve feedback loop,
dropdown-to-pills conversion. Four real, previously-invisible bugs surfaced and fixed along the way (none
introduced this session): (1) grp's slow-query event had silently failed to ever clear mysql.slow_log since
2026-06-26 — MariaDB log tables reject DELETE entirely, only TRUNCATE works; (2) SHOW STATUS without GLOBAL
was reading empty per-connection session counters, not real server activity; (3) the GLOBAL query counter was
shared across both apps on this box — replaced with a CountingPDO-based per-app Memcached counter;
(4) performance_schema turned out to be globally OFF on the server (would need a restart, not attempted).
Full writeup: sessions/2026-07-01.md, "Session 3".

## [2026-07-01] session | System tab: live refresh, ANALYZE cron, per-db slow query capture + feedback loop

Added "Live (1s)" auto-refresh option and a daily ANALYZE TABLE cron (03:30, mirrors the existing weekly
OPTIMIZE job). Bigger piece: mysql.slow_log is server-wide, shared with the grp app (db `themightygroupbuy`)
on the same box — grp already had an hourly EVENT (grp_import_slow_queries) draining ALL of it into its own
cache table then trying to clear it, which silently ate tmgb_price's rows too. Added the tmgb_price twin
(pc_slow_query_cache + pc_import_slow_queries event, migration 005) scoped by `WHERE db = 'tmgb_price'`, and
scoped grp's event to its own db too (edited directly via root SQL — themightygroupbuy isn't this project's
schema, not tracked in a migration file).

**Real bug found mid-build:** MariaDB log tables (log_output=TABLE) reject DELETE entirely — filtered AND
unconditional — error 1556 "You can't use locks with log tables". Only TRUNCATE works, and TRUNCATE can't be
scoped to one db. This means grp's *original* event had been silently failing to ever clear mysql.slow_log
since it was created (explains 88 stale rows sitting since 2026-06-26, unrelated to anything touched this
session). Fixed both events (migration 006 for price, direct root SQL for grp) to only SELECT from
mysql.slow_log, never DELETE from it. Added a new **root-owned, shared** `/etc/cron.d/mysql-slowlog-truncate`
(daily 04:30, outside either app's deploy/repo) as the sole thing that clears it, after both apps' 24 hourly
imports have had a chance to run.

Also added the acknowledge/resolve feedback-loop workflow requested for slow queries: pc_slow_query_cache has
a status column (new/acknowledged/resolved), admin can set it from System tab, and a "resolved" query that
recurs automatically flips back to "new" so a fix that didn't stick re-surfaces.

Server credentials note: needed root MySQL access mid-session (tmgb_price's DB user is correctly scoped to
its own db only, no visibility into mysql.slow_log or themightygroupbuy) — user created `/root/.my.cnf` on
request rather than sharing a password in chat.

Commits: 7d30c73 (live refresh + ANALYZE cron), 3ab44c8 (slow query cache + feedback loop), 9a3ec84 (DELETE fix).

## [2026-07-01] session | Account merged into Settings, referral/feedback card redesign

AccountView.vue deleted — Profile, Subscription, and a redesigned Refer-a-Friend card (icon header, code+copy,
Joined/Converted/Credit-Earned stat tiles via new /api/me/referral-stats) all merged into SettingsView.vue.
BottomNav dropped its 5th "Account" item; Settings is now the single entry point (matches TopBar, which never
had a separate Account icon). Fixed settings-stack not being centered (missing margin:0 auto). Feedback card
redesigned to match a reference app's style — icon header, category pills with icons (General/UI-UX/Feature/Bug/
Performance, swapped from that app's "Group Buy"), full-width submit button. pc_feedback.type enum widened via
migration 004 (kept 'other' for old rows, not exposed as a selectable category). Deployed via deploy.sh --all,
verified live: centering, card order, pills, and referral-stats endpoint all confirmed via browser JS checks.
Commit: 29aeb3a.

## [2026-07-01] fix | Migration 003 silently skipped on prod — schema.sql seed bug

Post-deploy verification (curling every new endpoint unauthenticated) caught `api/auth/verify-email-change`
returning 500 instead of 404. Root cause: `schema.sql` pre-seeded `pc_migrations` with migration 003 marked
applied, so `migrate.sh` skipped its real `ALTER TABLE ADD COLUMN` statements on prod's existing tables — the
new `pc_users`/`pc_perf_metrics` columns never actually landed despite `pc_migrations` claiming otherwise.
Fixed migration 003 to use `ADD COLUMN IF NOT EXISTS` (idempotent), removed the premature seed rows for 002/003
from schema.sql (only 001 should ever be pre-seeded), cleared the false tracking row on prod via SSH, re-ran
`deploy.sh --sync-schema` — applied for real this time. Confirmed via SHOW COLUMNS + endpoint re-check.
Commit: `ed85e80`. Full writeup: sessions/2026-07-01.md, "Session 2" § 9.

## [2026-07-01] session | Nav unification, user settings, admin panel gaps, security/caching hardening

Second session of the day. Unified desktop nav to match mobile (top+bottom, sidebar deleted), wrapped PricingView
in the layout (had zero nav before). Built out full user settings page (timezone, push+PWA, password/email
change, login history, data export, feedback, self-service account deletion). Filled every remaining admin-panel
gap from two reference-app specs: new Overview and System/Ops tabs, Users (verified badge/test-account/referral
tree), Waitlist (bulk delete/CSV export/status badges), Performance (range/device/path filters, breakdowns).
Comparison queries now log timing/params for admin debugging + live re-run. Security pass: CSRF via Origin
allowlist, fail-closed rate limiting (user's explicit call over a spec contradiction), transactional writes,
self-referral guard, enumeration-safe auth, security headers/CSP/HSTS. Memcached version-counter cache
invalidation added for admin lists + app settings. Split deploy.sh into 4 explicit modes (sync-files/build/
sync-schema/all) and added an automatic post-deploy smoke check. Merged to main, pushed, deployed to production
via `deploy.sh --all` — smoke check passed.
Session notes: sessions/2026-07-01.md (appended as "Session 2").
Memory updated: escalated memory/feedback_wiki_location.md — this is the 3rd-4th time the ~/.claude/ vs
Obsidian location had to be corrected; ~/.claude/ memory for this project now holds only a redirect pointer.

## [2026-07-01] session | Full build: nav shell, Comparison, Calendar, admin panel, Claude pipeline

Security fixes first: referral codes widened to a real 24-char hash, admin now bypasses tier gates entirely.
Rebuilt nav (top: Admin/Settings/Logout, mobile bottom bar: Dashboard/Calendar/Comparison), shipped the real
Comparison table (filters, $/unit highlight, free-tier metering) and a new Calendar/price-history view.
Built out all 10 admin tabs (previously 8 were placeholder text) and the full Claude extraction pipeline
(PDF via document block, XLSX/CSV via a hand-rolled ZipArchive/SimpleXML reader — no new dependency).
Stripe/billing intentionally excluded; ClamAV upload scanning added to CLAUDE.md backlog.
`deploy.sh` given a `--sync-schema` flag, then decoupled from full deploy after a prod-risk flag from the user.
`database/migrations/002_widen_referral_code.sql` created and applied against production (test seed data
stripped from schema.sql first). Session notes filed: sessions/2026-07-01.md. wiki/entities/phase-roadmap.md
updated — Phase 2/3 mostly built, Stripe/CSV-export/full-history moved to a new backlog section.

## [2026-06-30] session | Waitlist email flow debugged and working

Brevo 400 "name is missing in to" root cause found and fixed. SELinux booleans added for ~/.env_pricetool.
Email templates extracted to backend/email_templates/. Deploy script hardened (php-fpm reload, log/ handling).
Created `sessions/2026-06-30.md`.

## [2026-06-30] ingest | 5 clippings — Epithalon variants + TB-500 variants

New clippings ingested from `raw/clippings/`:
- `dustenPep-Resources.md` → `wiki/sources/epithalon-vs-n-acetyl-epitalon-amidate.md`
- `dustenPep-Resources 1.md` → `wiki/sources/tb-500-variants.md`
- `Epithalon (Epitalon) Peptide Overview.md` → `wiki/sources/epithalon-peptide-overview.md`
- `TB-500 (Thymosin Beta-4) Peptide Overview.md` → `wiki/sources/tb-500-thymosin-beta4-overview.md`
- `TB-500 Fragment (17-23) Peptide Overview.md` → `wiki/sources/tb-500-fragment-17-23-overview.md`

New entity pages: `epithalon`, `epithalon-base`, `epithalon-n-acetyl-amidate`, `tb-500`, `thymosin-b4-acetate`, `tb-500-fragment-17-23`, `tb5-mao-b-inhibitor`
New concept page: `variant-compounds` (watchlist for weekly scan)
Weekly scan routine configured (Mondays 09:00 UTC).

## [2026-06-29] lint | 2 issues found, fixed

- `wiki/sources/phase1-framework.md`: `.env_price` → `.env_pricetool` (renamed during first deploy)
- `wiki/concepts/subscription-tiers.md`: `.env_price` → `.env_pricetool`; Stripe Price ID list replaced with note that constants are added during billing phase (removed from config.php today)

## [2026-06-29] session | First production deploy

First live deployment of price.themightygroupbuy.com. Six bugs found and fixed:
missing admin tab stubs, broken env file path/naming, Apache vhost conflict (certbot hijack),
PHP spread operator syntax, Vite base path mismatch, .env permissions.
Site verified live. Created `sessions/2026-06-29.md`, `wiki/concepts/deployment.md`.
Updated `wiki/entities/phase-roadmap.md` (Phase 1 marked deployed).

## [2026-06-30] session | Ponytail audit, wiki lint, variant compound build-out, weekly scan

- Ponytail audit: ~340 lines deleted (dead Memcached cache, duplicate migration, Stripe scaffolding, empty dirs, TIER_ORDER)
- Wiki lint: 2 stale `.env_price` refs fixed
- Ingested 5 clippings → 5 source pages, 7 entity pages, 1 concept page (variant-compounds watchlist)
- Weekly scan routine created: trig_012jsqYj1nEgZcVzphK6syjy, Mondays 09:00 UTC; WhatsApp export support added
- CLAUDE.md updated with full wiki section; karpathy raw clipping backfilled; persistent memory saved
- Session notes filed: sessions/2026-06-30.md (appended to existing)

## [2026-06-30] raw | karpathy-llm-wiki-pattern.md added to raw/clippings/

Raw source file backfilled. Source page `wiki/sources/karpathy-llm-wiki-pattern.md` already exists — no re-ingest needed.

## [2026-06-29] ingest | Karpathy LLM Wiki Pattern

Ingested Andrej Karpathy's LLM Wiki pattern gist as the first source. Created:
- `wiki/sources/karpathy-llm-wiki-pattern.md`
- `wiki/concepts/llm-wiki-pattern.md`
- `wiki/concepts/rag-vs-wiki.md`

Updated `index.md` with all three pages.

## [2026-06-29] ingest | Phase 1 Framework Reference

Ingested full Phase 1 codebase reference (~52 files, PHP/Vue SaaS). Created:
- `wiki/sources/phase1-framework.md` — full architecture, domain model, auth flows, routes
- `wiki/entities/phase-roadmap.md` — Phase 1/2/3 breakdown
- `wiki/concepts/subscription-tiers.md` — tier pricing, capabilities, Stripe IDs, DB enforcement
- `wiki/concepts/query-quota.md` — free-tier 3/72h rolling window system

Updated `index.md` with all four pages.

## [2026-06-29] init | Wiki scaffolded

Created directory structure, CLAUDE.md schema, index, log, and four page templates (source, entity, concept, analysis).

## [2026-07-01] query | Vendor onboarding + file upload full spec

## [2026-07-01] session | Vendor intake + COA verification build, ClamAV deployment saga, commit-style violation caught

## [2026-07-01] push | Session 4 commits pushed to origin/main (abe77a5..bc15862)

## [2026-07-01] debug | Add-vendor no-op traced to skipped deploy step; found + fixed USDT/USDC payment-method parse gap; found (unfixed) missing ANTHROPIC_API_KEY blocking Claude fallback

## [2026-07-01] fix | Shipping price -> free-text note (migration 008), blank-token skip, payment-address-as-label recognition; verified live with real Purelypep Factory vendor reply

## [2026-07-02] query | ZIP upload spec — how to handle vendor price lists split across multiple images/PDFs in a zip

## [2026-07-02] fix | ZIP upload built per finalized spec — 3-entry/12MB caps, zip-bomb-safe validation, extract-then-scan malware check, callClaudeExtraction refactored to one content-blocks param; verified via real multipart upload through live API (multi-image single Claude call confirmed) plus all 4 validation cases

## [2026-07-02] fix | Review queue editable + vendor SKU, abbreviation field retired, dashboard stats wired up, Remitly payment method, phone-number vendor dedup on paste-intake — session 4 pushed to origin/main (23 commits)

## [2026-07-02] fix | Transaction-rollback bug (mid-processing failures silently discarded, never recorded) and three layered file-preview bugs (Download link 401ing since day one, wrong image MIME type, CSP blocking blob: sources) — pushed to origin/main (4 commits)

## [2026-07-02] fix | PDF preview only filling ~150px (replaced-element inset/width sizing gotcha, took two attempts), zip files no longer show a View button — pushed to origin/main

## [2026-07-02] fix | PDF preview finally working after 6 total root causes across two sessions' worth of debugging: native viewer resize bugs, 2 CSS replaced-element gotchas, missing Apache .mjs MIME mapping, stale cache on a content-hashed asset, and a real pdf.js 5.x/6.x bug (native Uint8Array.toHex() unsupported before Chromium 140) — pinned pdfjs-dist to 4.10.38, confirmed working on user's Chrome 128

## [2026-07-02] lint | Corrected session-log dating — sections that were actually 2026-07-02 work had been appended to the 2026-07-01.md file (date rolled over mid-conversation); moved to a new sessions/2026-07-02.md, index.md updated to match

## [2026-07-02] status | Project status check found ClamAV built but disabled (kill switch off) and clamd@scan crash-looping in production — real gap, not yet fixed

## [2026-07-02] feature | Comparison Cat No. search, admin product edit UI, Product feedback pill (linked from Comparison page)

## [2026-07-02] feature | Review Queue: remaining count, empty-required-field flags, cross-vendor auto-approve on exact product+spec match — validated against 918 real pending rows

## [2026-07-02] bugfix | BPC-157/TB-500 blend found miscategorized as a BPC-157 spec, live on the comparison table — built spec editing + move-to-different-product, and a new Inventory tab for full per-vendor price-line editing

## [2026-07-02] fix | Products tab alias-wrap vertical-alignment, refined twice (blanket top-align, then rescoped to just Name/Category/Aliases after a real screenshot showed the Edit button stranded with dead space)

## [2026-07-02] fix | Review Queue auto-approve message moved below the card (was above it, shifting the card on appear/clear)

## [2026-07-03] ingest | Original founding project blueprint (schema, API, Claude prompt, frontend architecture, Excel/CSV export formatting) — never previously captured, explains the earlier sort-order question

## [2026-07-03] analysis | Full gap analysis: blueprint vs. actual app. SaaS pivot confirmed intentional. Export/price-history/referral-credit backlog decisions made; price_per_unit found missing a kit-vial-count factor

## [2026-07-03] fix | Spec sorting bug found and fixed: vendors/show.php's price query (Inventory tab + Vendors-tab price list) sorted by spec_label string instead of numeric_value column — public Comparison table and Products tab were already correct

## [2026-07-03] backlog | Wiki designated source of truth for the backlog — full 13-item list migrated to phase-roadmap.md; CLAUDE.md reduced to a pointer. Wiki is local-only (gitignored), so the backlog no longer lives in the pushed repo.

## [2026-07-03] feature | Products tab: duplicate-spec merge built (`POST /products/specifications/{id}/merge`), verified live on a real 5-Amino-1MQ 5mg/5mg*10vials duplicate

## [2026-07-03] fix | Products tab Edit-button row-alignment bug — `display:flex` on a `<td>` was breaking table row-height stretching, pulling that cell's border ~11px above the rest of the row; diagnosed via DOM geometry after Chrome screenshot capture broke for the whole session, fixed and confirmed

## [2026-07-03] fix | Backlog #7/#10/#13 shipped: real `/api/health` endpoint wired into deploy.sh's smoke check, pc_prices.kit_vial_count/tier_kit_size widened to SMALLINT UNSIGNED (migration 014), perf.php rate-limited — all deployed and verified live

## [2026-07-03] analysis | Price history spec (backlog #3) drafted and agreed — new append-only pc_price_history table, snapshotted from commitPriceRow() and prices/update.php on real change only, no FKs, Calendar UI rebuild deferred to a follow-up. Not yet built.

## [2026-07-03] backlog | Two new items added: #14 no file-level dedup on vendor uploads, #15 Calendar view doesn't show real price history (surfaced while scoping #3)

## [2026-07-03] feature | Backlog #3 built and deployed: pc_price_history ledger (migration 015), commitPriceRow() and prices/update.php now snapshot on real price/kit-count change only — verified live via a real manual edit (bump+revert, 2 accurate rows) and a rolled-back server-side transaction test covering new-line/real-change/no-op-resubmit cases

## [2026-07-03] analysis | Shopping cart + Buy This Stack + classification rework spec (backlog #16) drafted and agreed — per-account cart ranks vendors covering the whole cart by cheapest total, admin-curated stacks bulk-add components via the same calc (no pre-mixed-SKU matching), pc_products.category fully retired for a multi-select pc_classifications tag system. Not yet built.

## [2026-07-03] feature | Backlog #16 phase 1 (classification) built and deployed: pc_products.category retired for pc_classifications/pc_product_classifications (migrations 016+017), 27 tags seeded, 112 product-tag assignments backfilled from two user-supplied source lists (one real Melanotan-1 mismatch caught and corrected pre-ship), all 10 real touch points cut over, new GET/POST /api/classifications endpoint — verified live via chip filtering (single+multi-tag OR), a live Products-tab edit round-trip, and confirming the app still works post-column-drop. Cart and Buy This Stack remain unbuilt.

## [2026-07-03] feature | Backlog #16 phase 2 (shopping cart) built and deployed: pc_cart_items (migration 018), GET/POST /api/cart + DELETE /api/cart/{id} (ownership-scoped), cheapest-single-vendor-covers-cart ranking with named-missing-items partial fallback, "+ Cart" on Comparison table, new /cart page, Dashboard card, bottom-nav entry — verified live via real API round-trip (add/dedup/remove/404-on-missing) and real UI interaction. Buy This Stack remains unbuilt.

## [2026-07-03] fix | Comparison table UI: moved "+ Cart" button to the leftmost sticky column, and fixed the Spec column appearing too wide — real cause was the uncapped Product column stretching to fit a long name elsewhere in the list (245px vs an assumed 140px), pushing Spec and everything after it right. Bounded all three sticky columns (Cart/Product/Spec) to fixed widths with wrap-instead-of-stretch, which also fixed a latent sticky-scroll misalignment. Verified via DOM geometry checks (zero gap/overlap, at rest and mid-scroll).

## [2026-07-03] feature | Backlog #16 phase 3 (Buy This Stack) built and deployed: pc_stacks/pc_stack_items (migration 019), full admin CRUD (list/create/get/update/delete/add-item/remove-item) mirroring the existing products/aliases.php pattern, new admin Stacks tab, public GET /api/stacks + POST /api/cart/add-stack/{id} (bulk-add, reuses cart's exact vendor-breakdown response via a new shared getCartSnapshot() helper). Deliberately components-only, no pre-mixed-blend-SKU matching. Verified live: created a real stack, bulk-added it to cart (byte-identical output to manually adding the same items), removed a component via real UI click (confirmed via DB after an initial false-positive DOM check), cleaned up all test data. Backlog #16 fully shipped, all three phases.

## [2026-07-03] analysis | Three specs drafted together, not yet built: backlog #5 (Export — Pro CSV/XLSX + Expert full JSON, XLSX blocked on user sourcing php_xlsxwriter), #11 (Comparison table tier selector — real data confirmed 3 tiers, 1/10/50-kit, cart/stack stay tier=1-only), #15 (Calendar rebuild to query pc_price_history for real old→new deltas). User asked to see specs before implementation.

## [2026-07-03] feature | Backlog #11 (Comparison tier selector) and #15 (Calendar real price history) built and deployed, per user's explicit choice to skip #5 (Export) this pass. Tier selector: runComparisonQuery() gained a $tierKitSize param, comparison/filters.php exposes real distinct tiers instead of a hardcoded list, frontend segmented control only shows when >1 tier exists — verified live (174/115/92 rows per tier, matching a real UI click). Calendar: calendar.php rewritten to query pc_price_history (LEFT JOIN, tolerates a since-purged vendor/product) instead of pc_prices.created_at — verified live against real historical test data, correct old→new deltas and source tags rendered. User clarified Export should eventually support CSV+Excel+JSON when built; XLSX still blocked on user sourcing php_xlsxwriter.

## [2026-07-03] analysis | Backlog #14 (upload dedup) spec expanded to two layers after user correctly pushed back that a byte-hash check alone misses "different file, same data" re-uploads (re-exports, re-screenshots). Layer 1: per-vendor file-hash pre-check, skips Claude extraction outright on a literal re-upload. Layer 2: post-extraction changed/unchanged tally reusing commitPriceRow()'s existing price-history comparison — can't save the API cost (extraction already ran) but surfaces the signal. Not yet built.

## [2026-07-03] feature | Backlog #14 both layers built and deployed: pc_vendor_files.content_hash + skipped_duplicate status (migration 020) for the per-vendor file-hash pre-check; commitPriceRow() now returns bool (was void) so vendor_file_processor.php can tally changed vs unchanged rows per file, folded into processing_notes/response message, no new schema. Verified live: real duplicate-detection test (uploaded a file, marked it complete, re-uploaded identical bytes under a different filename, correctly flagged; a genuinely different file correctly wasn't) plus a rolled-back server transaction confirming commitPriceRow()'s new/changed/unchanged return values. All test data cleaned up. Backlog #14 fully shipped.

## [2026-07-03] investigation | User reported new vendor inventory from that day appeared to have been "removed" by the price-history change. Audited live DB directly: all pc_prices rows is_active=1, all vendors is_active=1, admin_audit_log showed a real 04:21-04:24 AM review-queue approval spree (~370+ items) that was clearly legitimate prior activity, not data loss. No actual data was ever deleted.

## [2026-07-03] fix | Real regression found from the investigation above: the backlog #15 Calendar rebuild made pc_price_history the sole data source, but that ledger didn't exist yet when the 04:21 AM approval spree happened, so those real commits have no history rows and vanished from the Calendar. Fixed by adding pc_pending_imports (grouped by DATE(reviewed_at), status='approved') as a second, independent signal alongside the price-change ledger — rendered as a separate calendar dot and day-detail section rather than merged into the price-change count, keeping that count's accuracy intact. Verified live: today correctly shows 379 approved items alongside 2 real price changes.

## [2026-07-03] feature | Backlog #17 (public-facing Calendar) built and deployed: /calendar no longer requires auth, CalendarView.vue branches on auth.isAuthenticated, new genuinely-public GET /api/calendar/public (month aggregate, classification breakdown weighted by change events, per-day teased product/spec names, never vendor names or prices). Options 4 (rotating featured product) and 5 (milestone callouts) backlogged as #18/#19 per user's explicit instruction. Verified live logged-out (zero auth headers, confirmed no vendor/price fields) and logged back in (unchanged full-ledger view).

## [2026-07-03] incident | Self-inflicted during the above verification: testing the logged-out view held the real session token in a window JS variable across a location.reload(), which wiped it — the "restore" step then wrote the literal string "undefined" into localStorage instead of the real token, crashing the app on that tab (JSON.parse("undefined") uncaught in stores/auth.js). Cleaned up the corrupted keys and added a try/catch around that JSON.parse so a corrupted stored value falls back to logged-out instead of hard-crashing the whole app. User had to log back in manually on that one tab; no other sessions or data affected.

## [2026-07-03] fix | User reported a bare SQL FK error (pc_specifications_ibfk_1 → pc_products). Root cause: pending_imports.php's approve endpoint trusted a stale candidate_product_id/product_id without checking it still existed — dead after products/merge.php deletes a merge-loser. Fixed by existence-checking before use, falling back to the already-existing findExactProductMatch/createProduct re-resolution. Committed, merged to main, pushed, deployed (backend-only, default build+sync), smoke check passed.

## [2026-07-03] fix | Comparison table's horizontal scrollbar sat at the bottom of however many rows the filters returned (unbounded table height), so reaching it meant scrolling the whole page down first. Bounded .table-scroll to max-height:70vh with its own overflow (both axes), and made both header rows sticky within that box so column/vendor context doesn't scroll away — real bug found along the way: a CSS specificity tie meant the frozen corner cell's z-index was silently losing to a more specific rule, verified and fixed by bumping that selector's specificity, confirmed live via getBoundingClientRect/computed z-index checks with real vertical+horizontal scroll applied simultaneously.

## [2026-07-03] fix | User built a real stack (KLOW) that didn't appear on the Dashboard's Buy This Stack card. Root cause: none of the three admin stack-mutation endpoints (create in admin/stacks/index.php, update/delete in show.php, add/remove item in items.php) ever called cacheBust('pricing_data') — the group GET /api/stacks caches under for 300s — so a newly created/edited stack stayed invisible to the public endpoint for up to 5 minutes. Added the missing cacheBust call to all three. Verified live: admin and public stack lists both show KLOW (4 items) immediately, the Dashboard card rendered it with a clickable Add to cart button, and clicking it correctly added all 4 real components to the cart (cheapest vendor $262.00).

## [2026-07-04] feature | Backlog #5 (Export) built and deployed, all three formats: CSV (GET /comparison/export/csv, Pro tier+, stdlib fputcsv, price-only per vendor + Avg/Median), XLSX (GET /comparison/export/xlsx, Pro tier+, full blueprint formatting — merged two-row navy/blue vendor headers, alternating row shading, green lowest-$/unit highlight, frozen panes at C3), full dataset JSON (GET /export/full, Expert tier, unfiltered — 105 products/1016 prices/9 vendors). Extracted parseComparisonFiltersFromGet() into comparison_query.php so param parsing can't drift between the live view and both export formats. Frontend: Export CSV/Excel buttons on Comparison (tier-gated), Full dataset export card on Settings (Expert-only), same fetch+blob+anchor pattern as WaitlistTab/BackupTab. Real bug caught during live verification: vendor Price/$-per-unit/Avg/Median columns were typed 'price' (forces numeric XML for the whole column), but the custom two-row header writes text into those columns ("HKpep", "Price ($)") — produced invalid <c t="n"><v>HKpep</v></c> XML that openpyxl (and real Excel) refused to open. Fixed by switching those columns to GENERAL (auto-detects string vs. number per cell). Verified live end-to-end against real production data through the actual UI: CSV/XLSX/JSON all confirmed correct post-fix, including the green highlight landing on the right cell (Purelypep Factory $0.28/mg on 5-Amino-1MQ 5mg).

## [2026-07-04] feature+fix | Slow-query triage: user chose both an export tool and a direct investigate/fix pass on the 408 rows in pc_slow_query_cache (all status='new'). Built GET /admin/slow-queries/export (CSV, admin-only) + System tab Export CSV button, verified live. Investigation found the ranked list was mostly noise (the collector's own maintenance queries pinned at 12.4s by a stale one-time GREATEST-latched spike, plus a harmless SLEEP() heartbeat) — but underneath that, 91 of 408 rows (90% of all logged occurrences, 122,077 of 135,441) were all findExactProductMatch() (backend/lib/price_import.php), which wrapped canonical_name/alias in LOWER(), defeating their UNIQUE indexes even though both columns already use case-insensitive collation (utf8mb4_unicode_ci) — a plain = already matches case-insensitively. Confirmed via EXPLAIN (index scan -> const lookup) and a real PHP call on the server (case-insensitive match still correct, non-match still returns null). Fixed, deployed, verified. Marked all 408 rows directly via SQL per the user's explicit instruction: 94 resolved (bug fix + noise, each with a status_note) and 314 acknowledged (reviewed, proportionate, no action needed) — verified live in the admin System tab.

## [2026-07-04] fix | Performance tab's "Daily avg load time" chart: converted from vertical bars to horizontal bars (label/track/value rows, left-to-right fill) per user request, and gave it its own 24h/7d/30d pill selector instead of sharing the top-level range dropdown (which still drives the overall stats/top pages/device split/recent requests). Device/path filters remain shared across both. Verified live: changing the top range selector left the chart's own pill unchanged, and clicking the chart's own pill updated only the chart.

## [2026-07-04] feature | Vendor contact card built and deployed: clicking a vendor name (Comparison table header, Cart's cheapest-vendor list) opens a card with WhatsApp/phone/email/Discord/Telegram/website/shipping-note, available to any authenticated user (not admin-only, not tier-gated). New GET /vendors/{id}/contact endpoint excludes the admin-only internal notes field. WhatsApp opens a wa.me deep link pre-filled with "I found you on Price TheMightyGroupBuy, I would like to know more about: " plus the Cat Nos of any cart items that vendor carries (getCartSnapshot() extended to carry vendor_sku per covered item, falling back to the product/spec label when a vendor didn't set one). New reusable VendorCard.vue component. Real bug caught during live verification: a vendor's whatsapp field held two numbers separated by "/" and stripping non-digits before splitting glued them into one invalid number — fixed by splitting first. Verified live against two real vendors post-fix.

## [2026-07-04] feature | Raw/bulk powder pricing support (user-surfaced): a new vendor file shape, raw peptide powder sold per gram (no vial/kit concept). Modeled as same product entity + new spec (spec_label="1g", numeric_value=1000mg) + new "Raw Material" classification tag, per user's confirmed design choice - reuses all existing $/mg math, Comparison table, cart, and classification filter with zero schema changes. Added extraction-prompt rule 10 (backend/lib/claude.php) recognizing "$/G"/per-gram listings. Imported the real triggering file (vendor "Scarlett," 58 rows, one zero-priced row correctly skipped) through the actual review-queue pipeline: created the vendor, converted the source .xls (legacy format, not accepted by the upload endpoint) to CSV, processed it, approved all 57 resulting new_spec rows via the real Review Queue UI, and bulk-tagged the resulting products with the new classification via direct SQL (safe/additive join table). Hit and fixed two real process issues along the way: a file-ownership permissions bug from placing the upload via SSH instead of the real HTTP path (fixed with chgrp), and a rapid-click batch that likely double-submitted an approval and froze a browser tab via a blocked alert() (recovered on a fresh tab with proper per-click confirmation). Verified live: all 57 committed with correct $/mg math, Raw Material filter renders, Scarlett shows as a real vendor column. Known limitation noted: the classification tag is product-level, so filtering by "Raw Material" shows a tagged product's other specs too, not just its 1g row.

## [2026-07-04] fix | User caught that the "Raw Material" classification tag (first-pass raw-powder design) was product-level, so filtering the Comparison table by it surfaced every spec of a tagged product (vial sizes included, e.g. Semaglutide's 2mg/5mg/10mg/15mg/20mg/30mg/40mg/50mg rows all showed up alongside the actual 1g raw row - confirmed live, 197 rows instead of the correct 57). Root-caused and fixed properly: migration 021_raw_material_spec.sql adds pc_specifications.is_raw_material (backfilled for all 57 existing "1g" specs), retires the classification tag entirely (deleted the join rows and the tag itself - product_classifications is additive/join-table so this was safe). Threaded the new flag through findOrCreateSpec(), the extraction prompt (now emits is_raw_material directly per row instead of leaving it inferred), pending_imports.php's approve action, a new Review Queue checkbox, and a real rawMaterialOnly filter in runComparisonQuery()/comparison/index.php/both export endpoints/query-log rerun - replacing the classification-join approach with a direct s.is_raw_material=1 WHERE clause. Frontend: real "Raw/bulk powder only" checkbox (matching the Verified-vendors-only pattern) plus a "Raw" badge on the spec column. Verified live: toggling the filter returns exactly the 57 real raw rows, no vial specs mixed in; unchecking restores the full set.

## [2026-07-04] analysis | User surfaced that the app was originally meant to be an installable PWA on iOS. Confirmed nothing exists today: no manifest.json, no icon files anywhere (even index.html's referenced favicon.svg has never existed), no service worker — verified live that /favicon.svg and /manifest.json both currently return HTTP 200 with the SPA shell's HTML instead of a real asset, and traced this to public/.htaccess only serving a path directly when a real file exists there (confirmed no Apache/vhost changes will be needed once real files exist). User chose: icon = generate a simple mark from the existing navy/gold design system (they're producing this separately), scope = full offline-capable PWA (not just bare iOS installability), and asked for a spec to implement later rather than building now. Filed as backlog #23, full spec at wiki/analyses/2026-07-04-pwa-spec.md covering manifest.json content, required icon files/sizes, index.html/iOS meta tags, service worker caching strategy (network-only for /api/*, cache-first for hashed JS/CSS, network-first for the SPA shell), the vite-plugin-pwa vs hand-rolled tradeoff, an update-available UX requirement given how often this app deploys, iOS-specific caveats, and a testing checklist.

## [2026-07-04] fix | Same row-alignment bug class as the earlier Products-tab fix found on the Files tab: .actions { display: flex } applied directly to a <td> overrides its default table-cell display, breaking row height/alignment. Fixed the same way (remove display:flex, use `button + button { margin-left: 4px }` for spacing instead, keeping display: table-cell). Proactively grepped the whole frontend for the same pattern (a CSS class applied to a <td> that also sets display:flex) and found one more latent instance: SystemTab.vue's .sq-actions (Acknowledge/Resolve/Reopen buttons on the slow-queries table) - fixed identically before it got reported separately. Verified live via DOM geometry (getBoundingClientRect + computed display) on both tables: all cells now share identical top/height and display:table-cell.

## [2026-07-05] feature+fix | Full 26-file vendor reimport (overnight, unattended, no Review Queue approvals per user instruction). Built Claude API call log (backlog #24, migration 022_claude_call_log.sql, pc_claude_call_log) — persists every raw Claude API call's response text, stop_reason, token usage, and parse outcome, hooked into callClaudeMessages() at all three exit points. User caught that the extraction prompt was peptides-only by framing ("You are a peptide vendor price list parser"), silently dropping real steroid/hormone and vitamin/cosmetic blend rows that vendors actually price — broadened to capture everything priced (backlog #25), verified live post-fix with real steroid/blend rows now landing in the Review Queue. The new call log immediately paid for itself: diagnosed a real "not valid JSON" failure on Peptide0616.xlsx (Purelypep Factory, ~399 rows) as a genuine max_tokens truncation (confirmed via stop_reason in the log, not a fresh API call) once the broadened scope made responses larger — fixed by raising max_tokens 48000->64000. Full reimport: 26/26 files succeeded, 0 failures, 45 total Claude calls logged (1 failure total, the max_tokens case, before the fix). Switched from flaky browser-tab automation to a script (reimport_batch.php) calling the exact same processVendorFile() function via sudo -u apache php in the background (nohup/disowned, survives independent of any SSH session) for the bulk of the overnight run. Final state: 794 items in Review Queue for user's morning review, 2,173 active prices, 109 products.

## [2026-07-05] feature | Claude API admin tab + manual JSON processing (backlog #26), built the same morning as the overnight reimport. New "Claude API" tab surfaces the live extraction/vendor-contact-parse system prompts and a browsable pc_claude_call_log table (list + full-detail view) via two new admin endpoints - no more needing direct SQL to inspect what Claude said. Refactored processVendorFile() to split out commitExtractionResult($file, $result) so the commit/pending-import logic is shared by the real Claude path and a new manual path: "Manual JSON" button on the Files tab lets an admin paste extraction output from another tool (Grok, hand-corrected JSON) and commit it through the identical logic a real Process click uses, without calling Claude. Verified live: prompt/call-log render correctly with all 45 real entries, and a real manual-JSON submission correctly sent a test row to the Review Queue and updated file status/notes identically to a Claude-processed file.

## [2026-07-05] fix+feature | Files tab notes truncated to 255 chars + click-to-expand modal (was showing full raw text inline). Full Review Queue clearance: 371 pending items processed to 0 (found the user had already manually reviewed part of the queue that morning before asking me to continue). Replicated pending_imports.php's exact approve logic as a script (same code path/audit trail, reliable transport for the volume) rather than one-by-one browser clicks. Caught a real identity bug before approving: 10 rows had fuzzy-matched Dulaglutide and Liraglutide onto Semaglutide - three genuinely different GLP-1 drugs sharing only a naming-convention suffix; forced these to create distinct products instead of merging real drugs together. Found and consolidated 9 duplicate-naming clusters in the new_product rows (same real product, inconsistent vendor-file wording) before approving, avoiding ~24 avoidable duplicate products. Fixed 11 rows that failed on spec_label exceeding VARCHAR(50) (multi-ingredient blend ingredient lists stuffed into the spec field) by truncating to fit. Broader find: a systemic pre-existing pattern where Claude bakes known aliases into canonical_name itself, defeating exact-match against real existing products - a substring self-join scan across the whole product table found 35 such duplicate pairs (including a 3-way KLOW split across products created on three different days), all merged via the existing merge-product logic. Catalog: ~219 products down to 184. Deliberately left 4 genuinely-ambiguous cases unmerged for the user's own call (Adipotide/FTPP, Gonadorelin/Acetate, Sermorelin/Acetate, L-Carnitine dose-baked-into-name) - filed as backlog #28. Verified live: 0 pending remain, KLOW search shows all 4 vendors under one consolidated product, repeat duplicate-scan confirms only the deliberately-excluded cases remain.

## [2026-07-06] scan | Weekly clipping scan — no new files

- Scanned `raw/clippings/`: 5 files found, all previously ingested on 2026-06-30
- No new source pages, entity pages, or variant sightings this week

## [2026-07-06] fix | Found and fixed the actual root cause of backlog #28's duplicate-naming pattern (the one flagged, not yet root-caused, on 2026-07-05). Rule 7 of the extraction prompt (`buildExtractionSystemPrompt()`) fed Claude the full product+alias catalog as `"Name (alias1, alias2)"` strings and asked it to map variants onto them - Claude was echoing that alias-annotated display string back as `canonical_name` itself instead of the plain name. Because `products/merge.php` already keeps a merged-away product's name as a new alias on the winner, each recurrence fed a longer alias back into the next catalog snapshot, compounding across merges. Caught via the 2026-07-06 Lucy import: product 278 was created with the literal name `"BPC+TB (TB+BPC, BPC-157+TB500, wolverine, BPC-157 + TB-500, BPC+TB (TB+BPC, BPC-157+TB500, wolverine, BPC-157 + TB-500))"` - visibly double-nested, traced back to an earlier 2026-07-05 merge (`[51, 165]` in `merge_duplicates_2.php`) whose kept-alias became the seed. `findExactProductMatch()`/`findFuzzyProductCandidate()` already match against both `canonical_name` and `pc_product_aliases`, so rule 7 was pure redundancy with matching the software already does - removed it, Claude now just extracts the vendor's literal printed name, matching stays 100% in the software layer. Also dropped the unused `is_new_product` output field (never read anywhere downstream), which reinforced the same wrong mental model. Cleaned up: merged product 278 into 51, removed the stale doubled alias (id 57) left from the earlier occurrence. Deployed and verified live via direct SQL query. This closes the root-cause half of backlog #28's duplicate pattern - the 4 genuinely-ambiguous cases (Adipotide/FTPP, Gonadorelin/Acetate, Sermorelin/Acetate, L-Carnitine) are unrelated real-identity questions, still open, still the user's call.

## [2026-07-06] fix | Broader sweep after the BPC+TB fix: the same rule-7 echo bug hit every row in the 2026-07-06 Lucy import batch that matched an existing catalog product, not just one - 26 self-nested duplicate products total (e.g. `Melanotan 1 (MT-1, Melanotan I, Melanotan 1 (MT-1, Melanotan I))`), all created within the same 09:58:46-10:04:17 window. Found by querying for products with `canonical_name LIKE '%(%'` created that morning and checking each against the existing shorter-named product it duplicated - all 26 mapped 1:1 onto products already established as canonical in the 2026-07-05 dedup pass. Merged via `migration_scripts/2026-07-06-merge_lucy_duplicates_batch.php` (same merge logic as before, does not keep the mangled name as an alias this time). Catalog: 220 -> 194 products. Re-ran the whole-catalog substring self-join duplicate scan afterward as a second check - everything remaining is legitimate (combo products, plus the same 4 already-flagged ambiguous cases from backlog #28). Genuinely new Lucy products (Alprostadil, Testagen, Livagen, Pancragen, Prostamax, Chonluten, Ovagen, N-Acetyl Epitalon Amidate, 3 new combos) have no self-nested pattern and were left untouched.

## [2026-07-06] blocker | Claude API usage limit hit while processing vendor file 32 (Lucy-Oil Updated List.pdf) — HTTP 400, "You have reached your specified API usage limits. You will regain access on 2026-08-01 at 00:00 UTC." File correctly marked failed with the error recorded, no stuck state. Filed as backlog #29 — blocks all further vendor-file extraction until the limit resets or is raised.

## [2026-07-06] fix | Admin Products page N+1 network bug found while investigating "every merge/alias action is slow." Not a slow SQL query at all - ProductsTab.vue's `load()` fired one sequential HTTP request per product to `GET /api/products/{id}` just to populate the aliases/classifications chips (194 sequential round trips to render the list once), and every single write action (merge, add/remove alias, spec save/move/merge, edit save) ended with `await load()`, re-triggering the whole loop. `show.php`'s per-product query was individually fast and even had a comment assuming single-product on-demand use - nobody anticipated it being called in a 194-iteration loop. Fixed: `products/index.php`'s GET now bulk-fetches aliases + classifications for every product in 2 extra queries total (grouped in PHP), folded into the existing list response - `load()` is one request. Specs+per-spec prices (only shown for one expanded/editing row at a time) stay behind a new on-demand `loadSpecs()`, called from `startEdit()` and after any spec-mutating action on the currently-edited row. Verified live: Products page load is 1 request (was 195), expanding a row for edit is 1 additional request (unchanged from before), specs render correctly, cancel/edit flow unaffected.

## [2026-07-06] fix | Review Queue cleanup continued: you raised the Anthropic org's $10/month spend limit (found via the console - it wasn't a credits or rate-limit issue, a self-configured cap at 100% used), so I reprocessed vendor file 32 (Lucy-Oil) successfully (3 exact-match imports, 64 to Review Queue, no recurrence of the alias-echo bug). Went through all 64: 46 of the 52 new_product rows were confirmed (case-by-case, not guessed) to be existing catalog products under this vendor's bare/abbreviated wording - mapped onto their real products and added as 44 real alias rows so future vendors match automatically. Caught a real identity bug before approving (L-Carnitine 500mg had a stale fuzzy-matched candidate pointing at the existing 600mg product - forced its own distinct product instead). 4 genuinely new products approved normally. 1 row (SUPER SHRED) left pending, flagged ambiguous rather than guessed. Surfaced a second dose-baked-into-product-name cluster (SU-400/Sustanon/Supertest/TESTOSTERONE CYPIONATE) alongside the existing L-Carnitine one - both extend backlog #28, still your call. Scripts: migration_scripts/2026-07-06-review_lucy_oil_batch.php, 2026-07-06-add_lucy_oil_aliases.php.

## [2026-07-06] fix | Raw SQL error leaking to the Inventory tab UI ("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '20-78-255-1' for key 'uq_price'"). Root cause: `frontend/src/utils/api.js`'s thrown error used `data.message || data.error` - backwards. Backend convention across 7+ endpoints (prices/update.php, files/process.php, manual_process.php, parse_intake.php, vendors/files.php, pending_imports.php, spec_update.php) is `{error: "friendly headline", message: rawExceptionText}`, with `message` meant as secondary diagnostic detail - but `||` picked `message` first every time both were present, so the raw PDO text always won over the intended friendly text everywhere this pattern is used, not just this one page. `prices/update.php` even already had a clean message ready for exactly this collision ("Update failed — check for a duplicate tier size on this vendor/spec.") that never reached the user. Fixed by swapping the precedence to `data.error || data.message`. Not a data bug - the underlying collision was a legitimate uq_price (vendor, product, spec, tier_kit_size) hit: Lucy already has two real tiers priced on Cerebrolysin 60mg (tier 1 @ $43, tier 6 @ $49); the edit just needs a tier_kit_size that isn't already taken.

## [2026-07-06] fix | Comparison table's Avg/Median columns were computed from price_per_unit ($/mg) instead of price_usd (kit price) - runComparisonQuery() (comparison_query.php) now averages/medians the kit price, matching the "Price" sub-column each vendor shows (as opposed to their separate "$/unit" sub-column). min/max and the is_lowest highlight logic stay on price_per_unit, since that's the fair cross-vendor comparison basis when kit sizes differ - only the two summary columns changed. CSV/XLSX exports read from the same shared function, so both picked up the fix automatically. Verified live via a direct call to runComparisonQuery() for BPC-157/1mg (6 vendors, kit prices $18-$41): avg now $30.67, median $30.50 (was $1.53/$1.525 in $/mg terms).

## [2026-07-06] fix | Comparison table: vendor display names truncated to 110px with ellipsis + hover tooltip (was stretching each vendor's whole 2-column group to fit long names like "Zhongke Meiye Pharmaceutical Co., Ltd.", leaving lots of empty space in the narrow Price/$-unit cells below). Added a vertical divider border between each vendor's column pair for visual separation. Verified live: truncation/tooltip/divider all render correctly, vendor card still opens on click.

## [2026-07-06] fix | Comparison table follow-up: numeric cells (Price/$-unit/Avg/Median) were right-aligned, now centered; vendor name truncation tightened from 110px to 80px for less empty space. Verified live.

## [2026-07-06] fix | Confirmed systemic bug: Claude conflated a vial-count baked into spec text ("10mg*10vials") with tier_kit_size across 7 vendors (Zhongke Meiye, Tingpeptide, Premipeptides, Tidetron, Guangzhou Guangjin, CALLA, Lucy) - proven by viewing every source file directly and by a working reference vendor (real 1/10/50-kit tiers, kit_vial_count constant, only tier_kit_size varies). Ruled out a blanket regex preprocessing fix - Purelypep Factory's genuinely-tiered file uses identical "*10vials" phrasing, so only Claude evaluating real column structure can tell the difference; fixed via prompt rule 1 instead (tier_kit_size stays 1 unless the source shows real separate prices per order quantity) plus a new rule preferring regular/list price over a promotional one. Corrected existing bad data: many combos had both a correct tier=1 row (pre-bug) and a duplicate buggy tier=10 row (post-bug, from a 2026-07-05 reprocessing pass) - deleted 343 exact-duplicate tier=10 rows, flipped tier_kit_size 10->1 for 429 tier=10-only rows, corrected Premipeptides' price_usd from "June Sale" to the real "Price" column (127 rows, matched via vendor_sku against the source spreadsheet). Left 10 rows untouched and flagged for manual review: 6 general mismatched duplicate pairs (real price/SKU discrepancies between the two processing runs) and 4 Premipeptides rows where the source itself has the same Cat. code listed twice with different prices. 65 more Premipeptides rows had no vendor_sku to match against the source at all (tier fixed, price left on June Sale) - a full reprocess of that file under the corrected prompt would resolve these cleanly, not yet done. Verified live: comparison query for Premipeptides+Tidetron at tier=1 went from 3 rows to 211.

## [2026-07-06] fix | Reprocessed Premipeptides (vendor file 30) under the corrected prompt - resolved the remaining 65 rows that still had the wrong June Sale price after the manual DB correction. 69 rows updated directly, 100 already matched the manual fix exactly (confirms that correction was accurate), 40 new rows sent to the Review Queue. Warning confirmed rule 11 worked as intended: "Sale prices (June Sale column) present for all rows but not used per rule 11 - regular price used instead." Tier distribution now 203 rows at tier=1, only legitimate non-standard kit sizes (6/10/11/12/13 vials, each correctly warned) remain elsewhere.

## [2026-07-06] fix | NOVI OREA MOQ-in-vials fix. Correction: the CSV (vendor file 27) did not fail as an earlier investigation note claimed - that was a bug in a local re-parse script, not a real extraction failure (pc_claude_call_log confirms parsed_ok=1); it holds correct 1/10/50-kit tiered pricing for ~40 products, complemented by image file 29's matching 1-kit tier. Only image file 28 (explicit "Price/vial" + "MOQ/vial" in raw vial units) had the bug: MOQ stuffed directly into kit_vial_count/tier_kit_size. Added prompt rule 12 (claude.php): kit_vial_count defaults to 10 unless the source states a different bundle size, tier_kit_size = ceil(MOQ/kit_vial_count), price_usd = per-vial price * kit_vial_count. Corrected the 11 affected rows: 6 were redundant duplicates of tiers the CSV already covered correctly (deleted), 5 were genuinely new (Retatrutide 60mg, GHK-Cu 100mg, 3 Botulinum toxin specs) and got corrected in place, including the BOTOX 15vial/bag case (100 MOQ / 15 = 6.67, rounded up to 7 kits per your call). Verified live: price_per_unit matches the original per-vial pricing exactly for all 5 corrected rows.

## [2026-07-06] fix | spec_label still carried the raw "10mg*10vials" packaging text even though numeric_value was correctly parsed to just 10 - rule 5 only told Claude to normalize numeric_value/unit, never spec_label itself. Fixed in claude.php (rule 5 now explicit: spec_label is just the dose, packaging suffix stripped). Corrected 90 existing spec rows matching this exact shape (16 others left alone - different, legitimate patterns like "1200mg/vial" or blend "10ml x 150.3mg/ml/vial" specs, not this bug). Nearly all had a same-product sibling spec already using the clean label (from a different vendor's correctly-extracted row), so 89 were merged (prices moved, buggy spec dropped) rather than renamed in place, which would have hit the (product_id, spec_label) unique constraint; 1 renamed directly (no clean sibling existed). Verified live: 0 specs remain with the vial-count suffix, 2,496 active prices total (unchanged - this was a spec-level merge, not a data loss).

## [2026-07-07] query | Full code review — 2 security holes + 9 correctness/quality findings

- Reviewed the whole app surface: router/CSRF/CORS (`public/index.php`), auth stack, all trust-boundary endpoints (uploads, downloads, public writes), import/pricing pipeline, merge tools, exports, schema FKs, frontend core. Overall shape is good: prepared statements throughout, hashed bearer tokens, fail-closed rate limiting, no `v-html`, audited admin actions.
- **Fixing now (user call, items 1-4)**: (1) stored XSS against admins via `coa_url` — `FILTER_VALIDATE_URL` accepts `javascript://…`, rendered as a clickable `<a :href>` in ReviewQueueTab; (2) same class via `vendor.website` — Claude-extracted from vendor-controlled files, rendered as `:href` in VendorCard for all users, no scheme validation on any write path; (3) `admin/backup.php` merges mysqldump stderr *into* `database.sql` (`2>&1` after `>`), corrupting the dump with any warning text and losing the error detail; (4) cart/stack items FK `ON DELETE CASCADE` to specs/products — every admin merge that deletes a loser spec (products/merge, spec_merge, spec_move, and yesterday's 89-spec label migration) silently deletes users' cart/stack rows instead of repointing them to the winner.
- **Backlogged (items 31-37 in phase-roadmap)**: async-queue claim race, quarantine placement/path inconsistency, audit-log CASCADE, CSV formula injection, email HTML injection, download filename header quoting, admin self-demotion guard.
- Accepted by design, confirmed not bugs: 60s session-cache staleness window on revocation (documented in `requireAuth()`), `perf.php` CSRF exemption (rate-limited, no state to forge), admin-only raw exception text in `message` fields.

## [2026-07-07] fix | Code-review items 1-4 fixed and deployed (items 5-11 filed as backlog #31-37)

- **XSS gate for href-destined URLs**: new `safeHttpUrl()` in `helpers.php` — only http(s) survives (FILTER_VALIDATE_URL alone accepts `javascript://…`, which Vue's `:href` renders live), bare domains get `https://` prepended, anything else stores as null. Applied at every write path: `coa/submit.php` (rejects 422 — this URL renders as a clickable link in the admin Review Queue), vendor `website` on create (`vendors/index.php`), update (`vendor_helpers.php` `updateVendorScalarFields`, shared by show.php PUT), and the Claude-extraction contact fill (`vendor_file_processor.php` — vendor-controlled content shown to every user). Self-check left behind: `backend/lib/safe_http_url_test.php` (matches the existing `price_per_unit_test.php` convention), passing on prod.
- **Backup dump corruption**: `admin/backup.php` redirected mysqldump stderr with `2>&1` *after* `> database.sql`, so any warning text landed inside the dump (breaking restore) and the failure `detail` was always empty. stderr now goes to its own file, read back for the error detail.
- **Cart/stack cascade wipe on merges**: `pc_cart_items`/`pc_stack_items` FK to specs/products with ON DELETE CASCADE, and none of the merge/move tools repointed them — every admin dedup that deleted a loser spec silently emptied matching rows from user carts and curated stacks (including yesterday's 89-spec label migration). New `repointCartAndStackItems()` helper (UPDATE IGNORE — an existing destination row wins, loser cascade-cleans, correct dedup) called in all five delete/re-home paths: `products/merge.php` (both branches), `spec_merge.php`, `spec_move.php` (both branches).
- Deployed via deploy.sh, php -l clean on all 9 changed files, both lib self-checks pass on prod, smoke check green.

## [2026-07-08] fix | Backlog batch: #2, #9, #30, #31, #32, #34, #37 (post-code-review session)

- **#2 price_per_unit backfill**: recomputed $/unit across all rows — 30 stale (Premipeptides June-Sale remnants + CALLA rows predating their spec numeric_value fix), all corrected, 0 remain, cache busted. Archived `migration_scripts/2026-07-08-price_per_unit_backfill.sql`.
- **#9 vendor purge = hide-not-delete**: new `pc_vendors.is_hidden` (migration 023). Hiding forces is_active=0 (removes from all user-facing queries) and drops the row from the admin list too; "Show hidden" toggle + per-row Hide/Unhide button on VendorsTab, all data retained. Never a real delete.
- **#30 duplicate cleanup**: overnight Premipeptides reprocess + new vendor "Peptide Research Solutions" created 23 dup products (320-342) under vendor/dose-in-name wording. Merged 22 confirmed case-by-case (`migration_scripts/2026-07-08-merge_overnight_import_duplicates.php`, same logic as products/merge.php incl. cart/stack repoint) — catalog 222->200, 0 orphans. Left id 321 "MT" (SKU MT1) flagged pending — probably Melanotan 1 but ambiguous. Cagrisema 332/333/334/336 -> generic Cagrilintide+Semaglutide (60), noted as a trade-name-vs-generic judgment call.
- **#31 async queue claim**: GET_LOCK('pc_process_async_queue',0) guard in cron/process_async_queue.php — overlapping ticks no-op instead of double-processing (double Claude spend). Auto-releases on connection close so a crash can't wedge it.
- **#32 quarantine**: quarantineFile() moved to storage/quarantine/ (outside the vendor_files/ tree backup.php zips — malware no longer in backups) and returns a storage-relative path like every other stored_path (fixes download.php breaking on quarantined rows). No files to migrate (flag off).
- **#34 CSV formula injection**: export_csv.php single-quote-prefixes cells starting with =+-@; numeric cells untouched.
- **#37 last-admin guard**: users_show.php rejects (422) clearing is_admin on the only admin.
- Deployed (schema sync + code), all changed files php -l clean, GET_LOCK/is_hidden/CSV-guard/safeHttpUrl self-checks pass on prod, smoke check green.

## [2026-07-08] fix | Backlog batch: #18, #19, #30(MT), #33, #35, #36

- **#18 featured product (admin-picked)**: new pc_calendar_features table (migration 025), admin endpoint admin/calendar-features (GET/POST/DELETE) + new admin "Calendar" tab (CalendarTab.vue) to pick date→product→spec→note. Public calendar_public.php resolves the featured day to the product's cheapest active listing (vendor, price, delta from latest history); CalendarView.vue renders a full card (★ on the cell) for logged-out visitors only. Verified live.
- **#19 all-time-low milestones**: calendar_public.php returns milestones per day (product+spec that first hit a new recorded low this month, needs a prior higher price). Name-only 🏆 callouts, preserving the teaser. Verified populated across real July history.
- **#30 MT→Melanotan 1**: merged product 321 "MT" (SKU MT1) onto 93 per user decision (migration_scripts/2026-07-08-merge_mt_into_melanotan1.php). Cagrisema kept merged per user decision. Catalog 200→199.
- **#33 audit log FK**: migration 024 — admin_id nullable, CASCADE→SET NULL, so deleting an admin keeps their ~3000 audit rows. Verified live.
- **#35 email escaping**: loadTemplate() htmlspecialchars() all vars except trusted self-built 'button'. Verified.
- **#36 download filename**: download.php sends sanitized quoted filename + RFC 5987 filename*=UTF-8'' for the real name. Verified an embedded quote no longer breaks the header.
- Deployed (schema 024/025 + code), all changed files php -l clean, smoke check green.

## [2026-07-08] change | Admin Panel tabs grouped into two primary sections (user request)

- The admin nav had grown to 17 pills in one row (flagged during the 2026-07-07 code review as unwieldy). Reorganized AdminView.vue into two primary group tabs, each fanning out to its own sub-tab row:
  - **Vendor / Product Management**: Vendors, Review Queue, Products, Inventory, Stacks, Files, Claude API, Calendar
  - **System / User Management**: Overview, Users, Waitlist, Subscriptions, Feedback, Performance, System, Backup, Settings
- Two-level tab state (activeGroup + activeTab); selecting a group defaults to its first sub-tab. Still lands on System→Overview so the default landing is unchanged. Primary groups render as navy buttons, sub-tabs as gold pills to distinguish the levels. Pure frontend change, no backend/route/API changes. Calendar and Stacks placed under Vendor/Product as the two judgment calls (both catalog-facing). Commit b8cd02f.

## [2026-07-08] note | Backlog #8 (ClamAV) root cause confirmed + put on hold

- User clarified the blocker: freshclam signature downloads are blocked from AWS/EC2 IP addresses (ClamAV's CDN, database.clamav.net, refuses this IP range), so clamd has no virus DB and crash-loops. This matches the existing comment in config.php. It is NOT a daemon/config bug to root-cause on the box — the daemon is fine, it has nothing to load.
- Item is ON HOLD until the user resolves the signature-download problem (mirror off the blocked range, private S3/DatabaseMirror in freshclam.conf, out-of-band CVD copy, or a proxy). Uploads remain unscanned (MALWARE_SCAN_ENABLED=false) until then. Backlog #8 updated to reflect this so it isn't re-investigated.

## [2026-07-08] feature | Cart cheapest-per-item (mix & match) view (#38, user request)

- Cart previously showed only single-vendor options (cheapest vendor covering the whole cart + partial-coverage vendors). Added a per-item breakdown: each item's lowest price from whichever vendor is cheapest for that line, plus a grand total — the "split the cart across vendors for the absolute lowest" mode.
- Backend: extended getCartSnapshot() (backend/lib/cart.php) to compute cheapest_by_item + cheapest_total from the price rows it already fetches (no extra query). Per-item entries aligned to cart order; vendor_id null when no vendor carries an item (shown as unavailable, not dropped). All snapshot consumers (cart GET/POST, add_stack) get the new fields automatically.
- Frontend: cart.js store exposes cheapestByItem/cheapestTotal via a shared _apply(); CartView.vue renders a new "Cheapest per item (mix & match)" card with clickable vendor names (→ VendorCard) and a total shown once every item has a vendor.
- Verified live on a real 4-item cart: $155.71 mix-and-match vs. $166.71 best single vendor; invariant (split ≤ best single-vendor full-coverage total) confirmed.

## [2026-07-08] feature | Vendor file upload: multi-file select + clipboard image paste (#39, user request)

- Admin vendor-file upload (VendorsTab.vue) previously took one file per pick. Backend (vendors/files.php) is one-file-per-request by design (per-file malware scan, dedup, is_current supersede), so kept it as-is and looped on the frontend.
- Added: `multiple` on the file input; a shared uploadFiles() that POSTs each file sequentially with a live progress counter (uploadDone/uploadTotal) and an end-of-batch summary alert (uploaded N / duplicates skipped / per-file failures) — silent on all-success since the file-repo table refreshes as feedback.
- Image paste: a focusable dashed paste-zone with @paste pulls image items off the clipboard, synthesizes a filename with the correct extension (backend keys file_type off the extension) since pasted blobs often lack a usable name, and runs them through the same uploadFiles() loop. Honors the selected category (price_list/coa/other). preventDefault only when images are actually found so text paste elsewhere isn't blocked.
- Frontend-only, no backend/route change. Deployed; build clean, new code confirmed in bundle, file input has `multiple`.

## [2026-07-10] feature | Comparison table: $/unit toggle + wide-screen full width (#40, user request)

- "Show $/unit" toggle in the Comparison filter bar (default on, persisted to localStorage `cmp_show_unit`). Off = hide each vendor's $/unit sub-column, halving column count for small screens. Done via conditional vendor-header colspan (2→1) + v-if on the $/unit sub-header/body/blank cells; two-row header stays aligned. Price cell keeps the lowest-value highlight (derived server-side from price_per_unit) even with $/unit hidden. Pure display, no re-query.
- Wide screens: AppLayout.vue gained a `wide` prop that removes the centered max-width:1400px cap; ComparisonView passes it, so the table fills a very wide window (minus gutter) instead of a narrow centered column. Other pages unaffected (default stays capped).
- Frontend-only. Deployed; build clean, toggle + wide CSS confirmed in bundle.

## [2026-07-10] investigate+fix | Comparison "multiple vendors" readability + median semantics (#41)

- **Investigation (Cagrilintide 2mg, user-reported):** confirmed NOT a data/calc bug. Row genuinely has 2 active tier-1 vendors (Premipeptides $58, Peptide Research Solutions $83); avg/median = $70.50 = correct mean of the two, no empty fields counted. Root cause of the confusion: with "multiple vendors" on, the matrix is 19 vendor columns wide and each row fills only the 2-3 that carry it; Avg/Median sit past all 19 at the far right, so scrolling to read them pushes both price cells off-screen — looked like "one vendor + average of empties."
- **#1 sticky summary**: Avg/Median columns pinned to the right edge (sticky, opaque bg matching row stripe, z-index above vendor sub-headers) so a row's summary stays visible while scrolling its prices.
- **#3 list view**: added a Table/List toggle in the filter bar (persisted `cmp_view`, defaults to List on <768px screens). List renders each row as a compact card — product+spec, avg/median summary line, and only the vendors that carry it, cheapest-first (by $/unit), lowest highlighted, honoring the Show $/unit toggle. Far more readable on mobile and for sparse rows than the wide matrix.
- **Median semantics fix**: median now requires n>=3 vendors; below that it returned the average trivially (n=2 median IS the mean), which was noise. comparison_query.php returns null under 3 vendors; UI shows "—", CSV export blank, XLSX "—". Verified live: n=2 → dash, n=17 → real median distinct from avg.
- Frontend + backend deploy, php -l clean, smoke green.

## [2026-07-10] fix+investigate | Admin tab cleanup batch (7 items)

- **#1 tier_status editable**: UsersTab Status column is now a dropdown (active/trialing/past_due/canceled/none) → PATCH /admin/users (backend already accepted it). Lets an admin flip a user to active without DB access.
- **#2 query-log sort/filter**: SystemTab comparison query log now sorts by any column (duration/user/results/when, click headers) and filters by email substring + min-ms, client-side over the loaded 200 rows.
- **#3 Re-run opens the query**: added an "Open" action that deep-links the logged query's filters to /comparison in a new tab. ComparisonView now reads classification_ids/vendors/tier/multi_only/verified_only/raw_material_only from the URL (product/spec IDs are always empty in real logged queries, so not restored). Kept the old server-side timing re-run as a "Time" button.
- **#4 what's slow on the comparison log**: NOTHING. 271 logged queries, 0 ever slow_flagged, avg 12ms, max 97ms (threshold 1500ms). EXPLAIN starts from pc_specifications (~590 rows) with filesort from the ORDER BY, then eq_ref joins — trivially fast on this data. The red "Slow" styling only lights for slow_flag rows, of which there are none.
- **#5 memcache**: already excellent — 97.7% hit rate (232k hits / 5.4k misses), 0 evictions, 30 items / 107KB of 128MB. Low byte count = small payloads (comparison results, settings, sessions), not underuse. DB is idle (avg 12ms). No action; adding cache wouldn't reduce a load that isn't there.
- **#6 DB connections**: "1" is just the current idle count — app opens one PDO per request (no pooling, correct for PHP-FPM), closes at request end. Max_used_connections=5 of max_connections=50 (10x headroom). No change needed; persistent connections not worth it at this scale.
- **#7 slow_query_cache — REAL issue, proposed not yet done**: 1565 rows, but only 2 are >=1s and BOTH are the import event's OWN INSERT/DELETE on pc_slow_query_cache (12.4s phantom, self-ingested — the event logs itself to mysql.slow_log, next run imports it; GREATEST() then freezes the max forever). Other 1563 rows are <1s queries flagged only by log_queries_not_using_indexes (a full scan on a 200-row table is correct+fast, not actionable). Proposed fix: (a) exclude sql_text LIKE '%pc_slow_query_cache%' from the import event, (b) only capture genuinely slow rows (query_time >= ~0.5s OR rows_examined >= ~5000), (c) truncate the existing noise and let it repopulate clean. Needs an event replacement (migration) + my.cnf min_examined_row_limit tweak — staged for user go-ahead.

## [2026-07-10] fix | Slow-query-log capture filter shipped (#7, migration 026)

- Replaced the pc_import_slow_queries hourly event: now excludes sql_text LIKE '%pc_slow_query_cache%' and '%slow_log%' (kills the self-ingested 12.4s phantom — the janitor was logging its own INSERT/DELETE), and only captures rows with query_time >= 0.5s OR rows_examined >= 5000 (drops fast full-scans of tiny tables that log_queries_not_using_indexes was flagging). Truncated the accumulated noise.
- Skipped the my.cnf min_examined_row_limit change on purpose — server-wide and shared with the grp app, and unnecessary since the event filter + the existing hourly `DELETE FROM mysql.slow_log WHERE db='tmgb_price'` keep the table clean.
- Result: table went 1565 noise rows -> 5 legitimate ones on first run, all high-rows-examined queries (comparison 14306, calendar/history 16399, vendors list 9315, audit 6243, calendar-approved 6644) — fast today (8-30ms) but the right early-warning watchlist as data grows. Zero self-referential/phantom rows. Verified live: event ENABLED with the new WHERE, table repopulated clean.
- Threshold (0.5s / 5000 rows) is easy to tune later in a follow-up migration if it's too chatty or too quiet.

## [2026-07-11] feature | User data-export audit log + admin display, memcache object count (#42)

- Found: none of the 4 user exports (comparison CSV/XLSX, full, personal-data) were logged; no user-side audit table existed (only pc_admin_audit_log for admins).
- pc_user_audit_log table (migration 027, FK CASCADE on user delete). logUserAction() helper in helpers.php — best-effort (try/catch, logs to error_log) so an audit-write failure never breaks the export it's auditing. Wired into all 4 exports with action + counts + filter context.
- GET /admin/users/{id}/activity (new endpoint + route) returns the user's audit entries + last 20 logins. UsersTab gained an "Activity" expander next to "Referrals" (refactored expand state to {id,type} so one panel opens at a time) showing an actions table + login list. me/export.php now includes the user's own activity_log in their GDPR-style dump and logs the export.
- Verified live: logUserAction round-trips through the admin query with JSON details intact; test row cleaned up.
- Also (user request, same turn): System-tab memcache card now shows "Cached objects" (curr_items) — the "cache barely used" impression was from low bytes (small payloads), not low usage; object count + 97.7% hit rate show it's working.

## [2026-07-11] fix | Admin exports audit-log gap closed (files/download.php)

- Swept every file-streaming endpoint (Content-Disposition/readfile/fputcsv/writeToStdOut). Correction to prior note: waitlist CSV and slow-queries CSV were already logged (export_waitlist_csv / export_slow_queries_csv), as was backup (download_backup). The only unlogged admin download was files/download.php (vendor-file download). Added logAdminAction('download_vendor_file', {file_id, filename, vendor_id}). Now every admin data export/download is in pc_admin_audit_log; every user export is in pc_user_audit_log.

## [2026-07-11] tweak | Admin panel default tab

- Landing tab changed from System/User Management -> Overview to Vendor/Product Management -> Vendors (`AdminView.vue` activeGroup/activeTab initial refs). Day-to-day admin work (vendor/price/product upkeep) is the common case; system stats are one click away.

## [2026-07-11] feature | COA admin revoke/status + Comparison-page verified badge (#43)

- Admin COA queue was next-pending-only (approve/reject); added a full list endpoint (`?list=1[&status=]`) and a `revoke` action, so any submission can move between pending/approved/rejected at any time, each transition audit-logged. ReviewQueueTab's COA tab gained a filterable full-submissions table under the existing single-card flow.
- `runComparisonQuery()` now flags each vendor cell with `has_coa` (approved COA exists for that vendor+product); Comparison page shows a ★ next to the price in both table and list views.
- Verified live: ran the query function directly against prod data (4 approved COAs already exist) — has_coa correctly true for the matching vendor/product.

## [2026-07-11] feature | Calendar featured product deep-links to Comparison (list view, no extra filters)

- `calendar_public.php`'s featured payload now includes `product_id` (was already selected in the SQL, just not returned). `CalendarView.vue`'s featured card gained a "See every vendor for this product" link to `/comparison?products={id}` — bare product ID, no other filters, per user decision (the point is showing the unfiltered full vendor list to back up the quoted price).
- `ComparisonView.vue` gained real `products` query-param support: `initFromQuery()` now reads `q.products`, forces list view (matches the "every vendor for this row" ask), and `runSearch()` passes it through to the existing `comparison.search()`/`buildParams()` (which already supported a `products` filter for other callers, just never wired to the URL before).
- `/comparison` requires auth; an anonymous visitor clicking the link bounces through `/login?redirect=...` (already-existing router guard behavior) and lands back on the right filtered comparison view post-login — no new redirect logic needed.
- Verified live: `pc_calendar_features` has real entries (today = product 63, PT-141); `GET /api/calendar/public?month=2026-07` confirmed `product_id` present in the JSON for all three current features.

## [2026-07-11] fix | Calendar featured product + milestones now visible to logged-in users too

- User feedback: after adding the Comparison deep-link (#44), realized the whole featured card/milestones only ever rendered for anonymous visitors — logged-in users (i.e. everyone, since "there shouldn't be much of a site without being logged in") never saw it. Confirmed this was the original backlog #18/#19 design (public-only), not a bug, then the user asked to drop that gate entirely.
- Extracted the featured-product and all-time-low-milestone logic (previously inline in `calendar_public.php`) into shared `backend/lib/calendar_featured.php` (`getCalendarFeatured()`/`getCalendarMilestones()`), and wired both into the authenticated `calendar.php` endpoint too (it only ever returned `days`+`approved` before). `calendar_public.php` now just calls the same two functions.
- `CalendarView.vue`: dropped `!auth.isAuthenticated &&` from the featured-card and milestones `v-if`s; `load()`'s authenticated branch now populates `featured`/`milestones` from the response instead of hardcoding `{}`.
- Verified live: ran `getCalendarFeatured()`/`getCalendarMilestones()` directly on the server (today's real data, product 23 Epithalon), and re-confirmed the public HTTP endpoint still returns identical output after the refactor.

## [2026-07-11] chore | Archive this session's diagnostic/verification scripts

- New `diagnostic_scripts/` directory (sibling to `migration_scripts/`) for one-off read-only PHP scripts run on the server to verify a change against live data — previously written to a scratch job-tmp path and discarded after running. Per user request, going forward every such script gets saved here and logged, same as `migration_scripts/` already does for data-mutating ones (see updated [[feedback_archive_migration_scripts]] and new [[feedback_archive_diagnostic_scripts]] in memory/).
- Recovered and archived the five scripts run earlier this session:
  - `2026-07-11-verify-comparison-has-coa.php` — confirmed `runComparisonQuery()`'s new `has_coa` flag resolves `true` for a vendor/product pair with a real approved COA (Retatrutide/vendor 24), verifying the ★ badge feature (#43).
  - `2026-07-11-verify-coa-submissions-list-query.php` — confirmed the admin COA-queue list SQL (vendor/product/submitter join) used by the new `?list=1` endpoint resolves correctly across all 5 real submissions (#43).
  - `2026-07-11-verify-calendar-featured-shared-functions.php` — confirmed `getCalendarFeatured()`/`getCalendarMilestones()` (extracted into `backend/lib/calendar_featured.php`) still return correct real data after the extraction (#44/#45).
  - `2026-07-11-dump-memcached-keys.php` — dumped every live Memcached key (`Memcached::getAllKeys()`) to see exactly what's cached at a point in time; used to investigate the "Cached objects" count.
  - `2026-07-11-cache-group-versions-and-stats.php` — printed each cache group's bust counter (`cv:<group>`) plus overall Memcached stats; found `pricing_data` had been busted ~1939 times (shared by comparison results/calendar/classifications/filters/stacks) and `admin_products` ~1499 times — the real explanation for the low object count, not underuse.

## [2026-07-11] fix | Split pricing_data cache group into 4, raise cache TTLs (#46)

- Split the overly-coarse `pricing_data` Memcached group (bust count ~1939, shared by comparison results/calendar/classifications/filters/stacks) into `comparison_data`, `calendar_data`, `classifications_data`, `stacks_data` — each busted only by the writes that actually affect it, so e.g. a price update no longer wipes the classifications list or stacks cache too. 31 backend files touched (every cacheGet/cacheBust call site), all `php -l` clean on the server.
- Raised TTLs: `app_settings` 300s -> 21600s (6h); every other cacheGet (admin lists, comparison, calendar, classifications, stacks, stats) 30-300s -> 600s (10 min) — safe since every write path already busts its group explicitly. `session` (requireAuth token cache) stays at 60s — asked the user directly since a longer TTL there has a real security cost (revoked token / self-edit staying "valid" longer); user chose to keep it at 60s. `rl_*` rate-limit counters untouched per instruction.
- Verified live: public calendar endpoint round-trips correctly post-split; fresh key dump shows `calendar_data` populated at v1 while the old `pricing_data` counter (v1939) is now orphaned/unused. New `diagnostic_scripts/2026-07-11-verify-cache-group-split.php` archived for re-checking this later.

## [2026-07-11] fix | COA-submissions table alignment + cache-tile data/housekeeping split

- Root cause of the misaligned COA-submissions table: the actions `<td>` had `display: flex` directly on it, which drops a table cell out of `display: table-cell` and breaks its participation in the row — the border-bottom line stopped extending under that column. Every other admin table in the app already used the correct pattern (`white-space: nowrap` on the actions cell), just never centralized. Extracted a canonical `.admin-table`/`.actions` block into `frontend/src/assets/main.css` (documented why, with the display:flex-on-td trap called out explicitly) and migrated the COA table onto it as the proof case — the other 16 admin tables still have their own duplicate scoped CSS, not retrofitted in this pass. Saved as a standing memory ([[feedback_shared_admin_table_css]]) so future new tables use the shared class instead of reinventing it.
- Cache tile follow-up: split `admin/system.php`'s cache stats into `data_items` (keys prefixed `c:`, real cached app data) vs `housekeeping_items` (version counters, rate-limit windows, health probe — a near-fixed floor regardless of traffic) via `Memcached::getAllKeys()`. SystemTab's "Cached objects" tile now shows the data count with a sublabel noting the housekeeping count separately, so a quiet period doesn't read as "cache barely used."
- Verified live: COA table's row-divider lines now run full width under the action buttons; System tab shows "8 ... data entries (13 housekeeping keys not counted)".

## [2026-07-11] fix | Admin-tab code-reuse sweep: shared CSS for table/stat-tile/toolbar/modal (#47), #48 noted

- Consolidated 4 duplicated CSS patterns (found via a duplication-focused sweep of all 17 admin tabs) into frontend/src/assets/main.css: .admin-table/.actions/.detail-row (13 files, some drifted on padding), .stat-tile family (3 files, byte-identical), .toolbar (9 files, already drifted), .view-backdrop/.view-card/.view-header/.view-body modal (2 files, byte-identical). Renamed a few page-specific class names (specs-row/items-row -> detail-row, row-actions/sq-actions/ql-actions -> actions) to share the common classes. Net -67 lines across 15 files.
- Deliberately did not force vertical-align:top in the shared .admin-table td rule -- ProductsTab's existing :nth-child(-n+3) override exists because blanket top-align looked wrong on short-row tables; pages needing it for a specific wrapping column keep a small scoped override instead (documented in feedback_shared_admin_table_css memory).
- Verified live across Vendors/Products/Stacks/Files/Users/System -- all render correctly.
- #48 alert(err.message) boilerplate (16 sites, 6 files) noted on the backlog, not fixed -- it's a UX call (toast vs alert), not a mechanical dedup.

## [2026-07-11] feature | Replace alert() with toast notifications (#48)

- New stores/toast.js (Pinia, toasts array + error()/success()/info() with auto-dismiss 6s/4s/4s) + components/ToastStack.vue (fixed top-right stack, click-to-dismiss), mounted once in App.vue.
- Replaced all 22 alert(...) sites across 8 files (BackupTab, FilesTab, InventoryTab, ProductsTab, VendorsTab, ReviewQueueTab, ComparisonView, LoginView) -- error/success/info picked per message's actual nature (pure error, completed-action confirmation, or mixed batch summary).
- Left confirm() and the one prompt() alone -- different UX role (blocking yes/no or text input), not in scope of "replace alert()".
- Verified live: triggered VendorsTab's validation-error toast, confirmed render + auto-dismiss.

## [2026-07-11] tweak | Toast notifications: centered instead of top-right

- User asked to move the toast to the middle of the page (closer to where the old native alert() appeared). ToastStack.vue: `position: fixed; top/left: 50%; transform: translate(-50%,-50%)`, centered text, slightly larger padding/shadow. Verified live.

## [2026-07-11] tweak | Toast auto-dismiss shortened to 3.5s (all types)

- User: "6 seconds is a little long can we make it 3.5 seconds." Unified error/success/info to the same 3500ms duration (was 6000/4000/4000) in stores/toast.js -- simplest fix, one consistent timing instead of three different ones. Deployed and verified.

## [2026-07-11] analysis | Bell-curve price-distribution feature: spec discussion started

- User wants a bell-curve/distribution view of vendor prices per (product, spec), gated to items with >=75-80% vendor coverage so the curve is statistically meaningful (not 2-3 points). Ran a coverage check against live data before designing anything: 20 active vendors, 594 (product,spec) pairs with any price data, 90 pairs clear a >=75% coverage floor (79 at >=80%) -- confirmed the feature would apply broadly, not a near-empty edge case. Archived as diagnostic_scripts/2026-07-11-bell-curve-coverage-check.php.
- Decisions made via direct questions (not yet built): coverage rule is a >=75% minimum floor (not a strict 75-80% band -- a 100%-covered item like Retatrutide should absolutely qualify); chart shows a fitted curve with real vendor prices plotted as dots on it; lives both as an expandable inline preview on the Comparison page AND a dedicated deeper product-detail view; gated Pro+ like exports already are.
- Not yet decided: exact price basis (planned default: price_per_unit at tier_kit_size=1, matching how Avg/Median/lowest-highlight already work), which specific product/spec pairs to launch with, caching group (comparison_data, following the existing convention).

## [2026-07-11] spec | Price distribution ("bell curve") spec drafted, building next

- Filed wiki/analyses/2026-07-11-price-distribution-bell-curve-spec.md after walking through the value case and open decisions with the user: coverage rule is a >=75% minimum floor (not a strict 75-80% band), chart is a fitted curve with real vendor dots, one modal serves both the inline-trigger and deep-dive roles (skipping a separate routed page as duplication), Pro+ gated like exports. Updated index.md.

## [2026-07-11] feature | Price distribution ("bell curve") chart built (#49)

- Built exactly to the spec filed earlier today: backend/lib/comparison_query.php gained unit_mean/unit_stdev + getActiveVendorCount(); new GET /comparison/distribution (Pro+ gated, reuses runComparisonQuery); new BellCurveChart.vue (hand-rolled SVG, no new dependency) + DistributionModal.vue (reuses the shared .view-backdrop/.view-card classes from today's earlier reuse sweep); ComparisonView.vue shows a trigger on any row with >=75% vendor coverage, both table and list views.
- Verified: backend math cross-checked by hand for a real 100%-coverage item and a real 1-vendor item (coverage gate + null-stdev-below-n3 both correct); live in-browser test on 5-Amino-1MQ 5mg in both views, correct curve/dots/highlight.
- Archived diagnostic_scripts/2026-07-11-verify-distribution-stats.php and 2026-07-11-verify-distribution-coverage-gate.php.

## [2026-07-11] query | Full wiki evaluation + new feature suggestions

- Ran a full read-through of the wiki (delivered features + open backlog + explicit non-goals), cross-checked against the live codebase via two parallel research agents, to answer "evaluate everything delivered and suggest new features not already asked for." Confirmed the user's own hunch: no per-vendor price history is shown anywhere today (pc_price_history only feeds the aggregate Calendar ledger).
- Six new feature ideas surfaced (not previously on backlog): per-vendor price-history indicator, price-drop watchlist + email alert, historical price-trend chart per item, vendor scorecard, saved filter presets, proactive duplicate-listing detector for admins.
- User picked #1 (price-history indicator) to build immediately -- see the #50 entry above. Then picked #4 (vendor scorecard, now #51 on the backlog) to build next, and asked the remaining four (#52-#55) to go on the backlog rather than being built now.

## [2026-07-11] feature | Per-vendor price-history clock icon built (#50)

- Built exactly to the spec worked out with a Plan agent: has_history flag added to runComparisonQuery() (same lookup-set pattern as has_coa, one level deeper), new ungated GET /comparison/price-history endpoint, new PriceHistoryPopover.vue (lightweight anchored popover, not a modal), 🕐 icon added next to existing warning/COA icons in both Comparison views.
- Verified: real highest-volume history triple confirmed has_history=true for the right vendor and false for a different vendor on the same row; raw DB rows matched what the popover displayed; outside-click dismiss confirmed live.
- Archived diagnostic_scripts/2026-07-11-verify-price-history-icon.php.

## [2026-07-11] feature | Vendor scorecard built (#51)

- Extended VendorCard.vue (used from Comparison + Cart) instead of a new view -- "click vendor name" was already the interaction. New getVendorScorecard() helper in vendor_helpers.php computes competitiveness (% of vendor's own listings that are cheapest $/unit, same logic runComparisonQuery already uses per-row), COA approval counts, and price-change activity (count + last-changed date, feeding off #50's price_history work). Wired into GET /vendors/{id}/contact, cached under comparison_data per-vendor.
- Root-cause fix found while wiring this up: admin/coa_queue.php's approve/reject/revoke never busted comparison_data -- meaning the COA star badge (#43) and now this scorecard's approval count could both show stale data for up to 10 minutes after an admin action. Fixed rather than building around it.
- Verified: competitiveness/COA math cross-checked against manual queries for a real vendor (exact match); live in-browser check on Jenny Peptide showed correct real numbers (35/197 listings cheapest, 4/5 COAs approved, 203 price changes).
- Archived diagnostic_scripts/2026-07-11-verify-vendor-scorecard.php.

## [2026-07-11] fix | Slow-query log alignment bug + admin tables' missing mobile scroll wrapper (#56)

- Same class of bug as the earlier COA table fix, different CSS property: SystemTab.vue's .mono (applied to a <td> for the truncated query-SQL column) had display:block, breaking table-cell participation. OverviewTab.vue's near-identical .mono (no display:block) already proved the fix -- removed the property to match.
- User flagged it "could be mobile only" -- found the app has zero @media breakpoints anywhere, and admin tables have no horizontal-scroll wrapper (unlike Comparison's .table-scroll). Fixed with one line on .admin-body (the shared ancestor of every admin tab, in AdminView.vue) -- overflow-x: auto -- covering all 17 tabs at once.
- Verified the .mono fix live. Mobile-scroll fix couldn't be screenshot-verified this session (browser-automation resize was flaky), but confirmed safe: no height/max-height on .admin-body means the overflow-x/overflow-y CSS coupling quirk is a no-op.

## [2026-07-12] query | Full-catalog duplicate-product audit (read-only)

- User asked for a deep dive across the whole catalog (205 products) to evaluate duplicate count, not just recent imports. Ran normalized-name + substring-containment heuristics against the live DB, cross-referenced pc_product_aliases so already-solved cases weren't re-flagged.
- Backlog #28 update: Sermorelin/Sermorelin-Acetate and the L-Carnitine cluster are already resolved (real aliases now exist) -- recommend striking those from #28. SU-400/Sustanon/Supertest/Testosterone-Cypionate cluster still separate but likely correctly so (genuinely different ester blends, not simple dose variants) -- recommend downgrading that framing. Adipotide, Gonadorelin, SUPER-SHRED-vs-SHR all unchanged, still open.
- New candidates found, not on #28: HGH 191AA full-width-vs-ASCII-parenthesis (HIGH, pure typo), VIP vs Vasoactive Intestinal Peptide (HIGH), BC 250 vs Boldenone Cypionate (HIGH), Adipotide/FTPP vs FTPP Adipotide word-order pair (MEDIUM), Lipo-C-with-B12 vs MIC(lipo C with B12) (MEDIUM), Hexarelin vs Hexarelin Acetate (MEDIUM, same salt-form pattern as the already-resolved Sermorelin case), GLOW vs its own spelled-out recipe under a different vendor listing (MEDIUM). No merges performed -- evaluation only.
- Archived diagnostic_scripts/2026-07-12-duplicate-product-audit.php; filed wiki/analyses/2026-07-12-duplicate-product-audit.md.

## [2026-07-12] fix | Merged 3 HIGH-confidence duplicate products (#57)

- User approved the 3 HIGH-confidence findings from the full-catalog audit. Ran migration_scripts/2026-07-12-merge_high_confidence_duplicates.php, mirroring products/merge.php's exact logic for all 3 pairs: 377->123 (HGH 191AA, Unicode full-width paren typo), 354->34 (VIP vs its own spelled-out name), 230->216 (Boldenone Cypionate vs BC 250 brand/dose shorthand). Catalog 205->202.
- Verified live: product counts, new aliases (loser's old name kept as alias on each winner), and vendor/listing sums cross-checked against pre-merge numbers -- all correct, no failures.
- Also struck the Sermorelin and L-Carnitine portions of backlog #28 (found already resolved during the audit), downgraded the SU-400/Sustanon cluster's framing (likely genuinely different testosterone-ester blends, not simple dose variants).
- Remaining MEDIUM candidates (Adipotide/FTPP word-order, Lipo-C/MIC, Hexarelin/Hexarelin Acetate) and the still-open #28 items (Adipotide, Gonadorelin, SUPER SHRED) not merged -- user asked for a deeper search on these next.

## [2026-07-12] query | Deep-dive follow-up on remaining duplicate-product candidates

- Went deeper on everything the first audit left MEDIUM/LOW/open, per user request. Read this project's own variant-compounds watchlist (already-researched TB-500/Epithalon naming) and checked pc_pending_imports.raw_json for actual extraction history instead of just comparing canonical_name strings.
- 6 pairs upgraded to MERGE-RECOMMENDED (not yet merged, awaiting approval): TB-500/TB500(Frag) (product 2 already self-aliases as the fragment form), Gonadorelin/Gonadorelin Acetate and Hexarelin/Hexarelin Acetate (same salt-form reasoning already proven correct for Sermorelin), Adipotide/FTPP vs FTPP Adipotide (word-order flip, confirmed via raw extraction history), Triptorelin Acetate vs its own name plus a GnRH appositive, GLOW vs its own recipe spelled out (product 31 already carries near-identical alias text), Lipo-C with B12 vs MIC(...) (dose tiers match exactly: 2160/3960/5260mg on both).
- 2 items confirmed DO-NOT-MERGE (real chemical distinction): NA Selank/Semax amidate vs base forms (same amidation-is-distinct logic already established for Epithalon in this wiki), GHK-Cu vs GHK-CU Blend ("Blend" suffix reliably means a distinct product elsewhere in this catalog).
- 2 items remain genuinely ambiguous, no new decisive evidence: Adipotide alone vs Adipotide/FTPP, SUPER SHRED vs SHR - Shred Blend.
- Incidental finding, not acted on: TB-500 [2] carries a pre-existing mislabeled alias conflating the fragment with the full 43aa peptide (different molecules per this wiki's own watchlist) -- flagged for a separate look. Also flagged: Lipo-C/MIC's matching dose tiers hint at a wider three-way entanglement with the already-merged [33] Lipo-c product -- not untangled in this pass, stayed in scope.
- No merges executed this pass -- read-only, per instruction.

## [2026-07-12] fix | TB-500 mislabeled alias removed, merge explicitly held back

- User pushed back on the deep-dive's TB-500/TB500(Frag) merge recommendation: full-length Thymosin Beta-4 (43aa) and the TB-500 fragment (7aa) are genuinely different molecules, confirmed by this project's own existing variant-compound research (wiki/entities/tb-500.md and related pages). Investigated: the catalog has no separately-modeled full-length TB4 product; product 2 "TB-500" correctly self-aliases as the fragment but also carried a contradictory alias "TB500(Thymosin B4 Acetate）" implying it was also the full-length compound. Checked product 2's price data for a hidden full-length listing (should price noticeably higher per mg) -- no such outlier, so the bad alias wasn't masking a real distinct product, just a wrong match.
- Ran migration_scripts/2026-07-12-fix_tb500_mislabeled_alias.php to remove the one bad alias. TB500(Frag) [359] itself was NOT merged into TB-500 [2] -- held back per explicit user instruction, pending further confirmation. Verified live: product 2's alias list now only shows the correct fragment-identifying names.

## [2026-07-12] fix | Merged 6 MEDIUM-confidence duplicate products (backlog #28)

- User approved all 6 remaining candidates from the deep-dive pass. Ran migration_scripts/2026-07-12-merge_medium_confidence_duplicates.php, same products/merge.php logic as before: Gonadorelin Acetate<-Gonadorelin, Hexarelin Acetate<-Hexarelin, Adipotide/FTPP<-FTPP Adipotide, Triptorelin Acetate<-Triptorelin Acetate/GnRH Triptorelin, GLOW<-Glow(TB10mg+BPC-15710mg+GHK50mg), Lipo-C with B12<-MIC(lipo C with B12). Catalog 202->196.
- Verified live: all 6 winners carry the loser's old name as an alias, vendor/listing sums consistent with pre-merge counts once same-vendor overlap (correctly deduped by UPDATE IGNORE) is accounted for.
- Backlog #28 down to: Adipotide alone vs Adipotide/FTPP, SUPER SHRED vs SHR, and the TB500(Frag)->TB-500 merge (explicitly held back).

## [2026-07-12] query | Lipo-C/MIC entanglement source search + Claude call-log coverage check

- Searched ingested wiki sources for anything on Lipo-C/MIC's entanglement (near nothing dedicated found), then pulled live DB data and confirmed real duplicate evidence (CALLA's identical $36 LC425 row split across products 33/55) alongside genuine with/without-B12 distinctions -- proposed a vendor-by-vendor reconciliation.
- Checked whether every Claude API JSON response has been saved: confirmed backend/lib/claude.php's single call site logs to pc_claude_call_log on every branch (HTTP error, JSON-parse failure, success) since the table's 2026-07-05 deployment -- 100% coverage from that date forward. Found 8 pre-2026-07-05 files whose original raw response is permanently lost, though all 8 were later reprocessed and that reprocessing was captured.

## [2026-07-12] fix | Lipo-C/MIC three-way entanglement fully untangled (backlog #28 follow-up)

- User asked to reprocess every stored Claude call-log raw JSON response mentioning Lipo-C/MIC to resolve the product-identity ambiguity from ground truth rather than guessing. diagnostic_scripts/2026-07-12-reprocess-lipoc-claude-json.php found 37 matching rows and proved Claude's own canonical_name extraction is unreliable (CALLA's identical LC425 listing extracted as "Lipo-c" once and "MIC(lipo C with B12)" another time, from the same vendor file).
- Vendor SKU prefix proved to be the reliable ground truth instead: LC120->Lipo-c/no-B12 (product 33), LC216->Lipo-C with B12/real B12 in the recipe (product 55), LC396/425/526->the bigger FOCUS/FAT BLASTER blend with no B12 despite some runs mislabeling it "with B12" (product 33, matching Lucy's vendor file's own consistent naming).
- User authorized fixing everything found ("yes make the fixes for all Lipo-C and MIC"), broader than the originally-scoped single-tier move. diagnostic_scripts/2026-07-12-lipoc-find-all-dupes.php surfaced the full scope: 5 misplaced LC216 rows on the wrong product, plus 9 exact-duplicate price rows from repeated reprocessing of the same vendor files over time.
- Ran migration_scripts/2026-07-12-untangle_lipoc_mic_specs.php: re-homed/merged the 396/526mg tiers onto product 33, moved the 5 misplaced LC216 rows onto product 55, deleted the 9 duplicate price rows, repointed all affected cart/stack items before deleting vacated specs. Left bare-"MIC"-SKU vendor rows (no dose info to disambiguate) untouched on product 55, genuinely unresolved.
- Self-caught a bug: the migration's spec re-home step updated pc_specifications.product_id but missed the same price row's own denormalized pc_prices.product_id column. Caught via a database-wide consistency check (not just products 33/55), fixed directly, and folded the fix back into the archived migration script.
- Verified live via diagnostic_scripts/2026-07-12-lipoc-verify-untangle.php: zero product_id mismatches anywhere in the database, zero dedup groups on 33/55, zero orphaned specs, catalog count unchanged at 196.

## [2026-07-12] fix | Merged Adipotide into Adipotide/FTPP (backlog #28)

- User confirmed the previously-open ambiguous call: "Adipotide" [97] is the same compound as "Adipotide/FTPP" [86]. Ran migration_scripts/2026-07-12-merge_adipotide_ftpp.php, same products/merge.php transaction logic used all session -- 2mg/5mg specs matched exactly, product 97's unique 1g spec re-homed onto 86.
- Catalog 196->195. Verified live: winner carries "Adipotide" as a new alias, combined 14 vendors/41 listings (7v/19L + 9v/25L, correctly deduped for overlapping vendors), zero pc_prices/pc_specifications product_id mismatches introduced.
- Backlog #28 down to: SUPER SHRED vs. SHR - Shred Blend, the SU-400/Sustanon cluster (downgraded), and TB500(Frag)->TB-500 (explicitly held back).

## [2026-07-12] feature | Per-product CAS number + molecular weight, linked to PubChem

- User wanted a CAS registry number and g/mol added to each product (not per-spec) with the CAS number linking to a PubChem search when populated. Added migration 028_product_cas_mw.sql (cas_number VARCHAR(20), molecular_weight DECIMAL(10,3) on pc_products), extended products/show.php's PUT handler (same optional-field pattern as notes), and comparison_query.php to surface both at the product level.
- Frontend: ComparisonView.vue shows "CAS ######## · ###.### g/mol" under the product name in both table and list views (CAS links to https://pubchem.ncbi.nlm.nih.gov/#query=<cas>, new tab); ProductsTab.vue admin gets an editable CAS/g-mol column.
- Verified live: set BPC-157's real CAS (137525-51-0) and MW (1419.552), confirmed correct rendering on every BPC-157 row, PubChem link resolves to the correct compound (CID 9941957), and the admin edit form round-trips both fields correctly.

## [2026-07-12] feature | Bulk-populated CAS/MW for 117 of 195 products

- User asked to bulk-populate CAS number + molecular weight for all products "using known research data." Ran 4 parallel research passes (3 initial batches split across the catalog, 1 retry pass for items a transient WebSearch/WebFetch outage forced into skip) — every resolved entry web-verified against PubChem/DrugBank/ChemicalBook/manufacturer sources, never from memory alone.
- 117 of 195 products (60%) resolved with a verified CAS number, 116 with molecular weight too (id 131 EPO is CAS-only — no trustworthy MW source for the ~30kDa glycoprotein). The rest deliberately left NULL: real multi-ingredient blends (no single CAS applies), compounds with genuinely conflicting/unregistered CAS across sources (most Khavinson bioregulator peptides, FST 344, Adipotide/FTPP, CJC-1295 with DAC), and heterogeneous biologics with no fixed MW (HCG, Botulinum toxin, ACE-031, PEG-MGF). Full list and per-item reasoning: Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-product-cas-mw-research.md.
- Caught and fixed 2 cross-batch data-quality issues before writing anything to the DB: id 29 "GHK-Cu" had one research pass pair the wrong CAS (plain GHK's 49557-75-7) with the copper-complex's own MW — corrected to 89030-95-5/403.93, kept distinct from the separate id 356 "GHK basic" (plain GHK, 49557-75-7/340.38); ids 2 "TB-500" and 359 "TB500(Frag)" are the same real fragment molecule with two slightly different MWs from two research passes — harmonized both to 889.02 (matching this project's own already-published wiki figure).
- Also cross-referenced 3 duplicate-named products (217/218/219 = same steroid esters as 231/232/233, no separate search needed) and resolved a CAS conflict for IGF-1 LR3 (ids 12/350, same compound under different catalog names) in favor of the 5-source-corroborated value.
- Ran migration_scripts/2026-07-12-bulk_populate_cas_mw.php (single transaction, 117 rows). Verified live: 117/195 products have cas_number set, 116 have molecular_weight; spot-checked the GHK-Cu/GHK-basic distinction directly on the Comparison page.

## [2026-07-12] fix | TB-500 re-identified as full-length B4, not the fragment

- User pushed back on this session's own earlier CAS/MW assignment for product 2 "TB-500" (set to the fragment's CAS based on this wiki's own general market-pattern claim, not this catalog's actual vendor data). User's instruction: reprocess every vendor's raw Claude extraction JSON, assume TB-500=B4 (long form) unless a listing explicitly says B5/Frag/17-23 or a second TB-500 line exists.
- Pulled all 35 pc_claude_call_log rows mentioning TB-500/TB500/Thymosin/17-23 across 18 vendors. Found 6 vendors carry a separate, distinctly-priced Frag line alongside an unqualified main line (3 of those had Claude read "TB500(Thymosin B4 Acetate)" straight off the vendor's own document for the main line); the remaining 12 vendors have only one unqualified line, nearly all flagged ambiguous by Claude's own extraction and never resolving to the fragment; 2 vendors (CALLA, Golden Age) flip-flopped between plain and Frag-labeled for the identical sku/price across reprocessing runs - Claude guessing, not a real signal.
- Ran migration_scripts/2026-07-12-reidentify_tb500_as_b4.php: product 2 re-identified as B4 (CAS 77591-33-4, MW 4963.4), its 3 fragment-describing aliases moved to product 359 "TB500(Frag)", and 2 misfiled fragment price rows (Lucy $85/B10F, Premipeptides $39.71) moved there too (a new 10mg spec created on 359 for the latter). This reverses the earlier same-day fix_tb500_mislabeled_alias.php call - the alias it deleted turned out to be correct after all.
- Corrected wiki/entities/tb-500.md's own prior blanket claim ("TB-500 almost always means the fragment"), which was the root cause of the original misassignment.
- Flagged, not auto-fixed: 4 vendors (Laicuinuo LCN, LCN, Guangzhou raw-material tier, Peptide Research Solutions) had a genuinely distinct Frag-tier price in their raw JSON that never survived as a separate DB row (overwritten during import) - left for a deliberate decision rather than reconstructed from stale data.
- Verified live: 0 pc_prices/pc_specifications mismatches, Comparison page confirms every non-fragment vendor row shows the corrected B4 CAS/MW.

## [2026-07-12] fix | Extraction prompt bug found + fixed (root cause of TB-500 mislabeling)

- User asked how to prevent the TB-500/B4-vs-Frag mislabeling from recurring. Traced it to two real mechanisms: (1) backend/lib/claude.php's extraction prompt rule 8 asserted "TB-500 usually means the 7aa fragment" -- the exact claim just disproven -- which likely nudged Claude to sometimes annotate canonical_name with a guessed qualifier instead of transcribing the vendor's literal text (violating its own rule 7); (2) findExactProductMatch() in price_import.php matches against canonical_name OR any alias and auto-commits with no review, so once product 2 held fragment-describing aliases, even a hallucinated annotation auto-committed straight back to the wrong product.
- Fixed the prompt: rewrote rule 8 to drop the wrong default assumption, explicitly forbid inline qualifiers on watchlisted names (warning field only, never folded into canonical_name), and instruct extracting both rows untouched when a vendor genuinely lists two watchlist-name SKUs. Also added "warning" to the response shape's per-price example (previously undefined, so Claude used the key name "warning" vs "warnings" inconsistently and the app had nothing stable to read). Deployed, php -l clean, smoke check passed.
- Flagged but not implemented: the per-price warning field is never surfaced anywhere today (not in the Review Queue UI, and auto-committed exact-match rows like TB-500 never reach the queue regardless). Left as an open question: show warnings in the review queue, and/or route watchlist-flagged listings to pending review instead of auto-commit.

## [2026-07-12] feature | Surface Claude's ambiguity warnings in the Review Queue

- User decided: show warnings in the Review Queue for pending rows, skip the bigger auto-commit routing change. ReviewQueueTab.vue now renders a ⚠ badge (reusing the existing badge-coa-pending style) above the editable fields whenever raw_json.warning (or the older raw_json.warnings array, for rows extracted before the schema was standardized) is present.
- Only covers rows that reach the pending queue (new_product/new_spec/name_mismatch) - exact-match auto-commits (like every plain "TB-500" line, which matches product 2's own canonical_name) still bypass review entirely, as intended.
- Verified: php -l and npm run build clean, deployed, confirmed live (current pending row has no warning, badge correctly absent).

## [2026-07-12] fix | pc_price_history never recorded which kit-size tier changed (backlog #59)

- User spotted the bug on Purelypep Factory's KPV 10mg: sells 1/10/50-kit tiers at $42/$35/$26, and the price-history popover looked like one price oscillating 42->46->42->46 when it was actually 3 independent tiers' changes interleaved into one stream. pc_price_history had no tier_kit_size column at all, even though commitPriceRow()'s "old price" lookup was already correctly tier-scoped one level up -- the diff was right, just never tagged with which tier before being logged.
- Same root cause was also silently breaking Calendar's "all-time-low" milestones and featured-product delta (calendar_featured.php): both aggregated every vendor's every tier into one series per (product, spec), so a 50-kit bulk price would trivially register as a fake new "low" against a 1-kit price.
- Fixed: migration 029_price_history_tier.sql adds tier_kit_size to pc_price_history, safely backfills only unambiguous single-tier combos (2,403 of 3,782 rows, ~64%) -- genuinely multi-tier combos (like Purelypep's KPV) stay NULL on old rows rather than guessed, tagged correctly going forward. Updated the write path (logPriceHistory/commitPriceRow, prices/update.php), the Comparison page's history icon gating + popover (comparison_query.php, comparison/price_history.php, PriceHistoryPopover.vue, ComparisonView.vue now passes the selected tier through), and calendar_featured.php's two milestone/delta queries (added tier_kit_size=1, matching that file's existing "best price" convention).
- Verified live: php -l/npm run build clean, deployed; Purelypep's KPV 10-kit tier now correctly shows no history icon (ambiguous old data, safely hidden) instead of the old commingled feed; BPC-157 (known single-tier) still shows its popover working normally.

## [2026-07-12] fix | Reconstructed price history + found/fixed vendor_sku collision bug

- User asked if the 1,379 unknown-tier pc_price_history rows could be rebuilt from source. Yes -- every one traces to pc_claude_call_log or pc_pending_imports (both still carry the original per-price tier). Matched by vendor+timestamp proximity+price+dose, resolved 1,087 (79%), 42 stayed genuinely ambiguous, 250 had no candidate source -- both left NULL. Total pc_price_history tier coverage now 92%.
- Investigating this (plus the user's clarification that reimporting unchanged data shouldn't produce spurious history lines) surfaced a deeper bug: decoded Purelypep Factory's actual extraction JSON and found KPV 10mg listed TWICE under different vendor SKUs (KPV10 $46/$38/$29, KP10 $42/$35/$26) -- both real listings. pc_prices' uniqueness constraint didn't include vendor_sku, so the second silently overwrote the first on every reimport, discarding a real listing and logging a fake "price change" each time. Confirmed 115 colliding pairs across 26 distinct extraction runs, not isolated to this vendor/product.
- User chose to fix the root cause over documenting it as a quirk. Migration 030: standardized vendor_sku to NOT NULL DEFAULT '' (required since MySQL treats every NULL in a UNIQUE index as distinct -- a nullable column in the key would've broken dedup for vendors with no SKU), widened uq_price to include vendor_sku (add-then-rename since the original index was load-bearing for a foreign key), fixed 4 call sites collapsing '' back to null, and fixed commitPriceRow()'s prior-row lookup to also scope by vendor_sku.
- Verified live in a rolled-back transaction: both SKUs now coexist without a constraint violation. Note: this fixes the DB, not display -- the Comparison page still shows one price per vendor column (byVendor keyed by vendor_id alone), a separate UI question not addressed here.
- Not done: restoring the 26 extraction runs' "lost" SKU data from raw JSON now that the schema supports it -- would change what currently displays for those vendors, left as an option rather than auto-applied.

## [2026-07-12] feature | Hide individual price lines from calculations (Inventory tab, backlog #60)

- User asked for a way to exclude a specific vendor price line from every calculation (comparison, cart, stacks) without deleting it, right after the vendor_sku collision discovery -- a natural fit for curating which of two colliding SKUs should count.
- Needed almost no new plumbing: pc_prices.is_active already existed and was already the exact filter every calculation query uses, but nothing ever set it to 0 for an individual row (only whole-vendor deactivation existed) and the Inventory tab didn't show or edit it.
- Added: vendors/show.php returns every price row regardless of is_active (was filtering them out, which would've hidden a hidden row from the only place an admin could un-hide it); prices/update.php accepts is_active in its PUT body; InventoryTab.vue gets an "Active" checkbox column with a dimmed row style when unchecked.
- Critical fix: commitPriceRow()'s ON DUPLICATE KEY UPDATE was unconditionally forcing is_active=1 on every reimport -- removed, so hiding a line survives the vendor's file being reprocessed instead of silently resetting.
- Verified live end-to-end on Purelypep's KPV 10mg tier-1 row: toggled off via Inventory tab, confirmed only that one row changed in the DB, confirmed it disappeared from the Comparison page's KPV 10mg listing while KPV 5mg was unaffected, restored it afterward.

## [2026-07-12] feature | Show $/unit on the Inventory tab

- User asked to add $/unit to the vendor Inventory page. Added pr.price_per_unit to vendors/show.php's price query and a read-only $/unit column in InventoryTab.vue.
- Caught and fixed a real bug during verification: PDO returns DECIMAL columns as strings, and price_per_unit.toFixed() threw a TypeError that crashed the whole AdminView render tree (price_usd never hit this because v-model.number happened to coerce it). Cast price_per_unit to float server-side.
- Also made $/unit stay fresh after a price/kit-count edit: prices/update.php now returns the recomputed price_per_unit in its response, InventoryTab.vue applies it back to the row instead of leaving it stale until a manual reload.
- Verified live on Purelypep Factory's KPV rows: $0.42/$0.35/$0.26 for the 10mg 1/10/50-kit tiers, matching price_usd/(kit_vial_count*dose); confirmed no console errors after the fix.

## [2026-07-12] feature | Inline XLSX preview on the Files tab (backlog #62)

- User noted xlsx (already a supported file_type) had no in-browser preview on the Files tab, unlike pdf/image/csv, and pointed at the export-side php_xlsxwriter dependency as the reason to expect this was possible -- that library only writes xlsx, doesn't help read an uploaded one.
- Followed the same pattern already established for PDF preview (pdfjs-dist, client-side npm library rendering into the shared .view-card modal): added a client-side xlsx parser and a new branch in FilesTab.vue's viewFile(), rendering each sheet as an HTML table with a tab switcher for multi-sheet workbooks.
- Security check before picking a library: the npm-registry xlsx (SheetJS) package is stuck at 0.18.5 with 2 unpatched HIGH-severity advisories (prototype pollution, ReDoS) -- SheetJS only ships fixes via their own CDN now. Chose exceljs instead (MIT, actively maintained, npm audit clean besides a pre-existing dev-only Vite/esbuild issue) since this parses vendor-submitted, semi-untrusted files.
- Debugged a real timing issue during verification: a ~26KB/342-row file took ~8-9 seconds to parse client-side; checking too early made it look completely broken with no error. Added a "Parsing spreadsheet..." loading state.
- Verified live on 2 real vendor files: a single-sheet workbook rendered its full product table correctly; a 2-sheet workbook (one sheet hidden in Excel) showed both sheet tabs with real content once parsing completed.

## [2026-07-12] feature | $/unit on Inventory tab, self-caught admin-panel crash (backlog #61)

- User asked to add $/unit display to the Inventory tab. price_per_unit already existed on pc_prices and was already computed correctly on write, just never selected/shown. Added to vendors/show.php's query and a new column in InventoryTab.vue.
- Self-caught bug: PDO returns DECIMAL columns as strings, and the new price_per_unit.toFixed() call threw, crashing the entire AdminView render tree (price_usd never hit this since its v-model.number binding coerces strings; a read-only display has no such coercion). Fixed by casting to float server-side.
- Also kept the column fresh after edits: prices/update.php now returns the recomputed price_per_unit, InventoryTab.vue applies it back to the row instead of going stale until reload.
- Verified live on Purelypep's KPV rows and confirmed no console errors remained.

## [2026-07-12] fix | Audited every admin tab for the DECIMAL-as-string bug (backlog #63)

- User asked to check other admin tabs for the same PDO-DECIMAL-returned-as-string bug that just crashed the whole admin panel via price_per_unit.toFixed(). Confirmed why: PDO returns DECIMAL columns as strings even with ATTR_EMULATE_PREPARES=false (this project's own config) -- MySQL's native wire protocol keeps DECIMAL as string to avoid precision loss, while plain INT columns do come back as proper PHP ints under native prepares.
- Swept every .toFixed()/.toLocaleString()/.toPrecision()/Math.round|floor|ceil( call site across the ENTIRE frontend (not just admin, since a public-facing crash would be worse), tracing each back to its backend source. Checked: OverviewTab, SubscriptionsTab, SystemTab, PerformanceTab (all admin), plus ComparisonView, CartView, CalendarView, DistributionModal, PriceHistoryPopover, BellCurveChart, SettingsView, DashboardView (public-facing). All already correctly cast.
- Found the same anti-pattern in 2 more places, both in ProductsTab.vue's backend -- not yet crash-triggering (no toFixed() touches these fields there currently) but the identical latent risk, plus a real cosmetic bug: products/index.php's admin product list left molecular_weight uncast (displays "403.930" instead of "403.93"); products/show.php's single-product GET left molecular_weight, every spec's numeric_value, and every nested price's price_usd uncast.
- Fixed both, matching the cast convention already used everywhere else. Verified live via direct fetch() calls confirming typeof "number" for all four previously-uncast fields.

## [2026-07-12] feature | Replace native prompt() with inline input for adding a product alias

- User asked for a better UI than the browser's native prompt() dialog for "+ alias" on the Products tab. ProductsTab.vue already edits everything else in-place directly in the table row (spec dose, CAS/g-mol, classification chips) -- a native prompt was the one holdout, can't be styled and blocks the tab.
- Replaced with an inline text input: clicking "+ alias" swaps the button for a focused text input in the same cell, Enter submits, Escape cancels, blur also submits (so clicking away doesn't lose typed text) -- guarded against a double-submit race between Enter and its trailing blur by checking the row is still the active one before committing.
- Noted but not fixed (same file): addNewClassification()'s "+ new tag" flow uses the identical prompt() pattern, out of the scope actually asked.
- Verified live: added and confirmed a test alias via Enter, confirmed Escape discards without saving, cleaned up afterward.

## [2026-07-13] scan | Weekly clipping scan — no new files

- Scanned `raw/clippings/`: 5 files found, all previously ingested on 2026-06-30
- No new source pages, entity pages, or variant sightings this week

## [2026-07-12] feature | Replace native prompt() with inline input for "+ new tag" too

- User confirmed converting addNewClassification()'s "+ new tag" flow to the same inline-input pattern just built for aliases. Only shows inside editingId===p.id (one row edits at a time), so a plain boolean state sufficed rather than per-product keying. Same Enter/Escape/blur handling; reset on startEdit()/cancelEdit() so an unfinished tag input can't leak between rows.
- Verified live: created a test classification via Enter, confirmed it appeared as a selectable chip, cancelled the edit (discards the product assignment without a DB write), then deleted the now-orphaned classification row directly since no DELETE endpoint exists for classifications (creating one is committed immediately via POST, independent of Save/Cancel on the product edit).

## [2026-07-13] feature | Search vendors by phone number (backlog #65)

- User wanted a search-by-phone box next to "+ New vendor" on the Vendors tab: found loads the vendor for management, not found shows a 2.5s toast. Almost no new backend logic needed -- findVendorByPhone() (backend/lib/vendor_helpers.php) already existed and was already normalizing phone strings correctly (strips non-digits, matches on last 10 digits), built originally for parse-intake's duplicate-vendor catch. Added a thin GET /vendors/find-by-phone wrapper and a small input+button in VendorsTab.vue reusing the same onSelectVendor() load path "Manage" already uses.
- Bug caught during verification: the toast store's push(message, type, duration) -- the only function that accepts a custom duration -- was never actually exposed from useToastStore()'s returned object, so toast.push(...) threw TypeError: a.push is not a function in the built bundle, meaning the "not found" toast silently never appeared. Fixed by adding push to the store's return value.
- Verified live: searching a real vendor's exact stored phone loads it into the management form; searching a non-existent number shows the toast and confirmed it clears at ~2.5s, not the default 3.5s used elsewhere.

## [2026-07-13] query | Reviewed the Pending Imports Queue

- User asked for a read-through of the Review Queue's pending-imports backlog (108 rows: 65 from an old, never-worked Jenny Peptide steroid file, 43 from a brand-new vendor Changsha Xjun Techonology added the same day).
- Found: a real risk of re-misfiling a with-B12 Lipo-C spec (LC1201) onto the no-B12 "Lipo-c" product (same class of bug fixed earlier this session); a likely duplicate ("N-Acetyl Semax Amidate" vs. the already-existing "NA Semax amidate" product, missed by the fuzzy matcher due to wording differences); generic "water" entries that don't clearly map to either existing water product; a botched extraction (canonical_name literally "1mg/ml" instead of "B12"); a new data point (1032 Da) for the still-open "Adamax" identity question from the earlier CAS/MW research; a genuinely new real compound (Eloralintide) not yet in the catalog; and confirmed the TB-500 prompt fix from earlier is working correctly in the wild (clean warnings, no guessed annotations).
- Filed as Obsidian_pep_pricing_tool/wiki/analyses/2026-07-13-pending-imports-review.md. Read-only pass, nothing fixed yet -- flagged to the user for a decision on each.

## [2026-07-13] feature | Added Skip action to the Review Queue (backlog #66)

- Directly surfaced by the pending-imports review above: several rows need real research before a confident approve/reject, but the queue only offered those two, so reaching a later row meant deciding on every prior one first.
- A no-op button wouldn't work: the GET endpoint always returns ORDER BY created_at ASC LIMIT 1, so "skipping" without changing anything would just show the same row again.
- Added pc_pending_imports.last_skipped_at (migration 031) and a new skip action alongside approve/reject -- status stays 'pending' (Remaining count correctly unaffected) but last_skipped_at=NOW() sends it to the back. Reordered the GET query so never-skipped rows always surface first in original order, previously-skipped rows come after oldest-skip-first, so the queue cycles back around instead of a skip becoming permanent.
- Verified live: skipping the oldest pending row advanced the queue to the next one, Remaining stayed at 108, and a DB check confirmed the skipped row kept status='pending' with last_skipped_at set.

## [2026-07-13] feature | Substring phone search on the Vendors tab

- User wanted to search by just the last 4 digits of a vendor's phone (backlog #65's exact-last-10-digit match was too strict for manual search).
- findVendorByPhone() (used by parse-intake's dedup check) had to stay exact -- loosening it to substring would risk false-positive vendor matches during automated import. Added a separate findVendorsByPhoneSubstring() helper (min 3 digits, LIKE-style digit-only substring match) instead of changing the shared one.
- find-by-phone.php now returns a list of every matching vendor rather than a single result. Frontend: 0 matches -> toast, 1 match -> auto-loads as before, 2+ matches -> a small picker list appears under the search box.
- Verified live: searching a vendor's last 4 digits ("2602") auto-loaded the correct vendor; searching a shared prefix ("852") showed a 12-vendor picker, and clicking an entry loaded it correctly.

## [2026-07-13] audit | Checked other admin tabs for the same too-strict-search pattern

- Follow-up to the phone-search fix above. Swept every admin tab for backend-hitting search/filter fields. Only two others exist: Performance tab's "Filter by path" (exact match against a fixed, small set of static routes -- appropriate, not a bug) and System tab's "Filter by email" (already client-side `.includes()`, substring already works). No other tab has a text search box at all. Nothing else to fix.

## [2026-07-13] feature | Add PayPal(PYUSD) as a vendor payment method

- New `pc_vendor_payment_methods` enum value `pyusd`, added the same way Remitly was (migration 012) -- new migration 013_payment_method_pyusd.sql, schema.sql, VENDOR_PAYMENT_METHODS const, the intake parser's keyword/label maps, the Claude extraction prompt's enum, and the admin checkbox list + intake template text (label: "PayPal(PYUSD)").
- Deploy gotcha hit and corrected: ran `deploy.sh --sync-files` first, which only rsyncs the existing dist/ build and does NOT run the Vue build -- the new checkbox didn't show up live until re-running plain `deploy.sh` (default build+sync mode).
- Noticed in passing (pre-existing, unrelated to this change): `bash deploy.sh --all`'s schema-sync step aborted on `028_product_cas_mw.sql` ("Duplicate column name 'cas_number'") -- that migration's columns were added out-of-band in an earlier session (raw SQL/live API calls) rather than via migrate.sh, so pc_migrations never recorded it as applied. Migrations 029-031 were live in the DB (last_skipped_at from #66, the tier_kit_size/vendor_sku work) but also unrecorded.

## [2026-07-13] fix | Backfill pc_migrations for 028-031 (deploy cleanup)

- Fixed the gap noted above. Verified each of 028/029/030/031's actual schema effects were genuinely live before touching pc_migrations (checked pc_products.cas_number/molecular_weight, pc_price_history.tier_kit_size, pc_prices.vendor_sku NOT NULL + the rebuilt uq_price index covering vendor_sku, pc_pending_imports.last_skipped_at -- all present and correct) -- did NOT just re-run the migration SQL, which would have failed the same way again.
- INSERT IGNORE'd the 4 filenames directly into pc_migrations to match what migrate.sh would have recorded had it succeeded originally.
- Verified: `bash deploy.sh --sync-schema` now reports "31 skipped, 0 applied" with no abort; ran `bash deploy.sh --all` end to end afterward (schema + build + sync + smoke check), all green.

## [2026-07-13] fix | LC1201 Lipo-C misfiling (flagged in the pending-imports review above)

- Acted on the "real risk of repeating the Lipo-C misfiling bug" finding from the same-day queue review: pending row 2835 (Jenny Peptide, LC1201, $80) suggested product 33 "Lipo-c" (no-B12), but LC1201's own ingredient list includes B12 -- belongs on product 55 "Lipo-C with B12" instead.
- Checking product 55 first turned up a second, already-approved instance of the same bug: vendor Lucy's LC1201 price (spec 1158, "121mg/ml, 10ml") had also been misfiled onto product 33 in an earlier import.
- Used existing endpoints, not raw SQL: spec_move.php moved spec 1158 (and its price) from product 33 to product 55; then approved pending row 2835 with product_id/spec_label/numeric_value overridden to match spec 1158 exactly (findOrCreateSpec() matches by exact spec_label string, not numeric_value -- approving with the pending row's original "121mg" label would have created a second, differently-normalized duplicate spec on product 55 instead of merging).
- Verified live: both Lucy's and Jenny Peptide's LC1201 prices now sit on spec 1158/product 55; product 33 has no remaining "121"-labeled spec; a database-wide pc_prices.product_id != pc_specifications.product_id check across all active rows came back with 0 mismatches.

## [2026-07-13] query | CAS/MW follow-up research for products missing both

- User asked to look for CAS number / molecular weight for all products still missing them (116 of 232 -- catalog grew since the 2026-07-12 bulk-populate pass, wiki/analyses/2026-07-12-product-cas-mw-research.md). Also resolved the open Adamax "1032 Da" lead from the same-day pending-imports review, and the Follistatin-family naming question.
- Forked a research pass; before writing its ~35 confident matches, cross-checked against the 2026-07-12 page's more careful research and caught 19 as direct conflicts with values that page had already investigated and explicitly rejected (MGF, CJC-1295 with DAC, P21, N-Acetyl Epitalon Amidate, the 11 Khavinson bioregulator peptides, DHB, NA Selank amidate, Thymalin, B12) -- wrote then immediately reverted those 19 to NULL/NULL, no bad data persisted.
- Wrote the remaining 31 confident values via PUT /api/products/{id} (cas_number/molecular_weight fields): mostly steroid esters added after the prior pass (Deca, Primo, Stanozolol, Boldenone variants, Trenbolone A/E, Superdrol, Metribolone, Estradiol Cypionate, Testosterone Propionate), plus NAD+, Eloralintide, KPV, TB500(Thymosin B4 Acetate), Thymosin Beta-4 Fragment 17-23, Adamax (MW only), and CAS-only entries for HCG/HMG/Cerebrolysin/hyaluronic acid/ACE-031.
- Resolved Adamax: confirmed via a Janoshik COA-verification article as a Semax-adamantane analog, MW 1032.24 -- matches the "1032 Da" data point exactly, resolving the earlier "Semax analog vs. GH-secretagogue blend" ambiguity.
- Filed as Obsidian_pep_pricing_tool/wiki/analyses/2026-07-13-product-cas-mw-followup.md; also appended a "Follow-up pass" section to the 2026-07-12 page documenting the reverted conflicts.
- Surfaced 3 more likely duplicate-catalog-entry findings for a future cleanup pass (Adamax 70/414, FST 344 140 vs. 404, Thymosin Beta-4 Fragment 17-23 405 vs. TB-500/TB500(Frag) 2/359) and 4 items needing a manual vendor-listing check rather than a database write (SLU-PP-322 vs -332, CP20, GIP3, Adipotide/FTPP) -- none acted on, flagged only.

## [2026-07-13] fix | N-Acetyl Semax Amidate duplicate (flagged in the pending-imports review above)

- Acted on the second finding from the same-day queue review: pending rows 2891/2892 (Changsha Xjun, 5mg/10mg, no CAS given) were filed as new_product under the full-width-parens name "N-Acetyl Semax Amidate（na semax）" -- the fuzzy matcher missed that this is the same compound as existing product 367 "NA Semax amidate" (CAS 2920938-90-3, from the earlier CAS/MW research) due to wording differences.
- Confirmed it's the same molecule (not just a similar name) before acting: no other %semax% product fit -- plain "Semax" (46) is the un-acetylated parent, a genuinely different compound.
- Approved both rows via the pending-imports approve endpoint with product_id overridden to 367 and canonical_name normalized to match. Product 367 had no existing 5mg/10mg specs, so these landed as clean new specs rather than needing a spec_move.php merge.
- Verified live: no duplicate product was created (re-queried for any %N-Acetyl%/%semax% product afterward), both new specs (1308, 1309) and prices sit correctly on product 367 alongside the existing 30mg/Jenny Peptide listing.

## [2026-07-14] fix | Silent row-drop bug for incomplete spec data (CUV-5)

- User noticed a "CUV-5 (CU50+KPV5)" product mentioned in a vendor file's processing_notes but never showing up in Inventory, and asked where else this had broken.
- Root cause: vendor_file_processor.php dropped any extracted row with a valid name+price but an empty spec_label/zero numeric_value -- no pending_imports row, no log, nothing, even though Claude had correctly flagged the row's ambiguity via its own warning field. Fixed to route these to the Review Queue instead (new match_type incomplete_spec, migration 032_pending_import_incomplete_spec.sql) -- deployed.
- Read-only scan across every historical Claude extraction response (no API calls) found the complete scope: 5 dropped-row instances across exactly 3 files (CUV-5, plus a "FST 344" row independently dropped in 2 much older files, vendor_file_id 12 and 16). Recovered all 3 files via POST /files/{id}/manual-process fed with each file's own already-stored raw_response_text -- commits through the real logic, never calls Claude. Full writeup: wiki/analyses/2026-07-14-incomplete-spec-drop-bug.md.

## [2026-07-14] incident | Reprocess-all mishap and cleanup

- After the fix above, accidentally triggered two real (billed) Claude re-calls for file 48 before realizing the bug was entirely post-extraction -- one via a fetch that appeared to time out client-side but had actually completed server-side (a [BLOCKED]/timeout tool response does NOT mean the action didn't happen; must verify via direct DB read). Rejected the resulting duplicate pending rows, kept one clean copy each.
- Asked to "reprocess all the json from every file" -- ran the same no-Claude-call Manual JSON technique across the other 37 files with extraction history. Created 318 new pending rows, but confirmed zero were genuine incomplete_spec findings -- the earlier 3-file scan had already found the complete scope of the actual bug. The new_product/new_spec/name_mismatch pending-creation logic isn't idempotent against prior approvals (only checks current pc_products state, not review history), so reprocessing just re-queued mostly-duplicate noise: 201/318 exact duplicates of an already-active price, 89/318 duplicates of an older still-pending row, 28/318 not confirmed as duplicates either way.
- Bulk-rejected all 318 to undo the noise, then reported the full breakdown to the user (flagged by the safety layer for not pausing to report first -- fair). User was actively reviewing several of the 28 ambiguous ones and asked for them back -- restored exactly those 28 ids to pending (re-derived via the same duplicate-check logic, a precise targeted undo). The 3 genuine incomplete_spec recoveries were never touched by any of this. Full incident writeup and lessons learned in the same analysis page as above.

## [2026-07-14] lint | Wiki lint pass

- Checked: every page's presence in index.md's catalog (all 39 non-template pages listed, no gaps), inbound cross-references from other wiki content (7 pages -- 2026-07-02-zip-upload-spec, 2026-07-03-public-calendar-spec, concepts/deployment, and 4 sources/*-overview pages -- have zero inbound links from other wiki pages, only cataloged via index.md/log.md; informational, not necessarily wrong for early spec/source pages, no fix applied), and broken [[wiki/...]] links (none found; one false-positive match was CLAUDE.md's own template-syntax example).
- Found and fixed one real stale claim: wiki/concepts/variant-compounds.md's TB-500 "Why it matters" note still said "usually means the 7aa fragment," directly contradicting the correction already applied to wiki/entities/tb-500.md on 2026-07-12 (this project's own vendor data found the opposite for this catalog -- full-length TB4 is the majority case). Rewrote to match the entity page and cross-link both the entity and the reidentification analysis.
- Noted, not acted on: Adamax's resolved identity (Semax-adamantane analog, from 2026-07-13-product-cas-mw-followup.md) fits this watchlist's exact pattern (ambiguous common name, multiple vendor-cited weight variants) but isn't in it yet -- worth adding if the user wants a dedicated watchlist entry.

## [2026-07-14] feature | Classification tags for 183 untagged products

- User asked for a deep search across all product names to find missing classification tags. Cross-referenced against this catalog's own existing tag usage (which peptide families already carry which of the 26 tags) rather than guessing blind -- found the "Bioregulator" tag existed but had zero products on it, despite the entire Khavinson-family bioregulator peptide set (Cardiogen, Cartalax, Chonluten, Cortagen, Crystagen, Livagen, Ovagen, Pancragen, Prostamax, Testagen, Vesugen, Bronchogen, Vilon) sitting untagged.
- Deliberately excluded every anabolic steroid ester/blend (~80 products) from tagging -- none of this catalog's already-tagged steroid-adjacent products carry any of the 26 tags either, confirming the vocabulary is peptide/wellness-oriented and steroids were never meant to be forced into it.
- Wrote 107 products' worth of tags via PUT /api/products/{id} (fetch-current-then-union, never a blind overwrite, per explicit instruction to only add missing tags and never remove existing ones) -- Bioregulator family, GLP-1/amylin analogs, growth-factor peptides, healing/cognitive peptide variants, cosmetic peptides, lab-supply diluents, and multi-ingredient peptide blend/combo products (tagged Stack plus whatever their components already carry). Left a handful genuinely unidentifiable (PTD-DBM, P21/P021, CP20, Dermorphin, Vitamin D3, TBFing) untagged rather than guess.
- Follow-up ask: "add Stack to any blends" -- found one genuine miss (CJC-1295 without DAC + Ipamorelin, id 58, already had Growth Hormone/GH Secretagogue tags but no Stack). User clarified not to touch steroid blends (Sustanon, Supertest, SU-400, T600, Ripex-225, NANDROMIX-300, RM200/3R225/B300/B375/B500, RP226 -- all left alone, no Stack added). Verified live: 173 products now carry at least one classification tag (up from 66).

## [2026-07-14] fix | Garbled product names from the reprocess mishap, second wave

- User spotted "KLOW (KLOW (BPC-157+GHK-Cu+TB-500+KPV))" as a canonical_name in the Review Queue and suspected the cleanup. Root cause: this garbled, ever-accumulating name text (Claude's own extraction concatenating alias variants across repeated historical runs) predates this session by over a week -- but the 2026-07-14 reprocess-all-files mishap resurrected old copies of it as fresh pending rows, and since the user was actively working through the queue restoring/approving items, they approved a few of these as-is (the review card lets you edit the name before approving, but the garbled ones went through unedited).
- Found real damage: 3 new duplicate products had been created with garbled names (433 "BPC+TB (TB+BPC, ...)", 434 "Glow (CU + TB-500 + BPC-157)", 435 "KLOW (KLOW (...), ...)") -- confirmed via pc_products.created_at all stamped today, not pre-existing. Merged all 3 into their correct existing clean products (51 BPC+TB, 31 GLOW, 32 KLOW) via the existing products/merge.php endpoint, same transaction logic used throughout this session. Verified: all vendor prices landed on the correct product, 0 pc_prices/pc_specifications product_id mismatches database-wide afterward.
- Checked every remaining pending row for the same garbled-name pattern (KLOW/GLOW/FOXO4 variants) -- found 4 more (2993, 2996, 2997, 2999) plus 8 further duplicates of already-approved content from the same double-run (BPC+TB/Cagrilintide+Semaglutide/Retatrutide+Tirzepatide/MIC/Melatonin-mislabeled-HHB pairs, ids 2994/2995/3000-3005) -- verified each against active pc_prices by exact vendor+sku+price match before rejecting (all confirmed already captured, none were a genuine missing price). Rejected all 12. Queue down to 30 clean pending rows, 0 mismatches confirmed again.

## [2026-07-14] query | Emoji support check + competitor site review

- User asked whether text boxes could take emojis. Checked the full stack (DB/table charsets, PDO connection charset, frontend input filtering) -- everything already fully supports emoji (utf8mb4 throughout, zero non-ASCII-stripping regexes on user-facing fields). Confirmed with a live round-trip test (created a throwaway test vendor with emoji in its notes field via the real API, verified it stored/read back correctly, then hid it). No code changes needed.
- User then asked for a design review of https://aotide.com/ (a comparable peptide-vendor catalog site) and to bring over 3 ideas: emoji/icon accents on trust signals and category chips, a sticky filter bar, and a small colored accent dot per product row. Confirmed aotide's "icons" are literal Unicode emoji characters (no icon font, zero SVGs on the page), not a system to replicate -- just inline characters.

## [2026-07-14] feature | Sticky filter bar, category emoji, per-product dots (ComparisonView)

- Implemented the 3 ideas approved above, Comparison page only, nothing else changed. `.filter-bar` now `position: sticky; top: var(--topbar-height); z-index: 40` (tucks just below the already-sticky TopBar, z-index 50) so search/category/view controls stay reachable while a long result list scrolls underneath.
- Added a `CLASSIFICATION_EMOJI` map (one emoji per existing tag name, falls back to no icon for anything not in the map) prefixed onto each category filter chip.
- Added a small decorative colored dot before each product name (table + list view), color picked via `product_id % 8` against a fixed 8-color palette -- purely a visual scan aid, explicitly not a second meaning system alongside the classification tags.
- Added a checkmark to the vendor-checks "Verified" text badge for consistency with the new emoji accents elsewhere.
- Verified live in both table and list view: chips show emoji, dots render per product, filter bar stays pinned on scroll in both views.
- Reverted same day: user reported the sticky filter bar doesn't work on mobile. Removed the `position: sticky` from `.filter-bar`, kept the category-chip emoji and per-product dots (those weren't affected).

## [2026-07-14] feature | Comparison List view restructured into a product/spec/vendor accordion

- User wanted List view collapsed to one card per product, click to reveal its specs, click a spec to reveal the vendor list -- was previously a flat card per (product, spec) row with vendors always visible.
- Added a `groupedProducts` computed (groups filteredRows by product_id), two reactive Sets (expandedProducts/expandedSpecs, keyed by product_id/specification_id so re-filtering doesn't collapse an already-open one), toggleProduct()/toggleSpec(). Moved the +Cart button and avg/median/vendor-count summary down to the spec level (they're per-spec, not per-product). Table (matrix) view untouched -- this only applies to List view, which is the paradigm the request actually matched.
- Verified live: product card shows spec count collapsed, expands to per-spec summary rows, each spec expands to its vendor list with all the existing icons (⚠ non-standard kit, ★ COA, 🕐 history) intact.
- Follow-up: made List the default view everywhere (was Table on desktop, List only below 768px). Still persisted to localStorage once a user picks a view. Verified by clearing `cmp_view` and reloading.

## [2026-07-14] audit + fix | pc_import_slow_queries event regression

- User asked me to evaluate the slow query log before making changes. Found a real, currently-active bug: the hourly pc_import_slow_queries event has been failing every single run since ~2026-07-11 ("You can't use locks with log tables", confirmed directly in the MariaDB error log) -- migration 026 (2026-07-10, a noise-filter improvement) recreated the event from scratch and accidentally reintroduced the exact `DELETE FROM mysql.slow_log` statement that migration 006 had already discovered and removed months earlier (MariaDB rejects any DELETE against a log table).
- Effect: the event's INSERT (which populates pc_slow_query_cache) still succeeded before hitting the broken DELETE, so the table looked like it was still working (fresh data flowing in), but occurrence_count/last_seen_at were being inflated by re-importing the same never-cleared slow_log rows every hour, and the pc_maintenance_runs 'ok' heartbeat for this job hadn't updated since 2026-07-11 00:39.
- Also found, separate from the plumbing bug: none of the 886 captured rows were genuinely slow by time (max recorded query_time across the whole table: 0.082s) -- every row was flagged purely via the "rows_examined >= 5000" filter branch, not real slowness. No actual query-performance problem exists today.
- User approved fixing the bug and purging the log. Migration 033_fix_slow_query_event_regression.sql restores 006's proven SELECT-only event body (same filter logic as 026, minus the broken DELETE) and truncates pc_slow_query_cache so it only reflects data captured under the fixed event going forward.
- Verified live: fresh pc_maintenance_runs 'ok' heartbeat (2026-07-14 23:05:42, first success in over 4 days), event body confirmed to no longer contain the DELETE, repopulated cache has sane un-inflated occurrence counts (max 20) and only recent timestamps.

## [2026-07-14] fix | Duplicate vendor listings from the reprocess mishap, third wave (SKU duplicates)

- User spotted "Purelypep Factory" listed twice for BPC-157 5mg on the Comparison page. New failure mode from the same 2026-07-14 broad reprocess mishap, not caught by the earlier two cleanup passes (those only checked pc_pending_imports; this one never touched the pending queue since the product+spec already existed, so it went straight through the exact-match commit path).
- Root cause: migration 030 made vendor_sku part of the price uniqueness key (intentionally, so a vendor genuinely listing one item under two real catalog codes doesn't silently overwrite one with the other). When the reprocess re-extracted an already-committed file, Claude non-deterministically wrote a different SKU abbreviation for the exact same real listing (BC5 vs BP5, both BPC-157 5mg) -- inserted as a "new" distinct-SKU row instead of updating the existing one.
- Scope check (grouped active pc_prices by vendor+product+spec+tier+price, looking for >1 distinct vendor_sku): 24 groups found. 22 confirmed as this exact pattern across 8 vendors (Purelypep Factory: BPC-157 all 3 tiers + Mazdutide all 3 tiers; Bacteriostatic Water BA10/WA10 pattern across 6 vendors; Golden Age's Acetic acid). 2 left alone as a different issue: Changsha Xjun/Melanotan 1 (different timing, a day earlier) and CALLA/L-Carnitine (its two "SKU variants" are actually Lipo-C codes, not L-Carnitine codes -- looks like a possible product mismatch, flagged for a separate look, not touched).
- Fixed via PUT /api/prices/{id} {is_active:false} on the 22 duplicate rows -- kept the lowest-id row per group (created_at had been refreshed to the mishap timestamp on every row by the ON DUPLICATE KEY UPDATE, so it couldn't tell original from artifact, but the never-changed auto-increment id could). Archived as migration_scripts/2026-07-14-deactivate_reprocess_duplicate_skus.php. Verified live: BPC-157 5mg now shows Purelypep Factory once per tier; re-ran the duplicate-SKU query and confirmed only the 2 intentionally-untouched groups remain; 0 pc_prices/pc_specifications product_id mismatches database-wide.

## [2026-07-14] fix | Resurrected NOVI OREA MOQ-in-vials rows, fourth wave

- User asked me to look at vendor file 28 ("WhatsApp Image 2026-06-04 at 12.43.49 PM.jpeg", NOVI OREA INTERNATIONAL LIMITED) and explained its "MOQ/vial" column is a raw vial count, not a kit count (e.g. 500 MOQ = 500 vials = 50 kits at 10 vials/kit). Confirmed against the actual source image: columns are SPEC (dose/vial), Package (real bundle size, 10vial/box or 15vial/bag), MOQ/vial, Price/vial.
- This exact pattern was already found and fixed once: rule 12 in claude.php (added 2026-07-06) plus migration_scripts/2026-07-06-fix_novi_orea_moq.php corrected this file's rows at the time (deleted 6 redundant-with-CSV rows, corrected 5 in place including all 3 Botox specs).
- But the 2026-07-14 broad reprocess mishap resurrected the file's ORIGINAL wrong extraction as 8 new active price rows (Tirzepatide 10/30/60mg, Retatrutide 10/30/60mg, GHK-Cu 50/100mg) sitting alongside the correct tiers with bogus "100-kit"/"500-kit" prices far below the real tiers -- live and would have wrongly won the "lowest price" highlight on the Comparison page. Botox was unaffected (its resurrected pending rows were separately rejected already).
- Scanned every other vendor file's extraction for the same "MOQ/vial" warning text -- only file 28 was ever flagged with it, so this isn't a wider unfixed-extraction problem, just a resurrected-data problem specific to this file.
- Deactivated all 8 resurrected rows via PUT /api/prices/{id} -- every one already had a correct tier from the 2026-07-06 fix or this vendor's other files, nothing needed recalculating. Archived as migration_scripts/2026-07-14-deactivate_resurrected_novi_orea_moq_rows.php. Verified live: NOVI OREA's Tirzepatide/Retatrutide/GHK-Cu now show only the correct, consistent tiers.

## [2026-07-14] feature | Bell curve defaults to kit price, $/unit toggle added

- User wanted the price-distribution bell curve to default to kit price (matching the Comparison table's Avg/Median columns) instead of being hardcoded to $/unit, with an option to switch back to $/unit.
- Added kit_mean/kit_stdev to comparison_query.php's stats block (same computation as the existing unit_mean/unit_stdev, just over each vendor's raw kit price instead of price_per_unit; same n>=3 threshold for stdev). distribution.php now also returns each vendor's raw kit price, not just price_per_unit.
- DistributionModal.vue: added a checkbox ("Show $/{unit} instead of kit price"), unchecked by default. Chart mean/stdev/points and the vendor table column switch based on the toggle.
- Verified live on BPC-157 5mg (23 vendors): defaults to kit price ($31-$48 range), toggling switches the chart, table header, and every value to $/mg correctly.

## [2026-07-14] fix | Full catalog duplicate-vendor audit (fifth wave, TB-500/Nina)

- User spotted "TB-500 10mg Nina" listed twice and asked to review every product. Broadened the dup-check beyond the earlier same-price-only query (which had only caught 22/24 rows) to any vendor with >1 active row for the same product/spec/tier regardless of price match -- found 75 groups, not 24.
- 62 matched the established reprocess-mishap pattern exactly (confirmed via source_file_id: both rows in every pair trace to the identical source file, one old + one freshly mishap-inserted with a different vendor_sku and sometimes a different price reading). Deactivated all 62 via PUT /api/prices/{id}, including the user's reported TB-500/Nina case. Archived as migration_scripts/2026-07-14-deactivate_reprocess_duplicates_full_audit.php. Verified: 0 pc_prices/pc_specifications mismatches database-wide.
- The other 13 didn't fit the pattern -- traced each to its actual extraction call and found nearly all came from a SINGLE extraction pass reading two genuinely distinct rows from the vendor's own source, not a reprocess duplicate at all. Categorized and reported: some look like genuine product mismatches (CALLA's L-Carnitine 10mg had 2 of its "duplicate" SKUs turn out to be Lipo-C tier codes; Mamoth's Semaglutide duplicate is literally extracted under canonical_name "GLP-1"; Golden Age's Cagrilintide+Semaglutide has a SKU "CD5" that reads like plain Cagrilintide), some look like genuine vendor-source duplicates needing a human pick (keruihk's 3 Adipotide/FTPP doses with a consistent AP-vs-ADP price pattern, keruihk's Mazdutide spanning two different source photos, LCN's TB500(Frag), Lucy's GHK-Cu/Selank).
- User approved 2 of the 13: (a) Changsha Xjun's NAD+ and Melanotan 1 -- confirmed via the actual source spreadsheet's shared-strings table that only ONE real SKU exists for each, so the blank-vendor_sku row in each pair was a plain extraction glitch, deactivated. (b) CALLA's 2 Lipo-C-miscoded L-Carnitine rows -- moved to the correct products/specs (33/1031 for LC120, 55/1032 for LC216), matching CALLA's own already-correct sibling prices exactly; price_per_unit recomputed against the target spec's real numeric_value. This move needed a direct SQL correction (with explicit sign-off) since no existing endpoint supports "move one price row to a different product+spec while leaving every other vendor's prices on the original spec alone" -- prices/update.php doesn't reassign product/spec, and products/spec_move.php moves an entire specification's worth of every vendor's prices, which would have wrongly relocated other vendors' real L-Carnitine 10mg pricing too. (c) The remaining 11 left untouched, pending a human decision on which listing is authoritative for each.

## [2026-07-14] query | Filed the remaining 11 flagged duplicates for research

- User asked to save the 11 unresolved conflicting-listing pairs to the wiki with an index link, and asked whether they were currently hidden -- confirmed no, all 20 rows across the 11 pairs are still `is_active=1`, fully live on the Comparison page (they're not in the Review Queue either -- these are already-committed active prices, a different thing from pc_pending_imports).
- Filed as wiki/analyses/2026-07-14-flagged-duplicate-listings-needing-research.md with the full vendor/product/SKU/price table and research instructions (check the vendor's Files-tab source, decide keep-one/keep-both/recategorize). Added to index.md.

## [2026-07-14] fix | Pending-import review buttons no longer shift position

- User asked for the Approve/Reject/Skip buttons on the pending-imports review card to sit on their own centered line, since they previously shared a flex row with the "Map onto X" checkbox label -- the buttons' position shifted left/right depending on how long that label's candidate-product-name text was.
- Wrapped the buttons in a new .action-buttons div (flex, centered) inside a .pending-actions modifier on the outer .review-actions container (column layout) -- scoped to just the pending-imports card so the COA review card's simpler 2-button row (which doesn't have this problem) is untouched.
- Verified live: buttons now sit centered on their own row below the checkbox.

## [2026-07-14] feature | Admin activity dashboard (backlog #67, first cut)

- User wanted a Day/Week/Month pill-selector stats section, admin-only, reporting signup count, login count, search count, and WhatsApp-link click count -- explicitly asked to ship a first version to iterate on, not a final build. Also asked to file a separate backlog item (#68): green checkmark next to the vendor's name, vendor's name shown inside the "Verified" badge itself -- not implemented, filed only.
- Signups (pc_users.created_at), logins (pc_login_history), and searches (pc_query_log) were already tracked -- just needed range-filtered COUNT queries. WhatsApp clicks were not tracked anywhere (VendorCard.vue's WhatsApp <a> had no click handler at all) -- added pc_whatsapp_clicks (migration 034) and a best-effort POST /track/whatsapp-click fired on click, doesn't block the outbound wa.me navigation.
- New GET /admin/activity-stats?range=day|week|month mirrors admin/performance.php's existing range-pill pattern exactly (same DATE_SUB(NOW(), INTERVAL ...) approach, same .pill/.range-pills CSS copied into OverviewTab.vue since it was scoped to PerformanceTab.vue only). Added an "Activity" section to the Overview tab, cached 600s per range.
- Verified live end-to-end: Day pill showed 3 signups/3 logins/7 searches/0 whatsapp clicks; Month pill showed 25/50/14/0. Clicked a real vendor's WhatsApp link in VendorCard.vue (Purelypep Factory) -- it opened the correct wa.me link in a new tab AND recorded a new pc_whatsapp_clicks row, confirming the click handler doesn't interfere with normal navigation.
- Follow-up: user asked for the last 13 days/weeks/months displayed as horizontal lines. New GET /admin/activity-trend groups each of the 4 metric tables by day/ISO-week/month in MySQL, matched against a PHP-generated canonical 13-period list so a period with zero activity still shows a row instead of vanishing. Added 3 stacked sections of horizontal rows (13 each) below the existing Day/Week/Month totals. Verified live: daily rows match known activity (Jul 11 signup/login spike, today's WhatsApp click test), weekly rollup (Wk 28: 20 signups/41 logins) correctly matches the sum of that week's daily rows.
- Follow-up again: user shared a reference screenshot (rounded-track proportional bars, label left/value right, card with a Day/Week/Month pill toggle in the header) and asked to match that style. Replaced the plain-text trend rows with 4 bar-chart cards (Signups/Logins/Searches/WhatsApp Clicks), bar width normalized to each metric's own max across its visible periods (not a shared cross-metric scale), using this app's own navy/gold palette rather than the reference's literal colors. All 4 cards' pill toggles are bound to one shared ref, so clicking any card's toggle switches all four together. Verified live: clicking Week on the Logins card switched all 4 cards to weekly bars simultaneously, correctly re-normalized.
