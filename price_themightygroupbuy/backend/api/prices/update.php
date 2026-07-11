<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';

// PUT /prices/{id}  body: { price_usd?, kit_vial_count?, vendor_sku?, tier_kit_size?, non_standard_kit? }
// Edits one vendor's existing price line directly (Inventory tab) — does not
// reassign which product/spec the line belongs to; see products/spec_move.php
// for moving a whole spec (and everyone's prices under it) to a different product.
method('PUT');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT pr.*, s.numeric_value FROM pc_prices pr
     JOIN pc_specifications s ON s.id = pr.specification_id
     WHERE pr.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$price = $stmt->fetch();
if (!$price) jsonResponse(['error' => 'Price row not found.'], 404);

$d      = input();
$fields = [];
$vals   = [];

$newPrice = null;
if (array_key_exists('price_usd', $d) && (float)$d['price_usd'] > 0) {
    $newPrice = (float)$d['price_usd'];
    $fields[] = 'price_usd = ?';
    $vals[]   = $newPrice;
}
$newKit = null;
if (array_key_exists('kit_vial_count', $d) && (int)$d['kit_vial_count'] >= 1 && (int)$d['kit_vial_count'] <= 65535) {
    $newKit = (int)$d['kit_vial_count'];
    $fields[] = 'kit_vial_count = ?';
    $vals[]   = $newKit;
}
// price_per_unit is computed at write-time (not a generated column) and depends
// on both price and kit_vial_count — recompute if either one changed.
if ($newPrice !== null || $newKit !== null) {
    $fields[] = 'price_per_unit = ?';
    $vals[]   = pricePerUnit(
        $newPrice ?? (float)$price['price_usd'],
        $newKit   ?? (int)$price['kit_vial_count'],
        (float)$price['numeric_value']
    );
}
if (array_key_exists('tier_kit_size', $d) && (int)$d['tier_kit_size'] >= 1 && (int)$d['tier_kit_size'] <= 65535) {
    $fields[] = 'tier_kit_size = ?';
    $vals[]   = (int)$d['tier_kit_size'];
}
if (array_key_exists('vendor_sku', $d)) {
    $fields[] = 'vendor_sku = ?';
    $vals[]   = trim((string)$d['vendor_sku']) ?: null;
}
if (array_key_exists('non_standard_kit', $d)) {
    $fields[] = 'non_standard_kit = ?';
    $vals[]   = !empty($d['non_standard_kit']) ? 1 : 0;
}
if (!$fields) jsonResponse(['error' => 'Nothing to update.'], 422);

$vals[] = $id;
try {
    db()->prepare('UPDATE pc_prices SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
} catch (Throwable $e) {
    // Most likely uq_price (vendor_id, product_id, specification_id, tier_kit_size)
    // if the edited tier_kit_size collides with another existing line.
    jsonResponse(['error' => 'Update failed — check for a duplicate tier size on this vendor/spec.', 'message' => $e->getMessage()], 409);
}

// Only a real price/kit-count change is a history event — a re-save of the
// same value, or an edit that only touched tier_kit_size/vendor_sku, isn't.
$priceActuallyChanged = ($newPrice !== null && $newPrice !== (float)$price['price_usd'])
    || ($newKit !== null && $newKit !== (int)$price['kit_vial_count']);
if ($priceActuallyChanged) {
    logPriceHistory(
        db(), (int)$price['vendor_id'], (int)$price['product_id'], (int)$price['specification_id'],
        (float)$price['price_usd'], (float)$price['price_per_unit'], (int)$price['kit_vial_count'],
        $newPrice ?? (float)$price['price_usd'],
        pricePerUnit($newPrice ?? (float)$price['price_usd'], $newKit ?? (int)$price['kit_vial_count'], (float)$price['numeric_value']),
        $newKit ?? (int)$price['kit_vial_count'],
        'manual_edit', (int)$admin['id']
    );
}

cacheBust('comparison_data');
cacheBust('calendar_data'); // a price edit that changes the price also writes a pc_price_history row
logAdminAction((int)$admin['id'], 'update_price', ['price_id' => $id, 'fields' => array_keys($d)]);
jsonResponse(['message' => 'Price updated.']);
