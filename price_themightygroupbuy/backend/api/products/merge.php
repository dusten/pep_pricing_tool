<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /products/{winnerId}/merge  body: { loser_id }
method('POST');
$admin    = requireAdmin();
$winnerId = (int)($PARAMS['id'] ?? 0);
$loserId  = (int)(input()['loser_id'] ?? 0);

if (!$winnerId || !$loserId || $winnerId === $loserId) {
    jsonResponse(['error' => 'A distinct winner and loser_id are required.'], 422);
}

$pdo = db();
$check = $pdo->prepare('SELECT id, canonical_name FROM pc_products WHERE id IN (?,?)');
$check->execute([$winnerId, $loserId]);
$rows = $check->fetchAll(PDO::FETCH_KEY_PAIR);
if (!isset($rows[$winnerId]) || !isset($rows[$loserId])) {
    jsonResponse(['error' => 'Product not found.'], 404);
}

$pdo->beginTransaction();
try {
    // Map loser specs onto matching winner specs (by label); otherwise re-home the spec row itself.
    $loserSpecs = $pdo->prepare('SELECT * FROM pc_specifications WHERE product_id = ?');
    $loserSpecs->execute([$loserId]);
    foreach ($loserSpecs->fetchAll() as $spec) {
        $match = $pdo->prepare('SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ? LIMIT 1');
        $match->execute([$winnerId, $spec['spec_label']]);
        $winnerSpecId = $match->fetchColumn();

        if ($winnerSpecId) {
            // Move prices onto the winner's equivalent spec, dropping any that would collide.
            $movePrices = $pdo->prepare(
                'UPDATE IGNORE pc_prices SET product_id = ?, specification_id = ? WHERE product_id = ? AND specification_id = ?'
            );
            $movePrices->execute([$winnerId, $winnerSpecId, $loserId, $spec['id']]);
            // Repoint user carts/stacks BEFORE the delete — their FKs cascade,
            // so without this the merge silently empties them.
            repointCartAndStackItems($pdo, $winnerId, (int)$winnerSpecId, (int)$spec['id']);
            $pdo->prepare('DELETE FROM pc_specifications WHERE id = ?')->execute([$spec['id']]);
        } else {
            // No equivalent spec on winner — re-home this spec (and its prices) directly.
            $pdo->prepare('UPDATE pc_specifications SET product_id = ? WHERE id = ?')->execute([$winnerId, $spec['id']]);
            $pdo->prepare('UPDATE pc_prices SET product_id = ? WHERE product_id = ? AND specification_id = ?')
                ->execute([$winnerId, $loserId, $spec['id']]);
            // Cart/stack rows carry their own product_id — left pointing at the
            // loser they'd cascade-delete when it's removed below.
            repointCartAndStackItems($pdo, $winnerId, (int)$spec['id'], (int)$spec['id']);
        }
    }

    // Move aliases, skipping any that already exist globally.
    $loserAliases = $pdo->prepare('SELECT id, alias FROM pc_product_aliases WHERE product_id = ?');
    $loserAliases->execute([$loserId]);
    foreach ($loserAliases->fetchAll() as $alias) {
        $exists = $pdo->prepare('SELECT id FROM pc_product_aliases WHERE alias = ? LIMIT 1');
        $exists->execute([$alias['alias']]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare('UPDATE pc_product_aliases SET product_id = ? WHERE id = ?')->execute([$winnerId, $alias['id']]);
        }
    }

    // Keep the loser's old name discoverable as an alias on the winner.
    $keepName = $pdo->prepare('SELECT id FROM pc_product_aliases WHERE alias = ? LIMIT 1');
    $keepName->execute([$rows[$loserId]]);
    if (!$keepName->fetchColumn()) {
        $pdo->prepare('INSERT INTO pc_product_aliases (product_id, alias) VALUES (?,?)')
            ->execute([$winnerId, $rows[$loserId]]);
    }

    $pdo->prepare('DELETE FROM pc_products WHERE id = ?')->execute([$loserId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[products/merge] ' . $e->getMessage());
    jsonResponse(['error' => 'Merge failed. Nothing was changed.'], 500);
}

cacheBust('admin_products');
cacheBust('pricing_data');
logAdminAction((int)$admin['id'], 'merge_product', ['winner_id' => $winnerId, 'loser_id' => $loserId]);
jsonResponse(['message' => 'Products merged.']);
