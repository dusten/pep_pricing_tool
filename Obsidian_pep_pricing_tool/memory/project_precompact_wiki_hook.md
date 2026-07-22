---
name: project-precompact-wiki-hook
description: A PreCompact hook is configured to auto-inject a wiki/log/session-note checkpoint reminder before every compaction (manual or automatic) — documented in CLAUDE.md, best-effort not a hard guarantee
metadata:
  type: project
---

User explicitly asked (2026-07-22) for an automated, no-intervention mechanism that writes
`log.md`, session notes, and `index.md` before context gets compacted — either the automatic
compaction that happens near context-limit (~95%) or a manual `/compact`. Set up via a real
`PreCompact` hook in `/home/dusten/projects/peptides_projects/pep_pricing_tool/.claude/settings.json`
(two entries, `matcher: "auto"` and `matcher: "manual"`, both running the same `jq`-built command)
rather than relying on a CLAUDE.md text instruction alone — a plain instruction can't guarantee
automatic behavior with no user intervention, only a harness-executed hook can. Documented in the
project's own `CLAUDE.md` under "Pre-compaction wiki checkpoint (automatic)".

**What's actually guaranteed vs. not:** the hook deterministically fires and injects a directive
(via `hookSpecificOutput.additionalContext`) every single time compaction is about to run, for
both trigger types. What is NOT mechanically guaranteed: a shell hook can't itself author real
wiki prose — only the model can — so the hook's injected reminder still depends on the model
actually acting on it with a real turn (Write/Edit + git commit/push) before/around compaction.
If compaction lands mid-tool-call or the session ends abruptly, the checkpoint can still be
skipped. This is a strong safety net layered on top of the existing "log everything as you go"
convention, not a replacement for it.

**How to apply:** don't assume every session boundary has a perfectly complete wiki record just
because this hook exists — a session-note/log gap discovered later (like the 2026-07-18/07-19
backfill done earlier this week, before this hook existed) is still worth a catch-up pass. If the
hook's mechanics ever need adjusting (e.g. the exact injected wording, or extending it to also
cover memory-file updates), edit `.claude/settings.json`'s `hooks.PreCompact` entries directly.
