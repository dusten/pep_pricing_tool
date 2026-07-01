<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('DELETE');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT id FROM pc_vendor_files WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
if (!$stmt->fetch()) jsonResponse(['error' => 'File not found.'], 404);

// Remove the record only — physical file stays on disk in case it's needed later.
db()->prepare('DELETE FROM pc_vendor_files WHERE id = ?')->execute([$id]);
logAdminAction((int)$admin['id'], 'delete_vendor_file', ['file_id' => $id]);

jsonResponse(['message' => 'File record removed.']);
