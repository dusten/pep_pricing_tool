---
title: Subscription Tiers
type: concept
tags: [billing, tiers, pricing, stripe]
created: 2026-06-29
sources: [phase1-framework]
---

# Subscription Tiers

## Tier structure

| Tier | Monthly | Annual (billed) | Saves |
|------|---------|-----------------|-------|
| Free | $0 | — | — |
| Advanced | $5/mo | $50/yr | $10 |
| Pro | $14/mo | $140/yr | $28 |
| Expert | $34/mo | $340/yr | $68 |

Annual = 2 months free. Configured via `annual_discount_months_free` in `pc_app_settings`.

## Tier differentiation

| Capability | Free | Advanced | Pro | Expert |
|------------|------|----------|-----|--------|
| Comparison queries | 3 / 72h | Unlimited | Unlimited | Unlimited |
| Download filtered results | ✗ | ✗ | ✓ | ✓ |
| Full raw data export | ✗ | ✗ | ✗ | ✓ |

## DB representation

`pc_users.tier` — ENUM('free','advanced','pro','expert')  
`pc_users.tier_status` — ENUM('active','past_due','canceled','trialing','none')

Active subscription = `tier_status IN ('active','trialing')`. Past-due or canceled users are treated as free tier for capability checks (`requireTier()` in `helpers.php`).

## Stripe IDs (Phase 2)

Six Stripe Price IDs needed (monthly + annual × 3 paid tiers). Added to `~/.env_pricetool` and `backend/config.php` during billing phase — not present yet.

## Enforcement

`requireTier(string $min)` in `backend/helpers.php` — checks tier × status, returns 402 with `upgrade_to` field if insufficient.

## Related

- [[wiki/concepts/query-quota|Query Quota System]]
- [[wiki/entities/phase-roadmap|Phase Roadmap]]
- [[wiki/sources/phase1-framework|Phase 1 Framework Reference]]
