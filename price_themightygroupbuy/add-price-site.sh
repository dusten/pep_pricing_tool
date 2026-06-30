#!/usr/bin/env bash
# ============================================================
# add-price-site.sh
# Add price.themightygroupbuy.com to an existing EC2 instance
# that already runs grp.themightygroupbuy.com.
#
# Prerequisites:
#   1. DNS: A record for price.themightygroupbuy.com → this server's IP
#   2. Code already deployed to /home/ec2-user/price_themightygroupbuy/
#      via deploy.sh from your local machine
#   3. Run as ec2-user with sudo access
# ============================================================
set -euo pipefail

APP_DIR="/home/ec2-user/price_themightygroupbuy"
DOMAIN="price.themightygroupbuy.com"
ENV_FILE="${APP_DIR}/.env_price"

# ── [1/5] Create database ─────────────────────────────────────
echo "=== [1/5] Create database ==="
read -rp  "DB name [tmgb_price]: "  DB_NAME; DB_NAME=${DB_NAME:-tmgb_price}
read -rp  "DB user [pc_user]: "     DB_USER; DB_USER=${DB_USER:-pc_user}
DB_PASS=$(openssl rand -base64 18 | tr -d '/+=')

read -rsp "MySQL root password: " DB_ROOT_PASS; echo

mysql -uroot -p"${DB_ROOT_PASS}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER,
      LOCK TABLES, EVENT
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "Importing schema…"
mysql -u"${DB_USER}" -p"${DB_PASS}" -h127.0.0.1 "${DB_NAME}" \
  < "${APP_DIR}/database/schema.sql"
echo "Database ready."

# ── [2/5] Write .env_price ────────────────────────────────────
echo "=== [2/5] Write .env_price ==="
APP_SECRET=$(openssl rand -hex 32)

read -rsp "BREVO_API_KEY (leave blank to set later): "     BREVO_KEY;     echo
read -rsp "ANTHROPIC_API_KEY (leave blank to set later): " ANTHROPIC_KEY; echo

cat > "${ENV_FILE}" <<ENV
APP_ENV=production
APP_URL=https://${DOMAIN}
APP_SECRET=${APP_SECRET}

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

MC_HOST=127.0.0.1
MC_PORT=11211

BREVO_API_KEY=${BREVO_KEY}
ANTHROPIC_API_KEY=${ANTHROPIC_KEY}

# Stripe — populate when Phase 2 billing is built
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
STRIPE_PRICE_ADV_MONTHLY=
STRIPE_PRICE_ADV_ANNUAL=
STRIPE_PRICE_PRO_MONTHLY=
STRIPE_PRICE_PRO_ANNUAL=
STRIPE_PRICE_EXPERT_MONTHLY=
STRIPE_PRICE_EXPERT_ANNUAL=
ENV
chmod 600 "${ENV_FILE}"
echo "Written: ${ENV_FILE}"

# MySQL options file so cron jobs never need passwords on the command line
cat > /home/ec2-user/.pc_my.cnf <<CNF
[client]
user=${DB_USER}
password=${DB_PASS}
host=127.0.0.1
database=${DB_NAME}
CNF
chmod 600 /home/ec2-user/.pc_my.cnf
echo "Written: /home/ec2-user/.pc_my.cnf"

# ── [3/5] Storage directory ───────────────────────────────────
echo "=== [3/5] Storage directory ==="
mkdir -p "${APP_DIR}/backend/storage/vendor_files"
mkdir -p "${APP_DIR}/public/dist"
sudo chown -R ec2-user:apache "${APP_DIR}/backend/storage"
sudo chmod -R 770              "${APP_DIR}/backend/storage"
sudo chcon -Rt httpd_sys_rw_content_t "${APP_DIR}/backend/storage" 2>/dev/null || true

# ── [4/5] Apache vhost + SELinux ──────────────────────────────
echo "=== [4/5] Apache vhost + SELinux ==="
sudo cp "${APP_DIR}/price.conf" /etc/httpd/conf.d/price.conf
sudo chcon -Rt httpd_sys_content_t "${APP_DIR}/public"      2>/dev/null || true
sudo chcon -Rt httpd_sys_content_t "${APP_DIR}/public/dist" 2>/dev/null || true
sudo systemctl reload httpd
echo "Apache reloaded."

# Install cron
sudo cp "${APP_DIR}/cron/price" /etc/cron.d/price
sudo chmod 644 /etc/cron.d/price
echo "Cron installed."

# ── [5/5] SSL certificate ─────────────────────────────────────
echo "=== [5/5] SSL certificate ==="
sudo certbot --apache -d "${DOMAIN}" \
  --non-interactive --agree-tos -m "admin@themightygroupbuy.com"

# ── Done ──────────────────────────────────────────────────────
echo ""
echo "✅  ${DOMAIN} is live!"
echo ""
echo "   DB name:    ${DB_NAME}"
echo "   DB user:    ${DB_USER}"
echo "   DB pass:    ${DB_PASS}   ← saved to ${ENV_FILE}"
echo ""
echo "   Verify: curl -s https://${DOMAIN}/api/app-settings"
