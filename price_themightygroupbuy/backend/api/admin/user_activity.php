<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/users/{id}/activity — a user's audit trail (exports & other
// logged actions) plus their recent logins, for the admin Users tab.
method('GET');
requireAdmin();
$id = (int)($PARAMS['id'] ?? 0);

$exists = db()->prepare('SELECT id FROM pc_users WHERE id = ? LIMIT 1');
$exists->execute([$id]);
if (!$exists->fetchColumn()) jsonResponse(['error' => 'User not found.'], 404);

$auditStmt = db()->prepare(
    'SELECT action, details, ip, created_at FROM pc_user_audit_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
);
$auditStmt->execute([$id]);
$audit = $auditStmt->fetchAll();
foreach ($audit as &$a) $a['details'] = $a['details'] ? json_decode($a['details'], true) : null;
unset($a);

$loginStmt = db()->prepare(
    'SELECT ip, user_agent, created_at FROM pc_login_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 20'
);
$loginStmt->execute([$id]);

jsonResponse(['audit' => $audit, 'logins' => $loginStmt->fetchAll()]);
