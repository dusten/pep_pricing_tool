<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
requireAdmin();

$tier  = in_array($_GET['tier'] ?? '', ['free','advanced','pro','expert'], true) ? $_GET['tier'] : null;
$where = [];
$params = [];
if ($tier) { $where[] = 'tier = ?'; $params[] = $tier; }

$sql = 'SELECT id, email, display_name, tier, tier_status, tier_renews_at, account_credit_usd,
               is_admin, referral_code, email_verified_at, last_login_at, created_at
        FROM pc_users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
        ORDER BY created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['id']       = (int)$r['id'];
    $r['is_admin'] = (bool)$r['is_admin'];
    $r['account_credit_usd'] = (float)$r['account_credit_usd'];
}

jsonResponse(['users' => $rows]);
