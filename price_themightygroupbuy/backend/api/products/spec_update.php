<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// PUT /products/specifications/{id}  body: { spec_label?, numeric_value?, unit? }
method('PUT');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pc_specifications WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$spec = $stmt->fetch();
if (!$spec) jsonResponse(['error' => 'Specification not found.'], 404);

$d      = input();
$fields = [];
$vals   = [];
if (array_key_exists('spec_label', $d) && trim((string)$d['spec_label']) !== '') {
    $fields[] = 'spec_label = ?';
    $vals[]   = trim((string)$d['spec_label']);
}
if (array_key_exists('numeric_value', $d) && (float)$d['numeric_value'] > 0) {
    $fields[] = 'numeric_value = ?';
    $vals[]   = (float)$d['numeric_value'];
}
if (array_key_exists('unit', $d) && in_array($d['unit'], ['mg', 'iu', 'ml', 'other'], true)) {
    $fields[] = 'unit = ?';
    $vals[]   = $d['unit'];
}
if (!$fields) jsonResponse(['error' => 'Nothing to update.'], 422);

$pdo = db();
$pdo->beginTransaction();
try {
    $vals[] = $id;
    $pdo->prepare('UPDATE pc_specifications SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);

    // price_per_unit (= price_usd / (kit_vial_count * numeric_value)) is computed
    // at write-time, not a generated column — changing the mg amount here would
    // silently strand every price row's $/unit on the old value otherwise.
    // Mirrors pricePerUnit() in PHP; GREATEST(kit_vial_count,1) guards a zero kit.
    if (array_key_exists('numeric_value', $d)) {
        $pdo->prepare('UPDATE pc_prices SET price_per_unit = ROUND(price_usd / (GREATEST(kit_vial_count, 1) * ?), 6) WHERE specification_id = ?')
            ->execute([(float)$d['numeric_value'], $id]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    // Most likely (product_id, spec_label)'s UNIQUE key if the new label
    // collides with another spec already on this product.
    jsonResponse(['error' => 'Update failed — check for a duplicate label on this product.', 'message' => $e->getMessage()], 500);
}

cacheBust('admin_products');
cacheBust('comparison_data');
logAdminAction((int)$admin['id'], 'update_specification', ['specification_id' => $id, 'fields' => array_keys($d)]);
jsonResponse(['message' => 'Specification updated.']);
