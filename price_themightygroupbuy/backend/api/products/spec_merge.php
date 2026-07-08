<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /products/specifications/{id}/merge  body: { into }
// Merges a duplicate spec row (e.g. "5mg" and "5mg*10vials", both 5mg) onto
// another spec on the same product — moves prices over, drops the loser.
method('POST');
$admin    = requireAdmin();
$loserId  = (int)($PARAMS['id'] ?? 0);
$winnerId = (int)(input()['into'] ?? 0);

if (!$loserId || !$winnerId || $loserId === $winnerId) {
    jsonResponse(['error' => 'A distinct target specification (into) is required.'], 422);
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM pc_specifications WHERE id IN (?,?)');
$stmt->execute([$loserId, $winnerId]);
$specs = [];
foreach ($stmt->fetchAll() as $s) $specs[(int)$s['id']] = $s;
if (!isset($specs[$loserId]) || !isset($specs[$winnerId])) {
    jsonResponse(['error' => 'Specification not found.'], 404);
}
if ((int)$specs[$loserId]['product_id'] !== (int)$specs[$winnerId]['product_id']) {
    jsonResponse(['error' => 'Specifications must belong to the same product.'], 422);
}

$pdo->beginTransaction();
try {
    // Move prices onto the winner; any that collide on (vendor, tier) are
    // left behind and cascade-deleted with the loser spec row below.
    $pdo->prepare('UPDATE IGNORE pc_prices SET specification_id = ? WHERE specification_id = ?')
        ->execute([$winnerId, $loserId]);
    // Repoint user carts/stacks BEFORE the delete — their FKs cascade.
    repointCartAndStackItems($pdo, (int)$specs[$winnerId]['product_id'], $winnerId, $loserId);
    $pdo->prepare('DELETE FROM pc_specifications WHERE id = ?')->execute([$loserId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[products/spec_merge] ' . $e->getMessage());
    jsonResponse(['error' => 'Merge failed. Nothing was changed.'], 500);
}

cacheBust('admin_products');
cacheBust('pricing_data');
logAdminAction((int)$admin['id'], 'merge_specification', ['loser_id' => $loserId, 'winner_id' => $winnerId]);
jsonResponse(['message' => 'Specifications merged.']);
