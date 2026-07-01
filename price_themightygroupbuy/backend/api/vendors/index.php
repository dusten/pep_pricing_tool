<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET', 'POST');
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = cacheGet('admin_vendors', 'all', 60, function () {
        $rows = db()->query(
            "SELECT v.*, COUNT(p.id) AS price_count, MAX(f.uploaded_at) AS last_upload
             FROM pc_vendors v
             LEFT JOIN pc_prices p ON p.vendor_id = v.id AND p.is_active = 1
             LEFT JOIN pc_vendor_files f ON f.vendor_id = v.id
             GROUP BY v.id
             ORDER BY v.display_name ASC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['is_active']   = (bool)$r['is_active'];
            $r['price_count'] = (int)$r['price_count'];
        }
        return $rows;
    });
    jsonResponse(['vendors' => $rows]);
}

// POST — create
$d           = input();
$displayName = trim($d['display_name'] ?? '');
if (mb_strlen($displayName) < 2) jsonResponse(['error' => 'Display name is required.'], 422);

$stmt = db()->prepare(
    'INSERT INTO pc_vendors (display_name, contact_name, email, whatsapp, website, notes)
     VALUES (?,?,?,?,?,?)'
);
$stmt->execute([
    $displayName,
    trim($d['contact_name'] ?? '') ?: null,
    trim($d['email'] ?? '') ?: null,
    trim($d['whatsapp'] ?? '') ?: null,
    trim($d['website'] ?? '') ?: null,
    trim($d['notes'] ?? '') ?: null,
]);
$id = (int)db()->lastInsertId();
cacheBust('admin_vendors');
logAdminAction((int)$admin['id'], 'create_vendor', ['vendor_id' => $id, 'display_name' => $displayName]);

jsonResponse(['id' => $id], 201);
