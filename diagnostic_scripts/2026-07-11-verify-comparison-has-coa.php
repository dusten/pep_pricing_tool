<?php
declare(strict_types=1);

/**
 * Verifies runComparisonQuery()'s new `has_coa` flag (added when the ★
 * verified-COA badge was built for the Comparison page) actually resolves
 * true for a vendor+product pair with a real approved pc_coa_submissions row.
 *
 * Run on the server: sudo -u apache php 2026-07-11-verify-comparison-has-coa.php
 * (copy alongside price_themightygroupbuy/ first, or fix the require paths below).
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';
require_once 'lib/comparison_query.php';

$rows = runComparisonQuery([40], [24], [], [], false); // product 40 = Retatrutide, vendor 24 = Jenny Peptide
foreach ($rows as $row) {
    foreach ($row['vendors'] as $v) {
        echo $row['product'] . ' | vendor ' . $v['vendor_id'] . ' | has_coa=' . var_export($v['has_coa'], true) . "\n";
    }
}
