---
title: Referral rewards converted from dollar credit to free months
type: analysis
tags: [referrals, billing, migration]
created: 2026-07-20
sources: []
---

# Referral rewards converted from dollar credit to free months (backlog #4 pre-Stripe correction)

Pre-Stripe correction flagged in [[wiki/entities/phase-roadmap|Phase Roadmap]] backlog #4 and
[[sessions/2026-07-03]]: `pc_referral_credits.amount_usd` and `pc_app_settings.referral_credit_usd`
were never written/consumed by any real code path — the referral reward had never actually been
granted. This makes the grant mechanism real for the first time, in months instead of dollars,
piggybacking on the existing admin Users-tab `tier_status` PATCH (no new admin UI).

## Schema (migration `040_referral_reward_months.sql`)

- `pc_referral_credits`: dropped `amount_usd`, added `months_granted SMALLINT UNSIGNED NOT NULL DEFAULT 0`.
- `pc_app_settings`: `referral_credit_usd` (was `'5.00'`) deleted, replaced with `referral_months_free` = `'2'` (matches `annual_discount_months_free`'s existing default). Seed data in `schema.sql` updated identically for fresh installs.

## Grant logic — `backend/api/admin/users_show.php` (PATCH `/admin/users/{id}`)

Fetches `tier_status`/`referred_by_id` *before* applying the admin's UPDATE. After the update, if the
new `tier_status` is `'active'`, the *old* value wasn't `'active'`, and the user has `referred_by_id`
set, checks `pc_referral_credits` for an existing `granted_at IS NOT NULL` row (dedupe). If none: in a
transaction, upserts `pc_referral_credits` (`months_granted` from `getAppSetting('referral_months_free', '2')`),
extends the referrer's `tier_renews_at` via
`DATE_ADD(GREATEST(COALESCE(tier_renews_at, NOW()), NOW()), INTERVAL ? MONTH)` (starts from NOW() if
the referrer has no renewal date or it's already past, else truly extends the future date), and
bumps the referrer's `tier_status` to `'active'` only if it wasn't already `active`/`trialing` (never
touches `tier`/plan level). Logged via `logAdminAction('referral_reward_granted', ...)`.

Two other read paths not mentioned in the original task spec were also found and fixed (would have
thrown a live SQL error on the dropped column otherwise): `backend/api/me/export.php`
(`referral_credits_earned` field) and `backend/api/admin/overview.php` +
`frontend/src/views/admin/tabs/OverviewTab.vue` (dashboard tile, renamed `total_credited_usd` →
`total_months_credited`).

## Frontend

- `SettingsView.vue`: "Refer a Friend" card — `credit_earned_usd` → `months_earned` stat tile ("Months
  Earned", no `$`), copy changed to "you earn free months added to your subscription".
- `UsersTab.vue`: referred-user list — `· credited $<amount>` → `· credited <n> mo`.
- Admin `SettingsTab.vue`: "Referral credit (USD)" number input → "Referral reward (months)".

## Live end-to-end verification (2026-07-20)

No real referred-and-unconverted user existed on prod, so a throwaway `test_account` user (id 32,
`referred_by_id = 9`, an existing test_account referrer) was minted directly via SQL, matching the
pattern from [[sessions/2026-07-17]] and [[sessions/2026-07-15]] (mint throwaway `pc_sessions` row
for an existing admin — id 4 — rather than materialize a real admin credential; hit the actual HTTP
endpoint with the real bearer token + required `Origin` header).

PATCH `/api/admin/users/32` `{"tier_status":"active"}` (referrer id 9 baseline: `tier_status=active`,
`tier_renews_at=NULL`):
- `pc_referral_credits`: `(referrer_id=9, referee_id=32, months_granted=2, granted_at=2026-07-20 23:09:19)` — matches configured default.
- Referrer `tier_renews_at`: `NULL` → `2026-09-20 23:09:19` (exactly NOW() + 2 months, correct baseline since it had no prior date).
- Referrer `tier_status`: stayed `active` (was already active — no incorrect downgrade/change).
- `pc_admin_audit_log`: one `referral_reward_granted` row with `{referrer_id:9, referee_id:32, months:2}`.

Dedupe check: re-PATCHed referee to `past_due` then back to `active` — no second `pc_referral_credits`
row, `tier_renews_at` unchanged, audit log still shows exactly 1 grant for this referee.

`GET /api/me/referral-stats` as referrer id 9 returned `{"joined":0,"converted":1,"months_earned":2}`
— correct (`joined` is 0 because the throwaway test didn't also create a `pc_referrals` row, only
`referred_by_id` + the credit; `converted`/`months_earned` derive purely from `pc_referral_credits`,
which is what this endpoint actually reports).

All test data (user 32, the credit row, the two admin-session tokens, the audit-log row,
`tier_renews_at` reset to NULL) cleaned up after — confirmed zero leftover rows.

`php -l` clean (SSH lint, no local PHP) on all 6 touched backend files. Deployed via
`bash deploy.sh --all`; smoke check passed.
