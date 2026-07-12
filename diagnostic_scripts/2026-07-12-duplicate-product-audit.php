<?php
declare(strict_types=1);

/**
 * Full-catalog duplicate-product audit (read-only) — a fresh pass across
 * ALL 205 products, not just recently-imported ones, requested to evaluate
 * how many products in pc_products are likely duplicates. Cross-references
 * backlog #28 (already-known open identity questions) and surfaces new
 * candidates via two heuristics:
 *   1. Normalized-name exact match (strip punctuation/case/whitespace) —
 *      catches typographic dupes like full-width vs ASCII parentheses.
 *   2. Substring containment between canonical_names, excluding pairs
 *      already linked by a real pc_product_aliases row (that's the alias
 *      system correctly doing its job, not a duplicate).
 * Findings still need human judgment (see the accompanying wiki analysis
 * page) — this script surfaces candidates, it does not classify them.
 *
 * Run on the server: sudo -u apache php 2026-07-12-duplicate-product-audit.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$pdo = db();

$products = $pdo->query(
    "SELECT p.id, p.canonical_name,
            (SELECT COUNT(*) FROM pc_prices pr WHERE pr.product_id = p.id AND pr.is_active = 1) AS listing_count,
            (SELECT COUNT(DISTINCT pr.vendor_id) FROM pc_prices pr WHERE pr.product_id = p.id AND pr.is_active = 1) AS vendor_count,
            (SELECT GROUP_CONCAT(a.alias SEPARATOR '|') FROM pc_product_aliases a WHERE a.product_id = p.id) AS aliases,
            (SELECT GROUP_CONCAT(s.spec_label SEPARATOR '|') FROM pc_specifications s WHERE s.product_id = p.id) AS specs,
            (SELECT GROUP_CONCAT(c.name SEPARATOR '|') FROM pc_product_classifications pcx JOIN pc_classifications c ON c.id=pcx.classification_id WHERE pcx.product_id=p.id) AS tags
     FROM pc_products p ORDER BY p.canonical_name"
)->fetchAll();

echo "TOTAL PRODUCTS: " . count($products) . "\n\n";

$aliasedNames = [];
foreach ($products as $p) {
    if ($p['aliases']) {
        foreach (explode('|', $p['aliases']) as $a) {
            $aliasedNames[strtolower(preg_replace('/[^a-z0-9]/i', '', $a))] = (int)$p['id'];
        }
    }
}

function norm($s) { return strtolower(preg_replace('/[^a-z0-9]/i', '', $s)); }

$byNorm = [];
foreach ($products as $p) $byNorm[norm($p['canonical_name'])][] = $p;

echo "=== 1. NORMALIZED-NAME EXACT DUPLICATES (punctuation/case/whitespace only) ===\n";
foreach ($byNorm as $group) {
    if (count($group) > 1) {
        foreach ($group as $g) echo "[{$g['id']}] \"{$g['canonical_name']}\" ({$g['vendor_count']}v/{$g['listing_count']}l)  ";
        echo "\n";
    }
}
echo "\n=== 2. SUBSTRING CONTAINMENT PAIRS (excluding already-aliased) ===\n";
$n = count($products);
$pairs = [];
for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        $a = $products[$i]; $b = $products[$j];
        $an = norm($a['canonical_name']); $bn = norm($b['canonical_name']);
        if ($an === $bn || strlen($an) < 3 || strlen($bn) < 3) continue;
        [$shorter, $longer] = strlen($an) <= strlen($bn) ? [$an, $bn] : [$bn, $an];
        if (strpos($longer, $shorter) === false) continue;
        if ((isset($aliasedNames[$an]) && $aliasedNames[$an] === (int)$b['id'])
            || (isset($aliasedNames[$bn]) && $aliasedNames[$bn] === (int)$a['id'])) continue;
        $pairs[] = [$a, $b];
    }
}
foreach ($pairs as [$a, $b]) {
    echo "[{$a['id']}] \"{$a['canonical_name']}\" ({$a['vendor_count']}v/{$a['listing_count']}l, specs: {$a['specs']})\n";
    echo "  vs [{$b['id']}] \"{$b['canonical_name']}\" ({$b['vendor_count']}v/{$b['listing_count']}l, specs: {$b['specs']})\n\n";
}
echo "Total pairs: " . count($pairs) . "\n\n";

echo "=== 3. BACKLOG #28 ITEMS — CURRENT STATE ===\n";
$watch = ['Adipotide', 'Gonadorelin', 'Sermorelin', 'L-Carnitine', 'SU-400', 'Sustanon', 'Supertest',
          'TESTOSTERONE CYPIONATE', 'Lipo-C', 'Lipo-c', 'SUPER SHRED', 'SHR'];
foreach ($watch as $w) {
    foreach ($products as $p) {
        if (stripos($p['canonical_name'], $w) !== false) {
            echo "  [{$p['id']}] \"{$p['canonical_name']}\" ({$p['vendor_count']}v/{$p['listing_count']}l) aliases: {$p['aliases']}\n";
        }
    }
}
