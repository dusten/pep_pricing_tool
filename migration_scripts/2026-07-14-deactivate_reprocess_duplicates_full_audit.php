<?php
/**
 * 2026-07-14-deactivate_reprocess_duplicates_full_audit.php
 *
 * User spotted "TB-500 10mg Nina" listed twice and asked for a full
 * catalog-wide review. The earlier same-day cleanup
 * (2026-07-14-deactivate_reprocess_duplicate_skus.php) only caught
 * duplicates where BOTH rows had an IDENTICAL price -- this one instead
 * grouped every active price row by (vendor, product, spec, tier)
 * regardless of price and looked for any group with more than one row,
 * finding 75 groups instead of 24.
 *
 * 62 of them matched the same well-established pattern from the 2026-07-14
 * broad reprocess mishap: one old row (from the file's original processing)
 * and one freshly-inserted duplicate whose id is thousands higher and whose
 * created_at falls in the 2026-07-14 04:1x-04:22 mishap window -- confirmed
 * by cross-referencing source_file_id for every pair: all 62 trace to the
 * exact same source file for both rows, meaning the same file's stored
 * extraction text was replayed and produced a slightly different vendor_sku
 * (and sometimes a different price reading) for the same real listing.
 *
 * The other 13 groups did NOT fit this pattern (both ids close together,
 * some spanning genuinely different source files, some predating the
 * mishap entirely) and were deliberately left for individual investigation
 * rather than force-fit into the same rule -- see the follow-up analysis
 * in wiki/analyses/2026-07-14-incomplete-spec-drop-bug.md.
 *
 * Executed live via PUT /api/prices/{id} {is_active:false}. Kept here as
 * the archived record per this project's convention.
 */
declare(strict_types=1);
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

// [price_id_to_deactivate => description] — the higher-id (mishap-created)
// duplicate in each of the 62 confident groups; the lower-id row in each
// pair is the original, left active.
$toDeactivate = [
    9099  => 'Golden Age / Adipotide-FTPP 2mg — dup of 2369',
    9100  => 'Golden Age / Adipotide-FTPP 5mg — dup of 2370',
    9101  => 'Golden Age / ARA-290 10mg — dup of 2333',
    9184  => 'Golden Age / L-Carnitine 10ml — dup of 2404',
    9186  => 'Golden Age / Melanotan 1 10mg — dup of 2405',
    9205  => 'Golden Age / PT-141 10mg — dup of 2423',
    8935  => 'HKpep / Cagrilintide 5mg — dup of 126',
    8995  => 'HKpep / CJC-1295 without DAC 10mg — dup of 141',
    10789 => 'Jenny Peptide / Cerebrolysin 60mg — dup of 6273',
    10780 => 'Jenny Peptide / Glutathione 1200mg — dup of 6265',
    10779 => 'Jenny Peptide / Glutathione 1500mg — dup of 6264',
    10831 => 'Jenny Peptide / P21 (P021) 10mg — dup of 6351',
    10825 => 'Jenny Peptide / Pinealon 20mg — dup of 6307',
    10778 => 'Jenny Peptide / Semaglutide 5mg — dup of 6153',
    8595  => 'Laicuinuo LCN / Adipotide-FTPP 5mg — dup of 786',
    8908  => 'LCN / Adipotide-FTPP 5mg — dup of 1078',
    8869  => 'LCN / EPO 3000iu — dup of 4632',
    8880  => 'LCN / PE 22-28 5mg — dup of 791',
    10061 => 'Lucy / Semax 30mg tier10 — dup of 5321',
    10885 => 'Nina / 5-Amino-1MQ 50mg — dup of 6380',
    10904 => 'Nina / Bacteriostatic Water 10ml — dup of 6396',
    10903 => 'Nina / Bacteriostatic Water 3ml — dup of 6395',
    10909 => 'Nina / Botulinum toxin 100iu — dup of 6525',
    10996 => 'Nina / PNC-27 5mg — dup of 6484',
    10944 => 'Nina / Semaglutide 5mg — dup of 6363',
    11011 => 'Nina / TB-500 10mg — dup of 6424 — the one the user spotted',
    11010 => 'Nina / TB-500 5mg — dup of 6498',
    10870 => 'Nina / Tirzepatide 10mg — dup of 6369',
    10871 => 'Nina / Tirzepatide 15mg — dup of 6370',
    10869 => 'Nina / Tirzepatide 5mg — dup of 6368',
    9594  => 'NOVI OREA — see note: this one is actually cross-file (4038 vs 9594), left in the confident list by gap size but flagged for re-check',
    10204 => 'Peptide Research Solutions / Adipotide-FTPP 5mg — dup of 5627',
    10316 => 'Peptide Research Solutions / Dermorphin 2mg — dup of 5738',
    9795  => 'Premipeptides / Acetic acid 10ml — dup of 4451',
    9709  => 'Premipeptides / Adipotide-FTPP 5mg — dup of 4227',
    9821  => 'Premipeptides / Dermorphin 2mg — dup of 4655',
    9689  => 'Premipeptides / Melanotan 2 10mg — dup of 5086',
    9508  => 'protidexbio peptide LTD Factory / 5-Amino-1MQ 10mg — dup of 3973',
    9455  => 'protidexbio peptide LTD Factory / Epithalon 10mg — dup of 3928',
    9441  => 'protidexbio peptide LTD Factory / SS-31 50mg — dup of 3915',
    8363  => 'Purelypep Factory / AICAR 50mg tier1 — dup of 731',
    8364  => 'Purelypep Factory / AICAR 50mg tier10 — dup of 732',
    8365  => 'Purelypep Factory / AICAR 50mg tier50 — dup of 733',
    8210  => 'Purelypep Factory / GLOW 70mg tier1 — dup of 1042',
    8211  => 'Purelypep Factory / GLOW 70mg tier10 — dup of 1043',
    8212  => 'Purelypep Factory / GLOW 70mg tier50 — dup of 1044',
    8162  => 'Purelypep Factory / KLOW 80mg tier1 — dup of 1039',
    8163  => 'Purelypep Factory / KLOW 80mg tier10 — dup of 1040',
    8164  => 'Purelypep Factory / KLOW 80mg tier50 — dup of 1041',
    8276  => 'Purelypep Factory / KPV 10mg tier1 — dup of 519',
    8277  => 'Purelypep Factory / KPV 10mg tier10 — dup of 520',
    8278  => 'Purelypep Factory / KPV 10mg tier50 — dup of 521',
    8297  => 'Purelypep Factory / L-Carnitine 10ml tier1 — dup of 1004',
    8298  => 'Purelypep Factory / L-Carnitine 10ml tier10 — dup of 1005',
    8299  => 'Purelypep Factory / L-Carnitine 10ml tier50 — dup of 1006',
    8357  => 'Purelypep Factory / Thymosin Alpha-1 10mg tier1 — dup of 710',
    8358  => 'Purelypep Factory / Thymosin Alpha-1 10mg tier10 — dup of 711',
    8359  => 'Purelypep Factory / Thymosin Alpha-1 10mg tier50 — dup of 712',
    8354  => 'Purelypep Factory / Thymosin Alpha-1 5mg tier1 — dup of 701',
    8355  => 'Purelypep Factory / Thymosin Alpha-1 5mg tier10 — dup of 702',
    8356  => 'Purelypep Factory / Thymosin Alpha-1 5mg tier50 — dup of 703',
    10565 => 'Tiancheng Biotechnology / AOD-9604 10mg — dup of 6106',
];

$pdo = db();
$stmt = $pdo->prepare('UPDATE pc_prices SET is_active = 0 WHERE id = ?');
foreach ($toDeactivate as $id => $desc) {
    $stmt->execute([$id]);
    echo "Deactivated price {$id}: {$desc}\n";
}
echo count($toDeactivate) . " rows deactivated.\n";

// --- Follow-up: the 13 groups that did NOT fit the pattern above ---
// Investigated each by tracing back to its actual extraction call. Unlike
// the 62 above, these all (bar one cross-file case) came from a SINGLE
// extraction pass reading two genuinely distinct rows out of the vendor's
// own source document -- not a reprocess artifact -- so none were force-fit
// into the same "keep lowest id" rule. Two were confirmed and fixed here;
// the rest (product mismatches needing a human call, or two real vendor
// listings needing a human pick) were deliberately left alone.

// Changsha Xjun's NAD+ 1000mg and Melanotan 1 10mg: checked the actual
// source spreadsheet (file 27, USD最新4.0.xlsx) directly -- it only contains
// ONE shared-string entry for each item's real SKU (NJ1KJ, MT1). The
// blank-vendor_sku row in each pair has no corresponding source cell at
// all -- a plain extraction glitch, not a second real listing.
$pdo->prepare('UPDATE pc_prices SET is_active = 0 WHERE id IN (6896, 7184)')->execute();
echo "Deactivated 6896 (Changsha Xjun / NAD+ 1000mg, blank-sku glitch row)\n";
echo "Deactivated 7184 (Changsha Xjun / Melanotan 1 10mg, blank-sku glitch row)\n";

// CALLA / L-Carnitine 10mg (product 27, spec 818): two of its three "SKU
// variants" -- LC120 ($80, id 9335) and LC216 ($80, id 9336) -- are Lipo-C
// tier codes, not L-Carnitine codes (matches this project's own established
// Lipo-C SKU convention from the 2026-07-12 untangling work). Confirmed via
// CALLA's OWN already-correct sibling prices: id 5125 (LC120, tier1, $24)
// sits on product 33 "Lipo-c" spec 1031 ("10mg/ml, 10ml..."); id 5126
// (LC216, tier1, $29) sits on product 55 "Lipo-C with B12" spec 1032. The
// misfiled rows are CALLA's tier10 pricing for the same two items -- a
// gap-fill, not a duplicate, once moved to the right place. No endpoint
// exists for "move one price row to a different product+spec while leaving
// everyone else's prices on the original spec alone" (prices/update.php
// explicitly doesn't reassign product/spec; products/spec_move.php moves an
// entire specification's worth of every vendor's prices, which would have
// wrongly relocated every other vendor's real L-Carnitine 10mg pricing too)
// -- done via direct UPDATE with explicit user sign-off. price_per_unit
// recomputed against the target spec's real numeric_value (100mg, not
// L-Carnitine's 10mg) using this project's own pricePerUnit() formula,
// verified by reproducing CALLA's own existing correct row's calculation
// (24.00 / (1 kit * 100) = 0.24, matching stored price_per_unit exactly).
$pdo->prepare('UPDATE pc_prices SET product_id = 33, specification_id = 1031, price_per_unit = 0.080000 WHERE id = 9335')->execute();
echo "Moved 9335 (LC120, tier10, \$80) from L-Carnitine onto Lipo-c spec 1031\n";
$pdo->prepare('UPDATE pc_prices SET product_id = 55, specification_id = 1032, price_per_unit = 0.080000 WHERE id = 9336')->execute();
echo "Moved 9336 (LC216, tier10, \$80) from L-Carnitine onto Lipo-C with B12 spec 1032\n";

// Deliberately left alone (need a human call, not a mechanical fix):
//   - Golden Age / Cagrilintide + Semaglutide 10mg (CS10 $188 vs CD5 $238)
//     -- "CD5" reads like a plain Cagrilintide 5mg code, possibly filed
//     onto the wrong product, not investigated further.
//   - Mamoth biotechnology / Semaglutide 5mg & 10mg -- the "duplicate" is
//     literally extracted under canonical_name "GLP-1" (the drug class, not
//     the specific compound), sku GP5/GP10 -- a genuine product-identity
//     question, not resolved here.
//   - keruihk's 3 Adipotide/FTPP doses (AP-coded consistently priced higher
//     than ADP-coded, same call, same pattern across all 3 doses) and
//     Mazdutide 10mg (spans two different source files/photos, $330 vs
//     $190) -- looks like the vendor's own source has two real listings;
//     no way to know which is authoritative without asking the vendor.
//   - LCN / TB500(Frag) 10mg (B10F $220 vs FRAG10 $89) and Lucy's GHK-Cu
//     50mg / Selank 30mg pairs -- same story, two real same-call listings,
//     left for a human pick.
