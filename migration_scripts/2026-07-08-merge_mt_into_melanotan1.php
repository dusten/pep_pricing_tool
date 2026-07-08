<?php
declare(strict_types=1);
// One-off (2026-07-08), backlog #30. Product 321 "MT" (Premipeptides, SKU MT1,
// $44/10mg) is Melanotan 1 — they list MT-2 separately, and SKU MT1 + the
// existing Melanotan 1 alias "MT-1" confirm it (user decision). Merges 321 onto
// product 93 (Melanotan 1) with the exact products/merge.php logic: move the
// 10mg price onto 93's existing 10mg spec (218), repoint any carts/stacks,
// drop the duplicate spec, keep "MT" as an alias, delete the loser product.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$LOSER = 321; $WINNER = 93;
$pdo = db();

$names = $pdo->prepare('SELECT id, canonical_name FROM pc_products WHERE id IN (?,?)');
$names->execute([$WINNER, $LOSER]);
$rows = $names->fetchAll(PDO::FETCH_KEY_PAIR);
if (!isset($rows[$WINNER], $rows[$LOSER])) { echo "one product missing — nothing done\n"; exit(1); }

$pdo->beginTransaction();
try {
    $loserSpecs = $pdo->prepare('SELECT * FROM pc_specifications WHERE product_id = ?');
    $loserSpecs->execute([$LOSER]);
    foreach ($loserSpecs->fetchAll() as $spec) {
        $match = $pdo->prepare('SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ? LIMIT 1');
        $match->execute([$WINNER, $spec['spec_label']]);
        $winnerSpecId = $match->fetchColumn();
        if ($winnerSpecId) {
            $pdo->prepare('UPDATE IGNORE pc_prices SET product_id = ?, specification_id = ? WHERE product_id = ? AND specification_id = ?')
                ->execute([$WINNER, $winnerSpecId, $LOSER, $spec['id']]);
            repointCartAndStackItems($pdo, $WINNER, (int)$winnerSpecId, (int)$spec['id']);
            $pdo->prepare('DELETE FROM pc_specifications WHERE id = ?')->execute([$spec['id']]);
        } else {
            $pdo->prepare('UPDATE pc_specifications SET product_id = ? WHERE id = ?')->execute([$WINNER, $spec['id']]);
            $pdo->prepare('UPDATE pc_prices SET product_id = ? WHERE product_id = ? AND specification_id = ?')
                ->execute([$WINNER, $LOSER, $spec['id']]);
            repointCartAndStackItems($pdo, $WINNER, (int)$spec['id'], (int)$spec['id']);
        }
    }
    $keepName = $pdo->prepare('SELECT id FROM pc_product_aliases WHERE alias = ? LIMIT 1');
    $keepName->execute([$rows[$LOSER]]);
    if (!$keepName->fetchColumn()) {
        $pdo->prepare('INSERT INTO pc_product_aliases (product_id, alias) VALUES (?,?)')->execute([$WINNER, $rows[$LOSER]]);
    }
    $pdo->prepare('DELETE FROM pc_products WHERE id = ?')->execute([$LOSER]);
    $pdo->commit();
    echo "merged $LOSER (\"{$rows[$LOSER]}\") -> $WINNER (\"{$rows[$WINNER]}\")\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

cacheBust('admin_products');
cacheBust('pricing_data');
echo "done\n";
