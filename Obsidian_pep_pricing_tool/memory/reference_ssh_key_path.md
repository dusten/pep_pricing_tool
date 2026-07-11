---
name: reference-ssh-key-path
description: The SSH private key for price.themightygroupbuy.com's server always lives at this fixed path
metadata:
  type: reference
---

The SSH key for the `price_themightygroupbuy` prod server (`ec2-user@price.themightygroupbuy.com`) is always at:

`/home/dusten/projects/peptides_projects/pepcal_key.pem`

**How to apply:** Use this path directly for any manual `ssh`/`scp` verification against the live server (e.g. `ssh -i /home/dusten/projects/peptides_projects/pepcal_key.pem ec2-user@price.themightygroupbuy.com ...`). Don't guess at a path under the project directory or probe for it — `deploy.sh` derives its own key path separately (`SSH_KEY` env var override, defaulting to a sibling-of-parent location) and that logic doesn't need touching. See [[feedback_no_local_php]] for why manual live-server checks (via this key) are the verification method on this project.
