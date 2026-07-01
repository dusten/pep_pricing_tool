<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET', 'PUT', 'DELETE');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pc_vendors WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$vendor = $stmt->fetch();
if (!$vendor) jsonResponse(['error' => 'Vendor not found.'], 404);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $files = db()->prepare('SELECT * FROM pc_vendor_files WHERE vendor_id = ? ORDER BY uploaded_at DESC');
    $files->execute([$id]);
    $vendor['files']      = $files->fetchAll();
    $vendor['is_active']  = (bool)$vendor['is_active'];
    jsonResponse($vendor);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    db()->prepare('UPDATE pc_vendors SET is_active = 0 WHERE id = ?')->execute([$id]);
    logAdminAction((int)$admin['id'], 'deactivate_vendor', ['vendor_id' => $id]);
    jsonResponse(['message' => 'Vendor deactivated.']);
}

// PUT — update
$d       = input();
$fields  = [];
$vals    = [];
foreach (['display_name', 'contact_name', 'email', 'whatsapp', 'website', 'notes'] as $f) {
    if (array_key_exists($f, $d)) {
        $fields[] = "$f = ?";
        $vals[]   = trim((string)$d[$f]) ?: null;
    }
}
if (array_key_exists('is_active', $d)) {
    $fields[] = 'is_active = ?';
    $vals[]   = (bool)$d['is_active'] ? 1 : 0;
}
if ($fields) {
    $vals[] = $id;
    db()->prepare('UPDATE pc_vendors SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    logAdminAction((int)$admin['id'], 'update_vendor', ['vendor_id' => $id, 'fields' => array_keys($d)]);
}

jsonResponse(['message' => 'Vendor updated.']);
