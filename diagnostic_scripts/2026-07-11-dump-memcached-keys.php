<?php
declare(strict_types=1);

/**
 * Dumps every live key currently in Memcached (via Memcached::getAllKeys()),
 * for auditing what the app actually caches at a point in time — used to
 * investigate why the System-tab "Cached objects" tile showed a low count
 * (~30) and confirm it's a natural consequence of short admin-list TTLs +
 * a shared, frequently-busted 'pricing_data' group, not a bug.
 *
 * Run on the server: sudo -u apache php 2026-07-11-dump-memcached-keys.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$mc = mc();
if (!$mc) { echo "no memcached\n"; exit; }

$keys = $mc->getAllKeys();
if ($keys === false) {
    echo "getAllKeys unsupported, falling back to stats\n";
    print_r($mc->getStats());
    exit;
}
sort($keys);
foreach ($keys as $k) echo $k . "\n";
echo "TOTAL: " . count($keys) . "\n";
