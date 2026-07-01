#!/usr/bin/env bash
# =============================================================
# deploy.sh — build and push price.themightygroupbuy.com
#
# Usage:
#   bash deploy.sh                          # full deploy — code only, never touches the DB
#   bash deploy.sh price.themightygroupbuy.com
#   bash deploy.sh --sync-schema            # DB only — applies schema.sql + migrate.sh, no code deploy
#   bash deploy.sh --sync-schema price.themightygroupbuy.com
#
# A full deploy and a schema sync are always separate, explicit actions —
# run both if you need both. Neither implies the other.
# =============================================================
set -euo pipefail

MODE="full"
HOST_ARG=""
for arg in "$@"; do
  case "$arg" in
    --sync-schema) MODE="sync-schema" ;;
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
# nothing else needs a full rebuild+deploy. Re-running schema.sql is safe —
# it's all CREATE TABLE IF NOT EXISTS / INSERT IGNORE.
if [[ "$MODE" == "sync-schema" ]]; then
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
  exit 0
fi

# ── Full deploy ────────────────────────────────────────────────
echo "▶ Building frontend…"
cd "$SCRIPT_DIR/frontend"
npm install
npm run build
cd "$SCRIPT_DIR"

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
