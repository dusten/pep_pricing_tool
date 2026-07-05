<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

method('POST');
// Perf logging is best-effort; don't require auth (logged-out pageviews matter too),
// but it's the one public write endpoint every other one rate-limits — cap it too.
rateLimit('perf_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 300);
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$userId     = null;
if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
    $hash = hashToken($m[1]);
    $stmt = db()->prepare('SELECT user_id FROM pc_sessions WHERE token_hash = ? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if ($row) $userId = (int)$row['user_id'];
}

$d = input();
db()->prepare(
    'INSERT INTO pc_perf_metrics (user_id, page, device_type, dns_ms, connect_ms, ttfb_ms, dom_load_ms, load_ms)
     VALUES (?,?,?,?,?,?,?,?)'
)->execute([
    $userId,
    substr($d['page'] ?? '', 0, 200),
    deviceType(),
    isset($d['dns_ms'])      ? (int)$d['dns_ms']      : null,
    isset($d['connect_ms'])  ? (int)$d['connect_ms']  : null,
    isset($d['ttfb_ms'])     ? (int)$d['ttfb_ms']     : null,
    isset($d['dom_load_ms']) ? (int)$d['dom_load_ms'] : null,
    isset($d['load_ms'])     ? (int)$d['load_ms']     : null,
]);

jsonResponse(['ok' => true]);
