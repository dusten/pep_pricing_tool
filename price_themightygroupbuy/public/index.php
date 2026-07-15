<?php
declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

// ── Global exception handler ────────────────────────────────────────
// Any unhandled failure past this point returns a generic 500, never a
// stack trace or raw DB error — real detail goes to the server log only.
set_exception_handler(function (Throwable $e): void {
    error_log('[unhandled] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(['error' => 'Something went wrong. Please try again.'], 500);
});

// ── Security headers (Apache also sets these for static/proxied
// responses via price.conf; repeated here so nothing depends on that
// vhost config alone, e.g. `php -S` local dev) ──────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
// img-src needs blob: on top of 'self' — the admin file preview fetches an
// image with auth, then renders it from a blob: object URL (a plain
// <img src> can't carry the Bearer header this app's endpoints require),
// which default-src 'self' alone doesn't allow. PDFs render via pdf.js
// canvas now, not an iframe, so frame-src never needed the same relaxation.
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; img-src 'self' blob:;");

// ── CORS ──────────────────────────────────────────────────────────
// Allowlist built from the app's own configured origin(s) — never a wildcard.
// The API takes its token via the Authorization header, not a cookie, so
// credentials are never part of this and Allow-Credentials is deliberately
// not sent.
$corsAllowed = [APP_URL];
if (APP_ENV === 'development') $corsAllowed[] = 'http://localhost:5173';
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array(rtrim($reqOrigin, '/'), $corsAllowed, true)) {
    header("Access-Control-Allow-Origin: $reqOrigin");
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ── CSRF (defense in depth) ────────────────────────────────────────
// Auth is a bearer token in the Authorization header, never a cookie, so a
// forged cross-site <form>/<img> POST can't carry it — that alone defeats
// classic CSRF. This Origin check is a second layer in case that ever
// changes: reject mutating requests whose Origin doesn't match us.
//
// A request can legitimately arrive with NO Origin header (server-to-server
// calls, e.g. a future payment-provider webhook) — those are allowed through
// only if their path is on this named, individually-justified exemption list.
// Nothing else gets a free pass for having an empty Origin.
$CSRF_EXEMPT_NO_ORIGIN = [
    'perf' => 'best-effort page-load timing beacon; fires pre-auth from real pages, no state to forge',
];
$mutating = in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
if ($mutating && str_starts_with(ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'), 'api/')) {
    $apiPathForCsrf = substr(ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'), 4);
    if ($reqOrigin !== '') {
        if (!in_array(rtrim($reqOrigin, '/'), $corsAllowed, true)) {
            jsonResponse(['error' => 'Cross-origin request rejected.'], 403);
        }
    } elseif (!array_key_exists($apiPathForCsrf, $CSRF_EXEMPT_NO_ORIGIN)) {
        jsonResponse(['error' => 'Cross-origin request rejected.'], 403);
    }
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
    'waitlist/join'        => 'waitlist/join.php',
    'auth/login'           => 'auth/login.php',
    'auth/register'        => 'auth/register.php',
    'auth/logout'          => 'auth/logout.php',
    'auth/verify-email'    => 'auth/verify_email.php',
    'auth/forgot-password' => 'auth/forgot_password.php',
    'auth/reset-password'  => 'auth/reset_password.php',
    'me'                   => 'me.php',
    'me/quota'             => 'me/quota.php',
    'me/password'          => 'me/password.php',
    'me/email'             => 'me/email.php',
    'me/login-history'     => 'me/login_history.php',
    'me/export'            => 'me/export.php',
    'me/referral-stats'    => 'me/referral_stats.php',
    'me/sessions/revoke-all' => 'me/sessions_revoke_all.php',
    'auth/verify-email-change' => 'auth/verify_email_change.php',
    'app-settings'         => 'app_settings.php',
    'health'               => 'health.php',
    'stats'                => 'stats.php',
    'feedback'             => 'feedback.php',
    'perf'                 => 'perf.php',
    'track/whatsapp-click' => 'track/whatsapp_click.php',
    'vendors'                 => 'vendors/index.php',
    'vendors/parse-intake'    => 'vendors/parse_intake.php',
    'vendors/find-by-phone'   => 'vendors/find_by_phone.php',
    'vendors/pending-imports' => 'vendors/pending_imports.php',
    'files/process-all'      => 'files/process_all.php',
    'coa/submit'              => 'coa/submit.php',
    'coa/vendor-products'     => 'coa/vendor_products.php',
    'admin/coa-queue'         => 'admin/coa_queue.php',
    'products'             => 'products/index.php',
    'classifications'      => 'classifications.php',
    'cart'                 => 'cart/index.php',
    'stacks'               => 'stacks.php',
    'admin/stacks'         => 'admin/stacks/index.php',
    'comparison'           => 'comparison/index.php',
    'comparison/filters'   => 'comparison/filters.php',
    'comparison/distribution' => 'comparison/distribution.php',
    'comparison/price-history' => 'comparison/price_history.php',
    'comparison/export/csv'  => 'comparison/export_csv.php',
    'comparison/export/xlsx' => 'comparison/export_xlsx.php',
    'export/full'          => 'export_full.php',
    'calendar'             => 'calendar.php',
    'calendar/public'      => 'calendar_public.php',
    'admin/overview'       => 'admin/overview.php',
    'admin/activity-stats' => 'admin/activity_stats.php',
    'admin/users'          => 'admin/users.php',
    'admin/waitlist'       => 'admin/waitlist.php',
    'admin/waitlist/export' => 'admin/waitlist_export.php',
    'admin/files'          => 'admin/files.php',
    'admin/feedback'       => 'admin/feedback.php',
    'admin/performance'    => 'admin/performance.php',
    'admin/system'         => 'admin/system.php',
    'admin/query-log'      => 'admin/query_log.php',
    'admin/slow-queries'   => 'admin/slow_queries.php',
    'admin/slow-queries/export' => 'admin/slow_queries_export.php',
    'admin/claude-prompt'  => 'admin/claude_prompt.php',
    'admin/claude-log'     => 'admin/claude_log.php',
    'admin/calendar-features' => 'admin/calendar_features.php',
    'admin/backup'         => 'admin/backup.php',
    // ── Backlog — Stripe billing, do not build yet ───────────────
    // 'billing/checkout'     => 'billing/checkout.php',
    // 'billing/portal'       => 'billing/portal.php',
    // 'billing/webhook'      => 'billing/webhook.php',
];

// Dynamic route patterns: ['regex' => ['file', 'param1', ...]]
$DYNAMIC = [
    'vendors/(\d+)'                    => ['vendors/show.php',       'id'],
    'vendors/(\d+)/files'               => ['vendors/files.php',      'id'],
    'vendors/(\d+)/merge'                => ['vendors/merge.php',      'id'],
    'vendors/(\d+)/recalc-prices'        => ['vendors/recalc_prices.php', 'id'],
    'vendors/(\d+)/contact'               => ['vendors/contact.php',    'id'],
    'vendors/pending-imports/(\d+)/(approve|reject|skip)' => ['vendors/pending_imports.php', 'id', 'action'],
    'admin/coa-queue/(\d+)/(approve|reject|revoke)'  => ['admin/coa_queue.php', 'id', 'action'],
    'files/(\d+)/download'              => ['files/download.php',    'id'],
    'files/(\d+)/process'               => ['files/process.php',     'id'],
    'files/(\d+)/manual-process'        => ['files/manual_process.php', 'id'],
    'files/(\d+)/status'                => ['files/status.php',      'id'],
    'files/(\d+)'                       => ['files/delete.php',      'id'],
    'admin/claude-log/(\d+)'            => ['admin/claude_log_show.php', 'id'],
    'admin/calendar-features/(\d{4}-\d{2}-\d{2})' => ['admin/calendar_features.php', 'date'],
    'products/(\d+)'                    => ['products/show.php',     'id'],
    'products/(\d+)/aliases'            => ['products/aliases.php',  'id'],
    'products/(\d+)/aliases/(\d+)'      => ['products/aliases.php',  'id', 'aliasId'],
    'products/specifications/(\d+)/move' => ['products/spec_move.php', 'id'],
    'products/specifications/(\d+)/merge' => ['products/spec_merge.php', 'id'],
    'products/specifications/(\d+)'     => ['products/spec_update.php', 'id'],
    'products/(\d+)/merge'              => ['products/merge.php',    'id'],
    'prices/(\d+)'                       => ['prices/update.php',    'id'],
    'cart/(\d+)'                         => ['cart/item.php',        'id'],
    'cart/add-stack/(\d+)'               => ['cart/add_stack.php',   'id'],
    'admin/stacks/(\d+)/items/(\d+)'    => ['admin/stacks/items.php', 'id', 'itemId'],
    'admin/stacks/(\d+)/items'          => ['admin/stacks/items.php', 'id'],
    'admin/stacks/(\d+)'                => ['admin/stacks/show.php',  'id'],
    'admin/users/(\d+)'                 => ['admin/users_show.php',    'id'],
    'admin/users/(\d+)/referrals'       => ['admin/user_referrals.php','id'],
    'admin/users/(\d+)/activity'        => ['admin/user_activity.php', 'id'],
    'admin/waitlist/(\d+)'              => ['admin/waitlist_show.php', 'id'],
    'admin/feedback/(\d+)'              => ['admin/feedback_show.php', 'id'],
    'admin/query-log/(\d+)/rerun'       => ['admin/query_log_rerun.php', 'id'],
    'admin/slow-queries/(\d+)'          => ['admin/slow_queries_show.php', 'id'],
];

$apiPath = substr($uri, 4); // strip 'api/'
$PARAMS  = [];

// Static match
$handler = isset($ROUTES[$apiPath])
    ? dirname(__DIR__) . '/backend/api/' . $ROUTES[$apiPath]
    : null;

// Dynamic match
if (!$handler) {
    foreach ($DYNAMIC as $pattern => $route) {
        [$file] = $route; $paramNames = array_slice($route, 1);
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
