---
title: Deployment Pattern
type: concept
tags: [deployment, devops, server, apache]
created: 2026-06-29
sources: [phase1-framework]
---

# Deployment Pattern

## Overview

Two deployment scripts cover two scenarios:

| Script | Use case |
|--------|----------|
| `setup.sh` | Fresh EC2 instance (no other sites) |
| `add-price-site.sh` | Add-on vhost to existing server (shared with grp. site) |

Normal deploys: `./deploy.sh` from local machine.

## Server Environment

- **OS**: Amazon Linux 2023
- **Web server**: Apache (httpd)
- **PHP**: 8.2 via PHP-FPM (`/run/php-fpm/www.sock`)
- **DB**: MariaDB 10.5
- **Cache**: Memcached
- **SSL**: certbot / Let's Encrypt

## Credentials Convention

Matches the existing peptools pattern on the shared server:

| File | Location | Purpose |
|------|----------|---------|
| `~/.env_pricetool` | `/home/ec2-user/.env_pricetool` | App config (DB, API keys, APP_SECRET) |
| `~/.pc_my.cnf` | `/home/ec2-user/.pc_my.cnf` | MySQL options file for cron/migrate (no password on command line) |

`~/.env_pricetool` permissions: `640 ec2-user:apache` — PHP-FPM runs as `apache` and needs read access.

`config.php` loads it via `dirname(__DIR__, 2) . '/.env_pricetool'` (two levels up from `backend/`), falling back to app root for local dev.

## add-price-site.sh Rules

- Must run as **root** (not sudo)
- MariaDB root requires a password on this server (set during peptools setup)
- DB user and database: both named `tmgb_price`
- Password auto-generated with `openssl rand`
- Certbot runs non-interactively; domain must already have DNS pointing to server
- **Caution**: certbot `--apache` can modify existing vhost SSL configs. If it hijacks another site's conf, remove it and let `price.conf` (which has SSL hardcoded) be the sole vhost.

## deploy.sh Flow (as of 2026-07-01, 4 explicit modes)

Schema changes and code deploys are always separate, explicit steps — no mode implies another except `--all`.

| Mode | Does |
|------|------|
| `bash deploy.sh` or `--build` (default) | `npm install && npm run build`, then file sync — same as pre-2026-07-01 default |
| `bash deploy.sh --sync-files` | File sync only, no `npm run build` — for backend-only/config-only changes, uses existing `frontend/dist` |
| `bash deploy.sh --sync-schema` | `rsync database/` + `migrate.sh` to remote, then remote `mysql < schema.sql` + `bash migrate.sh` — DB only, no code touched |
| `bash deploy.sh --all` | `sync-schema` then `--build` — the only mode that touches both DB and code |

File sync step (used by `--sync-files`, `--build`, `--all`): `rsync` to remote (excludes `.env_pricetool`, `node_modules`, `dist/.vite`, `vendor_files`, `log/`), then remote: cron file copy → log dir perms/SELinux context → `systemctl reload php-fpm` + `httpd` (graceful, not restart) → **automatic smoke check** (`curl` homepage + `/api/app-settings`, both must return 200, script exits non-zero otherwise).

Append a hostname arg to any mode to override the default target (`price.themightygroupbuy.com`).

## Key Gotchas Learned

- **Vite base path**: must set `base: '/dist/'` in `vite.config.js` because `outDir: '../public/dist'` puts assets at `/dist/assets/` but default base `/` generates HTML paths like `/assets/`
- **PHP spread in destructuring** (`[$a, ...$b]`) requires PHP 8.1+; use `array_slice()` instead for safety
- **Apache vhost conflicts**: certbot can modify other sites' SSL confs when run with `--apache`; verify after running
- **Shared server**: always `reload` httpd, never `restart` — drops connections on the grp. vhost
- **Never pre-seed `pc_migrations` in `schema.sql` for anything beyond `001_initial.sql`.** `--sync-schema` runs `mysql < schema.sql` *before* `migrate.sh`. If schema.sql's seed data marks a later migration "applied," `migrate.sh` skips its real `ALTER TABLE` statements on an existing install — `schema.sql`'s `CREATE TABLE IF NOT EXISTS` is a no-op there, so the columns silently never land while `pc_migrations` claims otherwise. Bit us for real on 2026-07-01 (migration 003 skipped on prod, see sessions/2026-07-01.md § 9). If a migration needs to be safe whether or not it's pre-seeded, write it idempotently instead (`ADD COLUMN IF NOT EXISTS`, MariaDB 10.0.2+) rather than gaming the tracking table.
- **MariaDB log tables (`mysql.slow_log`/`mysql.general_log` when `log_output=TABLE`) reject `DELETE` entirely** — filtered *and* unconditional, error 1556 "You can't use locks with log tables". Only `TRUNCATE TABLE` works, and it can't be scoped to one database's rows. `mysql.slow_log` on this box is server-wide, shared with the grp app (`themightygroupbuy` db) — both apps' hourly slow-query-import events only `SELECT` from it, never delete from it; a single shared root-owned `/etc/cron.d/mysql-slowlog-truncate` (not part of either app's deploy) truncates it once daily after both have had many chances to import. See sessions/2026-07-01.md for the full story — this bug existed in grp's original event silently since 2026-06-26, unrelated to anything built this session.
- **This server hosts two apps sharing one MariaDB instance** (`tmgb_price` for this project, `themightygroupbuy` for grp). The `tmgb_price` DB user is correctly scoped — no visibility into `mysql.slow_log` or the other db — so cross-cutting server-level work (grants, other apps' events, shared cron) needs root, via `/root/.my.cnf` on the box (not something to request as a plaintext password in chat).

## Related

- [[wiki/entities/phase-roadmap|Phase Roadmap]]
- [[wiki/sources/phase1-framework|Phase 1 Framework Reference]]
