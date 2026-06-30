<?php
declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

// ── CORS (dev only) ───────────────────────────────────────────────
if (APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
}

// ── Route to SPA for non-API requests ────────────────────────────
$uri = ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if (!str_starts_with($uri, 'api/')) {
    $spa = __DIR__ . '/dist/index.html';
    if (is_file($spa)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($spa);
    } else {
        http_response_code(503);
        echo 'Frontend not built. Run: cd frontend && npm run build';
    }
    exit;
}

// ── Maintenance mode (skip for admins already authed) ────────────
// ponytail: simple flag check; no middleware stack needed at this scale
if (getAppSetting('maintenance_mode') === '1') {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $isAdmin    = false;
    if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
        $stmt = db()->prepare(
            'SELECT u.is_admin FROM pc_users u JOIN pc_sessions s ON s.user_id = u.id
             WHERE s.token_hash = ? AND s.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([hashToken($m[1])]);
        $row = $stmt->fetch();
        $isAdmin = !empty($row['is_admin']);
    }
    if (!$isAdmin) jsonResponse(['error' => 'maintenance', 'message' => 'Down for maintenance — check back shortly.'], 503);
}

// ── Route table ───────────────────────────────────────────────────
// pattern => handler file (relative to backend/api/)
// Static routes only for Phase 1; dynamic {id} routes added per phase
$ROUTES = [
    'auth/login'           => 'auth/login.php',
    'auth/register'        => 'auth/register.php',
    'auth/logout'          => 'auth/logout.php',
    'auth/verify-email'    => 'auth/verify_email.php',
    'auth/forgot-password' => 'auth/forgot_password.php',
    'auth/reset-password'  => 'auth/reset_password.php',
    'me'                   => 'me.php',
    'me/quota'             => 'me/quota.php',
    'app-settings'         => 'app_settings.php',
    'feedback'             => 'feedback.php',
    'perf'                 => 'perf.php',
    // ── Phase 2+ (uncomment as built) ────────────────────────────
    // 'vendors'              => 'vendors/index.php',
    // 'products'             => 'products/index.php',
    // 'comparison'           => 'comparison/index.php',
    // 'billing/checkout'     => 'billing/checkout.php',
    // 'billing/portal'       => 'billing/portal.php',
    // 'billing/webhook'      => 'billing/webhook.php',
];

// Dynamic route patterns: ['regex' => ['file', 'param1', ...]]
$DYNAMIC = [
    // 'vendors/(\d+)'              => ['vendors/show.php',       'id'],
    // 'vendors/(\d+)/files'        => ['vendors/files.php',      'id'],
    // 'files/(\d+)/process'        => ['files/process.php',      'id'],
    // 'files/(\d+)/status'         => ['files/status.php',       'id'],
    // 'products/(\d+)'             => ['products/show.php',       'id'],
    // 'products/(\d+)/aliases'     => ['products/aliases.php',    'id'],
    // 'products/(\d+)/merge'       => ['products/merge.php',      'id'],
];

$apiPath = substr($uri, 4); // strip 'api/'
$PARAMS  = [];

// Static match
$handler = isset($ROUTES[$apiPath])
    ? dirname(__DIR__) . '/backend/api/' . $ROUTES[$apiPath]
    : null;

// Dynamic match
if (!$handler) {
    foreach ($DYNAMIC as $pattern => [$file, ...$paramNames]) {
        if (preg_match("#^{$pattern}$#", $apiPath, $matches)) {
            array_shift($matches);
            foreach ($paramNames as $i => $name) $PARAMS[$name] = $matches[$i] ?? null;
            $handler = dirname(__DIR__) . '/backend/api/' . $file;
            break;
        }
    }
}

if ($handler && is_file($handler)) {
    require $handler;
} else {
    jsonResponse(['error' => 'Not found', 'path' => $apiPath], 404);
}
