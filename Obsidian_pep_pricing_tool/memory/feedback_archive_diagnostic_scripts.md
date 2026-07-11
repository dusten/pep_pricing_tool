---
name: feedback-archive-diagnostic-scripts
description: Every one-off read-only verification script run on the server also gets saved to diagnostic_scripts/ and logged in the wiki, not just migration-style data-mutating scripts
metadata:
  type: feedback
---

Whenever a one-off PHP script is written and run directly on the server (`sudo -u apache php <script>`, the standard way to verify backend code since [[feedback_no_local_php|there's no local PHP]]) purely to check something — a new function against live data, cache contents, a query's real output — it must be saved to `diagnostic_scripts/` at the repo root and logged in `log.md`/an analysis page, not deleted after running.

**Why:** User asked directly (2026-07-11) to make sure every tmp script is stored in the project "so we don't have to recreate things," plus a wiki note on what each one does. This extends the existing [[feedback_archive_migration_scripts|migration-scripts archiving rule]] — which only covered scripts with a lasting effect on production data — to cover read-only diagnostics too, which had been getting written to a job tmp dir or server `/tmp` and cleaned up as routine housekeeping.

**How to apply:**
- Directory: `diagnostic_scripts/` at the git repo root, sibling to `migration_scripts/` — has its own `README.md` explaining the distinction (this dir never mutates data; `migration_scripts/` is for scripts that do).
- Naming: `YYYY-MM-DD-descriptive-name.php`, same convention as `migration_scripts/`.
- Write the script directly into `diagnostic_scripts/` (paths relative to that location, e.g. `chdir(__DIR__ . '/../price_themightygroupbuy/backend')`) rather than a scratchpad/job-tmp path, then `scp` to the server to run — no separate "recover it afterward" step.
- Each file keeps a header comment: what it checks, and which feature/fix it was verifying.
- Log what each script does in the wiki (a line in `log.md` for the session, or reference it from the relevant `wiki/analyses/` page) — not just the file's own header comment, so it's discoverable without opening the file.
- Commit normally, per [[feedback_commit_style]].
