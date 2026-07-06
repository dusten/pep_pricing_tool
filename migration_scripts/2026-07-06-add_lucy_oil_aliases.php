<?php
declare(strict_types=1);
// One-off (2026-07-06), follow-up to review_lucy_oil_batch.php. That script
// mapped 46 of this vendor's bare product names onto existing products by
// product_id directly - correct for this batch, but leaves no trail for
// findExactProductMatch() to use next time a DIFFERENT vendor lists the same
// product under similarly bare wording (e.g. "BLEND 300" vs the catalog's
// "B300 (BLEND 300, Trenbolone Acetate 100mg + ...)"). Adding each of this
// vendor's literal names as a real alias row closes that gap going forward.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pdo = db();

$aliases = [
    205 => 'Anadrol-50', 242 => 'BLEND 300', 243 => 'BLEND-375', 244 => 'BLEND-500',
    215 => ['BU 200 (EQUIPOISE)', 'BU-300 (EQUIPOISE)', 'BU 600 (BOLDEN-600)'],
    247 => 'DECA (NANDROLONE DECANOATE) 200mg/ml', 248 => 'DECA (NANDROLONE DECANOATE) 300mg/ml',
    202 => 'DHB (1-Testosterone cypionate)', 233 => 'Dianabol-50', 211 => 'Estradial Cypionate',
    192 => 'Immunological Enhancement Blend', 199 => 'MAST Blend-200', 232 => 'MAST-200',
    231 => 'masteron 100 (DP)', 245 => 'METHENOLONE ENANTHATE (Primo) 100mg/ml',
    246 => 'METHENOLONE ENANTHATE (Primo) 200mg/ml', 209 => 'metribolone',
    200 => 'STANOZOLOL oil base', 201 => 'STANOZOLOL suspension',
    257 => 'SU-400', 259 => 'Supertest', 258 => 'Sustanon', 249 => 'TESTOSTERONE CYPIONATE',
    251 => 'TE-300', 250 => 'TE250', 256 => 'TEST BASE 100mg (TNE)', 234 => 'Testo-600',
    253 => 'TP', 235 => 'TRA', 241 => 'TRB 50', 236 => 'TRE 100mg/ml', 237 => 'TRE 200mg/ml',
    239 => 'TRENMIX-200', 240 => 'TriTren 225', 238 => 'TrX-100', 255 => 'TS (TESTOSTERONE SUSPENSION)',
    252 => 'TU-300', 198 => 'SUPER Human Blend', 208 => 'Superdrol', 193 => 'SHRED',
    33 => ['Lipo-C[FOCUS]', 'Lipo-C[FAT BLASTER]'],
];

$exists = $pdo->prepare('SELECT id FROM pc_product_aliases WHERE alias = ? LIMIT 1');
$insert = $pdo->prepare('INSERT INTO pc_product_aliases (product_id, alias) VALUES (?,?)');
$added = 0;
foreach ($aliases as $productId => $names) {
    foreach ((array)$names as $alias) {
        $exists->execute([$alias]);
        if ($exists->fetchColumn()) { echo "skip \"$alias\" - already an alias somewhere\n"; continue; }
        $insert->execute([$productId, $alias]);
        $added++;
        echo "added alias \"$alias\" -> product $productId\n";
    }
}

cacheBust('admin_products');
echo "=== added $added aliases ===\n";
