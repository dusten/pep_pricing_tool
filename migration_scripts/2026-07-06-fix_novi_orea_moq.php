<?php
declare(strict_types=1);
// One-off (2026-07-06). NOVI OREA (vendor 12) has 3 uploaded files: a CSV
// (file 27, correctly extracted - clean 1/10/50-kit tiers for ~40 products,
// despite an earlier mistaken investigation note claiming it had failed to
// parse; it hadn't, the parse-check script used during investigation was
// wrong, pc_claude_call_log confirms parsed_ok=1), and two images (file 29,
// which supplied the matching 1-kit tier for many of those same products;
// file 28, which used an explicit "Price/vial" + "MOQ/vial" (raw vial count,
// not kits) layout that got extracted with the MOQ stuffed directly into
// kit_vial_count/tier_kit_size - see rule 12 in claude.php, added alongside
// this fix. Corrects the 11 rows from file 28: 6 are now redundant duplicates
// of tiers the CSV already covers correctly (delete), 5 are genuinely new
// (Retatrutide 60mg, GHK-Cu 100mg, and all 3 Botulinum toxin specs) and get
// corrected in place: kit_vial_count = the source's own bundle size (10 for
// Tirzepatide/Retatrutide/GHK-Cu, 15 for Botulinum toxin's "15vial/bag"),
// tier_kit_size = ceil(MOQ / kit_vial_count), price_usd = per-vial price *
// kit_vial_count.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pdo = db();

// Redundant duplicates - CSV (file 27) already has the correct tier for these.
$deleteIds = [4110, 4111, 4112, 4113, 4114, 4116];
$stmt = $pdo->prepare('DELETE FROM pc_prices WHERE id = ? AND source_file_id = 28');
$deleted = 0;
foreach ($deleteIds as $id) { $deleted += $stmt->execute([$id]) ? $stmt->rowCount() : 0; }
echo "deleted $deleted redundant duplicate rows\n";

// Genuinely new - correct kit_vial_count/tier_kit_size/price_usd in place.
$fixes = [
    // id => [kit_vial_count, tier_kit_size, price_usd]
    4115 => [10, 10, 110.00], // Retatrutide 60mg: $11/vial * 10
    4117 => [10, 10, 60.00],  // GHK-Cu 100mg: $6/vial * 10
    5083 => [15, 7, 90.00],   // Botulinum toxin 100U: $6/vial * 15
    5084 => [15, 7, 105.00],  // Botulinum toxin 200U: $7/vial * 15
    5085 => [15, 7, 135.00],  // Botulinum toxin 500U: $9/vial * 15
];
$updateStmt = $pdo->prepare('UPDATE pc_prices SET kit_vial_count=?, tier_kit_size=?, price_usd=?, price_per_unit = ROUND(? / (? * (SELECT numeric_value FROM pc_specifications WHERE id = pc_prices.specification_id)), 6) WHERE id=?');
$fixed = 0;
foreach ($fixes as $id => [$kvc, $tier, $price]) {
    $updateStmt->execute([$kvc, $tier, $price, $price, $kvc, $id]);
    $fixed += $updateStmt->rowCount();
    echo "corrected row $id -> kit_vial_count=$kvc tier_kit_size=$tier price_usd=$price\n";
}
echo "corrected $fixed rows\n";

cacheBust('pricing_data');
cacheBust('admin_products');
echo "=== NOVI OREA MOQ fix done ===\n";
