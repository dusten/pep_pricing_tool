#!/usr/bin/env bash
# =============================================================
# deploy.sh — build and push price.themightygroupbuy.com
#
# Usage:
#   bash deploy.sh                          # build + sync (default, code only, never touches the DB)
#   bash deploy.sh --sync-files             # sync files only — skips npm build, uses existing frontend/dist
#   bash deploy.sh --build                  # same as default: npm build, then sync
#   bash deploy.sh --sync-schema            # DB only — applies schema.sql + migrate.sh, no code deploy
#   bash deploy.sh --all                    # everything — schema sync, then build + sync
#   (append a hostname to any of the above to override the default target)
#
# These are always separate, explicit steps — pick the one you need.
# --all is the only mode that combines schema + code.
# =============================================================
set -euo pipefail

MODE="build"
HOST_ARG=""
for arg in "$@"; do
  case "$arg" in
    --sync-schema) MODE="sync-schema" ;;
    --sync-files)  MODE="sync-files" ;;
    --build)       MODE="build" ;;
    --all)         MODE="all" ;;
    *) HOST_ARG="$arg" ;;
  esac
done

REMOTE_HOST="${HOST_ARG:-price.themightygroupbuy.com}"
REMOTE_USER="ec2-user"
REMOTE_DIR="/home/ec2-user/price_themightygroupbuy"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# SSH key lives one directory above this script; override with SSH_KEY env var
SSH_KEY="${SSH_KEY:-$(dirname "$(dirname "$SCRIPT_DIR")")/pepcal_key.pem}"

# ── Schema/migrations-only sync ───────────────────────────────
# For when database/schema.sql or database/migrations/*.sql changed but
# nothing else needs a rebuild+deploy. Re-running schema.sql is safe —
# it's all CREATE TABLE IF NOT EXISTS / INSERT IGNORE.
sync_schema() {
  echo "▶ Syncing database/ and migrate.sh to $REMOTE_HOST…"
  rsync -avz \
    -e "ssh -i $SSH_KEY" \
    "$SCRIPT_DIR/database/" \
    "$REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/database/"
  rsync -avz \
    -e "ssh -i $SSH_KEY" \
    "$SCRIPT_DIR/migrate.sh" \
    "$REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/migrate.sh"

  echo "▶ Applying schema + migrations on $REMOTE_HOST…"
  REMOTE_SCRIPT='
  set -euo pipefail
  cd /home/ec2-user/price_themightygroupbuy
  mysql --defaults-file="$HOME/.pc_my.cnf" < database/schema.sql
  echo "schema.sql applied."
  bash migrate.sh
  '
  ssh -i "$SSH_KEY" "$REMOTE_USER@$REMOTE_HOST" bash -s <<< "$REMOTE_SCRIPT"

  echo "✓ Schema sync complete — https://$REMOTE_HOST"
}

# ── File sync + remote post-deploy steps (no build) ───────────
# Assumes frontend/dist is already up to date (either committed or built
# in a prior --build/--all run). Use this for backend-only or config-only
# changes where rebuilding the frontend would just be wasted time.
sync_files() {
  echo "▶ Syncing to $REMOTE_HOST…"
  rsync -avz --delete \
    -e "ssh -i $SSH_KEY" \
    --exclude='.env_pricetool' \
    --exclude='.git/' \
    --exclude='frontend/node_modules/' \
    --exclude='frontend/.vite/' \
    --exclude='backend/storage/vendor_files/' \
    --exclude='log/' \
    "$SCRIPT_DIR/" \
    "$REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/"

  echo "▶ Running remote post-deploy steps…"
  REMOTE_SCRIPT='
  set -euo pipefail
  cd /home/ec2-user/price_themightygroupbuy

  sudo cp cron/price /etc/cron.d/price
  sudo chmod 644 /etc/cron.d/price

  sudo chown -R apache:apache log/
  sudo chmod -R 775 log/
  sudo chcon -Rt httpd_sys_rw_content_t log/

  sudo systemctl reload php-fpm
  sudo systemctl reload httpd

  echo "Deploy complete"
  '
  ssh -i "$SSH_KEY" "$REMOTE_USER@$REMOTE_HOST" bash -s <<< "$REMOTE_SCRIPT"

  echo "✓ Done — https://$REMOTE_HOST"
  smoke_check
}

# ── Post-deploy smoke check ────────────────────────────────────
# Confirms the deploy actually landed, not just that rsync/ssh exited 0.
# TODO(backlog): replace the app-settings check with a real /api/health
# endpoint that also exercises DB writes, Memcached, and email config —
# see CLAUDE.md backlog item 3.
smoke_check() {
  echo "▶ Smoke check…"
  local home_code api_code
  home_code=$(curl -s -o /dev/null -w '%{http_code}' "https://$REMOTE_HOST/")
  api_code=$(curl -s -o /dev/null -w '%{http_code}' "https://$REMOTE_HOST/api/app-settings")

  if [[ "$home_code" == "200" && "$api_code" == "200" ]]; then
    echo "✓ Smoke check passed (homepage $home_code, api $api_code)"
  else
    echo "✗ Smoke check FAILED (homepage $home_code, api $api_code) — deploy may not have landed cleanly" >&2
    exit 1
  fi
}

# ── Build frontend, then sync files ────────────────────────────
build_and_sync() {
  echo "▶ Building frontend…"
  cd "$SCRIPT_DIR/frontend"
  npm install
  npm run build
  cd "$SCRIPT_DIR"

  sync_files
}

case "$MODE" in
  sync-schema) sync_schema ;;
  sync-files)  sync_files ;;
  build)       build_and_sync ;;
  all)         sync_schema; build_and_sync ;;
esac
