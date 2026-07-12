<?php
declare(strict_types=1);
// Backlog #28/#57 follow-up (2026-07-12 duplicate-product audit + deep-dive
// pass, see Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-duplicate-product-audit.md),
// user-approved MEDIUM-confidence merges. Mirrors backend/api/products/merge.php's
// exact logic (spec-label matching, cart/stack repoint before delete, alias
// carry-over, keep loser's old name as an alias) for each pair below:
//
//    96 "Gonadorelin Acetate"                       <- 119 "Gonadorelin" (salt-form pattern, same as Sermorelin)
//    81 "Hexarelin Acetate"                          <- 353 "Hexarelin" (same salt-form pattern)
//    86 "Adipotide/FTPP"                             <- 87 "FTPP Adipotide" (word-order flip)
//   139 "Triptorelin Acetate"                        <- 373 "Triptorelin Acetate/GnRH Triptorelin"
//    31 "GLOW"                                       <- 376 "Glow(TB10mg+BPC-15710mg+GHK50mg)"
//    55 "Lipo-C with B12"                             <- 36 "MIC(lipo C with B12)"
//
// Winner picked as the more-standard/canonical name in each pair, generally
// (but not always) the side with more active vendor listings.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pairs = [
    ['winner' => 96,  'loser' => 119],
    ['winner' => 81,  'loser' => 353],
    ['winner' => 86,  'loser' => 87],
    ['winner' => 139, 'loser' => 373],
    ['winner' => 31,  'loser' => 376],
    ['winner' => 55,  'loser' => 36],
];

$pdo = db();

foreach ($pairs as $pair) {
    $winnerId = $pair['winner'];
    $loserId  = $pair['loser'];

    $names = $pdo->prepare('SELECT id, canonical_name FROM pc_products WHERE id IN (?,?)');
    $names->execute([$winnerId, $loserId]);
    $rows = $names->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!isset($rows[$winnerId], $rows[$loserId])) {
        echo "SKIP $winnerId/$loserId — one product missing\n";
        continue;
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
    }
}

cacheBust('admin_products');
cacheBust('comparison_data');
echo "done\n";
