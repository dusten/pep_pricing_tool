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
#   3. Run as root: sudo bash add-price-site.sh
# ============================================================
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2; exit 1
fi

APP_DIR="/home/ec2-user/price_themightygroupbuy"
DOMAIN="price.themightygroupbuy.com"
ENV_FILE="/home/ec2-user/.env_pricetool"
DB_NAME="tmgb_price"
DB_USER="tmgb_price"
DB_PASS=$(openssl rand -base64 18 | tr -d '/+=')

# ── [1/5] Create database ─────────────────────────────────────
echo "=== [1/5] Create database ==="
read -rsp "MariaDB root password: " DB_ROOT_PASS; echo

mysql -uroot -p"${DB_ROOT_PASS}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER,
      LOCK TABLES, EVENT
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
-- mysql.slow_log is server-wide (shared with other DBs on this box); the
-- pc_import_slow_queries EVENT needs this to read/clear its own db's rows.
GRANT SELECT, DELETE ON mysql.slow_log TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "Importing schema…"
mysql -uroot -p"${DB_ROOT_PASS}" -h127.0.0.1 "${DB_NAME}" \
  < "${APP_DIR}/database/schema.sql"
echo "Database ready."

# ── [2/5] Write ~/.env_pricetool ─────────────────────────────
echo "=== [2/5] Write ${ENV_FILE} ==="
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
chmod 640 "${ENV_FILE}"
chown ec2-user:apache "${ENV_FILE}"
echo "Written: ${ENV_FILE}"

# MySQL options file for cron / migrate (no password on command line)
cat > /home/ec2-user/.pc_my.cnf <<CNF
[client]
user=${DB_USER}
password=${DB_PASS}
host=127.0.0.1
database=${DB_NAME}
CNF
chmod 600 /home/ec2-user/.pc_my.cnf
chown ec2-user:ec2-user /home/ec2-user/.pc_my.cnf
echo "Written: /home/ec2-user/.pc_my.cnf"

# ── ClamAV (malware scanning on vendor file uploads) ──────────
# This server was originally provisioned by the sibling grp app's
# setup-al2023.sh, which never installed ClamAV either — install it here too.
echo "=== ClamAV setup ==="
dnf install -y clamav clamav-update clamav-filesystem clamav-server clamav-server-systemd
sed -i 's/^Example/#Example/' /etc/freshclam.conf
freshclam
sed -i 's/^Example/#Example/' /etc/clamd.d/scan.conf
systemctl enable --now clamd@scan
systemctl enable --now clamav-freshclam 2>/dev/null || \
  echo "  ⚠  clamav-freshclam.service not found — cron a daily 'freshclam' instead."
echo "  ✓ ClamAV installed, signatures loaded, clamd@scan running"

# ── [3/5] Storage directory ───────────────────────────────────
echo "=== [3/5] Storage directory ==="
mkdir -p "${APP_DIR}/backend/storage/vendor_files"
mkdir -p "${APP_DIR}/public/dist"
chown -R ec2-user:apache "${APP_DIR}/backend/storage"
chmod -R 770              "${APP_DIR}/backend/storage"
chcon -Rt httpd_sys_rw_content_t "${APP_DIR}/backend/storage" 2>/dev/null || true

# ── [4/5] Apache vhost + SELinux ──────────────────────────────
echo "=== [4/5] Apache vhost + SELinux ==="
cp "${APP_DIR}/price.conf" /etc/httpd/conf.d/price.conf
chcon -Rt httpd_sys_content_t "${APP_DIR}/public"      2>/dev/null || true
chcon -Rt httpd_sys_content_t "${APP_DIR}/public/dist" 2>/dev/null || true
systemctl reload httpd
echo "Apache reloaded."

cp "${APP_DIR}/cron/price" /etc/cron.d/price
chmod 644 /etc/cron.d/price
echo "Cron installed."

# ── [5/5] SSL certificate ─────────────────────────────────────
echo "=== [5/5] SSL certificate ==="
certbot --apache -d "${DOMAIN}" \
  --non-interactive --agree-tos -m "admin@themightygroupbuy.com"

# ── Done ──────────────────────────────────────────────────────
echo ""
echo "✅  ${DOMAIN} is live!"
echo ""
echo "   DB name:    ${DB_NAME}"
echo "   DB user:    ${DB_USER}"
echo "   DB pass:    ${DB_PASS}   ← saved to ${ENV_FILE}"
echo "   APP_SECRET: ${APP_SECRET}"
echo ""
echo "   Verify: curl -s https://${DOMAIN}/api/app-settings"
