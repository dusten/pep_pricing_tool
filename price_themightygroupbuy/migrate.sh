#!/usr/bin/env bash
# =============================================================
# migrate.sh — apply pending DB migrations in order
# Tracks applied migrations in pc_migrations table.
# Safe to run repeatedly (idempotent).
# =============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Env file: ~/.env_pricetool on server, .env_pricetool in app root for local dev
for _f in "$HOME/.env_pricetool" "$APP_DIR/.env_pricetool"; do
  if [[ -f "$_f" ]]; then
    set -a
    # shellcheck disable=SC1090
    source <(grep -v '^\s*#' "$_f" | grep '=')
    set +a
    break
  fi
done

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-tmgb_price}"
DB_USER="${DB_USER:-tmgb_price}"
DB_PASS="${DB_PASS:-}"

MYSQL="mysql -h$DB_HOST -P$DB_PORT -u$DB_USER -p$DB_PASS $DB_NAME"

# Ensure pc_migrations table exists
$MYSQL <<'SQL'
CREATE TABLE IF NOT EXISTS pc_migrations (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename   VARCHAR(200) NOT NULL UNIQUE,
  applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL

MIGRATIONS_DIR="$APP_DIR/database/migrations"
APPLIED=0
SKIPPED=0

for file in "$MIGRATIONS_DIR"/[0-9]*.sql; do
  [[ -f "$file" ]] || continue
  name=$(basename "$file")

  # Check if already applied
  count=$($MYSQL -sN -e "SELECT COUNT(*) FROM pc_migrations WHERE filename='$name'" 2>/dev/null)
  if [[ "$count" -gt 0 ]]; then
    echo "  ↷ $name (already applied)"
    ((SKIPPED++)) || true
    continue
  fi

  echo "  ▶ Applying $name…"
  $MYSQL < "$file"
  $MYSQL -e "INSERT IGNORE INTO pc_migrations (filename) VALUES ('$name')"
  echo "  ✓ $name applied"
  ((APPLIED++)) || true
done

echo ""
echo "Migration complete: $APPLIED applied, $SKIPPED skipped."
