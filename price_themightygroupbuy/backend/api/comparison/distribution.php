<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/comparison_query.php';

// GET /comparison/distribution?product_id=&specification_id= — the
// price-distribution ("bell curve") chart data for one (product, spec) pair.
// Pro+ gated like exports: the per-vendor prices are already visible in the
// base Comparison table at every tier, same as export data being visible
// on-screen before the paywalled convenience of downloading it — the
// statistical framing here is the thing being gated, not the raw numbers.
// See Obsidian_pep_pricing_tool/wiki/analyses/2026-07-11-price-distribution-bell-curve-spec.md
method('GET');
requireTier('pro');

$productId = (int)($_GET['product_id'] ?? 0);
$specId    = (int)($_GET['specification_id'] ?? 0);
if (!$productId || !$specId) jsonResponse(['error' => 'product_id and specification_id are required.'], 422);

// Reuses the exact same computation path as the live Comparison page, so
// this chart can never silently disagree with what Avg/Median/lowest
// already show for the row.
$rows = runComparisonQuery([$productId], [], [$specId], [], false);
if (!$rows) jsonResponse(['error' => 'No active pricing data for this item.'], 404);

$row          = $rows[0];
$totalVendors = getActiveVendorCount();
$vendorCount  = count($row['vendors']);
$coveragePct  = $totalVendors > 0 ? round($vendorCount / $totalVendors * 100, 1) : 0.0;

if ($coveragePct < 75.0) {
    jsonResponse(['qualifies' => false, 'coverage_pct' => $coveragePct, 'vendor_count' => $vendorCount, 'total_active_vendors' => $totalVendors]);
}

jsonResponse([
    'qualifies'            => true,
    'product'              => $row['product'],
    'spec'                 => $row['spec'],
    'unit'                 => $row['unit'],
    'coverage_pct'         => $coveragePct,
    'vendor_count'         => $vendorCount,
    'total_active_vendors' => $totalVendors,
    'stats'                => $row['stats'],
    'vendors'              => array_map(fn($v) => [
        'vendor_id'      => $v['vendor_id'],
        'name'           => $v['name'],
        'price'          => $v['price'],
        'price_per_unit' => $v['price_per_unit'],
        'is_lowest'      => $v['is_lowest'],
    ], $row['vendors']),
]);
