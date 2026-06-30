#!/usr/bin/env bash
# =============================================================
# deploy.sh — build and push price.themightygroupbuy.com
#
# Usage:
#   bash deploy.sh                         # uses default host
#   bash deploy.sh price.themightygroupbuy.com
# =============================================================
set -euo pipefail

REMOTE_HOST="${1:-price.themightygroupbuy.com}"
REMOTE_USER="ec2-user"
REMOTE_DIR="/home/ec2-user/price_themightygroupbuy"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# SSH key lives one directory above this script; override with SSH_KEY env var
SSH_KEY="${SSH_KEY:-$(dirname "$(dirname "$SCRIPT_DIR")")/pepcal_key.pem}"

echo "▶ Building frontend…"
cd "$SCRIPT_DIR/frontend"
npm ci --silent
npm run build
cd "$SCRIPT_DIR"

echo "▶ Syncing to $REMOTE_HOST…"
rsync -avz --delete \
  -e "ssh -i $SSH_KEY" \
  --exclude='.env_price' \
  --exclude='.git/' \
  --exclude='frontend/node_modules/' \
  --exclude='frontend/.vite/' \
  --exclude='backend/storage/vendor_files/' \
  "$SCRIPT_DIR/" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/"

echo "▶ Running remote post-deploy steps…"
ssh -i "$SSH_KEY" "$REMOTE_USER@$REMOTE_HOST" bash << 'REMOTE'
set -euo pipefail
cd /home/ec2-user/price_themightygroupbuy

# Apply any pending DB migrations
bash migrate.sh

# Install / refresh cron
sudo cp cron/price /etc/cron.d/price
sudo chmod 644 /etc/cron.d/price

# Graceful reload only — picks up config changes without dropping connections
# or affecting the grp. vhost sharing this server. Never restart here.
sudo systemctl reload httpd

echo "✓ Deploy complete"
REMOTE

echo "✓ Done — https://$REMOTE_HOST"
