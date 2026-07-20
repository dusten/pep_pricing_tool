---
name: feedback-delegate-builds-to-sonnet
description: Build/implementation work on this project gets delegated to background Sonnet 5 subagents; the primary session does investigation, planning, verification review, and wiki logging itself
metadata:
  type: feedback
---

Confirmed repeatedly (2026-07-15 through 2026-07-18, vendor-suggestions Phases 1/2, the admin-approval gate, price-history merge-orphan fix, per-call and per-suggestion Claude cost tracking): the working pattern on this project is to research/investigate directly (Explore agents or direct grep/SSH), design the change, then hand the actual implementation — code changes, migrations, deploy, server-side verification — to a background `Agent` call with `model: "sonnet"` and a fully-specified prompt (exact files, line numbers, function signatures, verification steps). The user explicitly asked for this at least once ("build with sonet5", "use auto mode, but use sonet5") and it has continued without correction since.

**Why:** keeps the primary session's context focused on coordination, cross-checking the subagent's report against the actual repo state, and catching things the subagent's own report didn't flag (e.g. the misplaced diagnostic scripts, the require-path break after a `git mv`) — rather than spending that context on raw implementation output. It also matches this project's general async/background-task rhythm (SSH deploys, cron verification) better than doing builds inline.

**How to apply:** when the user asks for a build/fix on this project, default to: investigate/confirm scope myself (fork or Explore agent if it's a big unknown), then delegate the actual implementation to a `general-purpose` agent with `model: "sonnet"`, a self-contained prompt citing exact file:line facts already gathered, and explicit wiki-logging + deploy + verification instructions baked into that prompt. After it reports back, independently verify anything load-bearing (git status, a live spot-check) rather than taking the subagent's self-report at face value — this caught at least two real gaps this week (git add silently dropping staged edits, scripts landing in the wrong directory).
