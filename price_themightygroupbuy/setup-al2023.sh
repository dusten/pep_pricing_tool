#!/usr/bin/env bash
# --------------------------------------------------------------------------
# setup-al2023.sh — TheMightyGroupBuy Full Installer & Configurator
# Amazon Linux 2023 (AL2023) EC2 instances
#
# Installs AND configures:
#   • Apache 2.4+  (VirtualHost, mod_rewrite, SSL-ready, SPA + API routing)
#   • PHP 8.2+     (mysqlnd, pdo, mbstring, opcache, memcached extension)
#   • MariaDB 10.5 (secured, DB created, user provisioned, schema+seed loaded)
#   • Memcached    (128 MB, loopback-only — PHP session backend)
#   • Node.js      (frontend build)
#   • Application  (.env written, permissions set, frontend compiled)
#
# Config source of truth: /home/ec2-user/.env  (loaded by env.php)
# Apache carries NO SetEnv directives — all config lives in .env only.
#
# Usage:
#   chmod +x setup-al2023.sh
#   sudo ./setup-al2023.sh
# --------------------------------------------------------------------------
set -euo pipefail

# ── Guard: must run as root ───────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root (sudo ./setup-al2023.sh)"
    exit 1
fi

# ── Resolve project root (directory containing this script) ───────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

# Validate expected project structure is present
for required in backend/public/index.php database/schema.sql database/seed.sql frontend/package.json; do
    if [ ! -f "$PROJECT_ROOT/$required" ]; then
        echo "ERROR: Expected file not found: $PROJECT_ROOT/$required"
        echo "       Sync the project first: ./deploy.sh --sync-only"
        exit 1
    fi
done

# ── Configuration ─────────────────────────────────────────────────────────
SERVER_NAME="grp.themightygroupbuy.com"
APP_DIR="/home/ec2-user/themightygroupbuy"

# .env lives one level above the app dir — this is where env.php expects it.
# index.php calls loadEnv(dirname(ROOT) . '/.env') → /home/ec2-user/.env
ENV_PATH="$(dirname "$APP_DIR")/.env"

# Database
DB_NAME="themightygroupbuy"
DB_USER="tmgb_user"
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"
MARIADB_ROOT_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"

# Memcached
MC_HOST="127.0.0.1"
MC_PORT="11211"

# Credentials file (root-only)
CRED_FILE="/root/.tmgb-credentials"

# Detect public IP for info display and DNS pre-check
SERVER_IP="$(curl -s --connect-timeout 5 http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || hostname -I | awk '{print $1}')"

echo "=========================================="
echo "  TheMightyGroupBuy — Full Install"
echo "=========================================="
echo ""
echo "  Project root : $PROJECT_ROOT"
echo "  Install to   : $APP_DIR"
echo "  .env path    : $ENV_PATH"
echo "  Server IP    : $SERVER_IP"
echo "  Server name  : $SERVER_NAME"
echo "  DB name      : $DB_NAME"
echo "  DB user      : $DB_USER"
echo ""

# ══════════════════════════════════════════════════════════════════════════
# PHASE 1: INSTALL PACKAGES
# ══════════════════════════════════════════════════════════════════════════

echo "┌──────────────────────────────────────────┐"
echo "│  PHASE 1: Installing system packages     │"
echo "└──────────────────────────────────────────┘"

# ── 1.1 System update ─────────────────────────────────────────────────────
echo ""
echo "[1/7] Updating system packages..."
dnf update -y -q

# ── 1.2 Apache + Certbot ──────────────────────────────────────────────────
echo ""
echo "[2/7] Installing Apache + Certbot (Let's Encrypt)..."
dnf install -y httpd mod_ssl certbot python3-certbot-apache
systemctl enable httpd
echo "  ✓ Apache installed"

# ── 1.3 PHP 8.2 ───────────────────────────────────────────────────────────
echo ""
echo "[3/7] Installing PHP 8.2..."
# AL2023 uses versioned package names directly — no dnf module streams.
# php8.2-json does not exist; JSON is built into PHP core since 7.4.
dnf install -y php8.2 php8.2-cli php8.2-mysqlnd php8.2-pdo \
    php8.2-mbstring php8.2-xml php8.2-opcache php8.2-pecl-memcached php8.2-zip
# curl may be bundled in the base package on some AL2023 AMIs
dnf install -y php8.2-curl 2>/dev/null \
    || echo "  Note: php8.2-curl not available separately (may be included in base)"
echo "  ✓ PHP 8.2 installed: $(php -v 2>/dev/null | head -1)"

# ── 1.4 Memcached ─────────────────────────────────────────────────────────
echo ""
echo "[4/7] Installing Memcached..."
dnf install -y memcached
systemctl enable memcached
echo "  ✓ Memcached installed"

# ── 1.5 MariaDB ───────────────────────────────────────────────────────────
echo ""
echo "[5/7] Installing MariaDB..."
dnf install -y mariadb105-server mariadb105
systemctl enable mariadb
echo "  ✓ MariaDB installed"

# ── 1.6 Node.js ───────────────────────────────────────────────────────────
echo ""
echo "[6/7] Installing Node.js..."
dnf install -y nodejs npm
echo "  ✓ Node.js installed: $(node --version)"

echo ""
echo "[7/7] Verifying certbot..."
certbot --version
echo "  ✓ Certbot ready"

echo ""
echo "  All packages installed."

# ══════════════════════════════════════════════════════════════════════════
# PHASE 2: CONFIGURE SERVICES
# ══════════════════════════════════════════════════════════════════════════

echo ""
echo "┌──────────────────────────────────────────┐"
echo "│  PHASE 2: Configuring services           │"
echo "└──────────────────────────────────────────┘"

# ── 2.1 Memcached ─────────────────────────────────────────────────────────
echo ""
echo "[2.1] Configuring Memcached..."
cat > /etc/sysconfig/memcached << 'MEMCACHED_CONF'
PORT="11211"
USER="memcached"
MAXCONN="1024"
CACHESIZE="128"
OPTIONS="-l 127.0.0.1 -U 0"
MEMCACHED_CONF
systemctl restart memcached
echo "  ✓ Memcached: 128 MB, loopback-only, no UDP"

# ── 2.2 MariaDB — secure, create DB + user, import schema ─────────────────
echo ""
echo "[2.2] Configuring MariaDB..."

cat > /etc/my.cnf.d/tmgb.cnf << 'MARIADB_CNF'
[mysqld]
# Character set
character-set-server = utf8mb4
collation-server     = utf8mb4_unicode_ci

# InnoDB tuning (small single-app instance)
innodb_buffer_pool_size        = 128M
innodb_log_file_size           = 64M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method            = O_DIRECT

# Connections
max_connections = 50

# Slow query log
slow_query_log      = 1
slow_query_log_file = /var/log/mariadb/slow.log
long_query_time     = 2

# Security
local_infile = 0
bind-address = 127.0.0.1

[client]
default-character-set = utf8mb4
MARIADB_CNF

mkdir -p /var/log/mariadb
chown mysql:mysql /var/log/mariadb
systemctl restart mariadb

echo "  Securing MariaDB..."
mariadb -u root << SECURE_SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${MARIADB_ROOT_PASS}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
SECURE_SQL
echo "  ✓ MariaDB secured (root password set, anonymous users removed)"

echo "  Creating database and user..."
mariadb -u root -p"${MARIADB_ROOT_PASS}" << APP_SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
APP_SQL
echo "  ✓ Database '${DB_NAME}' and user '${DB_USER}' created"

echo "  Importing schema..."
mariadb -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "$PROJECT_ROOT/database/schema.sql"
echo "  ✓ Schema imported"

echo "  Importing seed data..."
mariadb -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "$PROJECT_ROOT/database/seed.sql"
echo "  ✓ Seed data imported (365 daily quotes)"

# ── 2.3 PHP configuration ─────────────────────────────────────────────────
echo ""
echo "[2.3] Configuring PHP..."

PHP_CONF_DIR="/etc/php.d"
[ -d "$PHP_CONF_DIR" ] || PHP_CONF_DIR="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;' 2>/dev/null)"

cat > "${PHP_CONF_DIR}/99-tmgb.ini" << 'PHP_OVERRIDE'
; ── TheMightyGroupBuy PHP overrides ──

; Sessions via Memcached
session.save_handler    = memcached
session.save_path       = "127.0.0.1:11211"
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
session.use_only_cookies = 1
session.name            = TMGB_SESSID
session.gc_maxlifetime  = 1800

; Timezone
date.timezone = UTC

; Uploads
upload_max_filesize = 20M
post_max_size       = 22M
max_input_vars      = 1000
memory_limit        = 256M
max_execution_time  = 30

; Error handling (production)
display_errors         = Off
display_startup_errors = Off
log_errors             = On
error_log              = /var/log/php-fpm/www-error.log
error_reporting        = E_ALL & ~E_DEPRECATED & ~E_STRICT

; OPcache
opcache.enable                  = 1
opcache.memory_consumption      = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files   = 4000
opcache.revalidate_freq         = 60
opcache.fast_shutdown           = 1

; Security
expose_php         = Off
allow_url_fopen    = On
allow_url_include  = Off
PHP_OVERRIDE
echo "  ✓ PHP configured (${PHP_CONF_DIR}/99-tmgb.ini)"

# ── 2.3b PHP-FPM pool configuration ──────────────────────────────────────
# The FPM pool's php_admin_value[error_log] overrides php.ini, so we align
# both to /var/log/php-fpm/www-error.log and enable catch_workers_output so
# worker stderr is captured rather than silently discarded.
PHP_FPM_POOL="/etc/php-fpm.d/www.conf"
if [ -f "$PHP_FPM_POOL" ]; then
    # Enable catch_workers_output (uncomment if present, add if missing)
    if grep -q '^;catch_workers_output' "$PHP_FPM_POOL"; then
        sed -i 's/^;catch_workers_output/catch_workers_output/' "$PHP_FPM_POOL"
    elif ! grep -q '^catch_workers_output' "$PHP_FPM_POOL"; then
        echo 'catch_workers_output = yes' >> "$PHP_FPM_POOL"
    fi
    # Ensure error_log points to our log file
    if grep -q 'php_admin_value\[error_log\]' "$PHP_FPM_POOL"; then
        sed -i "s|php_admin_value\[error_log\].*|php_admin_value[error_log] = /var/log/php-fpm/www-error.log|" "$PHP_FPM_POOL"
    else
        echo 'php_admin_value[error_log] = /var/log/php-fpm/www-error.log' >> "$PHP_FPM_POOL"
    fi
    echo "  ✓ PHP-FPM pool: catch_workers_output=yes, error_log=/var/log/php-fpm/www-error.log"
fi

# Ensure log file exists and is writable by the FPM worker (apache user)
mkdir -p /var/log/php-fpm
touch /var/log/php-fpm/www-error.log
chown root:apache /var/log/php-fpm/www-error.log
chmod 664 /var/log/php-fpm/www-error.log
echo "  ✓ PHP-FPM log file ready: /var/log/php-fpm/www-error.log"

# ── 2.4 Application files ─────────────────────────────────────────────────
echo ""
echo "[2.4] Deploying application..."
mkdir -p "$APP_DIR"
REAL_PROJECT="$(realpath "$PROJECT_ROOT")"
REAL_APP="$(realpath "$APP_DIR")"
if [ "$REAL_PROJECT" != "$REAL_APP" ]; then
    rsync -a --delete \
        --exclude='node_modules' \
        --exclude='.git' \
        --exclude='.env' \
        "$PROJECT_ROOT/" "$APP_DIR/"
    echo "  ✓ Application files copied to $APP_DIR"
else
    echo "  ✓ Application already at $APP_DIR (in-place install)"
fi

# ── 2.5 Create .env ───────────────────────────────────────────────────────
# Written to dirname(APP_DIR) = /home/ec2-user/.env
# This matches index.php: loadEnv(dirname(ROOT) . '/.env')
echo ""
echo "[2.5] Creating .env at ${ENV_PATH}..."
cat > "${ENV_PATH}" << ENV_FILE
# TheMightyGroupBuy — Environment Configuration
# Generated by setup-al2023.sh on $(date -u '+%Y-%m-%d %H:%M:%S UTC')
# ─────────────────────────────────────────────────────────────────
# This file is the single source of truth for all app config.
# Apache carries no SetEnv directives — env.php loads this file.

# ── Application ──────────────────────────────────────────────────
APP_URL=https://${SERVER_NAME}
FRONTEND_URL=https://${SERVER_NAME}

# ── Database ─────────────────────────────────────────────────────
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

# ── Memcached ────────────────────────────────────────────────────
MEMCACHED_HOST=${MC_HOST}
MEMCACHED_PORT=${MC_PORT}

# ── Email ────────────────────────────────────────────────────────
# MAIL_DRIVER options: brevo | smtp | log
MAIL_DRIVER=log
MAIL_FROM_EMAIL=noreply@grp.themightygroupbuy.com
MAIL_FROM_NAME=TheMightyGroupBuy

# Brevo — set MAIL_DRIVER=brevo, add your API key, and authorize
# the server's outbound IP at: https://app.brevo.com/security/authorised_ips
# Verify the sender domain at:  https://app.brevo.com/senders (SPF + DKIM)
BREVO_API_KEY=

ENV_FILE
chown ec2-user:apache "${ENV_PATH}"
chmod 640 "${ENV_PATH}"
echo "  ✓ .env written to ${ENV_PATH}"
echo "    (update MAIL_DRIVER and BREVO_API_KEY when ready)"

# ── 2.6 Build frontend ────────────────────────────────────────────────────
echo ""
echo "[2.6] Building frontend..."
cd "$APP_DIR/frontend"
npm install --no-audit --no-fund 2>&1 | tail -1
npm run build 2>&1 | tail -3
cd "$PROJECT_ROOT"
echo "  ✓ Frontend built → $APP_DIR/frontend/dist/"

# ── 2.6b Backend Composer dependencies ───────────────────────────────────────
echo ""
echo "[2.6b] Installing backend Composer dependencies..."
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    echo "  ✓ Composer installed to /usr/local/bin/composer"
fi
cd "$APP_DIR/backend"
composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -5
cd "$PROJECT_ROOT"
echo "  ✓ Backend Composer packages installed"

# ── 2.7 File permissions ──────────────────────────────────────────────────
echo ""
echo "[2.7] Setting permissions..."
APACHE_GROUP="apache"

# Apache must traverse the ec2-user home to reach the app
chmod o+x /home/ec2-user

# App files: owned by ec2-user, group-readable by Apache
chown -R ec2-user:${APACHE_GROUP} "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
find "$APP_DIR" -type f -exec chmod 640 {} \;

# Backend public/ must be fully readable by Apache (PHP-FPM executes these)
find "$APP_DIR/backend/public" -type d -exec chmod 755 {} \;
find "$APP_DIR/backend/public" -type f -exec chmod 644 {} \;

# Frontend dist/ must be fully readable by Apache (served as static files)
find "$APP_DIR/frontend/dist" -type d -exec chmod 755 {} \;
find "$APP_DIR/frontend/dist" -type f -exec chmod 644 {} \;

# email-templates/mail.log needs to be writable by Apache
touch "$APP_DIR/email-templates/mail.log" 2>/dev/null || true
chown ec2-user:${APACHE_GROUP} "$APP_DIR/email-templates/mail.log" 2>/dev/null || true
chmod 664 "$APP_DIR/email-templates/mail.log" 2>/dev/null || true

# backend/storage/labels/ — PHP-FPM writes label PDFs here; not web-accessible
mkdir -p "$APP_DIR/backend/storage/labels"
chown ec2-user:${APACHE_GROUP} "$APP_DIR/backend/storage"
chown ec2-user:${APACHE_GROUP} "$APP_DIR/backend/storage/labels"
chmod 770 "$APP_DIR/backend/storage"
chmod 770 "$APP_DIR/backend/storage/labels"
echo "  ✓ Label storage directory ready: $APP_DIR/backend/storage/labels"
# backend/lib/font/ — FPDF 1.9 core font JSON files (required by fpdf.php)
FONT_DIR="$APP_DIR/backend/lib/font"
if [ ! -f "$FONT_DIR/helvetica.json" ]; then
    echo "  Downloading FPDF 1.9 core fonts from fpdf.org..."
    mkdir -p "$FONT_DIR"
    cd /tmp
    if curl -sL "http://www.fpdf.org/en/dl.php?v=19&f=zip" -o fpdf19.zip 2>/dev/null && \
       unzip -o fpdf19.zip -d fpdf_extract >/dev/null 2>&1; then
        FONT_JSON=$(find /tmp/fpdf_extract -name "helvetica.json" -type f 2>/dev/null | head -1)
        if [ -n "$FONT_JSON" ]; then
            FONT_SRC=$(dirname "$FONT_JSON")
            cp "$FONT_SRC"/*.json "$FONT_DIR/"
            echo "  ✓ FPDF core fonts installed to $FONT_DIR"
        else
            echo "  ✗ Font files not found in archive — copy font/ dir from fpdf.org manually"
        fi
    else
        echo "  ✗ FPDF download failed — copy font/ dir from fpdf.org manually"
    fi
    rm -rf /tmp/fpdf19.zip /tmp/fpdf_extract
    cd - >/dev/null
else
    echo "  ✓ FPDF core fonts already present ($FONT_DIR)"
fi

# setup script stays executable
chmod 750 "$APP_DIR/setup-al2023.sh"

echo "  ✓ Permissions set (owner: ec2-user, group: apache)"

# ── 2.8 SELinux ───────────────────────────────────────────────────────────
# AL2023 runs SELinux in enforcing mode by default. Without these booleans
# and file contexts, httpd cannot read files under /home/ec2-user even if
# Unix permissions are correct.
echo ""
echo "[2.8] Configuring SELinux..."
if command -v getenforce &>/dev/null && [ "$(getenforce)" != "Disabled" ]; then
    setsebool -P httpd_enable_homedirs 1
    setsebool -P httpd_read_user_content 1
    setsebool -P httpd_can_network_connect 1
    chcon -R -t httpd_sys_content_t "${APP_DIR}/"
    chcon    -t httpd_sys_content_t "${ENV_PATH}"
    echo "  ✓ SELinux booleans: httpd_enable_homedirs=1, httpd_read_user_content=1, httpd_can_network_connect=1"
    echo "  ✓ SELinux contexts: httpd_sys_content_t applied to app dir and .env"
else
    echo "  ✓ SELinux disabled — skipping"
fi

# ── 2.9 Apache VirtualHost ────────────────────────────────────────────────
echo ""
echo "[2.9] Configuring Apache..."

# Enable mod_rewrite if commented out
HTTPD_CONF="/etc/httpd/conf/httpd.conf"
if grep -q "^#LoadModule rewrite_module" "$HTTPD_CONF" 2>/dev/null; then
    sed -i 's/^#LoadModule rewrite_module/LoadModule rewrite_module/' "$HTTPD_CONF"
fi

# Disable default welcome page
[ -f /etc/httpd/conf.d/welcome.conf ] \
    && mv /etc/httpd/conf.d/welcome.conf /etc/httpd/conf.d/welcome.conf.disabled

# Disable default ssl.conf (certbot manages SSL)
[ -f /etc/httpd/conf.d/ssl.conf ] \
    && mv /etc/httpd/conf.d/ssl.conf /etc/httpd/conf.d/ssl.conf.disabled

# Global security hardening
cat > /etc/httpd/conf.d/00-security.conf << 'SECURITY_CONF'
# TheMightyGroupBuy — Global Apache Security
ServerTokens Prod
ServerSignature Off
Header always set X-Content-Type-Options "nosniff"
TraceEnable Off
Timeout 60
KeepAlive On
MaxKeepAliveRequests 100
KeepAliveTimeout 5
SECURITY_CONF

# Main VirtualHost
# - No SetEnv directives: all config is in /home/ec2-user/.env, loaded by env.php
# - Frontend (Vue SPA) at /          → frontend/dist  (FallbackResource for history mode)
# - API (PHP router) at /api         → backend/public (Alias + AllowOverride All)
# - certbot --apache fills in the SSL cert paths automatically
cat > /etc/httpd/conf.d/tmgb.conf << 'VHOST_CONF'
# TheMightyGroupBuy — Apache VirtualHost
# Generated by setup-al2023.sh
#
# Port 80 : redirect to HTTPS only
# Port 443 : SPA + API — NO SetEnv; all config via /home/ec2-user/.env

# ── HTTP → HTTPS redirect ────────────────────────────────────────────────────
<VirtualHost *:80>
    ServerName SERVER_NAME_PLACEHOLDER
    RewriteEngine on
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>

# ── HTTPS: full application ──────────────────────────────────────────────────
<VirtualHost *:443>
    ServerName SERVER_NAME_PLACEHOLDER

    SSLEngine on
    # certbot --apache fills in these three lines:
    #   SSLCertificateFile    /etc/letsencrypt/live/SERVER_NAME_PLACEHOLDER/fullchain.pem
    #   SSLCertificateKeyFile /etc/letsencrypt/live/SERVER_NAME_PLACEHOLDER/privkey.pem
    #   Include               /etc/letsencrypt/options-ssl-apache.conf

    # ── Frontend (Vue SPA) ───────────────────────────────────────────────────
    DocumentRoot APP_DIR_PLACEHOLDER/frontend/dist
    <Directory APP_DIR_PLACEHOLDER/frontend/dist>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /index.html
    </Directory>

    # Static assets: content-hashed filenames → aggressive caching
    <Directory APP_DIR_PLACEHOLDER/frontend/dist/assets>
        <IfModule mod_expires.c>
            ExpiresActive On
            ExpiresDefault "access plus 1 year"
        </IfModule>
        <IfModule mod_headers.c>
            Header set Cache-Control "public, max-age=31536000, immutable"
        </IfModule>
        FallbackResource disabled
    </Directory>

    # ── API (PHP router) ─────────────────────────────────────────────────────
    Alias /api APP_DIR_PLACEHOLDER/backend/public
    <Directory APP_DIR_PLACEHOLDER/backend/public>
        Options -Indexes -MultiViews +FollowSymLinks
        AllowOverride All
        Require all granted
        LimitRequestBody 2097152
    </Directory>

    # Block direct access to backend code outside public/
    <DirectoryMatch "^APP_DIR_PLACEHOLDER/backend/(api|config|includes|cron)">
        Require all denied
    </DirectoryMatch>

    # Block dotfiles (.env, .git, etc.)
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    # ── Security headers ─────────────────────────────────────────────────────
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # ── Compression ──────────────────────────────────────────────────────────
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
    </IfModule>

    # ── Logging ──────────────────────────────────────────────────────────────
    ErrorLog  /var/log/httpd/tmgb_ssl_error.log
    CustomLog /var/log/httpd/tmgb_ssl_access.log combined
    LogLevel warn
</VirtualHost>
VHOST_CONF

# Substitute runtime values (only path/hostname placeholders — no DB/credential SetEnv)
sed -i "s|APP_DIR_PLACEHOLDER|${APP_DIR}|g"         /etc/httpd/conf.d/tmgb.conf
sed -i "s|SERVER_NAME_PLACEHOLDER|${SERVER_NAME}|g" /etc/httpd/conf.d/tmgb.conf

echo "  Testing Apache config..."
if ! httpd -t 2>&1; then
    echo "  ✗ Apache config FAILED — check /etc/httpd/conf.d/tmgb.conf"
    exit 1
fi
echo "  ✓ Apache VirtualHost configured (no SetEnv — config via .env)"

# ── 2.10 Save credentials ─────────────────────────────────────────────────
cat > "$CRED_FILE" << CREDS
# TheMightyGroupBuy Credentials
# Generated: $(date -u '+%Y-%m-%d %H:%M:%S UTC')
# ─────────────────────────────────────────────

MariaDB Root Password : ${MARIADB_ROOT_PASS}
App DB Name           : ${DB_NAME}
App DB User           : ${DB_USER}
App DB Password       : ${DB_PASS}

# Credentials also written to:
#   ${ENV_PATH}
CREDS
chmod 600 "$CRED_FILE"
echo ""
echo "[2.10] Credentials saved to $CRED_FILE (root-only)"

# ══════════════════════════════════════════════════════════════════════════
# PHASE 3: START SERVICES & VERIFY
# ══════════════════════════════════════════════════════════════════════════

echo ""
echo "┌──────────────────────────────────────────┐"
echo "│  PHASE 3: Starting services & verifying  │"
echo "└──────────────────────────────────────────┘"
echo ""

systemctl restart memcached
systemctl restart mariadb
systemctl restart php-fpm
systemctl restart httpd
# cronie — cron daemon (not in AL2023 default image; required for /etc/cron.d/)
dnf install -y cronie -q
systemctl enable crond --now
echo "  ✓ Memcached, MariaDB, PHP-FPM, Apache, crond started"

echo ""
echo "────────────────────────────────────────────"
echo "  VERIFICATION"
echo "────────────────────────────────────────────"
echo ""
printf "  Apache    : "; httpd -v 2>&1 | head -1
printf "  PHP       : "; php -v 2>&1 | head -1
printf "  MariaDB   : "; mariadb --version 2>&1 | head -1
printf "  Node.js   : "; node --version
printf "  npm       : "; npm --version

echo ""
echo "  PHP extensions:"
php -m 2>/dev/null | grep -iE "pdo_mysql|session|openssl|memcached|opcache" | sed 's/^/    ✓ /'

echo ""
echo "  Apache modules:"
httpd -M 2>/dev/null | grep -E "rewrite|deflate|expires|headers|alias" | sed 's/^/    ✓ /'

echo ""
echo "  Database connectivity:"
TABLE_COUNT=$(mariadb -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null || echo "0")
if [ "$TABLE_COUNT" -gt 0 ]; then
    echo "    ✓ Connected to ${DB_NAME} (${TABLE_COUNT} tables)"
else
    echo "    ✗ Database connection FAILED"
fi

echo ""
echo "  Memcached:"
MC_TEST=$(php -r '$m=new Memcached();$m->addServer("127.0.0.1",11211);echo $m->getVersion()?"ok":"fail";' 2>/dev/null || echo "fail")
if [ "$MC_TEST" = "ok" ]; then
    echo "    ✓ Memcached responding"
else
    echo "    ✗ Memcached check failed"
fi

echo ""
echo "  HTTP check:"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    echo "    ✓ Apache serving on port 80 (HTTP $HTTP_CODE)"
else
    echo "    ⚠ Apache returned HTTP $HTTP_CODE"
fi

API_CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/api/auth/csrf" 2>/dev/null || echo "000")
if [ "$API_CODE" = "200" ]; then
    echo "    ✓ API responding at /api/auth/csrf (HTTP $API_CODE)"
else
    echo "    ⚠ API returned HTTP $API_CODE — check /var/log/httpd/tmgb_ssl_error.log"
fi

# ══════════════════════════════════════════════════════════════════════════
# PHASE 3.5: SSL VIA LET'S ENCRYPT (CERTBOT)
# ══════════════════════════════════════════════════════════════════════════

echo ""
echo "┌──────────────────────────────────────────┐"
echo "│  PHASE 3.5: Let's Encrypt SSL (certbot)  │"
echo "└──────────────────────────────────────────┘"
echo ""

# Check DNS resolves to this server before attempting the ACME HTTP-01 challenge
RESOLVED_IP="$(getent hosts "${SERVER_NAME}" 2>/dev/null | awk '{print $1}' | head -1 || echo "")"
SSL_OK=0

if [ -z "$RESOLVED_IP" ]; then
    echo "  ⚠ DNS lookup for ${SERVER_NAME} returned nothing."
    echo "    certbot skipped — run manually once DNS propagates:"
    echo "    sudo certbot --apache -d ${SERVER_NAME} --redirect --non-interactive --agree-tos -m admin@themightygroupbuy.com"
elif [ "$RESOLVED_IP" != "$SERVER_IP" ]; then
    echo "  ⚠ ${SERVER_NAME} resolves to ${RESOLVED_IP}, this server is ${SERVER_IP}."
    echo "    DNS is not pointing here yet — certbot skipped."
    echo "    Run manually once DNS is updated:"
    echo "    sudo certbot --apache -d ${SERVER_NAME} --redirect --non-interactive --agree-tos -m admin@themightygroupbuy.com"
else
    echo "  ✓ DNS check passed — ${SERVER_NAME} → ${SERVER_IP}"
    echo "  Running certbot..."
    if certbot --apache \
               --non-interactive \
               --agree-tos \
               --email "admin@themightygroupbuy.com" \
               --redirect \
               -d "${SERVER_NAME}" 2>&1; then
        SSL_OK=1
        echo "  ✓ SSL certificate issued and Apache configured for HTTPS"

        # Verify auto-renewal is in place
        if systemctl is-enabled certbot-renew.timer &>/dev/null; then
            echo "  ✓ Auto-renewal: certbot-renew.timer is enabled"
        elif [ -f /etc/cron.d/certbot-renew ]; then
            echo "  ✓ Auto-renewal: cron job present at /etc/cron.d/certbot-renew"
        else
            echo "  ⚠ Auto-renewal not detected — add manually:"
            echo "    echo '0 3 * * * root certbot renew --quiet --post-hook \"systemctl reload httpd\"' > /etc/cron.d/certbot-renew"
        fi
    else
        echo "  ✗ certbot failed — site will remain HTTP only."
        echo "    The site is running on HTTP. Retry after fixing DNS/firewall:"
        echo "    sudo certbot --apache -d ${SERVER_NAME} --redirect --non-interactive --agree-tos -m admin@themightygroupbuy.com"
        SSL_OK=0
    fi
fi

# ══════════════════════════════════════════════════════════════════════════
# PHASE 4: SUMMARY
# ══════════════════════════════════════════════════════════════════════════

echo ""
echo "=========================================="
echo "  ✅  TheMightyGroupBuy is LIVE!"
echo "=========================================="
echo ""
echo "  🌐 App URL          : $([ "$SSL_OK" -eq 1 ] && echo "https://${SERVER_NAME}" || echo "http://${SERVER_NAME} (HTTPS pending)")"
echo "  📁 App directory    : ${APP_DIR}"
echo "  🔑 Config (.env)    : ${ENV_PATH}"
echo ""
echo "  🔧 Apache vhost     : /etc/httpd/conf.d/tmgb.conf"
echo "  🔧 Apache security  : /etc/httpd/conf.d/00-security.conf"
echo "  🔧 PHP overrides    : /etc/php.d/99-tmgb.ini"
echo "  🔧 MariaDB config   : /etc/my.cnf.d/tmgb.cnf"
echo "  🔧 Memcached config : /etc/sysconfig/memcached"
echo "  🔑 Credentials      : ${CRED_FILE} (root-only)"
echo ""
echo "  📊 Database         : ${DB_NAME}"
echo "  👤 DB user          : ${DB_USER}"
echo "  🔒 Passwords        : saved in ${CRED_FILE}"
echo ""
echo "  📝 Logs:"
echo "     Apache error  : /var/log/httpd/tmgb_ssl_error.log"
echo "     Apache access : /var/log/httpd/tmgb_ssl_access.log"
echo "     PHP errors    : /var/log/php-fpm/www-error.log"
echo "     MariaDB slow  : /var/log/mariadb/slow.log"
echo "     Email (log)   : ${APP_DIR}/email-templates/mail.log"
echo ""
echo "  📧 Email driver     : MAIL_DRIVER=log (safe default)"
echo "     To switch to Brevo, edit ${ENV_PATH}:"
echo "       MAIL_DRIVER=brevo"
echo "       BREVO_API_KEY=your-key-here"
echo "     Also required in Brevo dashboard:"
echo "       • Authorize server IP: https://app.brevo.com/security/authorised_ips"
echo "       • Verify sender domain (SPF+DKIM): https://app.brevo.com/senders"
echo "     Then: sudo systemctl reload httpd"
echo ""
echo "  ⚠️  Open ports 80 and 443 in your EC2 Security Group if not already done."
echo ""
echo "  ⚙️  Cron — low-stock push notifications:"
echo "     echo '*/5 * * * * php ${APP_DIR}/backend/cron/check-low-stock.php >> /var/log/tmgb-cron.log 2>&1' | crontab -"
echo ""
echo "=========================================="
