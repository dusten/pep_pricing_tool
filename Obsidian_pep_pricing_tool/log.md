# Log

Append-only record of wiki activity. Parse with: `grep "^## \[" log.md`

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
