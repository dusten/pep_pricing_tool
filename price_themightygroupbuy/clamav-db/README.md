# ClamAV signature files (not committed)

`database.clamav.net` blocks this project's EC2 IP range with a 403, so
`freshclam` can't run on the server. `deploy.sh` works around this
automatically: `fetch_clamav_db()` downloads `main.cvd`/`daily.cvd`/`bytecode.cvd`
from here (your machine's network, not the server's) before every file sync,
then the remote post-deploy step installs them into `/var/lib/clamav/` and
restarts `clamd@scan`.

Nothing to do manually — just run `deploy.sh` as usual. `.cvd`/`.cld` files
in this directory are gitignored and never touch git history.
