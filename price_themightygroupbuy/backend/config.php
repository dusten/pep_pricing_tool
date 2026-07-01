<?php
declare(strict_types=1);

// Load .env_pricetool — production: ~/  (matches ~/.env_peptools pattern), dev: app root
$_envFile = is_file(dirname(__DIR__, 2) . '/.env_pricetool')
    ? dirname(__DIR__, 2) . '/.env_pricetool'
    : dirname(__DIR__, 1) . '/.env_pricetool';
if (is_file($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (str_starts_with(trim($_line), '#') || !str_contains($_line, '=')) continue;
        [$_k, $_v] = array_map('trim', explode('=', $_line, 2));
        // Strip inline comments and surrounding quotes
        $_v = preg_replace('/\s+#.*$/', '', $_v);
        $_v = trim($_v, '"\'');
        $_ENV[$_k] = $_SERVER[$_k] = $_v;
        putenv("$_k=$_v");
    }
}
unset($_envFile, $_line, $_k, $_v);

function _env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ── App ───────────────────────────────────────────────────────────
define('APP_ENV',    _env('APP_ENV',    'production'));
define('APP_URL',    rtrim(_env('APP_URL', 'https://price.themightygroupbuy.com'), '/'));
define('APP_SECRET', _env('APP_SECRET'));   // used for HMAC where needed

// ── Database ──────────────────────────────────────────────────────
define('DB_HOST', _env('DB_HOST', '127.0.0.1'));
define('DB_PORT', _env('DB_PORT', '3306'));
define('DB_NAME', _env('DB_NAME', 'tmgb_price'));
define('DB_USER', _env('DB_USER', 'pc_user'));
define('DB_PASS', _env('DB_PASS', ''));

// ── Memcached ─────────────────────────────────────────────────────
define('MC_HOST', _env('MC_HOST', '127.0.0.1'));
define('MC_PORT', (int)_env('MC_PORT', '11211'));

// ── Email ─────────────────────────────────────────────────────────
define('MAIL_DRIVER',    _env('MAIL_DRIVER',    'brevo')); // brevo | log
define('BREVO_API_KEY',  _env('BREVO_API_KEY'));
define('MAIL_FROM',      _env('MAIL_FROM_EMAIL', 'noreply@price.themightygroupbuy.com'));
define('MAIL_FROM_NAME', _env('MAIL_FROM_NAME',  'TheMightyGroupBuy Prices'));

// ── External services (populated in later phases) ─────────────────
define('ANTHROPIC_API_KEY',     _env('ANTHROPIC_API_KEY'));
define('STRIPE_SECRET_KEY',     _env('STRIPE_SECRET_KEY'));
define('STRIPE_WEBHOOK_SECRET', _env('STRIPE_WEBHOOK_SECRET'));

// ── Singletons ────────────────────────────────────────────────────

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function mc(): ?Memcached {
    static $mc;
    if ($mc === null) {
        if (!class_exists('Memcached')) return null; // ponytail: graceful degrade if ext missing
        $mc = new Memcached();
        $mc->addServer(MC_HOST, MC_PORT);
    }
    return $mc;
}
