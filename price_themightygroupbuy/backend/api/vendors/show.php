<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_helpers.php';

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
    $vendor['files']        = $files->fetchAll();
    $vendor['is_active']    = (bool)$vendor['is_active'];
    $vendor['is_verified']  = (bool)$vendor['is_verified'];
    $vendor                 = array_merge($vendor, loadVendorPhonesAndPaymentMethods(db(), $id));
    jsonResponse($vendor);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    db()->prepare('UPDATE pc_vendors SET is_active = 0 WHERE id = ?')->execute([$id]);
    cacheBust('admin_vendors');
    cacheBust('pricing_data'); // is_active flag feeds comparison/filters results
    logAdminAction((int)$admin['id'], 'deactivate_vendor', ['vendor_id' => $id]);
    jsonResponse(['message' => 'Vendor deactivated.']);
}

// PUT — update
$d       = input();
$fields  = [];
$vals    = [];
foreach (['display_name', 'contact_name', 'email', 'whatsapp', 'discord', 'telegram', 'website', 'notes'] as $f) {
    if (array_key_exists($f, $d)) {
        $fields[] = "$f = ?";
        $vals[]   = trim((string)$d[$f]) ?: null;
    }
}
if (array_key_exists('shipping_price', $d)) {
    $fields[] = 'shipping_price = ?';
    $vals[]   = $d['shipping_price'] !== '' && $d['shipping_price'] !== null ? (float)$d['shipping_price'] : null;
}
if (array_key_exists('is_active', $d)) {
    $fields[] = 'is_active = ?';
    $vals[]   = (bool)$d['is_active'] ? 1 : 0;
}
if (array_key_exists('is_verified', $d)) {
    $fields[] = 'is_verified = ?';
    $vals[]   = (bool)$d['is_verified'] ? 1 : 0;
}

$pdo = db();
$pdo->beginTransaction();
if ($fields) {
    $vals[] = $id;
    $pdo->prepare('UPDATE pc_vendors SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
}
saveVendorPhonesAndPaymentMethods($pdo, $id, $d);
$pdo->commit();

if ($fields || array_key_exists('phones', $d) || array_key_exists('payment_methods', $d)) {
    cacheBust('admin_vendors');
    if (array_key_exists('is_active', $d)) cacheBust('pricing_data');
    if (array_key_exists('is_verified', $d)) cacheBust('pricing_data'); // verified badge/filter feeds comparison
    logAdminAction((int)$admin['id'], 'update_vendor', ['vendor_id' => $id, 'fields' => array_keys($d)]);
}

jsonResponse(['message' => 'Vendor updated.']);
