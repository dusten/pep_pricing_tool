<?php
declare(strict_types=1);

/**
 * Verifies the price-history clock-icon feature after building it: finds a
 * real (vendor, product, spec) triple with actual pc_price_history rows,
 * confirms runComparisonQuery()'s new has_history flag is true for that
 * vendor and false for a different vendor on the same row, then dumps the
 * raw history rows to cross-check against what the API/popover would show.
 *
 * Run on the server: sudo -u apache php 2026-07-11-verify-price-history-icon.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';
require_once 'lib/comparison_query.php';

$h = db()->query(
    'SELECT vendor_id, product_id, specification_id, COUNT(*) c
     FROM pc_price_history GROUP BY vendor_id, product_id, specification_id
     ORDER BY c DESC LIMIT 1'
)->fetch();
echo "Real history triple: vendor={$h['vendor_id']} product={$h['product_id']} spec={$h['specification_id']} ({$h['c']} rows)\n";

$rows = runComparisonQuery([(int)$h['product_id']], [], [(int)$h['specification_id']], [], false);
$row  = $rows[0] ?? null;
if (!$row) { echo "no active row for this pair\n"; exit; }

foreach ($row['vendors'] as $v) {
    if ($v['vendor_id'] == $h['vendor_id']) {
        echo "has_history (expected vendor): " . var_export($v['has_history'], true) . "\n";
    }
}
foreach ($row['vendors'] as $v) {
    if ($v['vendor_id'] != $h['vendor_id']) {
        echo "has_history (different vendor {$v['vendor_id']}): " . var_export($v['has_history'], true) . "\n";
        break;
    }
}

echo "\nRaw history rows for this triple:\n";
$stmt = db()->prepare(
    'SELECT old_price_usd, new_price_usd, source, changed_at FROM pc_price_history
     WHERE vendor_id = ? AND product_id = ? AND specification_id = ? ORDER BY changed_at DESC LIMIT 20'
);
$stmt->execute([$h['vendor_id'], $h['product_id'], $h['specification_id']]);
foreach ($stmt->fetchAll() as $r) {
    echo "{$r['changed_at']} | {$r['old_price_usd']} -> {$r['new_price_usd']} | {$r['source']}\n";
}
