<?php
declare(strict_types=1);
// Backlog #28 follow-up (2026-07-12 duplicate-product audit). User confirmed
// "Adipotide" [97] alone is the same compound as "Adipotide/FTPP" [86] --
// previously left as genuinely ambiguous, since FTPP is sometimes used as
// another name for Adipotide in this market but wasn't certain enough to
// call from data alone. 2mg/5mg specs match exactly across both; product 97
// also carries a unique 1g spec, re-homed onto the winner. Mirrors
// backend/api/products/merge.php's exact logic (spec-label matching,
// cart/stack repoint before delete, alias carry-over, keep loser's old name
// as an alias) -- same template used for every merge this session.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$winnerId = 86; // "Adipotide/FTPP"
$loserId  = 97; // "Adipotide"

$pdo = db();

$names = $pdo->prepare('SELECT id, canonical_name FROM pc_products WHERE id IN (?,?)');
$names->execute([$winnerId, $loserId]);
$rows = $names->fetchAll(PDO::FETCH_KEY_PAIR);
if (!isset($rows[$winnerId], $rows[$loserId])) {
    echo "SKIP $winnerId/$loserId — one product missing\n";
    exit(1);
}

$pdo->beginTransaction();
try {
    $loserSpecs = $pdo->prepare('SELECT * FROM pc_specifications WHERE product_id = ?');
    $loserSpecs->execute([$loserId]);
    foreach ($loserSpecs->fetchAll() as $spec) {
        $match = $pdo->prepare('SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ? LIMIT 1');
        $match->execute([$winnerId, $spec['spec_label']]);
        $winnerSpecId = $match->fetchColumn();

        if ($winnerSpecId) {
            $pdo->prepare('UPDATE IGNORE pc_prices SET product_id = ?, specification_id = ? WHERE product_id = ? AND specification_id = ?')
                ->execute([$winnerId, $winnerSpecId, $loserId, $spec['id']]);
            repointCartAndStackItems($pdo, $winnerId, (int)$winnerSpecId, (int)$spec['id']);
            $pdo->prepare('DELETE FROM pc_specifications WHERE id = ?')->execute([$spec['id']]);
        } else {
            $pdo->prepare('UPDATE pc_specifications SET product_id = ? WHERE id = ?')->execute([$winnerId, $spec['id']]);
            $pdo->prepare('UPDATE pc_prices SET product_id = ? WHERE product_id = ? AND specification_id = ?')
                ->execute([$winnerId, $loserId, $spec['id']]);
            repointCartAndStackItems($pdo, $winnerId, (int)$spec['id'], (int)$spec['id']);
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
        $pdo->prepare('INSERT INTO pc_product_aliases (product_id, alias) VALUES (?,?)')
            ->execute([$winnerId, $rows[$loserId]]);
    }

    $pdo->prepare('DELETE FROM pc_products WHERE id = ?')->execute([$loserId]);
    $pdo->commit();
    echo "merged $loserId (\"{$rows[$loserId]}\") -> $winnerId (\"{$rows[$winnerId]}\")\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "FAILED $winnerId/$loserId: " . $e->getMessage() . "\n";
    exit(1);
}

cacheBust('admin_products');
cacheBust('comparison_data');
echo "done\n";
