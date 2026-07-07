---
name: feedback-commit-style
description: Commit message style preference — short, no co-author, no per-file details
metadata:
  type: feedback
---

Keep commit messages short: one line summary only. No file-by-file breakdown. No `Co-Authored-By` trailer. No `Claude-Session` trailer.

**Why:** User has asked four times across three sessions now. Hard no — do not add these ever again under any circumstances.

**Repeated 2026-07-06:** wrote multi-paragraph explanatory bodies on every commit in a long session (N+1 fix, Review Queue cleanup, error-message fix, Avg/Median fix) despite this memory already existing — did not re-read memory at the point of committing, only at session start days earlier. User had to stop mid-session and redirect again.

**How to apply:** Every commit, no exceptions. Format: `"Add X"` / `"Update Y"` / `"Fix Z"` — nothing more unless the user explicitly requests detail. Re-check this file before *every* commit in a long/multi-topic session, not just once at session start — memory read early in a session does not guarantee the habit holds 10 commits later.
