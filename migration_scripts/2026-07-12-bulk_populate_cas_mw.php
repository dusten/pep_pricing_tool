<?php
declare(strict_types=1);
// Backlog #58 follow-up (2026-07-12). Bulk-populate cas_number/molecular_weight
// for 117 of 195 products, researched and web-verified (PubChem/DrugBank/
// ChemicalBook/manufacturer datasheets) via 4 parallel research passes.
//
// Deliberately left NULL (not guessed):
//  - Multi-ingredient blends/combos: no single CAS applies to a mixture
//    (GLOW, KLOW, Lipo-C variants, Sustanon/SU-400/Supertest, RM200/3R225/
//    B300/B375/B500, MAST Blend-200, HHB/LMX/GAZ/SHR blends, all "X + Y
//    combo" products, GHK-CU Blend, Relaxation PM, etc.)
//  - Real compounds with genuinely conflicting or unregistered CAS across
//    sources: MGF, SLU-PP-322, FST 344, DHB (name conflates two concepts),
//    NA Selank amidate, TBFing (unidentifiable), Adipotide/FTPP, most
//    Khavinson bioregulator tetrapeptides (Cardiogen/Cortagen/Crystagen/
//    Bronchogen/Testagen/Pancragen/Prostamax/Chonluten/Ovagen/Vesugen/
//    Cartalax — sequences are known but no CAS has ever been registered for
//    most of these, and the few candidate CAS strings found don't validate
//    format or independently corroborate), P21, CJC-1295 with DAC (CAS
//    conflicts across sources), B12/Insulin-form/HCG/Botulinum
//    toxin/ACE-031/PEG-MGF/EPO-MW (heterogeneous biologics/glycosylation —
//    no single fixed MW), N-Acetyl Epitalon Amidate.
//
// Two cross-batch corrections made during review, before ever touching the
// DB:
//  - id 29 "GHK-Cu": one research pass paired the WRONG CAS (49557-75-7,
//    which is plain GHK with no copper) with the copper-complex MW. Real
//    GHK-Cu (copper tripeptide-1) is CAS 89030-95-5, MW 403.93. Plain GHK
//    itself is correctly id 356 "GHK basic" (49557-75-7 / 340.38).
//  - ids 2 "TB-500" and 359 "TB500(Frag)" are the same real 7aa fragment
//    molecule (confirmed via this project's own prior variant-compound
//    research this session) represented as two separate catalog entries;
//    harmonized both to the same MW (889.02) rather than two slightly
//    different figures from two different research passes.
//  - ids 217/218/219 (steroid esters named without a vendor SKU prefix)
//    are literally the same compounds as ids 231/232/233 (same esters, just
//    different vendor-shorthand catalog names) — filled via direct
//    cross-reference, no separate search needed.
//  - ids 106/123/352 are all the same real compound (recombinant human
//    growth hormone, 191aa/Somatropin) under 3 different catalog names —
//    same CAS/MW applied to all three for consistency.
//  - ids 12/350 are the same real compound (IGF-1 LR3) under 2 different
//    catalog names — a CAS conflict (946870-92-4 vs 143045-27-6) was
//    resolved in favor of 946870-92-4 (5 independent sources vs 1-2 weak
//    mentions), applied to both.
//
// id 131 "EPO": CAS confirmed (113427-24-0, epoetin alfa) but no trustworthy
// MW source was found for the ~30kDa glycoprotein — CAS-only row, molecular
// weight left NULL rather than using an implausible cited figure.
//
// Large biologics (106/123/352 HGH, 141 GDF-8, 203 Dulaglutide) carry
// approximate whole-number MWs (no false 3-decimal precision) since exact
// mass varies by glycosylation/complex state for true glycoproteins, but
// HGH itself is a non-glycosylated single-chain 191aa protein so its
// ~22125 Da figure is solid, not a loose approximation.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$data = [
    1   => ['137525-51-0', 1419.552], // BPC-157 (already set, unchanged)
    2   => ['885340-08-9', 889.02],   // TB-500 (7aa fragment) — MW harmonized w/ id 359
    3   => ['910463-68-2', 4113.64],  // Semaglutide
    4   => ['53-84-9', 663.43],       // NAD+
    12  => ['946870-92-4', 9117.6],   // IGF-1 LR3
    16  => ['1415456-99-3', 4409.01], // Cagrilintide
    21  => ['62568-57-4', 848.81],    // DSIP
    22  => ['1401708-83-5', 504.7],   // Dihexa
    23  => ['307297-39-8', 390.35],   // Epithalon
    25  => ['2460055-10-9', 5358.06], // FOXO4-DRI
    26  => ['42464-96-0', 194.66],    // 5-Amino-1MQ (HCl salt)
    27  => ['541-15-1', 161.20],      // L-Carnitine
    28  => ['170851-70-4', 711.86],   // Ipamorelin
    29  => ['89030-95-5', 403.93],    // GHK-Cu — corrected CAS (see header)
    30  => ['70-18-8', 307.32],       // Glutathione
    34  => ['40077-57-4', 3325.8],    // VIP
    35  => ['767286-83-9', 401.9],    // AHK-Cu
    39  => ['1627580-64-6', 2174.64], // MOTS-c
    40  => ['2381089-83-2', 4731.33], // Retatrutide
    41  => ['2023788-19-2', 4813.53], // Tirzepatide
    42  => ['114466-38-5', 3357.9],   // Sermorelin Acetate
    44  => ['67727-97-3', 342.43],    // KPV
    45  => ['129954-34-3', 751.87],   // Selank
    46  => ['80714-61-0', 813.93],    // Semax
    47  => ['736992-21-5', 639.8],    // SS-31 (elamipretide)
    48  => ['218949-48-5', 5135.9],   // Tesamorelin
    57  => ['863288-34-0', 3367.9],   // CJC-1295 without DAC
    63  => ['189691-06-3', 1025.18],  // PT-141 (bremelanotide)
    64  => ['64-19-7', 60.05],        // Acetic acid
    67  => ['175175-23-2', 418.4],    // Pinealon
    68  => ['158861-67-7', 817.98],   // GHRP-2 Acetate
    71  => ['868844-74-0', 951.08],   // Snap-8
    72  => ['374675-21-5', 1302.45],  // Kisspeptin-10
    74  => ['62304-98-7', 3108.3],    // Thymosin Alpha-1
    75  => ['87616-84-0', 873.01],    // GHRP-6 Acetate
    76  => ['2627-69-2', 258.23],     // AICAR
    81  => ['208251-52-9', 887.0],    // Hexarelin Acetate
    83  => ['154947-66-7', 4493.34],  // LL-37
    84  => ['73-31-4', 232.28],       // Melatonin
    85  => ['6233-83-6', 1067.24],    // Oxytocin Acetate
    88  => ['1801959-12-5', 773.88],  // PE 22-28
    89  => ['159752-10-0', 624.77],   // MK-677
    90  => ['1159861-00-3', 4031.72], // PNC-27
    93  => ['75921-69-6', 1646.87],   // Melanotan 1 (afamelanotide)
    94  => ['121062-08-6', 1024.2],   // Melanotan 2
    95  => ['221231-10-3', 1815.1],   // AOD-9604
    96  => ['34973-08-5', 1182.3],    // Gonadorelin Acetate
    102 => ['2805997-46-8', 4231.62], // Survodutide
    103 => ['1208243-50-8', 1257.3],  // ARA-290
    106 => ['12629-01-5', 22125],     // HGH (Somatropin)
    107 => ['303760-60-3', 290.32],   // SLU-PP-332
    118 => ['2259884-03-0', 4563.07], // Mazdutide
    120 => ['77614-16-5', 802.88],    // Dermorphin
    123 => ['12629-01-5', 22125],     // HGH 191AA(Somatropin) — same as 106
    128 => ['112603-35-7', 7365.4],   // IGF-DES (Des(1-3)IGF-1)
    130 => ['66004-57-7', 1799.1],    // HGH Fragment 176-191
    131 => ['113427-24-0', null],     // EPO — CAS only, no trustworthy MW source
    133 => ['12279-41-3', 4541.07],   // ACTH 1-39
    138 => ['330936-69-1', 2687.2],   // Humanin
    139 => ['140194-24-7', 1371.52],  // Triptorelin Acetate
    141 => ['271597-12-7', 25000],    // GDF-8 (myostatin, homodimer, approx.)
    142 => ['214047-00-4', 802.05],   // Matrixyl (palmitoyl pentapeptide-4)
    143 => ['205640-91-1', 2897.6],   // Orexin B
    144 => ['205640-90-0', 3561.19],  // Orexin A
    153 => ['16941-32-5', 3483],      // Glucagon
    155 => ['313951-59-6', 3244.4],   // [Des-octanoyl]-Ghrelin (human)
    156 => ['1609454-11-6', 3082.6],  // PTD-DBM
    186 => ['1818415-56-3', 2986.58], // B7-33
    188 => ['56-75-7', 323.13],       // Chloramphenicol
    200 => ['10418-03-8', 328.49],    // Stanozolol (Winstrol) oil base
    201 => ['10418-03-8', 328.49],    // Stanozolol (Winstrol) suspension
    203 => ['923950-08-7', 63000],    // Dulaglutide (Fc-fusion, approx.)
    204 => ['204656-20-2', 3751.2],   // Liraglutide
    205 => ['434-07-1', 332.48],      // Anadrol-50 (Oxymetholone)
    206 => ['6157-87-5', 330.47],     // Trestolone acetate (MENT)
    207 => ['521-18-6', 290.44],      // Stanolone (DHT)
    208 => ['3381-88-2', 318.49],     // Superdrol (Methyldrostanolone)
    209 => ['965-93-5', 284.39],      // Metribolone
    211 => ['313-06-4', 396.56],      // Estradiol Cypionate
    213 => ['62-90-8', 406.56],       // NPP-100 (Nandrolone Phenylpropionate)
    214 => ['62-90-8', 406.56],       // NPP-200 — same compound
    215 => ['13103-34-9', 452.67],    // Boldenone Undecylenate (Equipoise)
    216 => ['106505-90-2', 410.59],   // Boldenone Cypionate
    217 => ['521-12-0', 360.53],      // Drostanolone Propionate — same as id 231
    218 => ['13425-31-5', 416.60],    // Drostanolone Enanthate — same as id 232
    219 => ['72-63-9', 300.44],       // Dianabol — same as id 233
    224 => ['45234-02-4', 275.30],    // Vilon
    225 => ['11061-68-0', 5807.57],   // Insulin (standard recombinant human)
    231 => ['521-12-0', 360.53],      // Masteron 100 (Drostanolone Propionate)
    232 => ['13425-31-5', 416.60],    // MAST-200 (Drostanolone Enanthate)
    233 => ['72-63-9', 300.44],       // Dianabol-50
    235 => ['10161-34-9', 312.40],    // R100 (Trenbolone Acetate)
    236 => ['1629618-98-9', 382.54],  // RY100 (Trenbolone Enanthate)
    237 => ['1629618-98-9', 382.54],  // R200 — same compound
    238 => ['23454-33-3', 410.55],    // H100 (Trenbolone Hexahydrobenzyl Carbonate / Parabolan)
    241 => ['10161-33-8', 270.37],    // BR50 (Trenbolone base)
    245 => ['303-42-4', 414.62],      // M100 (Methenolone Enanthate / Primo)
    246 => ['303-42-4', 414.62],      // M200 — same compound
    247 => ['360-70-3', 428.65],      // N200 (Nandrolone Decanoate / Deca)
    248 => ['360-70-3', 428.65],      // N300 — same compound
    249 => ['58-20-8', 412.60],       // Testosterone Cypionate
    250 => ['315-37-7', 400.59],      // TE250 (Testosterone Enanthate)
    251 => ['315-37-7', 400.59],      // TE-300 — same compound
    252 => ['5949-44-0', 456.70],     // TU-300 (Testosterone Undecanoate)
    253 => ['57-85-2', 344.49],       // TP 100mg/ml (Testosterone Propionate)
    254 => ['57-85-2', 344.49],       // TP 200mg/ml — same compound
    255 => ['58-22-0', 288.42],       // TS (Testosterone Suspension)
    256 => ['58-22-0', 288.42],       // TEST BASE 100mg (TNE) — same compound
    298 => ['745-65-3', 354.48],      // Alprostadil (PGE1)
    306 => ['52232-67-4', 4117.72],   // Teriparatide (PTH 1-34)
    307 => ['195875-84-4', 461.46],   // Livagen
    318 => ['67-97-0', 384.64],       // Vitamin D3 (Cholecalciferol)
    350 => ['946870-92-4', 9117.6],   // IGF-LR3/1 — same as id 12
    352 => ['12629-01-5', 22125],     // HGH-191 — same as id 106/123
    356 => ['49557-75-7', 340.38],    // GHK basic (plain GHK, no copper)
    359 => ['885340-08-9', 889.02],   // TB500(Frag) — same as id 2
    367 => ['2920938-90-3', 855.0],   // NA Semax amidate
];

$pdo = db();
$stmt = $pdo->prepare('UPDATE pc_products SET cas_number = ?, molecular_weight = ? WHERE id = ?');

$pdo->beginTransaction();
try {
    $updated = 0;
    foreach ($data as $id => [$cas, $mw]) {
        $stmt->execute([$cas, $mw, $id]);
        $updated += $stmt->rowCount();
    }
    $pdo->commit();
    echo "updated $updated rows (of " . count($data) . " ids attempted)\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

cacheBust('admin_products');
cacheBust('comparison_data');
echo "cache busted\n";
