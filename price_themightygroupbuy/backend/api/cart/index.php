<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/cart.php';

// GET  /cart — current user's cart items + cheapest-vendor-total breakdown
// POST /cart — add an item, body: { product_id, specification_id }
method('GET', 'POST');
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d         = input();
    $productId = (int)($d['product_id'] ?? 0);
    $specId    = (int)($d['specification_id'] ?? 0);
    if (!$productId || !$specId) jsonResponse(['error' => 'product_id and specification_id are required.'], 422);

    $check = db()->prepare('SELECT id FROM pc_specifications WHERE id = ? AND product_id = ? LIMIT 1');
    $check->execute([$specId, $productId]);
    if (!$check->fetchColumn()) jsonResponse(['error' => 'Specification not found for this product.'], 404);

    db()->prepare('INSERT IGNORE INTO pc_cart_items (user_id, product_id, specification_id) VALUES (?,?,?)')
        ->execute([$user['id'], $productId, $specId]);
}

jsonResponse(getCartSnapshot(db(), (int)$user['id']));
