<?php
declare(strict_types=1);
// Backlog #28 follow-up (2026-07-12 duplicate-product audit). Reprocessed
// every Claude call-log raw JSON response mentioning Lipo-C/MIC (see
// Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-duplicate-product-audit.md)
// and found the real ground truth is the vendor SKU prefix, not whatever
// name Claude gave a listing on a given run (which was inconsistent even
// for the SAME vendor+file reprocessed twice):
//   LC120           -> Lipo-c, no B12               (product 33)
//   LC216           -> Lipo-C with B12 (real B12 in the recipe)  (product 55)
//   LC396/425/526   -> the bigger "FOCUS"/"FAT BLASTER" blend, NO B12 in
//                      the recipe despite some runs naming it "MIC(lipo C
//                      with B12)" -- confirmed by Lucy's vendor file, which
//                      names these tiers "Lipo-C[FOCUS]"/"Lipo-C[FAT
//                      BLASTER]" -- both already-existing aliases on
//                      product 33, not 55.
//
// This produced two kinds of mess across products 33/55, both fixed here:
//  (a) several LC216 vendor rows landed on product 33 (wrong product) and
//      the LC396/425/526 family is split across both 33 and 55, because
//      most vendors just wrote a generic "10ml" spec label with no dose,
//      so import matching had nothing but Claude's inconsistent naming to
//      go on;
//  (b) repeated reprocessing of the same vendor file over time created
//      near-duplicate specs for the identical real listing (e.g. CALLA's
//      $36 LC425 line item exists 4 times across specs 840/946/1036/1033
//      with slightly different label text each time).
//
// Read-only bare "MIC" SKU vendors (Peptide Research Solutions, Zhongke,
// Norco) are NOT touched -- no dose info to disambiguate which tier they
// mean, and their own extracted name already says "with B12", so left on
// product 55 as-is; genuinely unresolved, not guessed.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pdo = db();
$pdo->beginTransaction();
try {
    // ---- Re-home the 396mg tier (no product-33 equivalent exists yet) ----
    // NOTE: pc_prices has its own denormalized product_id, separate from its
    // specification's product_id -- both must be updated together or the row
    // becomes inconsistent (caught via a post-run full-DB consistency check).
    $pdo->prepare('UPDATE pc_specifications SET product_id = 33 WHERE id = 928')->execute(); // "3960mg (10ml x 396mg/ml)"
    $pdo->prepare('UPDATE pc_prices SET product_id = 33 WHERE id = 4966')->execute();

    // ---- Merge the 526mg tier onto product 33's existing spec 1162 ("526mg/ml, 10ml") ----
    $pdo->prepare('UPDATE IGNORE pc_prices SET product_id = 33, specification_id = 1162 WHERE id IN (4967, 5133)')->execute();

    // ---- Move 5 misplaced LC216 (with-B12) rows off product 33's generic "10ml" spec onto product 55's ----
    $pdo->prepare('UPDATE IGNORE pc_prices SET product_id = 55, specification_id = 207 WHERE id IN (2401, 1129, 6533, 6648, 6647)')->execute();

    // ---- Delete exact-duplicate price rows (same vendor/SKU/price already correctly represented elsewhere) ----
    // Guangzhou LC120 $59 (dup of 4964/spec926, kept)
    // CALLA LC120 $24 (dup of 5125/spec1031, kept)
    // CALLA LC425 $36 x3 (dup of 5131/spec1036, kept -- the canonical home)
    // CALLA LC216 $29 (dup of 5126/spec1032 on product 55, kept -- correct product)
    // Guangzhou LC216 $59 (dup of 4965/spec927, kept)
    // Guangzhou LC396 $58 (dup of 4966/spec928, kept, now re-homed to product 33 above)
    // Guangzhou LC526 $59 (dup of 4967/spec929, kept, now merged onto product33/spec1162 above)
    $pdo->prepare('DELETE FROM pc_prices WHERE id IN (4707, 4835, 4838, 5024, 5127, 5021, 4708, 4709, 4710)')->execute();

    // ---- Repoint any cart/stack items off the specs about to be deleted, onto their surviving equivalent ----
    repointCartAndStackItems($pdo, 33, 926, 763);
    repointCartAndStackItems($pdo, 33, 1031, 837);
    repointCartAndStackItems($pdo, 33, 1036, 840);
    repointCartAndStackItems($pdo, 33, 1036, 946);
    repointCartAndStackItems($pdo, 33, 1036, 1033); // was on product 55, canonical home is now product 33
    repointCartAndStackItems($pdo, 55, 1032, 944);  // was on product 33, canonical home is product 55
    repointCartAndStackItems($pdo, 55, 927, 764);
    repointCartAndStackItems($pdo, 33, 928, 765);   // 928 already re-homed to product 33 above
    repointCartAndStackItems($pdo, 33, 1162, 766);
    repointCartAndStackItems($pdo, 33, 1162, 929);
    repointCartAndStackItems($pdo, 33, 1162, 1038);

    // ---- Delete the now-empty duplicate/vacated specs ----
    $pdo->prepare('DELETE FROM pc_specifications WHERE id IN (763, 837, 840, 946, 1033, 944, 764, 765, 766, 929, 1038)')->execute();

    $pdo->commit();
    echo "done\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

cacheBust('admin_products');
cacheBust('comparison_data');
echo "cache busted\n";
