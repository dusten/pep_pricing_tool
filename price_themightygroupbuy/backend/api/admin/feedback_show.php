<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// PATCH /admin/feedback/{id}  body: { is_read }
method('PATCH');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);
$d     = input();

db()->prepare('UPDATE pc_feedback SET is_read = ? WHERE id = ?')
    ->execute([!empty($d['is_read']) ? 1 : 0, $id]);
logAdminAction((int)$admin['id'], 'update_feedback', ['id' => $id, 'is_read' => !empty($d['is_read'])]);

jsonResponse(['message' => 'Feedback updated.']);
