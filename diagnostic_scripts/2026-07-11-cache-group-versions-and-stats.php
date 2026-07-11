<?php
declare(strict_types=1);

/**
 * Prints each cache group's version counter (cv:<group> — how many times
 * cacheBust() has fired for it, see helpers.php's version-counter scheme)
 * plus overall Memcached stats (hits/misses/evictions/bytes). Companion to
 * 2026-07-11-dump-memcached-keys.php: that shows what's cached right now,
 * this shows how often each group gets invalidated. Used to find that
 * 'pricing_data' (shared by comparison results, calendar, classifications,
 * filters, and stacks) had been busted ~1939 times — the real reason cached
 * comparison results rarely survive long enough to be reused.
 *
 * Run on the server: sudo -u apache php 2026-07-11-cache-group-versions-and-stats.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$mc = mc();
foreach (['pricing_data', 'admin_vendors', 'admin_products', 'admin_users', 'admin_waitlist',
          'admin_feedback', 'admin_overview', 'admin_performance', 'app_settings', 'session'] as $g) {
    echo str_pad($g, 20) . ' v' . $mc->get("cv:$g") . "\n";
}
$s   = $mc->getStats();
$srv = array_key_first($s);
echo "\ncurr_items={$s[$srv]['curr_items']} get_hits={$s[$srv]['get_hits']} get_misses={$s[$srv]['get_misses']} evictions={$s[$srv]['evictions']} bytes={$s[$srv]['bytes']}\n";
