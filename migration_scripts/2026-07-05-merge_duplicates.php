<?php
declare(strict_types=1);
// One-off (2026-07-05): merges 4 duplicate products created by two independent
// review passes the same morning - the user's own manual Review Queue review,
// then 2026-07-05-review_batch.php (not knowing about it) creating near-
// duplicates of the same 4 real products under different vendor-file wording.
// Same exact logic as backend/api/products/merge.php. Winners are the user's
// own manually-created products.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$adminId = 4;
$pdo = db();

$pairs = [
    [190, 194], // HHB - Healthy Hair Skin Nails Blend  <- Healthy Hair Skin Nails Blend
    [192, 195], // GAZ - Immunological Enhancement Blend <- GAZ - immunological enhancement
    [191, 196], // LMX - Lipo Mino Mix                   <- Lipo Mino Mix
    [193, 197], // SHR - Shred Blend                      <- SHR - SHRED
];

function mergeProduct(PDO $pdo, int $winnerId, int $loserId, int $adminId): void {
    $check = $pdo->prepare('SELECT id, canonical_name FROM pc_products WHERE id IN (?,?)');
    $check->execute([$winnerId, $loserId]);
    $rows = $check->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!isset($rows[$winnerId]) || !isset($rows[$loserId])) { echo "winner $winnerId / loser $loserId: not found, skip\n"; return; }

    $pdo->beginTransaction();
    try {
        $loserSpecs = $pdo->prepare('SELECT * FROM pc_specifications WHERE product_id = ?');
        $loserSpecs->execute([$loserId]);
        foreach ($loserSpecs->fetchAll() as $spec) {
            $match = $pdo->prepare('SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ? LIMIT 1');
            $match->execute([$winnerId, $spec['spec_label']]);
            $winnerSpecId = $match->fetchColumn();

            if ($winnerSpecId) {
                $movePrices = $pdo->prepare('UPDATE IGNORE pc_prices SET product_id = ?, specification_id = ? WHERE product_id = ? AND specification_id = ?');
                $movePrices->execute([$winnerId, $winnerSpecId, $loserId, $spec['id']]);
                $pdo->prepare('DELETE FROM pc_specifications WHERE id = ?')->execute([$spec['id']]);
            } else {
                $pdo->prepare('UPDATE pc_specifications SET product_id = ? WHERE id = ?')->execute([$winnerId, $spec['id']]);
                $pdo->prepare('UPDATE pc_prices SET product_id = ? WHERE product_id = ? AND specification_id = ?')->execute([$winnerId, $loserId, $spec['id']]);
            }
        }

        $loserAliases = $pdo->prepare('SELECT id, alias FROM pc_product_aliases WHERE product_id = ?');
        $loserAliases->execute([$loserId]);
        foreach ($loserAliases->fetchAll() as $alias) {
            $exists = $pdo->prepare('SELECT id FROM pc_product_aliases WHERE alias = ? LIMIT 1');
            $exists->execute([$alias['alias']]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('UPDATE pc_product_aliases SET product_id = ? WHERE id = ?')->execute([$winnerId, $alias['id']]);
            }
        }

        $keepName = $pdo->prepare('SELECT id FROM pc_product_aliases WHERE alias = ? LIMIT 1');
        $keepName->execute([$rows[$loserId]]);
        if (!$keepName->fetchColumn()) {
            $pdo->prepare('INSERT INTO pc_product_aliases (product_id, alias) VALUES (?,?)')->execute([$winnerId, $rows[$loserId]]);
        }

        $pdo->prepare('DELETE FROM pc_products WHERE id = ?')->execute([$loserId]);
        $pdo->commit();
        logAdminAction($adminId, 'merge_product', ['winner_id' => $winnerId, 'loser_id' => $loserId]);
        echo "merged loser $loserId ({$rows[$loserId]}) into winner $winnerId ({$rows[$winnerId]})\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "winner $winnerId / loser $loserId: FAILED - " . $e->getMessage() . "\n";
    }
}

foreach ($pairs as [$winner, $loser]) {
    mergeProduct($pdo, $winner, $loser, $adminId);
}

cacheBust('admin_products');
cacheBust('pricing_data');
echo "=== merge done ===\n";
