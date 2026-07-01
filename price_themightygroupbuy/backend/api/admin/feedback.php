<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
requireAdmin();

$rows = db()->query(
    "SELECT f.*, u.email AS user_email, u.display_name
     FROM pc_feedback f LEFT JOIN pc_users u ON u.id = f.user_id
     ORDER BY f.is_read ASC, f.created_at DESC"
)->fetchAll();
foreach ($rows as &$r) { $r['is_read'] = (bool)$r['is_read']; }

jsonResponse(['feedback' => $rows]);
