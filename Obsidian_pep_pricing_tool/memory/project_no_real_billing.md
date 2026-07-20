---
name: project-no-real-billing
description: No Stripe/payment integration exists — tier, tier_status, and tier_renews_at on pc_users are 100% manually set by admins; confirm before assuming any billing automation
metadata:
  type: project
---

Confirmed via full investigation (2026-07-20, while converting referral rewards from dollars to
months): this app has **no real billing integration today**. `pc_users.tier` / `tier_status` /
`tier_renews_at` are set entirely by hand via the admin Users tab PATCH endpoint
(`backend/api/admin/users_show.php`) — there is no Stripe integration (grep for "Stripe" across
`backend/` returns nothing live), no webhook handler, no cron that enforces expiry or auto-renews
anything. `stripe_customer_id`/`stripe_subscription_id` columns exist on `pc_users` but are never
populated — they're schema scaffolding for backlog #4 (Stripe billing), which remains unbuilt.

The old `pc_users.account_credit_usd` dollar-credit field was **removed 2026-07-20** — it was never
consumed by any code (confirmed: no checkout, no invoice, no renewal ever read it), purely
cosmetic since the day it was added. Referral rewards now grant real free months instead (see
[[wiki/analyses/2026-07-20-referral-months-migration]]), but the underlying mechanism is still
100% admin-driven: a grant fires off the same manual `tier_status → active` PATCH admins already
do, extending `tier_renews_at` directly. There's still no automated enforcement of that date —
it's a data field an admin can read, same as before.

**Why this matters:** any future work touching subscriptions, pricing tiers, or "what happens when
someone's subscription expires" needs to start from "nothing enforces this today" — don't assume
Stripe webhooks, invoice events, or scheduled expiry checks exist just because the schema has
columns that look ready for them.

**How to apply:** before designing anything billing-adjacent, re-confirm this is still true (grep
for "Stripe" in `backend/`, check whether `stripe_customer_id` has any real values) rather than
assuming it's been built since — this memory should be updated or removed the moment real billing
lands.
