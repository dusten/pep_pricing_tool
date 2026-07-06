<?php
declare(strict_types=1);
// One-off (2026-07-06), second pass. The [[2026-07-06-merge_lucy_duplicate.php]] fix for
// product 278 was one instance of a much bigger batch: the entire 2026-07-06 Lucy import
// (09:58:46-10:04:17) hit the same rule-7 echo bug on every row that matched an existing
// catalog entry, producing 25 more self-nested duplicate names (e.g.
// "Melanotan 1 (MT-1, Melanotan I, Melanotan 1 (MT-1, Melanotan I))"). Each maps 1:1 to
// the existing product already established as the canonical one in the 2026-07-05
// dedup pass (see merge_duplicates_2.php) - confirmed by direct id lookup below, not
// guessed. Genuinely new Lucy products (Alprostadil, Testagen, Livagen, Pancragen,
// Prostamax, Chonluten, Ovagen, N-Acetyl Epitalon Amidate, and the 3 new combo
// products) have no self-nested pattern and are left alone.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$adminId = 4;
$pdo = db();

$pairs = [
    [93, 274],  // Melanotan 1
    [94, 275],  // Melanotan 2
    [63, 276],  // PT-141
    [23, 277],  // Epithalon
    [68, 279],  // GHRP-2 Acetate
    [75, 280],  // GHRP-6 Acetate
    [57, 281],  // CJC-1295 without DAC
    [58, 282],  // CJC-1295 without DAC + Ipamorelin
    [59, 283],  // CJC-1295 with DAC
    [2, 284],   // TB-500
    [79, 285],  // PEG-MGF
    [95, 286],  // AOD-9604
    [12, 287],  // IGF-1 LR3
    [82, 288],  // Thymalin
    [74, 289],  // Thymosin Alpha-1
    [24, 290],  // FOXO4
    [83, 291],  // LL-37
    [40, 292],  // Retatrutide
    [120, 293], // Dermorphin
    [65, 294],  // Bacteriostatic Water
    [64, 295],  // Acetic acid
    [103, 296], // ARA-290
    [71, 297],  // Snap-8
    [88, 301],  // PE 22-28
    [90, 304],  // PNC-27
    [145, 305], // P21 (P021)
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

        // Don't keep the loser's mangled name as an alias - that's the artifact we're cleaning up.
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
echo "=== lucy batch duplicate cleanup done ===\n";
