<?php
/**
 * 2026-08-06-protidexbio-dedupe-and-price-fix.php
 *
 * Vendor "protidexbio peptide LTD Factory" (id 18) uploaded a new price list
 * ("Faye's latest price list.pdf") whose Claude extraction had two bugs:
 * (1) it used the vendor's unlabeled internal SKU-code column as
 * canonical_name instead of the separately-present "name" column for most
 * rows (e.g. "SM5" instead of "Semaglutide" — approved onto the wrong
 * existing product, Sermorelin Acetate, via a stale automated name_mismatch
 * suggestion); (2) a genuine row-alignment slip in one merged-name table
 * block (source rows 47-64) duplicated one row (a phantom "CD10" that
 * doesn't exist in the source) and dropped another (PT-141), net-shifting
 * ~18 rows' prices onto the wrong name — caught when the user noticed the
 * review card for "IGF-1" showed $64 (really MOTS-c's price) instead of
 * IGF-1's real $220.
 *
 * See diagnostic_scripts/2026-08-06-protidexbio-ground-truth.py for the full
 * hand-transcription of the source PDF used to verify every one of the 123
 * pc_pending_imports rows this file produced (ids 4003-4125), and
 * 2026-08-06-protidexbio-review-queue-decisions.py for the original (partly
 * wrong) first-pass triage this corrects.
 *
 * Separately, and unrelated to the extraction bug itself: this vendor
 * already had almost this entire catalog correctly committed from an
 * earlier file (2026-07-05, reprocessed 2026-07-14 per the mishap in
 * wiki/analyses/2026-07-14-incomplete-spec-drop-bug.md). Because
 * vendor_sku is part of the price-uniqueness key (migration 030), today's
 * new extraction — which mostly produced blank vendor_sku values, since it
 * used the SKU column as canonical_name instead — created ~74 duplicate
 * active price rows for items that already existed, instead of updating
 * them. This is the exact "third wave" failure mode already documented and
 * fixed once before for this same reason (see
 * migration_scripts/2026-07-14-deactivate_reprocess_duplicate_skus.php) —
 * this file is a fresh occurrence, not a repeat of that exact incident.
 *
 * Executed live via PUT /api/prices/{id} and POST /vendors/18/prices (same
 * effect as this script). Kept here as the archived record per this
 * project's convention. Ran once, not idempotent (re-running would try to
 * deactivate/re-fix already-corrected rows — harmless no-op for the
 * deactivations, but the two new-row creations would duplicate if re-run).
 */
declare(strict_types=1);
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/price_import.php';

$pdo = db();

// Step 1 — price corrections on rows already on the RIGHT product, just the
// wrong price due to the row-shift bug (id => corrected price_usd).
$priceFixes = [
    15721 => 167, // CJC-1295 with DAC 5mg (was 90)
    15723 => 90,  // AOD-9604 5mg (was 160)
    15724 => 160, // AOD-9604 10mg (was 220)
    15726 => 48,  // Melanotan 2 10mg (was 39)
    15727 => 39,  // Epithalon 5mg (was 60)
    15728 => 60,  // Epithalon 10mg (was 70)
    15744 => 50,  // AHK-CU 50mg (was 70)
    15755 => 60,  // Glutathione 600mg (was 56)
    3962   => 42, // Hexarelin Acetate 2mg (was 56) -- approved by another admin mid-session using the uncorrected value
    15670  => 66, // PT-141 10mg (was 50) -- this one auto-committed via the exact-name-match path, never entered the review queue at all
];
$stmt = $pdo->prepare('UPDATE pc_prices SET price_usd = ?, price_per_unit = price_per_unit * (? / price_usd) WHERE id = ?');
foreach ($priceFixes as $id => $newPrice) {
    $stmt->execute([$newPrice, $newPrice, $id]);
    echo "Fixed price {$id} -> \${$newPrice}\n";
}

// Step 2 — deactivate phantom/wrong-product/duplicate rows.
$toDeactivate = [
    15722 => 'phantom "CD10" (10mg CJC-1295 with DAC) — does not exist in the source, extraction duplicated CD5 into two rows',
    15855 => 'Snap-8 75IU/$70 — wrong spec+price duplicate; correct row (10mg/$50) already existed at id 3976',
    15671 => 'VIP10 5mg/$90 on the wrong product (490) — real item is plain "VIP" (34), recreated separately below then found ALSO a pre-existing duplicate (3940) and re-deactivated',
    15672 => 'Semaglutide 5mg wrongly committed onto Sermorelin Acetate (SM5)',
    15673 => 'Semaglutide 10mg wrongly committed onto Sermorelin Acetate (SM10)',
    15683 => 'Semaglutide 15mg wrongly committed onto Sermorelin Acetate (SM15)',
    15684 => 'Semaglutide 20mg wrongly committed onto Sermorelin Acetate (SM20)',
    15685 => 'Semaglutide 30mg wrongly committed onto Sermorelin Acetate (SM30)',
    15871 => 'VIP 5mg/$90 -- my own re-creation on the correct product (34), turned out to duplicate a pre-existing correct row (3940) from the July file',
    // The remaining 74: today's fresh extraction re-created almost this
    // vendor's entire catalog with blank vendor_sku, duplicating rows that
    // already existed correctly from the 2026-07-05/07-14 file. Kept the
    // lower (older) id in each pair, deactivated today's (higher id)
    // duplicate — except two where today's PDF-verified value was the one
    // kept (Epithalon 10mg, Melanotan 2 10mg; the OLDER row was the wrong
    // one there and got deactivated instead), and three left alone entirely
    // as genuinely distinct listings sharing a spec_label (Retatrutide
    // 40mg regular-vs-pen, Tirzepatide 40mg regular-vs-pen, HMG 75IU
    // 6-vial-vs-10-vial pack).
    15711 => 'BPC-157 5mg dup of 3911', 15712 => 'BPC-157 10mg dup of 3912', 15713 => 'BPC-157 20mg dup of 4679',
    15714 => 'TB-500 5mg dup of 3913', 15715 => 'TB-500 10mg dup of 3914',
    15739 => 'NAD+ 100mg dup of 3952', 15740 => 'NAD+ 500mg dup of 3953', 15741 => 'NAD+ 1000mg dup of 3954',
    15763 => 'Lemon Bottle 10ml dup of 3983',
    15886 => 'Cagrilintide 5mg dup of 3950', 15887 => 'Cagrilintide 10mg dup of 3951',
    15749 => 'DSIP 5mg dup of 3959', 15750 => 'DSIP 10mg dup of 3960',
    3928   => 'Epithalon 10mg -- OLD row was wrong ($80), today\'s PDF-verified $60 kept instead',
    15729 => 'Epithalon 50mg dup of 3930',
    15760 => '5-Amino-1MQ 10mg dup of 3973', 15761 => '5-Amino-1MQ 50mg dup of 3975',
    15884 => 'Ipamorelin 5mg dup of 3948', 15885 => 'Ipamorelin 10mg dup of 3949',
    15742 => 'GHK-Cu 50mg dup of 3955', 15743 => 'GHK-Cu 100mg dup of 3956',
    15756 => 'Glutathione 1500mg dup of 3971', 15755 => 'Glutathione 600mg dup of 3970 (after price fix above)',
    15872 => 'MOTS-c 10mg dup of 3924', 15873 => 'MOTS-c 40mg dup of 3925',
    15695 => 'Retatrutide 5mg dup of 3902', 15696 => 'Retatrutide 10mg dup of 3903', 15697 => 'Retatrutide 15mg dup of 3904',
    15698 => 'Retatrutide 20mg dup of 3905', 15699 => 'Retatrutide 30mg dup of 3906', 15702 => 'Retatrutide 50mg dup of 3908',
    15703 => 'Retatrutide 60mg dup of 3909',
    15686 => 'Tirzepatide 5mg dup of 3894', 15687 => 'Tirzepatide 10mg dup of 3895', 15688 => 'Tirzepatide 15mg dup of 3896',
    15689 => 'Tirzepatide 20mg dup of 3897', 15690 => 'Tirzepatide 30mg dup of 3898', 15693 => 'Tirzepatide 50mg dup of 3900',
    15694 => 'Tirzepatide 60mg dup of 3901',
    15730 => 'KPV 10mg dup of 3939',
    15880 => 'Selank 5mg dup of 3935', 15881 => 'Selank 10mg dup of 3936',
    15882 => 'Semax 5mg dup of 3937', 15883 => 'Semax 10mg dup of 3938',
    15865 => 'SS-31 50mg dup of 3915',
    15732 => 'Tesamorelin 5mg dup of 3941', 15733 => 'Tesamorelin 10mg dup of 3942', 15734 => 'Tesamorelin 20mg dup of 3943',
    15716 => 'BPC+TB 10mg dup of 5065', 15717 => 'BPC+TB 20mg dup of 5066',
    15718 => 'CJC-1295 without DAC 5mg dup of 3917', 15719 => 'CJC-1295 without DAC 10mg dup of 3918',
    15721 => 'CJC-1295 with DAC 5mg dup of 3919 (after price fix above)',
    15876 => 'GHRP-2 Acetate 5mg dup of 3931', 15877 => 'GHRP-2 Acetate 10mg dup of 3932',
    15747 => 'Kisspeptin-10 5mg dup of 3957', 15748 => 'Kisspeptin-10 10mg dup of 3958',
    15752 => 'HCG 10000IU dup of 3965', 15753 => 'HCG 5000IU dup of 3966',
    15737 => 'Thymosin Alpha-1 5mg dup of 3946', 15738 => 'Thymosin Alpha-1 10mg dup of 3947',
    15878 => 'GHRP-6 Acetate 5mg dup of 3933', 15879 => 'GHRP-6 Acetate 10mg dup of 3934',
    15754 => 'Thymalin 10mg dup of 3969',
    15888 => 'Oxytocin Acetate 5mg dup of 3961',
    15725 => 'Melanotan 1 10mg dup of 3926',
    3927   => 'Melanotan 2 10mg -- OLD row was wrong ($39), today\'s PDF-verified $48 kept instead (id 15726)',
    15723 => 'AOD-9604 5mg dup of 3920 (after price fix above)', 15724 => 'AOD-9604 10mg dup of 3921 (after price fix above)',
    15758 => 'Gonadorelin Acetate 10mg dup of 4729', 15757 => 'Gonadorelin Acetate 5mg dup of 4727',
    15875 => 'Cartalax 20mg dup of 4722',
    15735 => 'Tesamorelin + Ipamorelin combo 13mg dup of 5070', 15736 => 'Tesamorelin + Ipamorelin combo 20mg dup of 5071',
];
$deact = $pdo->prepare('UPDATE pc_prices SET is_active = 0 WHERE id = ?');
foreach ($toDeactivate as $id => $desc) {
    $deact->execute([$id]);
    echo "Deactivated {$id}: {$desc}\n";
}

// Step 3 — brand-new correct rows this vendor never had (moved off the
// wrong product they'd been wrongly approved onto in step 2).
// VIP (34) 5mg/$90 was ALSO attempted here but turned out to already exist
// (id 3940) — see the 15871 deactivation entry above.
$specId = findOrCreateSpec($pdo, 3, '5mg', 5, 'mg', false, false);
commitPriceRow($pdo, 18, 3, $specId, 40, 5, 10, 1, false, null, 'SM5', 'manual_edit', 0);
$specId = findOrCreateSpec($pdo, 3, '10mg', 10, 'mg', false, false);
commitPriceRow($pdo, 18, 3, $specId, 50, 10, 10, 1, false, null, 'SM10', 'manual_edit', 0);
$specId = findOrCreateSpec($pdo, 3, '15mg', 15, 'mg', false, false);
commitPriceRow($pdo, 18, 3, $specId, 60, 15, 10, 1, false, null, 'SM15', 'manual_edit', 0);
$specId = findOrCreateSpec($pdo, 3, '20mg', 20, 'mg', false, false);
commitPriceRow($pdo, 18, 3, $specId, 80, 20, 10, 1, false, null, 'SM20', 'manual_edit', 0);
$specId = findOrCreateSpec($pdo, 3, '30mg', 30, 'mg', false, false);
commitPriceRow($pdo, 18, 3, $specId, 110, 30, 10, 1, false, null, 'SM30', 'manual_edit', 0);
echo "Created 5 Semaglutide rows.\n";

echo "Done.\n";
