<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /products/specifications/{id}/move  body: { product_id }
// Re-homes one specification (and its prices) onto a different product —
// e.g. a blend/combo spec (extracted under a single-compound product it
// doesn't actually belong to) needs its own product. Same per-spec re-homing
// merge.php already does while merging two whole products, scoped to just
// the one spec instead of an entire product's worth.
method('POST');
$admin    = requireAdmin();
$specId   = (int)($PARAMS['id'] ?? 0);
$targetId = (int)(input()['product_id'] ?? 0);

if (!$specId || !$targetId) {
    jsonResponse(['error' => 'product_id is required.'], 422);
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM pc_specifications WHERE id = ? LIMIT 1');
$stmt->execute([$specId]);
$spec = $stmt->fetch();
if (!$spec) jsonResponse(['error' => 'Specification not found.'], 404);

if ((int)$spec['product_id'] === $targetId) {
    jsonResponse(['error' => 'Already belongs to that product.'], 422);
}

$target = $pdo->prepare('SELECT id FROM pc_products WHERE id = ? LIMIT 1');
$target->execute([$targetId]);
if (!$target->fetchColumn()) jsonResponse(['error' => 'Target product not found.'], 404);

$pdo->beginTransaction();
try {
    $match = $pdo->prepare('SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ? LIMIT 1');
    $match->execute([$targetId, $spec['spec_label']]);
    $targetSpecId = $match->fetchColumn();

    if ($targetSpecId) {
        // Target already has an equivalent spec — move prices onto it, drop the now-duplicate spec row.
        $pdo->prepare('UPDATE IGNORE pc_prices SET product_id = ?, specification_id = ? WHERE product_id = ? AND specification_id = ?')
            ->execute([$targetId, $targetSpecId, $spec['product_id'], $specId]);
        // Repoint user carts/stacks BEFORE the delete — their FKs cascade.
        repointCartAndStackItems($pdo, $targetId, (int)$targetSpecId, $specId);
        $pdo->prepare('DELETE FROM pc_specifications WHERE id = ?')->execute([$specId]);
    } else {
        // No equivalent on the target — re-home the spec row itself.
        $pdo->prepare('UPDATE pc_specifications SET product_id = ? WHERE id = ?')->execute([$targetId, $specId]);
        $pdo->prepare('UPDATE pc_prices SET product_id = ? WHERE product_id = ? AND specification_id = ?')
            ->execute([$targetId, $spec['product_id'], $specId]);
        // Cart/stack rows carry their own product_id — keep them in sync with the move.
        repointCartAndStackItems($pdo, $targetId, $specId, $specId);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[products/spec_move] ' . $e->getMessage());
    jsonResponse(['error' => 'Move failed. Nothing was changed.'], 500);
}

cacheBust('admin_products');
cacheBust('pricing_data');
logAdminAction((int)$admin['id'], 'move_specification', [
    'specification_id' => $specId, 'from_product_id' => (int)$spec['product_id'], 'to_product_id' => $targetId,
]);
jsonResponse(['message' => 'Specification moved.']);
