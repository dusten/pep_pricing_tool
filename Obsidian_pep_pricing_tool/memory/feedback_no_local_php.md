---
name: feedback-no-local-php
description: No PHP interpreter is installed on this local dev machine — don't run php -l or try to execute PHP locally for this project
metadata:
  type: feedback
---

There is no local PHP install for pep_pricing_tool's dev environment — `php -l` and any other local PHP execution will fail with "command not found".

**Why:** User corrected this directly after a `php -l` syntax-check attempt failed on every file. **Repeated 2026-07-04** — ran `which php`/`php -v` before remembering this was already settled; user had to stop and redirect to memory/wiki again. Treat this as settled, don't re-attempt any local PHP execution "just to check."

**How to apply:** Don't attempt local PHP syntax checks or local PHP execution for this project, including just probing whether a PHP binary exists. Rely on careful visual review of the diff, and let the live deploy + [[feedback_deploy_workflow|deploy.sh smoke check]] (especially `/api/health`) surface real breakage after deploying. Bash/shell scripts (deploy.sh etc.) can still be checked locally with `bash -n`.

**Bonus technique that caught a real bug (2026-07-04):** for binary output formats PHP generates (XLSX, ZIP, etc.), downloading the actual generated file through the live app and inspecting it with an unrelated local tool (e.g. Python's `openpyxl`/`zipfile`) is a legitimate, non-PHP way to verify correctness beyond visual code review — it caught a genuine invalid-XML bug (text written into a numeric-typed cell) that looked fine in the PHP source but produced a file real Excel/openpyxl couldn't open.
