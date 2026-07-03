---
name: feedback-wiki-location
description: All persistent notes, session logs, and memory for this project live in the Obsidian wiki, not ~/.claude/
metadata:
  type: feedback
---

All session notes, analyses, and persistent memory for the pep_pricing_tool project live in:
`/home/dusten/projects/pep_cal/pep_pricing_tool/Obsidian_pep_pricing_tool/`

- Session notes → `sessions/YYYY-MM-DD.md`
- Memory entries → `memory/` (with pointer in `memory/MEMORY.md`)
- After writing any wiki page: update `index.md` and append to `log.md`

**Why:** User configured the full wiki structure in a dedicated session. The `~/.claude/projects/` memory path is wrong for this project. As of 2026-07-01 the user has had to repeat this correction 3-4 times — the harness's own `~/.claude/` memory auto-loads into context and is easy to default to without thinking, which is exactly why it keeps happening. That directory now contains nothing but a one-file pointer back to here, specifically to break that habit.

**How to apply:** At the START of every pep_pricing_tool session — not just when about to write something — read this file and `CLAUDE.md`. Never write session notes, analyses, or persistent memory content into `~/.claude/`. If asked to "update session notes" or "save this to memory," that always means a file under this Obsidian folder.
