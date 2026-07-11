<?php
declare(strict_types=1);

/**
 * Post-split check for the pricing_data -> comparison_data/calendar_data/
 * classifications_data/stacks_data cache-group split (backlog #46). Prints
 * each new group's bust counter (should be fresh/low right after deploy,
 * unlike the old shared 'pricing_data' counter which had reached ~1939) and
 * lists any live keys under the new groups, to confirm the split actually
 * took effect and isn't silently still writing to the old group name.
 *
 * Run on the server: sudo -u apache php 2026-07-11-verify-cache-group-split.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$mc = mc();
foreach (['comparison_data', 'calendar_data', 'classifications_data', 'stacks_data', 'pricing_data'] as $g) {
    echo str_pad($g, 22) . ' v' . ($mc->get("cv:$g") ?: '(none yet)') . "\n";
}

echo "\nlive keys under the new groups:\n";
$keys = $mc->getAllKeys();
sort($keys);
foreach ($keys as $k) {
    foreach (['comparison_data', 'calendar_data', 'classifications_data', 'stacks_data'] as $g) {
        if (str_starts_with($k, "c:$g")) { echo "$k\n"; break; }
    }
}
