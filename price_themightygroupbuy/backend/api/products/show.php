<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET', 'PUT');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pc_products WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) jsonResponse(['error' => 'Product not found.'], 404);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $aliases = db()->prepare('SELECT id, alias FROM pc_product_aliases WHERE product_id = ? ORDER BY alias');
    $aliases->execute([$id]);
    $specs = db()->prepare('SELECT * FROM pc_specifications WHERE product_id = ? ORDER BY numeric_value');
    $specs->execute([$id]);
    $specRows = $specs->fetchAll();

    // Nested per-spec so the admin can edit a spec's mg amount and each
    // vendor's kit_vial_count from the same product-edit view, without a
    // separate round trip per spec — a handful of specs per product, so the
    // N+1 here is cheap and this is an admin-only, on-demand view, not a hot path.
    $pricesStmt = db()->prepare(
        'SELECT pr.id, pr.vendor_id, v.display_name AS vendor_name, pr.price_usd,
                pr.kit_vial_count, pr.tier_kit_size, pr.vendor_sku, pr.non_standard_kit
         FROM pc_prices pr JOIN pc_vendors v ON v.id = pr.vendor_id
         WHERE pr.specification_id = ? AND pr.is_active = 1
         ORDER BY v.display_name, pr.tier_kit_size'
    );
    foreach ($specRows as &$spec) {
        $pricesStmt->execute([$spec['id']]);
        $spec['prices'] = $pricesStmt->fetchAll();
    }
    unset($spec);

    $classifications = db()->prepare(
        'SELECT c.id, c.name FROM pc_classifications c
         JOIN pc_product_classifications pc ON pc.classification_id = c.id
         WHERE pc.product_id = ? ORDER BY c.name'
    );
    $classifications->execute([$id]);

    $product['aliases']       = $aliases->fetchAll();
    $product['specifications'] = $specRows;
    $product['classifications'] = $classifications->fetchAll();
    jsonResponse($product);
}

// PUT — update
$d      = input();
$fields = [];
$vals   = [];
if (array_key_exists('canonical_name', $d) && mb_strlen(trim($d['canonical_name'])) >= 2) {
    $fields[] = 'canonical_name = ?';
    $vals[]   = trim($d['canonical_name']);
}
if (array_key_exists('notes', $d)) {
    $fields[] = 'notes = ?';
    $vals[]   = trim((string)$d['notes']) ?: null;
}
if ($fields) {
    $vals[] = $id;
    db()->prepare('UPDATE pc_products SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
}

// classification_ids is a full replace-all, same pattern as aliases — the
// caller always sends the complete desired set, not a diff.
if (array_key_exists('classification_ids', $d)) {
    $classificationIds = array_values(array_unique(array_map('intval', (array)$d['classification_ids'])));
    db()->prepare('DELETE FROM pc_product_classifications WHERE product_id = ?')->execute([$id]);
    if ($classificationIds) {
        $inClause = implode(',', array_fill(0, count($classificationIds), '?'));
        $ins = db()->prepare("INSERT IGNORE INTO pc_product_classifications (product_id, classification_id) SELECT ?, id FROM pc_classifications WHERE id IN ($inClause)");
        $ins->execute([$id, ...$classificationIds]);
    }
    $fields[] = 'classification_ids'; // for the audit-log field list below
}

if ($fields) {
    cacheBust('admin_products');
    cacheBust('comparison_data'); // canonical_name/classifications feed comparison results
    logAdminAction((int)$admin['id'], 'update_product', ['product_id' => $id, 'fields' => array_keys($d)]);
}

jsonResponse(['message' => 'Product updated.']);
