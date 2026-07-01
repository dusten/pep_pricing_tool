<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
requireAdmin();

$overall = db()->query(
    "SELECT COUNT(*) AS samples, AVG(dns_ms) AS dns_ms, AVG(connect_ms) AS connect_ms,
            AVG(ttfb_ms) AS ttfb_ms, AVG(dom_load_ms) AS dom_load_ms, AVG(load_ms) AS load_ms
     FROM pc_perf_metrics WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
)->fetch();

$daily = db()->query(
    "SELECT DATE(created_at) AS day, AVG(load_ms) AS avg_load_ms, COUNT(*) AS samples
     FROM pc_perf_metrics WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY DATE(created_at) ORDER BY day ASC"
)->fetchAll();

foreach (['dns_ms','connect_ms','ttfb_ms','dom_load_ms','load_ms'] as $k) {
    $overall[$k] = $overall[$k] !== null ? round((float)$overall[$k], 1) : null;
}
$overall['samples'] = (int)$overall['samples'];
foreach ($daily as &$d) {
    $d['avg_load_ms'] = round((float)$d['avg_load_ms'], 1);
    $d['samples']     = (int)$d['samples'];
}

jsonResponse(['overall' => $overall, 'daily' => $daily]);
