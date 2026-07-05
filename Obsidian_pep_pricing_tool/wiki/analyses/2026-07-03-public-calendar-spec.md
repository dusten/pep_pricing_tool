---
title: Public-Facing Calendar Spec
type: analysis
tags: [spec, calendar, public, marketing, backlog]
created: 2026-07-03
sources: [export-tier-selector-calendar-history-spec]
---

# Public-Facing Calendar

Design and build for backlog #17, done together same day (no separate spec-then-build gap this time, per direct instruction). **Status: built and deployed 2026-07-03.**

## Decisions

Five candidate content ideas were discussed for a public (unauthenticated) calendar surface; the user chose to build 1/2/3 now and backlog 4/5:

1. **Built** — Aggregate stats: total change count + distinct vendor count for the month, no vendor names.
2. **Built** — Classification-level activity breakdown.
3. **Built** — Teased specifics: real product/spec names, no vendor or price.
4. **Backlogged (#18)** — A rotating, fully-open featured product per day (real vendor + price), everything else stays teased.
5. **Backlogged (#19)** — Milestone callouts ("all-time low recorded for X today").

The core tension: a public surface is a marketing/lead-gen lever, but it also cuts against the free-tier query quota system's entire purpose (rationing real data to drive signups). Options 1–3 were chosen specifically because they give away *activity* (the app is alive, actively curated, has real breadth) without ever exposing what a signup gets you (which vendor, what price) — vendor names and dollar amounts never appear anywhere in the public response.

## Design

**Route**: `/calendar` no longer requires auth (removed `meta: { requiresAuth: true }`). `CalendarView.vue` branches its own behavior on `auth.isAuthenticated` — logged-in users get the exact full-ledger view built earlier the same day (backlog #15/dot-pair fix), anonymous visitors get the new teaser. One page, one route, no duplication.

**Backend**: new `GET /api/calendar/public?month=YYYY-MM` (`backend/api/calendar_public.php`) — genuinely no auth check at all, unlike every other endpoint in this app. Reads `pc_price_history` only (not `pc_pending_imports` — review-queue approval counts are an internal curation metric, not something a public visitor cares about or should see). Response shape:

```json
{
  "month": "2026-07",
  "summary": {
    "total_changes": 176,
    "vendor_count": 5,
    "by_classification": [{"name": "Growth Hormone", "count": 18}, ...]
  },
  "days": {
    "2026-07-03": [{"product": "BPC-157", "spec": "5mg", "is_new": false}, ...]
  }
}
```

Classification counts are weighted by actual change events, not distinct products — a product that changed price 5 times contributes 5 to its category, not 1 — and inclusive across a product's full tag set (same OR semantics as the Comparison filter's classification chips), so category counts can sum to more than `total_changes`.

**Frontend**: the existing calendar-grid UI (month nav, day cells, day-detail panel) is reused as-is; only the data source and the day-detail table's columns change based on auth state. Public day-detail shows Product/Spec/"Price changed" or "New listing" — no Vendor column, no $ column — plus a "Sign in to see vendor names and exact prices" CTA linking to `/login`.

## Verification

Hit `/api/calendar/public` with zero auth headers directly — confirmed real data (176 changes, 5 vendors, full classification breakdown for the month) with no vendor or price field anywhere in the payload. Then confirmed the actual rendered page in the browser both logged out (summary banner, classification chips, teased list, sign-in CTA all present) and logged back in (unchanged full-ledger view, both dot types still correct).

## Incident during verification (self-inflicted, fixed)

Testing the logged-out view required saving the real admin session token before clearing `localStorage`, to restore it afterward. The save was held in a `window.__savedToken` JS variable — which does **not** survive a `location.reload()` (full page navigations wipe the JS heap). The restore step ran after an intervening reload, so it wrote the *literal string* `"undefined"` into `localStorage` instead of the real token. `stores/auth.js`'s startup read (`JSON.parse(localStorage.getItem(USER_KEY) || 'null')`) had no error handling, so `JSON.parse("undefined")` threw uncaught and crashed the entire app — not just the Calendar page — on that browser tab.

Fixed in two parts: cleared the corrupted keys immediately (restoring a clean logged-out state), and wrapped the `JSON.parse` in a try/catch so any corrupted stored value falls back to logged-out gracefully instead of hard-crashing the app — closing the actual failure mode, not just the immediate mess. The user had to log back in manually on that one browser tab; no other sessions, users, or data were affected.

**Process lesson**: when testing an auth-state toggle via browser automation, don't hold values that need to survive a `location.reload()` in a JS variable — persist them somewhere that survives navigation (e.g. read `localStorage` fresh immediately before reload, or use two separate confirmed reads rather than a carried-over `window` property).

## Non-goals

Options 4 and 5 (backlog #18, #19). Rate-limiting the new public endpoint (it's read-only and cached 300s per month like every other comparison-data endpoint, same cost profile as the existing public `app-settings`/`health` endpoints — not singled out here).

## Related

- [[wiki/entities/phase-roadmap|Phase Roadmap]] — backlog #17, #18, #19
- [[wiki/analyses/2026-07-03-export-tier-selector-calendar-history-spec|Calendar Real Price History]] — the authenticated ledger view this builds on
