<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/comparison_query.php';
require_once dirname(__DIR__, 2) . '/lib/xlsxwriter.class.php';

// GET /comparison/export/xlsx — same filters as GET /comparison, Pro tier and above.
// Formatting matches the original blueprint verbatim: navy/blue two-row vendor
// headers, alternating row shading, green lowest-$/unit highlight, frozen panes.
method('GET');
$user = requireTier('pro');

[$productIds, $vendorIds, $specIds, $classificationIds, $multiOnly, $verifiedOnly, $tierKitSize, $rawMaterialOnly] = parseComparisonFiltersFromGet();
$rows = runComparisonQuery($productIds, $vendorIds, $specIds, $classificationIds, $multiOnly, $verifiedOnly, $tierKitSize, $rawMaterialOnly);
logUserAction((int)$user['id'], 'export_comparison_xlsx', ['rows' => count($rows), 'tier' => $tierKitSize, 'multi_only' => $multiOnly, 'verified_only' => $verifiedOnly]);

$vendorNames = [];
foreach ($rows as $row) foreach ($row['vendors'] as $v) $vendorNames[$v['name']] = true;
$vendorNames = array_keys($vendorNames);
sort($vendorNames);

$border  = ['border' => 'left,right,top,bottom', 'border-style' => 'thin', 'border-color' => '#D0D0D0'];
$navy    = $border + ['fill' => '#2F5496', 'color' => '#FFFFFF', 'font' => 'Arial', 'font-size' => 9, 'font-style' => 'bold', 'halign' => 'center', 'valign' => 'center'];
$blue    = $border + ['fill' => '#4472C4', 'color' => '#FFFFFF', 'font' => 'Arial', 'font-size' => 8, 'font-style' => 'bold', 'halign' => 'center', 'valign' => 'center'];

$sheetName = 'Comparison';
$writer = new XLSXWriter();

// ── Column layout: Product, Specification, then a Price/$-per-unit pair per vendor, then Avg/Median ──
// Vendor/Avg/Median columns are 'GENERAL', not a fixed numeric format: the same
// column carries our custom text header rows ("HKpep", "Price ($)") AND numeric
// data rows below, and XLSXWriter's number format is fixed per-column — forcing
// it numeric wrote the header text as invalid <c t="n"><v>HKpep</v></c> XML that
// real Excel/openpyxl can't parse. GENERAL auto-detects string vs. number per cell.
$headerTypes = ['Product' => '@', 'Specification' => '@'];
$colWidths   = [32, 13];
foreach ($vendorNames as $name) {
    $headerTypes[$name . ' price']  = 'GENERAL';
    $headerTypes[$name . ' ppu']    = 'GENERAL';
    $colWidths[] = 10;
    $colWidths[] = 9;
}
$headerTypes['Avg']    = 'GENERAL';
$headerTypes['Median'] = 'GENERAL';
$colWidths[] = 13;
$colWidths[] = 14;

$writer->writeSheetHeader($sheetName, $headerTypes, [
    'widths' => $colWidths, 'freeze_rows' => 2, 'freeze_columns' => 2, 'suppress_row' => true,
]);

// ── Header row 1: Product/Specification/Avg/Median (merged down into row 2), vendor names (merged across their pair) ──
$row1Vals = ['Product', 'Specification'];
$row1Styles = [$navy, $navy];
foreach ($vendorNames as $name) { $row1Vals[] = $name; $row1Vals[] = ''; $row1Styles[] = $navy; $row1Styles[] = $navy; }
$row1Vals[] = 'Avg'; $row1Vals[] = 'Median'; $row1Styles[] = $navy; $row1Styles[] = $navy;
$row1Styles['height'] = 22;
$writer->writeSheetRow($sheetName, $row1Vals, $row1Styles);

// ── Header row 2: Price ($) / $/unit sub-headers per vendor ──
$row2Vals = ['', ''];
$row2Styles = [$navy, $navy];
foreach ($vendorNames as $name) { $row2Vals[] = 'Price ($)'; $row2Vals[] = '$/unit'; $row2Styles[] = $blue; $row2Styles[] = $blue; }
$row2Vals[] = ''; $row2Vals[] = ''; $row2Styles[] = $navy; $row2Styles[] = $navy;
$row2Styles['height'] = 16;
$writer->writeSheetRow($sheetName, $row2Vals, $row2Styles);

$lastCol = 1 + count($vendorNames) * 2 + 2;
$writer->markMergedCell($sheetName, 0, 0, 1, 0); // Product
$writer->markMergedCell($sheetName, 0, 1, 1, 1); // Specification
foreach ($vendorNames as $i => $name) {
    $startCol = 2 + $i * 2;
    $writer->markMergedCell($sheetName, 0, $startCol, 0, $startCol + 1);
}
$writer->markMergedCell($sheetName, 0, $lastCol - 1, 1, $lastCol - 1); // Avg
$writer->markMergedCell($sheetName, 0, $lastCol, 1, $lastCol);         // Median

// ── Data rows ──
foreach ($rows as $i => $row) {
    $bg    = $i % 2 === 0 ? '#FFFFFF' : '#EEF2F9';
    $bgPpu = $i % 2 === 0 ? '#E4EAF5' : '#DCE4F0'; // slightly darker than $bg, for the $/unit column
    $cell    = $border + ['fill' => $bg,    'font' => 'Arial', 'font-size' => 8];
    $cellPpu = $border + ['fill' => $bgPpu, 'font' => 'Arial', 'font-size' => 8, 'font-style' => 'italic'];
    $lowCell    = $border + ['fill' => '#C6EFCE', 'color' => '#276221', 'font' => 'Arial', 'font-size' => 8];
    $lowCellPpu = $lowCell + ['font-style' => 'italic'];

    $byName = array_column($row['vendors'], null, 'name');
    $vals   = [$row['product'], $row['spec']];
    $styles = [$cell + ['halign' => 'left'], $cell + ['halign' => 'left']];
    foreach ($vendorNames as $name) {
        $v = $byName[$name] ?? null;
        $vals[]   = $v ? $v['price'] : '';
        $vals[]   = $v ? $v['price_per_unit'] : '';
        $isLowest = $v && $v['is_lowest'];
        $styles[] = $isLowest ? $lowCell : $cell;
        $styles[] = $isLowest ? $lowCellPpu : $cellPpu;
    }
    $vals[]   = $row['stats']['avg'];
    $vals[]   = $row['stats']['median'] ?? '—'; // null when <3 vendors (see comparison_query.php)
    $styles[] = $cell;
    $styles[] = $cell;

    $writer->writeSheetRow($sheetName, $vals, $styles);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="comparison-' . date('Y-m-d') . '.xlsx"');
$writer->writeToStdOut();
exit;
