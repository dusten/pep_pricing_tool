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
