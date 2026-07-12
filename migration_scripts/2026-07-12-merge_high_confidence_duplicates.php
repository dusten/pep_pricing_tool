<?php
declare(strict_types=1);
// Backlog: 2026-07-12 full-catalog duplicate-product audit (see
// Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-duplicate-product-audit.md),
// user-approved HIGH-confidence merges only. Mirrors backend/api/products/merge.php's
// exact logic (spec-label matching, cart/stack repoint before delete, alias
// carry-over, keep loser's old name as an alias) for each pair below:
//
//   123 "HGH 191AA(Somatropin)"        <- 377 "HGH 191AA (Somatropin\xef\xbc\x89" (full-width paren typo)
//    34 "VIP"                          <- 354 "Vasoactive Intestinal Peptide (VIP)"
//   216 "Boldenone Cypionate"          <- 230 "BC 250 (Boldenone Cypionate)"
//
// Winner picked as the larger/cleaner canonical name in each pair (HGH/VIP: far
// more vendor listings; Boldenone: generic scientific name over a vendor-brand
// dose shorthand, matching this catalog's existing naming convention even
// though 230 currently has slightly more listings than 216).
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pairs = [
    ['winner' => 123, 'loser' => 377],
    ['winner' => 34,  'loser' => 354],
    ['winner' => 216, 'loser' => 230],
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
