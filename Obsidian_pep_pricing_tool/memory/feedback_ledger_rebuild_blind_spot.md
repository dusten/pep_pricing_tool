---
name: feedback-ledger-rebuild-blind-spot
description: When rebuilding a display to read from a new ledger/audit table instead of a live column, check whether the ledger has coverage back to before the rebuild shipped — same-day (or older) events that predate the ledger's existence silently vanish
metadata:
  type: feedback
---

Switching a view (e.g. Calendar) from reading a live column (`pc_prices.created_at`) to a purpose-built ledger (`pc_price_history`) is usually the right fix for accuracy — but the ledger only has rows from the moment it started being written. Anything that happened earlier — including earlier the *same day* the ledger shipped — has no ledger row and disappears from the view entirely, even though the underlying data was never touched.

**Why:** Built the Calendar rebuild (backlog #15) to query `pc_price_history` exclusively. A real batch of ~370 Review Queue approvals had happened at 04:21 AM that same day, before the ledger table existed. Those approvals vanished from the Calendar even though `pc_prices` was fully intact (verified: all rows `is_active=1`, nothing deleted) — the user caught this as "inventory got removed," which cost a real investigation cycle (audit log, price row counts, vendor status) before the actual mechanism was found.

**How to apply:** Before shipping a "read from the new ledger only" rebuild, ask: does anything that already happened before the ledger existed need to keep showing up in this view? If yes, either (a) keep a second signal from the pre-existing live-column/timestamp source for historical coverage, or (b) explicitly flag the cutover date to the user as a known gap before shipping, not after they notice missing data. In this case the fix was adding `pc_pending_imports.reviewed_at` as an independent second signal (see [[wiki/analyses/2026-07-03-export-tier-selector-calendar-history-spec]]) rather than trying to backfill the ledger itself.
