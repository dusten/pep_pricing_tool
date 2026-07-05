<?php
declare(strict_types=1);
// One-off (2026-07-05), second/broader duplicate-merge pass. Found via a
// substring self-join scan across the whole pc_products table: Claude's
// extraction sometimes bakes known aliases into canonical_name itself (e.g.
// "AOD-9604 (AOD9604)"), which defeats exact-match against the real existing
// product ("AOD-9604") and creates a duplicate - a systemic, pre-existing
// pattern, not something this session's reimport caused on its own. Every
// pair below is a pure alias/spacing/casing variant of the same real
// product, confirmed by manual read. Genuinely different products (FOXO4 vs
// FOXO4-DRI, combo products, different esters, dose-baked-into-name cases)
// were deliberately excluded and left for a human call - see backlog #28 in
// wiki/entities/phase-roadmap.md.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$adminId = 4;
$pdo = db();

$pairs = [
    [95, 177],  // AOD-9604
    [103, 168], // ARA-290
    [65, 158],  // Bacteriostatic Water
    [51, 165],  // BPC+TB
    [59, 174],  // CJC-1295 with DAC
    [57, 172],  // CJC-1295 without DAC
    [58, 173],  // CJC-1295 without DAC + Ipamorelin
    [120, 181], // Dermorphin
    [23, 164],  // Epithalon
    [68, 170],  // GHRP-2 Acetate
    [75, 171],  // GHRP-6 Acetate
    [31, 166],  // GLOW
    [12, 178],  // IGF-1 LR3
    [32, 167],  // KLOW
    [32, 272],  // KLOW
    [33, 183],  // Lipo-c
    [83, 180],  // LL-37
    [93, 220],  // Melanotan 1
    [94, 221],  // Melanotan 2
    [89, 185],  // MK-677
    [88, 182],  // PE 22-28
    [79, 176],  // PEG-MGF
    [90, 184],  // PNC-27
    [63, 159],  // PT-141
    [71, 160],  // Snap-8
    [2, 157],   // TB-500
    [2, 175],   // TB-500
    [82, 161],  // Thymalin
    [74, 162],  // Thymosin Alpha-1
    [64, 151],  // Acetic acid
    [64, 108],  // Acetic acid
    [145, 223], // P21
    [25, 179],  // FOXO4-DRI
    [24, 222],  // FOXO4
    [40, 163],  // Retatrutide
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
echo "=== merge pass 2 done ===\n";
