<?php
declare(strict_types=1);
// One-off (2026-07-08), backlog #30. The overnight Premipeptides reprocess +
// a new "Peptide Research Solutions" vendor import created 23 products
// (ids 320-342) that are existing catalog products under vendor-specific or
// dose-baked-in wording that didn't exact/fuzzy-match. Each was confirmed
// case-by-case against the winner's existing specs (see the session log), not
// guessed. Maps 22 of them onto their real product via the exact same logic
// products/merge.php uses (move specs/prices/aliases, repoint carts/stacks,
// keep the loser's name as an alias, delete the loser). 321 "MT" (SKU MT1)
// left untouched — genuinely ambiguous (Melanotan 1 vs 2), flagged not guessed.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

// loser_id => winner_id
$MERGES = [
    320 => 94,   // MT-2 (Melanotan 2 Acetate) -> Melanotan 2
    322 => 51,   // BPC 5mg + TB 5mg           -> BPC+TB (10mg spec)
    323 => 51,   // BPC 10mg + TB 10mg         -> BPC+TB (20mg)
    324 => 51,   // BPC 15mg+TB15mg            -> BPC+TB (30mg) [+ PRS 10/20/30]
    325 => 58,   // CJC-1295 w/o DAC 5mg + IPA -> CJC-1295 without DAC + Ipamorelin
    326 => 2,    // TB500(Thymosin B4 Acetate) -> TB-500
    327 => 124,  // Retatrutide 20 + Trizepatide 40 -> Retatrutide + Tirzepatide
    328 => 64,   // Acetic Acid 0.6%           -> Acetic acid
    329 => 31,   // TB+BPC-157+GHK50           -> GLOW (BPC+GHK-Cu+TB500, 70mg)
    330 => 32,   // BT+BP+CU50+kp10            -> KLOW (SKU Klow80, 80mg)
    331 => 55,   // Lipo-C w/ vitamin B12 (garbled) -> Lipo-C with B12 (SKU LC216)
    332 => 60,   // cagrilintide 5 + Semaglutide 5 -> Cagrilintide + Semaglutide
    333 => 60,   // Cagrisema (2.5+2.5)        -> Cagrilintide + Semaglutide
    334 => 60,   // Cagrisema (5+5)            -> Cagrilintide + Semaglutide
    335 => 190,  // HHB                        -> HHB - Healthy Hair Skin Nails Blend
    336 => 60,   // Cagriema (10+10)           -> Cagrilintide + Semaglutide
    337 => 58,   // CJC 1295 w/o DAC 5 + IPA 5 -> CJC-1295 without DAC + Ipamorelin
    338 => 31,   // GLOW(BPC+GHK-CU+TB500)     -> GLOW
    339 => 39,   // MOTS-c (Human)             -> MOTS-c
    340 => 47,   // SS·31                      -> SS-31
    341 => 313,  // Semax 10 + Selank 10       -> Semax + Selank combo
    342 => 37,   // WiTH-B12 (garbled, SKU B12) -> B12
];

$pdo = db();

// Same body as products/merge.php's transaction, per loser->winner pair.
function mergeProduct(PDO $pdo, int $loserId, int $winnerId): void {
    $names = $pdo->prepare('SELECT id, canonical_name FROM pc_products WHERE id IN (?,?)');
    $names->execute([$winnerId, $loserId]);
    $rows = $names->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!isset($rows[$winnerId]) || !isset($rows[$loserId])) {
        echo "  SKIP $loserId -> $winnerId (one not found)\n";
        return;
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
        echo "  merged $loserId (\"{$rows[$loserId]}\") -> $winnerId (\"{$rows[$winnerId]}\")\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  FAILED $loserId -> $winnerId: " . $e->getMessage() . "\n";
    }
}

foreach ($MERGES as $loser => $winner) mergeProduct($pdo, $loser, $winner);

cacheBust('admin_products');
cacheBust('pricing_data');
echo "done: " . count($MERGES) . " merges attempted\n";
