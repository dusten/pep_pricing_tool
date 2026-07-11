---
name: feedback-archive-migration-scripts
description: Any one-off script run directly on the server for a bulk data operation must be saved to migration_scripts/ and committed to git, not deleted after running
metadata:
  type: feedback
---

Whenever a one-off PHP script gets written and run directly on the server (via `sudo -u apache php <script>`) for a bulk/migration-style data operation — batch reimports, Review Queue clearance, product-merge cleanups, anything that mutates production data at scale — it must be saved into `migration_scripts/` at the repo root and committed to git, not deleted after running.

**Why:** After running five such scripts in one session (an overnight 26-file reimport, a 371-item Review Queue clearance, a spec-length fix, and two duplicate-product merge passes) and cleaning them up off the server as routine housekeeping, the user asked to recover and commit them — they're a real record of what was actually done to production data, not disposable scratch work.

**How to apply:**
- Directory: `migration_scripts/` at the git repo root (sibling to `price_themightygroupbuy/` and `Obsidian_pep_pricing_tool/`), with a `README.md` explaining the folder's purpose.
- Naming: `YYYY-MM-DD-descriptive-name.php`.
- Each script keeps a header comment explaining what it did, why, and what it replicated (e.g. "mirrors backend/api/vendors/pending_imports.php's approve logic exactly").
- Write the script directly into `migration_scripts/` (with paths relative to that location, e.g. `__DIR__ . '/../price_themightygroupbuy/backend/config.php'`) rather than a scratchpad/temp path, then `scp`/copy it to the server to actually run — so there's no separate "reconstruct it afterward" step and no risk of losing it if server cleanup happens first.
- Only scripts with a real, lasting effect on production data belong here. Read-only diagnostic/verification scripts (dumping cache contents, checking a new function against live data, debugging a failure) go in the sibling `diagnostic_scripts/` directory instead — see [[feedback_archive_diagnostic_scripts]]. Deliberately-rolled-back test transactions that never persist anything don't need archiving anywhere.
- Commit normally afterward, per [[feedback_commit_style]] (short message, no per-file breakdown, no trailers).
