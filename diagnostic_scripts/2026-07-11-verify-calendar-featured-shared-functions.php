<?php
declare(strict_types=1);

/**
 * Verifies backend/lib/calendar_featured.php's getCalendarFeatured() and
 * getCalendarMilestones() — extracted out of calendar_public.php and wired
 * into the authenticated calendar.php too, so logged-in users see the
 * featured product + all-time-low milestones instead of just anonymous
 * visitors. Confirms both functions still return real data (product_id
 * included) against the live pc_calendar_features / pc_price_history rows.
 *
 * Run on the server: sudo -u apache php 2026-07-11-verify-calendar-featured-shared-functions.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';
require_once 'lib/calendar_featured.php';

$f = getCalendarFeatured('2026-07');
$m = getCalendarMilestones('2026-07');
echo "featured days: " . implode(',', array_keys($f)) . "\n";
echo "milestone days: " . implode(',', array_keys($m)) . "\n";
echo json_encode($f['2026-07-11'] ?? null) . "\n";
