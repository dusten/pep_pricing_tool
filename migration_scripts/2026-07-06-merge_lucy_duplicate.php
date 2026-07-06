<?php
declare(strict_types=1);
// One-off (2026-07-06). Root cause: buildExtractionSystemPrompt() used to hand Claude
// the full product+alias catalog and ask it to "map common variants" (old rule 7 in
// backend/lib/claude.php). Claude echoed the alias-annotated display string itself
// back as canonical_name instead of just the name, and because every merge already
// keeps the loser's old name as a new alias on the winner (products/merge.php), each
// recurrence fed a longer alias back into the next catalog snapshot - visible here as
// literal nesting. Rule 7 removed; matching now relies entirely on
// findExactProductMatch()/findFuzzyProductCandidate() (price_import.php), which already
// check pc_product_aliases. This script just cleans up the one duplicate product this
// bug produced from the 2026-07-06 Lucy import (product 278), and removes the
// already-doubled alias (id 57) left over from an earlier occurrence of the same bug.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$adminId = 4;
$pdo = db();

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

        // Loser's name here is already the doubled/mangled string - do NOT keep it as a
        // discoverable alias (that's exactly the artifact we're cleaning up), just drop the product.
        $pdo->prepare('DELETE FROM pc_products WHERE id = ?')->execute([$loserId]);
        $pdo->commit();
        logAdminAction($adminId, 'merge_product', ['winner_id' => $winnerId, 'loser_id' => $loserId]);
        echo "merged loser $loserId ({$rows[$loserId]}) into winner $winnerId ({$rows[$winnerId]})\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "winner $winnerId / loser $loserId: FAILED - " . $e->getMessage() . "\n";
    }
}

mergeProduct($pdo, 51, 278, $adminId);

// Remove the earlier occurrence's doubled alias - superseded by aliases 2-5 which already
// cover every real variant string ("TB+BPC", "BPC-157+TB500", "wolverine", "BPC-157 + TB-500").
$pdo->prepare('DELETE FROM pc_product_aliases WHERE id = 57 AND product_id = 51')->execute();
echo "removed stale doubled alias id 57 (if present)\n";

cacheBust('admin_products');
cacheBust('pricing_data');
echo "=== lucy duplicate cleanup done ===\n";
