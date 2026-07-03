---
title: "Phase 1 Framework Reference — pep_pricing_tool"
type: source
tags: [architecture, phase1, backend, frontend, deployment]
created: 2026-06-29
author: dusten
---

# Phase 1 Framework Reference

## Summary

Complete codebase reference for Phase 1 of price.themightygroupbuy.com — a peptide vendor price comparison SaaS. Phase 1 is finished (~52 files). Phase 2 (Stripe billing) is next. Phase 3 (Claude extraction pipeline + comparison table) follows.

## Stack

| Layer | Tech |
|-------|------|
| Backend | PHP 8.2, no Composer, direct PDO only |
| Frontend | Vue 3 + Vite + Pinia |
| DB | MariaDB — database `tmgb_price`, table prefix `pc_` |
| Cache / rate-limiting | Memcached (degrades gracefully if missing) |
| Web server | Apache |
| Email | Brevo (transactional) |
| Env config | `.env_pricetool` (not `.env`) — lives at `~/.env_pricetool` on server |
| Server OS | Amazon Linux 2023 |

## Key architectural decisions

- **Front-controller router**: `public/index.php` → `/api/*` PHP handlers, SPA fallback to `public/dist/index.html`
- **Auth**: opaque SHA-256 bearer tokens in `pc_sessions`; no JWTs
- **No ORM, no Composer** — all PDO directly
- **Two deploy paths**: `setup.sh` (fresh server) and `add-price-site.sh` (add-on vhost to existing grp. server)
- **DB passwords auto-generated**, stored in `~/.pc_my.cnf` — never appear on cron command lines
- **SELinux**: `setsebool` + `chcon` required on AL2023
- **Memcached rate limiting degrades gracefully** — no Memcached = no rate limit (noted as acceptable for now)

## Domain model (DB tables)

### Auth
- `pc_users` — email, password_hash, tier, stripe IDs, referral_code
- `pc_sessions` — sha256 token hashes, expires_at
- `pc_password_resets` — single-use, 1hr expiry

### Waitlist & referrals
- `pc_waitlist` — email, invite_token (single-use)
- `pc_referrals` — referrer/referee edges
- `pc_referral_credits` — credit granted when referee's first invoice settles

### Domain: Products & Pricing
- `pc_products` — canonical_name, category (glp1/peptide/hormone/blend/consumable/other)
- `pc_product_aliases` — alternate names mapping to canonical
- `pc_specifications` — product_id + spec_label (e.g. "5mg") + numeric_value + unit
- `pc_vendors` — display_name, contact, website, is_active
- `pc_vendor_files` — uploaded price lists (pdf/xlsx/csv), processing_status
- `pc_prices` — vendor × product × specification → price_usd + price_per_unit (computed in PHP)

### Metering & billing
- `pc_query_log` — user_id + filter_hash + created_at (rolling 72h window for free tier)
- `pc_billing_events` — Stripe webhook log (idempotent by stripe_event_id)

### Admin
- `pc_app_settings` — key/value store (waitlist_mode, maintenance_mode, tier limits)
- `pc_admin_audit_log` — admin action log
- `pc_feedback` — user-submitted bug/feature/other

## Subscription tiers

See [[wiki/concepts/subscription-tiers|Subscription Tiers]] for full detail.

| Tier | Monthly | Annual |
|------|---------|--------|
| Free | $0 | $0 |
| Advanced | $5/mo | $50/yr |
| Pro | $14/mo | $140/yr |
| Expert | $34/mo | $340/yr |

Annual = 2 months free (10mo price). Tier differentiation is on query quota and download access.

## Query quota system

See [[wiki/concepts/query-quota|Query Quota System]].

Free tier: 3 distinct comparison queries per 72-hour rolling window. Uniqueness keyed on `filter_hash` (sha1 of normalized filter params). Paid users: unlimited.

## Auth flows built

- Register (waitlist gate + invite_token, optional referral_code)
- Email verification (72hr token, welcome email on verify)
- Login (rate-limited, returns opaque bearer token)
- Logout (token deletion)
- Forgot/reset password (1hr token, invalidates all sessions on reset)

## Frontend routes

| Path | View | Auth |
|------|------|------|
| /login, /register, /forgot-password, /reset-password, /verify-email | Auth views | guest |
| /dashboard | DashboardView | required |
| /comparison | ComparisonView (placeholder) | required |
| /pricing | PricingView | public |
| /account | AccountView | required |
| /admin | AdminView | admin |

## Admin panel tabs (Phase 1 shells)

Settings and Feedback tabs are functional. Users, Waitlist, Subscriptions, Vendors, Products, Files, Performance, Backup are placeholder stubs — built in Phase 2/3.

## Seed data

Schema ships with placeholder products: BPC-157, TB-500, Semaglutide with 2mg/5mg/10mg specs.

## Phase roadmap

See [[wiki/entities/phase-roadmap|Phase Roadmap]].

- **Phase 1** ✅ — auth, quota, design system, SPA shell, deployment scripts
- **Phase 2** 🔜 — Stripe billing, admin user/waitlist/subscription management
- **Phase 3** 🔜 — Claude extraction pipeline, comparison table, vendor file processing
