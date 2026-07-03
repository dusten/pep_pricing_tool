---
name: feedback-deploy-workflow
description: Run deploy.sh directly instead of a standalone build step — it already builds and syncs in one command
metadata:
  type: feedback
---

Never stop at a standalone `npm run build` (or a backend-only edit with no sync step) when a fix or feature is meant to go live. Run `deploy.sh` directly — its default/`--build` mode already runs `npm run build` and then syncs to the server in one command, and `--all` also applies schema/migrations first when the DB changed too. There's no reason to split "build" and "ship" into two steps.

**Why:** Built and committed the create-vendor fix locally, ran `npm run build`, then stopped — never called `deploy.sh`. The fix silently never reached production; the user hit the identical bug again with zero signal anything was wrong (no error, no failed command, just a stale bundle quietly still live). Only caught by diffing the live `index.html`'s asset hash against the local build. `deploy.sh` doing build+sync atomically exists specifically to prevent this gap.

**How to apply:** On `pep_pricing_tool`, treat "committed" and "built locally" as not done — "deployed" is done. After any change intended to ship, the actual last step is `bash deploy.sh` (add `--sync-schema` or use `--all` if the DB also changed), not `npm run build` alone. See [[feedback_commit_style|commit style]] and [[feedback_wiki_location|wiki location]] for the other standing process rules on this project.
