<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/cart.php';

// POST /cart/add-stack/{stackId} — bulk-adds every active stack's components
// into the caller's cart (dedups against items already there), then returns
// the same items + cheapest-vendor-total shape as GET /cart.
method('POST');
$user    = requireAuth();
$stackId = (int)($PARAMS['id'] ?? 0);

$stack = db()->prepare('SELECT id FROM pc_stacks WHERE id = ? AND is_active = 1 LIMIT 1');
$stack->execute([$stackId]);
if (!$stack->fetchColumn()) jsonResponse(['error' => 'Stack not found.'], 404);

$stackItems = db()->prepare('SELECT product_id, specification_id FROM pc_stack_items WHERE stack_id = ?');
$stackItems->execute([$stackId]);

$ins = db()->prepare('INSERT IGNORE INTO pc_cart_items (user_id, product_id, specification_id) VALUES (?,?,?)');
foreach ($stackItems->fetchAll() as $item) {
    $ins->execute([$user['id'], $item['product_id'], $item['specification_id']]);
}

jsonResponse(getCartSnapshot(db(), (int)$user['id']));
