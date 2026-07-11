<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/performance?range=24h|7d|30d&device=desktop|mobile|tablet|other&path=/dashboard
method('GET');
requireAdmin();

$RANGES = ['24h' => '1 DAY', '7d' => '7 DAY', '30d' => '30 DAY'];
$range  = array_key_exists($_GET['range'] ?? '', $RANGES) ? $_GET['range'] : '7d'; // reasonable default — no configuration required to see something
$interval = $RANGES[$range];

$device = in_array($_GET['device'] ?? '', ['desktop', 'mobile', 'tablet', 'other'], true) ? $_GET['device'] : null;
$path   = trim((string)($_GET['path'] ?? ''));

// pc_perf_metrics is write-heavy (a beacon on every page load) and read
// rarely relative to that — TTL-only caching, no bust wiring, same
// reasoning as admin/overview.php.
$variant = "$range:" . ($device ?? '-') . ':' . ($path ?: '-');
$data = cacheGet('admin_performance', $variant, 600, function () use ($interval, $device, $path, $range) {
    $where  = ["created_at >= DATE_SUB(NOW(), INTERVAL $interval)"];
    $params = [];
    if ($device) { $where[] = 'device_type = ?'; $params[] = $device; }
    if ($path)   { $where[] = 'page = ?';        $params[] = $path; }
    $whereSql = implode(' AND ', $where);

    $overall = db()->prepare(
        "SELECT COUNT(*) AS samples, AVG(dns_ms) AS dns_ms, AVG(connect_ms) AS connect_ms,
                AVG(ttfb_ms) AS ttfb_ms, AVG(dom_load_ms) AS dom_load_ms, AVG(load_ms) AS load_ms
         FROM pc_perf_metrics WHERE $whereSql"
    );
    $overall->execute($params);
    $overall = $overall->fetch();

    $daily = db()->prepare(
        "SELECT DATE(created_at) AS day, AVG(load_ms) AS avg_load_ms, COUNT(*) AS samples
         FROM pc_perf_metrics WHERE $whereSql
         GROUP BY DATE(created_at) ORDER BY day ASC"
    );
    $daily->execute($params);
    $daily = $daily->fetchAll();

    $topPages = db()->prepare(
        "SELECT page, COUNT(*) AS samples, AVG(load_ms) AS avg_load_ms
         FROM pc_perf_metrics WHERE $whereSql AND page IS NOT NULL AND page != ''
         GROUP BY page ORDER BY samples DESC LIMIT 10"
    );
    $topPages->execute($params);
    $topPages = $topPages->fetchAll();

    $deviceSplit = db()->prepare(
        "SELECT device_type, COUNT(*) AS samples, AVG(load_ms) AS avg_load_ms
         FROM pc_perf_metrics WHERE $whereSql
         GROUP BY device_type ORDER BY samples DESC"
    );
    $deviceSplit->execute($params);
    $deviceSplit = $deviceSplit->fetchAll();

    $recent = db()->prepare(
        "SELECT page, device_type, ttfb_ms, dom_load_ms, load_ms, created_at
         FROM pc_perf_metrics WHERE $whereSql
         ORDER BY created_at DESC LIMIT 50"
    );
    $recent->execute($params);
    $recent = $recent->fetchAll();

    foreach (['dns_ms','connect_ms','ttfb_ms','dom_load_ms','load_ms'] as $k) {
        $overall[$k] = $overall[$k] !== null ? round((float)$overall[$k], 1) : null;
    }
    $overall['samples'] = (int)$overall['samples'];
    foreach ($daily as &$d)       { $d['avg_load_ms'] = round((float)$d['avg_load_ms'], 1); $d['samples'] = (int)$d['samples']; }
    foreach ($topPages as &$p)    { $p['avg_load_ms'] = round((float)$p['avg_load_ms'], 1); $p['samples'] = (int)$p['samples']; }
    foreach ($deviceSplit as &$s) { $s['avg_load_ms'] = round((float)$s['avg_load_ms'], 1); $s['samples'] = (int)$s['samples']; }

    return [
        'range'        => $range,
        'overall'      => $overall,
        'daily'        => $daily,
        'top_pages'    => $topPages,
        'device_split' => $deviceSplit,
        'recent'       => $recent,
    ];
});

jsonResponse($data);
