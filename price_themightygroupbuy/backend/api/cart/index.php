<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/cart.php';

// GET  /cart — current user's cart items + cheapest-vendor-total breakdown
// POST /cart — add an item, body: { product_id, specification_id? }
//   — specification_id omitted/0 means "any size of this product" (a NULL
//     row) — priced by cheapest $/unit across every vendor/spec, since kit
//     prices aren't comparable across different doses. See getCartSnapshot().
method('GET', 'POST');
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d         = input();
    $productId = (int)($d['product_id'] ?? 0);
    $specId    = isset($d['specification_id']) ? (int)$d['specification_id'] : 0;
    if (!$productId) jsonResponse(['error' => 'product_id is required.'], 422);

    if ($specId) {
        $check = db()->prepare('SELECT id FROM pc_specifications WHERE id = ? AND product_id = ? LIMIT 1');
        $check->execute([$specId, $productId]);
        if (!$check->fetchColumn()) jsonResponse(['error' => 'Specification not found for this product.'], 404);

        db()->prepare('INSERT IGNORE INTO pc_cart_items (user_id, product_id, specification_id) VALUES (?,?,?)')
            ->execute([$user['id'], $productId, $specId]);
    } else {
        // MariaDB's UNIQUE key treats every NULL as distinct, so INSERT IGNORE
        // won't dedupe a second "any size" row for the same product — check first.
        $exists = db()->prepare('SELECT id FROM pc_cart_items WHERE user_id = ? AND product_id = ? AND specification_id IS NULL');
        $exists->execute([$user['id'], $productId]);
        if (!$exists->fetchColumn()) {
            db()->prepare('INSERT INTO pc_cart_items (user_id, product_id, specification_id) VALUES (?, ?, NULL)')
                ->execute([$user['id'], $productId]);
        }
    }
}

jsonResponse(getCartSnapshot(db(), (int)$user['id']));
