<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/comparison_query.php';

// GET /comparison/export/csv — same filters as GET /comparison, Pro tier and above.
// One column per vendor (price only — $/unit and highlighting live in the XLSX export).
method('GET');
requireTier('pro');

[$productIds, $vendorIds, $specIds, $classificationIds, $multiOnly, $verifiedOnly, $tierKitSize, $rawMaterialOnly] = parseComparisonFiltersFromGet();
$rows = runComparisonQuery($productIds, $vendorIds, $specIds, $classificationIds, $multiOnly, $verifiedOnly, $tierKitSize, $rawMaterialOnly);

$vendorNames = [];
foreach ($rows as $row) foreach ($row['vendors'] as $v) $vendorNames[$v['name']] = true;
$vendorNames = array_keys($vendorNames);
sort($vendorNames);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="comparison-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, array_merge(['Product', 'Specification'], $vendorNames, ['Avg', 'Median']));
foreach ($rows as $row) {
    $byName = array_column($row['vendors'], null, 'name');
    $line = [$row['product'], $row['spec']];
    foreach ($vendorNames as $name) $line[] = isset($byName[$name]) ? $byName[$name]['price'] : '';
    $line[] = $row['stats']['avg'];
    $line[] = $row['stats']['median'];
    fputcsv($out, $line);
}
fclose($out);
exit;
