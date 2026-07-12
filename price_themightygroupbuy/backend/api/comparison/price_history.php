<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /comparison/price-history?vendor_id=&product_id=&specification_id= — one
// vendor's price-change ledger for one item, behind the clock icon next to a
// price cell on the Comparison page. No tier gate: a vendor's current price is
// already visible to every tier in the base Comparison payload, and the
// Calendar pages already show this exact ledger (vendor/product/price/source)
// to every authenticated user with no tier check — history is just older
// instances of an already-public number, not a new computed insight like the
// distribution chart's mean/stdev.
method('GET');
requireAuth();

$vendorId = (int)($_GET['vendor_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);
$specId    = (int)($_GET['specification_id'] ?? 0);
if (!$vendorId || !$productId || !$specId) {
    jsonResponse(['error' => 'vendor_id, product_id, and specification_id are required.'], 422);
}

$changes = cacheGet('comparison_data', "price_history:$vendorId:$productId:$specId", 600, function () use ($vendorId, $productId, $specId) {
    $stmt = db()->prepare(
        'SELECT old_price_usd, new_price_usd, source, changed_at
         FROM pc_price_history
         WHERE vendor_id = ? AND product_id = ? AND specification_id = ?
         ORDER BY changed_at DESC
         LIMIT 20'
    );
    $stmt->execute([$vendorId, $productId, $specId]);
    return array_map(fn($r) => [
        'old_price'  => $r['old_price_usd'] !== null ? (float)$r['old_price_usd'] : null,
        'new_price'  => (float)$r['new_price_usd'],
        'source'     => $r['source'],
        'changed_at' => $r['changed_at'],
    ], $stmt->fetchAll());
});

jsonResponse(['changes' => $changes]);
