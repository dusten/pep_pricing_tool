<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/comparison_query.php';

// GET /comparison/export/csv — same filters as GET /comparison, Pro tier and above.
// One column per vendor (price only — $/unit and highlighting live in the XLSX export).
method('GET');
$user = requireTier('pro');

[$productIds, $vendorIds, $specIds, $classificationIds, $multiOnly, $verifiedOnly, $tierKitSize, $rawMaterialOnly] = parseComparisonFiltersFromGet();
$rows = runComparisonQuery($productIds, $vendorIds, $specIds, $classificationIds, $multiOnly, $verifiedOnly, $tierKitSize, $rawMaterialOnly);
logUserAction((int)$user['id'], 'export_comparison_csv', ['rows' => count($rows), 'tier' => $tierKitSize, 'multi_only' => $multiOnly, 'verified_only' => $verifiedOnly]);
cacheBust('admin_activity_trend'); // so the admin Activity dashboard reflects this export immediately

$vendorNames = [];
foreach ($rows as $row) foreach ($row['vendors'] as $v) $vendorNames[$v['name']] = true;
$vendorNames = array_keys($vendorNames);
sort($vendorNames);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="comparison-' . date('Y-m-d') . '.csv"');

// CSV formula injection guard (backlog #34): product/spec/vendor names come
// from vendor files — a value starting with = + - or @ becomes a live formula
// when the CSV opens in Excel. Single-quote prefix is the standard defusal
// (Excel shows the text as-is); numeric price cells pass through untouched.
$noFormula = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;

$out = fopen('php://output', 'w');
fputcsv($out, array_map($noFormula, array_merge(['Product', 'Specification'], $vendorNames, ['Avg', 'Median'])));
foreach ($rows as $row) {
    $byName = array_column($row['vendors'], null, 'name');
    $line = [$row['product'], $row['spec']];
    foreach ($vendorNames as $name) $line[] = isset($byName[$name]) ? $byName[$name]['price'] : '';
    $line[] = $row['stats']['avg'];
    $line[] = $row['stats']['median'] ?? ''; // null when <3 vendors (see comparison_query.php)
    fputcsv($out, array_map($noFormula, $line));
}
fclose($out);
exit;
