<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// DELETE /admin/waitlist/{id}
method('DELETE');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

db()->prepare('DELETE FROM pc_waitlist WHERE id = ?')->execute([$id]);
logAdminAction((int)$admin['id'], 'delete_waitlist_entry', ['id' => $id]);

jsonResponse(['message' => 'Waitlist entry removed.']);
