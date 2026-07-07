<?php
declare(strict_types=1);
// One-off (2026-07-06). Root cause: the extraction prompt let Claude conflate a
// vial-count baked into spec/dose text (e.g. "10mg*10vials" - packaging, how many
// vials come in one kit) with tier_kit_size (a genuine purchase-quantity-break
// tier) whenever a vendor's source has only ONE flat price per line item. Fixed
// in claude.php (rule 1) going forward; this script corrects the resulting bad
// data already committed across 7 vendors: Zhongke Meiye (1), Tingpeptide (8),
// Premipeptides (10), Tidetron (14), Guangzhou Guangjin Trading (15), CALLA (17),
// Lucy (20).
//
// Several of these files were reprocessed twice (once before the 2026-07-05
// scope-broadening, once after) - the bug only exists in the "after" runs, so
// many product+spec combos now have BOTH a correct tier=1 row (from the earlier,
// good run) and a buggy duplicate tier=10 row (from the later, bad run).
//
// Three cases, verified by hand before writing this:
// 1. Duplicate pairs where the tier=1 and tier=10 rows match exactly on
//    price/vendor_sku/kit_vial_count (343 confirmed) - delete the tier=10 dup.
// 2. Duplicate pairs that DON'T match exactly (6 confirmed, ids below) - leave
//    both rows untouched; these are real discrepancies (price changed between
//    runs, or a missing SKU one run) that need a human, not a guess.
// 3. tier=10-only rows with no colliding tier=1 row (429 confirmed) - safe to
//    just correct tier_kit_size to 1 in place.
//
// Premipeptides additionally used the "June Sale" column instead of "Price" -
// corrected here using the vendor's real source spreadsheet (already downloaded
// and parsed), matched by vendor_sku. 3 Cat. codes in that vendor's own sheet
// (MT1, CS10, LC) appear twice with different prices - a vendor data-entry
// duplication, not resolvable by SKU alone - those rows get their tier fixed but
// price left alone, flagged for manual review same as the 6 general mismatches.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pdo = db();

// Known mismatched tier=10 row ids - never touched by this script (price or tier).
$mismatchIds = [934, 3802, 3869, 4435, 4452, 5321];
$ambiguousPremiSkus = ['MT1', 'CS10', 'LC']; // tier gets fixed, price does not

$vendorIds = [1, 8, 10, 14, 15, 17, 20];
$inClause  = implode(',', $vendorIds);
$mismatchInClause = implode(',', $mismatchIds);

// --- Step 1: delete exact-duplicate tier=10 rows (343 expected) ---
$deleteStmt = $pdo->prepare("
    DELETE t10 FROM pc_prices t10
    JOIN pc_prices t1 ON t1.vendor_id = t10.vendor_id AND t1.product_id = t10.product_id
        AND t1.specification_id = t10.specification_id AND t1.tier_kit_size = 1 AND t1.is_active = 1
    WHERE t10.vendor_id IN ($inClause) AND t10.tier_kit_size = 10 AND t10.is_active = 1
      AND t10.id NOT IN ($mismatchInClause)
      AND t1.price_usd = t10.price_usd AND t1.vendor_sku <=> t10.vendor_sku AND t1.kit_vial_count = t10.kit_vial_count
");
$deleteStmt->execute();
echo "deleted " . $deleteStmt->rowCount() . " exact-duplicate tier=10 rows\n";

// --- Step 2: Premipeptides price correction (June Sale -> Price) + tier fix ---
$priceMap = json_decode(file_get_contents(__DIR__ . '/2026-07-06-premipeptides_price_map.json'), true);
$premiRows = $pdo->query("SELECT id, vendor_sku, price_usd FROM pc_prices WHERE vendor_id=10 AND tier_kit_size=10 AND is_active=1")->fetchAll();
$premiFixed = 0; $premiFlaggedOnly = 0;
foreach ($premiRows as $row) {
    if (in_array((int)$row['id'], $mismatchIds, true)) continue; // Acetic Acid mismatch, hands off entirely

    $sku = $row['vendor_sku'];
    if ($sku !== null && in_array($sku, $ambiguousPremiSkus, true)) {
        $pdo->prepare('UPDATE pc_prices SET tier_kit_size=1 WHERE id=?')->execute([$row['id']]);
        $premiFlaggedOnly++;
        continue;
    }
    if ($sku !== null && isset($priceMap[$sku])) {
        $pdo->prepare('UPDATE pc_prices SET tier_kit_size=1, price_usd=? WHERE id=?')->execute([$priceMap[$sku], $row['id']]);
        $premiFixed++;
    } else {
        // No SKU match in the source sheet - fix tier, leave price, flag for review.
        $pdo->prepare('UPDATE pc_prices SET tier_kit_size=1 WHERE id=?')->execute([$row['id']]);
        $premiFlaggedOnly++;
        echo "  premipeptides row {$row['id']} (sku=" . ($sku ?? 'NULL') . ") - no source match, tier fixed, price left as-is for review\n";
    }
}
echo "premipeptides: corrected price+tier for $premiFixed rows, tier-only (flagged for price review) for $premiFlaggedOnly rows\n";

// --- Step 3: remaining vendors (1,8,14,15,17), tier-only fix ---
$otherVendors = [1, 8, 14, 15, 17, 20];
$otherInClause = implode(',', $otherVendors);
$tierFixStmt = $pdo->prepare("
    UPDATE pc_prices SET tier_kit_size = 1
    WHERE vendor_id IN ($otherInClause) AND tier_kit_size = 10 AND is_active = 1
      AND id NOT IN ($mismatchInClause)
");
$tierFixStmt->execute();
echo "flipped tier_kit_size 10->1 for " . $tierFixStmt->rowCount() . " rows (Zhongke Meiye/Tingpeptide/Tidetron/Guangzhou Guangjin/CALLA/Lucy)\n";

cacheBust('pricing_data');
cacheBust('admin_products');
echo "=== tier/vial conflation cleanup done ===\n";
