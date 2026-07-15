#!/usr/bin/env bash
# ============================================================
# setup.sh — price.themightygroupbuy.com (Amazon Linux 2023)
# Full server setup for a FRESH EC2 instance.
# For adding to an existing grp. server, use add-price-site.sh.
#
# Prerequisites:
#   1. Code deployed to /home/ec2-user/price_themightygroupbuy/
#      (scp or rsync the repo before running this script)
#   2. DNS: A record for price.themightygroupbuy.com → this IP
#   3. Run as ec2-user with sudo access:
#        bash setup.sh [price.themightygroupbuy.com]
# ============================================================
set -euo pipefail

APP_DIR="/home/ec2-user/price_themightygroupbuy"
DOMAIN="${1:-price.themightygroupbuy.com}"
ENV_FILE="/home/ec2-user/.env_pricetool"

# ── [1/10] System update ──────────────────────────────────────
echo "=== [1/10] System update ==="
sudo dnf update -y

# ── [2/10] Apache, PHP 8.2, MariaDB 10.5, Memcached, Node ───
echo "=== [2/10] Install packages ==="
sudo dnf install -y \
  httpd \
  php8.2 php8.2-fpm php8.2-mysqlnd php8.2-curl php8.2-mbstring \
  php8.2-xml php8.2-zip php8.2-intl php8.2-gd php8.2-opcache \
  mariadb105-server mariadb105 \
  memcached \
  nodejs npm \
  certbot python3-certbot-apache \
  git unzip

# php8.2-memcached may need EPEL on some AL2023 AMIs
sudo dnf install -y php8.2-memcached 2>/dev/null || \
  echo "  ⚠  php8.2-memcached unavailable — rate limiting will degrade gracefully."

# ── ClamAV (malware scanning on vendor file uploads) ──────────
# Plain `clamav`/`clamd` (0.103 track) conflicts with clamav1.4 on this
# AL2023 image — clamav1.4/clamd1.4 is the track that actually installs.
echo "=== ClamAV setup ==="
sudo dnf install -y clamav1.4 clamav1.4-freshclam clamd1.4

sudo sed -i 's/^Example/#Example/' /etc/freshclam.conf
# database.clamav.net 403s the EC2 IP range — freshclam won't succeed here.
# Signatures are pushed in manually via deploy.sh from clamav-db/ instead
# (see clamav-db/README.md). Don't let this abort setup on a fresh install.
sudo freshclam || echo "  ⚠  freshclam blocked (expected on EC2) — signatures pushed via deploy.sh's clamav-db/ instead."

# clamd@scan listens on a unix socket at /run/clamd.scan/clamd.sock by default
sudo sed -i 's/^Example/#Example/' /etc/clamd.d/scan.conf
sudo systemctl enable --now clamd@scan 2>/dev/null || \
  echo "  ⚠  clamd@scan won't start without signatures yet — will come up once deploy.sh pushes clamav-db/ over."

echo "  ✓ ClamAV installed, signatures loaded, clamd@scan running"
echo "    PHP calls: clamdscan --no-summary --fdpass <file>  (via clamd, not the slower clamscan CLI)"

# ── [3/10] PHP configuration ──────────────────────────────────
echo "=== [3/10] PHP configuration ==="
PHP_INI=$(php --ini 2>/dev/null | grep "Loaded Configuration" | awk '{print $NF}')
if [[ -n "$PHP_INI" && -f "$PHP_INI" ]]; then
  sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 30M/' "$PHP_INI"
  sudo sed -i 's/^post_max_size.*/post_max_size = 32M/'             "$PHP_INI"
  sudo sed -i 's/^max_execution_time.*/max_execution_time = 60/'    "$PHP_INI"
  sudo sed -i 's/^memory_limit.*/memory_limit = 256M/'              "$PHP_INI"
fi

PHP_FPM_CONF="/etc/php-fpm.d/www.conf"
sudo sed -i 's|^listen = .*|listen = /run/php-fpm/www.sock|'   "$PHP_FPM_CONF"
sudo sed -i 's/^;listen\.owner = .*/listen.owner = apache/'    "$PHP_FPM_CONF"
sudo sed -i 's/^;listen\.group = .*/listen.group = apache/'    "$PHP_FPM_CONF"
sudo sed -i 's/^listen\.owner = .*/listen.owner = apache/'     "$PHP_FPM_CONF"
sudo sed -i 's/^listen\.group = .*/listen.group = apache/'     "$PHP_FPM_CONF"

# ── [4/10] Apache vhost ───────────────────────────────────────
echo "=== [4/10] Apache vhost ==="
sudo cp "${APP_DIR}/price-standalone.conf" /etc/httpd/conf.d/price.conf

# ── [5/10] SELinux ────────────────────────────────────────────
echo "=== [5/10] SELinux permissions ==="
sudo setsebool -P httpd_can_network_connect 1
sudo setsebool -P httpd_can_network_connect_db 1
sudo setsebool -P httpd_enable_homedirs 1
sudo setsebool -P httpd_read_user_content 1
sudo chcon -Rt httpd_sys_content_t    "${APP_DIR}/public"          2>/dev/null || true
sudo chcon -Rt httpd_sys_content_t    "${APP_DIR}/public/dist"     2>/dev/null || true
sudo chcon -Rt httpd_sys_rw_content_t "${APP_DIR}/backend/storage" 2>/dev/null || true
sudo chcon -Rt httpd_sys_rw_content_t "${APP_DIR}/log"             2>/dev/null || true

# ── [6/10] MariaDB ────────────────────────────────────────────
echo "=== [6/10] MariaDB setup ==="
sudo systemctl enable --now mariadb

read -rp  "DB name [tmgb_price]: "  DB_NAME; DB_NAME=${DB_NAME:-tmgb_price}
read -rp  "DB user [pc_user]: "     DB_USER; DB_USER=${DB_USER:-pc_user}
DB_PASS=$(openssl rand -base64 18 | tr -d '/+=')

read -rsp "MariaDB root password (set it now for the first time): " DB_ROOT_PASS; echo

sudo mysql -uroot -p"${DB_ROOT_PASS}" <<SQL
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

# ── [7/10] Write .env_price ───────────────────────────────────
echo "=== [7/10] Write .env_price ==="
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

MAIL_DRIVER=brevo
MAIL_FROM_EMAIL=noreply@${DOMAIN}
MAIL_FROM_NAME=TheMightyGroupBuy Prices

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
chown ec2-user:apache "${ENV_FILE}"
chmod 640 "${ENV_FILE}"
sudo chcon -t httpd_sys_content_t "${ENV_FILE}" 2>/dev/null || true
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

# ── [8/10] Storage + log directories ─────────────────────────
echo "=== [8/10] Storage + log directories ==="
mkdir -p "${APP_DIR}/backend/storage/vendor_files"
mkdir -p "${APP_DIR}/backend/storage/vendor_suggestions"
mkdir -p "${APP_DIR}/backend/storage/quarantine"
mkdir -p "${APP_DIR}/public/dist"
mkdir -p "${APP_DIR}/log"
sudo chown -R ec2-user:apache "${APP_DIR}/backend/storage"
sudo chmod -R 770              "${APP_DIR}/backend/storage"
sudo chcon -Rt httpd_sys_rw_content_t "${APP_DIR}/backend/storage" 2>/dev/null || true
# Cron jobs run as ec2-user but PHP-FPM creates upload dirs/files as apache:apache
# (750/644) — without apache group membership the async workers can't read them.
sudo usermod -aG apache ec2-user
sudo chown apache:apache "${APP_DIR}/log"
sudo chmod 775           "${APP_DIR}/log"
sudo chcon -t httpd_sys_rw_content_t "${APP_DIR}/log" 2>/dev/null || true

# ── [9/10] Import schema + install cron ──────────────────────
echo "=== [9/10] Schema + cron ==="
mysql --defaults-file=/home/ec2-user/.pc_my.cnf "${DB_NAME}" \
  < "${APP_DIR}/database/schema.sql"
echo "Schema imported."

sudo cp "${APP_DIR}/cron/price" /etc/cron.d/price
sudo chmod 644 /etc/cron.d/price
echo "Cron installed."

# ── [10/10] Start services + SSL ─────────────────────────────
echo "=== [10/10] Start services ==="
sudo systemctl enable --now php-fpm memcached
sudo systemctl enable --now httpd

sudo certbot --apache -d "${DOMAIN}" \
  --non-interactive --agree-tos -m "admin@themightygroupbuy.com"

# ── Done ──────────────────────────────────────────────────────
echo ""
echo "✅  https://${DOMAIN} is live!"
echo ""
echo "   DB name:     ${DB_NAME}"
echo "   DB user:     ${DB_USER}"
echo "   DB pass:     ${DB_PASS}   ← saved to ${ENV_FILE}"
echo "   APP_SECRET:  ${APP_SECRET}"
echo ""
echo "   Verify: curl -s https://${DOMAIN}/api/app-settings"
echo ""
echo "   To deploy updates from your local machine:"
echo "     bash deploy.sh ${DOMAIN}"
