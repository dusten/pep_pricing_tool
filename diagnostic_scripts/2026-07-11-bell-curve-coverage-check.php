<?php
declare(strict_types=1);

/**
 * Grounds the "bell curve of price, gated by 75-80% vendor coverage" feature
 * discussion in real data: counts active vendors, and buckets every
 * (product_id, specification_id) pair by what % of active vendors carry it
 * at tier_kit_size=1. Used to confirm the coverage gate isn't a near-empty
 * edge case before designing the feature.
 *
 * Run on the server: sudo -u apache php 2026-07-11-bell-curve-coverage-check.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$totalVendors = (int)db()->query("SELECT COUNT(*) FROM pc_vendors WHERE is_active = 1")->fetchColumn();
echo "Active vendors: $totalVendors\n\n";

$stmt = db()->query(
    "SELECT pr.product_id, pr.specification_id, p.canonical_name, s.spec_label,
            COUNT(DISTINCT pr.vendor_id) AS vendor_count
     FROM pc_prices pr
     JOIN pc_products p ON p.id = pr.product_id
     JOIN pc_specifications s ON s.id = pr.specification_id
     JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1
     WHERE pr.is_active = 1 AND pr.tier_kit_size = 1
     GROUP BY pr.product_id, pr.specification_id
     ORDER BY vendor_count DESC"
);
$rows = $stmt->fetchAll();
echo "Total (product,spec) pairs with >=1 vendor: " . count($rows) . "\n\n";

$buckets = [];
$c75 = 0; $c80 = 0;
foreach ($rows as $r) {
    $pct = $r['vendor_count'] / $totalVendors;
    $bucket = (int)floor($pct * 100 / 10) * 10;
    $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
    if ($pct >= 0.75) $c75++;
    if ($pct >= 0.80) $c80++;
}
ksort($buckets);
foreach ($buckets as $b => $cnt) echo "{$b}-" . ($b + 9) . "% coverage: $cnt pairs\n";
echo "\npairs >=75% coverage: $c75\n";
echo "pairs >=80% coverage: $c80\n";

echo "\nTop 15 by vendor_count:\n";
foreach (array_slice($rows, 0, 15) as $r) {
    $pct = round($r['vendor_count'] / $totalVendors * 100, 1);
    echo "{$r['canonical_name']} {$r['spec_label']}: {$r['vendor_count']}/{$totalVendors} vendors ({$pct}%)\n";
}
