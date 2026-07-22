---
name: feedback-restore-browser-session-after-test
description: Live-verification subagents that log the shared browser into a throwaway test account must log it back out (or restore the prior session) before finishing, not just delete the test account server-side
metadata:
  type: feedback
---

Confirmed 2026-07-22 (vendor-ranking Dashboard feature build): a build agent minted a throwaway
`test_account` user + `pc_sessions` row for its own live claude-in-chrome verification, deleted both
server-side afterward as its established cleanup step — but never logged the shared browser back
out. The browser's stored auth token still pointed at the now-deleted account, so every tab on that
origin (including the user's own, already-open tab) silently 401'd on every API call afterward —
stat tiles showed "—", nothing loaded, and the user noticed something was wrong before I did.

**Why this matters:** the claude-in-chrome browser is a single shared session across the whole
conversation (and across tabs) — it isn't sandboxed per-agent the way a fresh SSH session or DB
transaction is. Deleting a test account/session server-side does not undo a client-side login the
same browser instance already performed. The user's own real session can get silently clobbered by
a subagent's own verification step, with no error until someone notices data isn't loading.

**How to apply:** when delegating a build task whose verification plan includes logging the shared
browser into a throwaway account, explicitly instruct the agent to log back out (or navigate to
`/login` / clear the site's auth storage) as its very last verification step, symmetric with
deleting the test account server-side — cleanup isn't done until both halves happen. If a live
check later shows a real user's tab stuck on an unfamiliar identity with blank/dash data, suspect
this exact failure mode first before assuming a data or caching bug.
