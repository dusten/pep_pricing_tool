<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/helpers.php';

// POST   /admin/stacks/{id}/items — add a component, body: { product_id, specification_id? }
//   — specification_id omitted/0 means "any size of this product" (a NULL
//     row) — resolved the same way pc_cart_items' NULL rows are once a user
//     adds this stack to their cart: cheapest $/unit, excluding raw
//     material/tablet specs unless that exact spec was explicitly picked.
// DELETE /admin/stacks/{id}/items/{itemId} — remove a component
method('POST', 'DELETE');
$admin   = requireAdmin();
$stackId = (int)($PARAMS['id'] ?? 0);
$itemId  = isset($PARAMS['itemId']) ? (int)$PARAMS['itemId'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!$itemId) jsonResponse(['error' => 'Item id required.'], 422);
    db()->prepare('DELETE FROM pc_stack_items WHERE id = ? AND stack_id = ?')->execute([$itemId, $stackId]);
    cacheBust('stacks_data'); // GET /api/stacks' item_count shares this group
    logAdminAction((int)$admin['id'], 'remove_stack_item', ['stack_id' => $stackId, 'item_id' => $itemId]);
    jsonResponse(['message' => 'Component removed.']);
}

$d         = input();
$productId = (int)($d['product_id'] ?? 0);
$specId    = isset($d['specification_id']) ? (int)$d['specification_id'] : 0;
if (!$productId) jsonResponse(['error' => 'product_id is required.'], 422);

if ($specId) {
    $check = db()->prepare('SELECT id FROM pc_specifications WHERE id = ? AND product_id = ? LIMIT 1');
    $check->execute([$specId, $productId]);
    if (!$check->fetchColumn()) jsonResponse(['error' => 'Specification not found for this product.'], 404);

    db()->prepare('INSERT IGNORE INTO pc_stack_items (stack_id, product_id, specification_id) VALUES (?,?,?)')
        ->execute([$stackId, $productId, $specId]);
} else {
    // MariaDB's UNIQUE key treats every NULL as distinct, so INSERT IGNORE
    // won't dedupe a second "any size" component for the same product — check first.
    $exists = db()->prepare('SELECT id FROM pc_stack_items WHERE stack_id = ? AND product_id = ? AND specification_id IS NULL');
    $exists->execute([$stackId, $productId]);
    if (!$exists->fetchColumn()) {
        db()->prepare('INSERT INTO pc_stack_items (stack_id, product_id, specification_id) VALUES (?, ?, NULL)')
            ->execute([$stackId, $productId]);
    }
}

cacheBust('stacks_data'); // GET /api/stacks' item_count shares this group
logAdminAction((int)$admin['id'], 'add_stack_item', ['stack_id' => $stackId, 'product_id' => $productId, 'specification_id' => $specId ?: null]);
jsonResponse(['message' => 'Component added.'], 201);
