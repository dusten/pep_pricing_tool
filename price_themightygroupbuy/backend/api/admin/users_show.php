<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// PATCH /admin/users/{id}  body: { is_admin?, tier?, tier_status? }
method('PATCH');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);
$d     = input();

$target = db()->prepare('SELECT id FROM pc_users WHERE id = ? LIMIT 1');
$target->execute([$id]);
if (!$target->fetch()) jsonResponse(['error' => 'User not found.'], 404);

$fields = [];
$vals   = [];
if (array_key_exists('is_admin', $d)) {
    $fields[] = 'is_admin = ?';
    $vals[]   = (bool)$d['is_admin'] ? 1 : 0;
}
if (array_key_exists('tier', $d) && in_array($d['tier'], ['free','advanced','pro','expert'], true)) {
    $fields[] = 'tier = ?';
    $vals[]   = $d['tier'];
}
if (array_key_exists('tier_status', $d) && in_array($d['tier_status'], ['active','past_due','canceled','trialing','none'], true)) {
    $fields[] = 'tier_status = ?';
    $vals[]   = $d['tier_status'];
}
if (!$fields) jsonResponse(['error' => 'No valid fields to update.'], 422);

$vals[] = $id;
db()->prepare('UPDATE pc_users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
logAdminAction((int)$admin['id'], 'update_user', ['user_id' => $id, 'fields' => $d]);

jsonResponse(['message' => 'User updated.']);
