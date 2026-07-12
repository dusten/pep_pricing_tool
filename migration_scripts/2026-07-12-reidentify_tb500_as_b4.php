<?php
declare(strict_types=1);
// Backlog #58 follow-up (2026-07-12). User pushed back on this session's own
// earlier CAS/MW assignment for product 2 "TB-500" (previously set to the
// 7aa fragment's CAS, based on a general market-pattern assumption, not a
// close read of THIS catalog's actual vendor extraction data). Reprocessed
// every pc_claude_call_log raw JSON response mentioning TB-500/TB500/
// Thymosin/17-23 (35 call-log rows across 18 vendors) to re-evaluate.
//
// Finding: the overwhelming majority of vendors carry only a single,
// unqualified "TB-500"/"TB500" line, and nearly every one of THOSE was
// flagged by Claude's own extraction as ambiguous ("may refer to 7aa
// fragment or full Thymosin Beta-4, no CAS given") -- never resolving to
// the fragment. Six vendors (Jenny Peptide, Nina, Premipeptides, Laicuinuo
// LCN, LCN, Peptide Research Solutions) carry a SEPARATE, distinctly-priced
// Frag/B5/17-23 SKU alongside their plain TB-500 line -- three of those
// (Jenny Peptide, Nina, Premipeptides) had Claude read the plain line
// straight off the vendor's own document text as "TB500(Thymosin B4
// Acetate)". Two vendors (CALLA, Golden Age) flip-flopped between "TB-500"
// and "TB-500(Frag...)" for the IDENTICAL sku/price across reprocessing
// runs -- Claude guessing, not reading a real distinguishing signal.
//
// Per user's rule ("assume TB-500 B4 unless it says B5/Frag/17-23 or
// there's a second TB-500 in the results"): product 2 "TB-500" is
// re-identified as the LONG form (Thymosin Beta-4/B4, 43aa). Product 359
// "TB500(Frag)" stays the fragment (unchanged, already correct). This
// reverses the earlier alias-removal call in
// migration_scripts/2026-07-12-fix_tb500_mislabeled_alias.php, which
// deleted the one alias ("TB500(Thymosin B4 Acetate)") that -- with this
// deeper per-vendor evidence in hand -- was actually the correct one for
// product 2, not a mislabeling. Not restoring that exact alias text (no
// need, canonical_name/CAS now carry the identity), but the 3 aliases that
// DO describe the fragment ("TB-500(Frag/B5/889/17-23)" etc.) are moved
// off product 2 (wrong product for them now) onto product 359 instead.
//
// Also fixes 2 price rows discovered misfiled under product 2 during this
// reprocessing -- both are genuinely the fragment, confirmed via distinct
// SKU/price matching product 359's already-correct Jenny Peptide Frag row:
//  - Lucy, price_id 5264 ($85, sku B10F) -- same sku+price as Jenny
//    Peptide's correctly-filed Frag row, wrong product (was on 2, spec
//    206/2mg -- moves to 359's existing 2mg spec 1264).
//  - Premipeptides, price_id 4165 ($39.71, no sku, "10mg") -- matches the
//    "Frag 17-23" line from call_log_id 50/44 for this vendor's file, was on
//    2/spec 5 (10mg) -- product 359 has no 10mg spec yet, so one is created.
//
// NOT auto-fixed here, flagged for the user instead: 4 more vendors
// (Laicuinuo LCN $198/B10F, LCN $220/B10F + $89/FRAG10, Guangzhou $185.38
// raw-material 1g, Peptide Research Solutions $190/FG) had a genuinely
// distinct Frag-tier line in their raw JSON extraction that never survived
// as a separate active price row in the DB -- looks like it got silently
// overwritten by the plain TB-500 line landing on the same specification_id
// during import. Reconstructing those from months-old JSON risks stale
// pricing; left for the user to decide whether to re-add.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pdo = db();
$pdo->beginTransaction();
try {
    // ---- Re-identify product 2 as the long form (Thymosin Beta-4 / B4) ----
    $pdo->prepare('UPDATE pc_products SET cas_number = ?, molecular_weight = ? WHERE id = 2')
        ->execute(['77591-33-4', 4963.4]);

    // ---- Move product 2's 3 fragment-describing aliases onto product 359 ----
    $pdo->prepare('UPDATE pc_product_aliases SET product_id = 359 WHERE id IN (79, 80, 93)')->execute();

    // ---- Lucy's B10F row: misfiled fragment listing, move to 359's existing 2mg spec ----
    $pdo->prepare('UPDATE pc_prices SET product_id = 359, specification_id = 1264 WHERE id = 5264')->execute();

    // ---- Premipeptides' Frag row: misfiled, needs a new 10mg spec on 359 ----
    $pdo->prepare("INSERT INTO pc_specifications (product_id, spec_label, numeric_value, unit, is_raw_material) VALUES (359, '10mg', 10, 'mg', 0)")->execute();
    $newSpecId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE pc_prices SET product_id = 359, specification_id = ? WHERE id = 4165')->execute([$newSpecId]);

    $pdo->commit();
    echo "done, new 10mg spec on product 359 = $newSpecId\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

cacheBust('admin_products');
cacheBust('comparison_data');
echo "cache busted\n";
