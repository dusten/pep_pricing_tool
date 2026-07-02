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

    // vendor_sku lives here (pc_prices), not on pc_products — it's per
    // vendor+spec+tier, e.g. AOD9604 5mg is "5AD" but 10mg is "10AD" for the
    // same vendor. No admin view listed individual price rows before this.
    $prices = db()->prepare(
        'SELECT pr.id, p.canonical_name, s.spec_label, pr.tier_kit_size, pr.price_usd, pr.vendor_sku,
                pr.kit_vial_count, pr.non_standard_kit
         FROM pc_prices pr
         JOIN pc_products p ON p.id = pr.product_id
         JOIN pc_specifications s ON s.id = pr.specification_id
         WHERE pr.vendor_id = ? AND pr.is_active = 1
         ORDER BY p.canonical_name, s.spec_label, pr.tier_kit_size'
    );
    $prices->execute([$id]);
    $vendor['prices']       = $prices->fetchAll();

    $vendor['is_active']    = (bool)$vendor['is_active'];
    $vendor['is_verified']  = (bool)$vendor['is_verified'];
    $vendor                 = array_merge($vendor, loadVendorPhonesAndPaymentMethods(db(), $id));
    jsonResponse($vendor);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $fileCount = db()->prepare('SELECT COUNT(*) FROM pc_vendor_files WHERE vendor_id = ?');
    $fileCount->execute([$id]);

    // Safe to hard-delete only if no files were ever uploaded — cascades
    // clean up phones/payment methods/prices/pending imports/COA submissions.
    // Once a vendor has file history, deactivate instead so that history
    // (and anything already on the comparison table) isn't destroyed.
    if ((int)$fileCount->fetchColumn() === 0) {
        db()->prepare('DELETE FROM pc_vendors WHERE id = ?')->execute([$id]);
        cacheBust('admin_vendors');
        cacheBust('pricing_data');
        logAdminAction((int)$admin['id'], 'delete_vendor', ['vendor_id' => $id, 'display_name' => $vendor['display_name']]);
        jsonResponse(['message' => 'Vendor deleted.']);
    }

    db()->prepare('UPDATE pc_vendors SET is_active = 0 WHERE id = ?')->execute([$id]);
    cacheBust('admin_vendors');
    cacheBust('pricing_data'); // is_active flag feeds comparison/filters results
    logAdminAction((int)$admin['id'], 'deactivate_vendor', ['vendor_id' => $id]);
    jsonResponse(['message' => 'Vendor has file history — deactivated instead of deleted.']);
}

// PUT — update
$d   = input();
$pdo = db();
$pdo->beginTransaction();
updateVendorScalarFields($pdo, $id, $d);
saveVendorPhonesAndPaymentMethods($pdo, $id, $d);
$pdo->commit();

if ($d) {
    cacheBust('admin_vendors');
    if (array_key_exists('is_active', $d))   cacheBust('pricing_data');
    if (array_key_exists('is_verified', $d)) cacheBust('pricing_data'); // verified badge/filter feeds comparison
    logAdminAction((int)$admin['id'], 'update_vendor', ['vendor_id' => $id, 'fields' => array_keys($d)]);
}

jsonResponse(['message' => 'Vendor updated.']);
