# price.themightygroupbuy.com — Project Context

## What This Is

Peptide vendor price comparison web app. Separate from `grp.themightygroupbuy.com`.
Subscription SaaS with annual pricing tiers ($50 / $140 / $340).

## Stack

- **Backend**: PHP 8.2 — no Composer, no ORM, direct PDO only
- **Frontend**: Vue 3 + Vite + Pinia
- **DB**: MariaDB — database `tmgb_price`, table prefix `pc_`
- **Cache / rate-limiting**: Memcached
- **Web server**: Apache
- **Email**: Brevo (transactional)
- **Env config**: `.env_pricetool` (not `.env` — lives at `~/.env_pricetool` on server, app root for local dev)
- **Deps placed manually**: `php_xlsxwriter` in lib directory

## Server / Deployment

- **OS**: Amazon Linux 2023 (AL2023)
- **Packages**: AL2023 native only — `mariadb105-server`, `php8.2-*`. No Remi or external repos.
- **Two deployment paths**: `setup.sh` (fresh server) and `add-price-site.sh` (add-on vhost)
- **Reference scripts to match exactly**: `add-peptools-site.sh`, `setup-al2023.sh`

## Deployment Script Rules (non-negotiable)

- DB passwords and `APP_SECRET` are **auto-generated** — never prompted, never hardcoded inline
- Use `~/.pc_my.cnf` MySQL options file — passwords must never appear on cron command lines or in process args
- SELinux: `setsebool` + `chcon` required
- Schema imported directly from `schema.sql`
- Certbot runs non-interactively
- Credential summary printed at end of script run

## What's Built (Phase 1 — complete, ~52 files)

- Front-controller router: `public/index.php` → `/api/*` PHP handlers, SPA fallback to `public/dist/index.html`
- Auth: opaque SHA-256 bearer tokens in `pc_sessions`; register (waitlist gate + referral codes), login (rate limited), email verify, forgot/reset password
- Endpoints: `/api/me`, `/api/me/quota`, `/api/app-settings`, `/api/feedback`, `/api/perf`
- Frontend: navy/gold Japanese-woodblock design system, CSS custom properties, light/dark/system theme via `data-theme`

## Backlog (in priority order)

1. **Stripe billing** — annual tiers $50 / $140 / $340
2. ~~Claude extraction pipeline~~ — built (`backend/api/files/process.php`, `backend/lib/claude.php`)
3. **Post-deploy health check** — after `deploy.sh` finishes, hit a `/api/health` endpoint that exercises DB (query), Memcached (set/get), and email config (reachability only); print a pass/fail summary per component
4. **ClamAV scan on vendor file uploads** — admin-uploaded PDF/XLSX/CSV files go straight into `backend/api/vendors/files.php` → Claude extraction with no malware scan. Add a `clamdscan` (via clamd daemon, not the slower `clamscan` CLI) check between `move_uploaded_file()` and the file being marked available for processing; quarantine/reject on a positive match.
5. **Full vendor purge** — vendors with file/price history can only be deactivated (`is_active=0`), never hard-deleted, since deleting would cascade-destroy price history and anything already surfaced on the comparison table. If a vendor needs to be fully gone (not just inactive), this probably isn't a real DB delete — more likely a "hidden" flag that excludes the vendor from every query (comparison table, admin lists, exports) while keeping the row and its history intact for audit purposes. Needs a decision on what "fully gone" actually means before building it.
6. **Tier sizes above 255 not storable** — `pc_prices.tier_kit_size` is `TINYINT UNSIGNED` (max 255). The tier-clamping fix that removed the hardcoded `[1, 10, 100]` restriction (extraction prompt + commit logic now accept any vendor-defined breakpoint) still floors/ceilings via `min(255, max(1, ...))` — a genuine 1000-kit tier would silently clamp to 255, not store 1000. Needs a migration widening the column (e.g. `SMALLINT UNSIGNED`, max 65,535) before a tier that large can actually be represented.
7. **Comparison table only ever shows the 1-kit tier** — `comparison_query.php` hardcodes `pr.tier_kit_size = 1` in its WHERE clause. Every tier a vendor quotes (10/50/100/etc.) is now stored correctly, but nothing surfaces them anywhere — admins and end users only ever see the 1-kit price, bulk pricing is invisible. Needs a decision on UI shape (tier selector? one column per tier?) before building; bigger than the storage fix since it touches the customer-facing query and whatever UI renders it, not just ingestion.

## Wiki / Knowledge Base

This project's wiki lives at `Obsidian_pep_pricing_tool/` in the repo root. It is an Obsidian vault following the LLM Wiki pattern. **Always use this vault for any wiki, research, or knowledge-base work on this project — never create wiki files elsewhere.**

### Directory layout

```
Obsidian_pep_pricing_tool/
  raw/clippings/    ← drop source files here; never modify them
  wiki/
    sources/        ← one summary page per ingested source
    entities/       ← vendors, peptides, compounds
    concepts/       ← domain ideas, pricing models, workflows
    analyses/       ← dated query answers (YYYY-MM-DD-topic.md)
    _templates/     ← page templates (not linked in index)
  index.md          ← catalog of all wiki pages; update every run
  log.md            ← append-only activity log; append every run
  CLAUDE.md         ← full wiki schema and workflow reference
```

### Page frontmatter (required on every wiki page)

```yaml
---
title: Page Title
type: source | entity | concept | analysis
tags: []
created: YYYY-MM-DD
sources: []
---
```

### Naming conventions

- Files: `kebab-case.md`
- Analysis pages: `YYYY-MM-DD-topic-slug.md`
- Cross-links: `[[wiki/page-name|Display Text]]`

### Workflows

**Ingest a new clipping** (`raw/clippings/` file not yet in `log.md`):
1. Read the file (never modify it)
2. Create `wiki/sources/<slug>.md` with key takeaways
3. Update or create entity/concept pages for anything mentioned
4. Update `index.md`
5. Append to `log.md`: `## [YYYY-MM-DD] ingest | Source Title`

**Answer a query**:
1. Read `index.md` to find relevant pages
2. Synthesise answer; if worth keeping, file as `wiki/analyses/YYYY-MM-DD-topic.md`
3. Update `index.md` and append to `log.md`: `## [YYYY-MM-DD] query | Question summary`

**Lint the wiki**:
1. Check for orphan pages, stale claims, missing cross-references
2. Report findings; fix what the user approves
3. Append to `log.md`: `## [YYYY-MM-DD] lint | N issues found`

### Variant compound watchlist

`wiki/concepts/variant-compounds.md` lists peptides sold under the same name but as different molecules. When ingesting any clipping, always scan for watch-name matches and update: the vendor entity page, the variant entity page, and `wiki/analyses/<date>-variant-scan.md`.

### Weekly automated scan

A cloud agent (routine `trig_012jsqYj1nEgZcVzphK6syjy`) runs every Monday 09:00 UTC to ingest new clippings automatically. Supports both web clippings and WhatsApp chat exports dropped into `raw/clippings/`.

## Key Constraints

- Pure PDO — no ORM, no query builder
- No Composer — dependencies placed manually
- All automation scripts must match patterns from `add-peptools-site.sh` / `setup-al2023.sh` exactly
