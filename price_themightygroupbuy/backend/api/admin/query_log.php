<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/query-log?slow=1&user_id=&range=24h|7d|30d — sortable by duration client-side
method('GET');
requireAdmin();

$RANGES = ['24h' => '1 DAY', '7d' => '7 DAY', '30d' => '30 DAY'];
$range  = array_key_exists($_GET['range'] ?? '', $RANGES) ? $_GET['range'] : '7d';

$where  = ["l.created_at >= DATE_SUB(NOW(), INTERVAL {$RANGES[$range]})"];
$params = [];
if (($_GET['slow'] ?? '') === '1') $where[] = 'l.slow_flag = 1';
if (!empty($_GET['user_id'])) { $where[] = 'l.user_id = ?'; $params[] = (int)$_GET['user_id']; }

$sql = 'SELECT l.id, l.user_id, u.email, l.selection_params, l.duration_ms, l.result_count, l.slow_flag, l.created_at
        FROM pc_comparison_log l JOIN pc_users u ON u.id = l.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY l.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['id']               = (int)$r['id'];
    $r['user_id']          = (int)$r['user_id'];
    $r['duration_ms']      = (int)$r['duration_ms'];
    $r['result_count']     = (int)$r['result_count'];
    $r['slow_flag']        = (bool)$r['slow_flag'];
    $r['selection_params'] = json_decode($r['selection_params'], true);
}

jsonResponse(['queries' => $rows]);
