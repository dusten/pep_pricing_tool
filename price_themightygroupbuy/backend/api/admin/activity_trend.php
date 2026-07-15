<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/activity-trend — last 13 days, last 13 weeks, and last 13
// months of signups/logins/searches/WhatsApp clicks, one row per period.
// Companion to /admin/activity-stats' single-window totals — this is the
// trend view (backlog #67 follow-up).
method('GET');
requireAdmin();

// Builds the canonical, always-13-long period list (oldest first) so a
// period with zero activity still shows as a row instead of vanishing —
// the DB query below only returns periods that have at least one row.
function activityPeriods(string $granularity): array {
    $periods = [];
    $now = new DateTimeImmutable('today');
    for ($i = 12; $i >= 0; $i--) {
        $d = match ($granularity) {
            'day'   => $now->modify("-$i day"),
            'week'  => $now->modify("-$i week"),
            'month' => $now->modify("-$i month"),
        };
        $periods[] = match ($granularity) {
            // key must match the SQL GROUP BY expression's output exactly
            'day'   => ['key' => $d->format('Y-m-d'), 'label' => $d->format('M j')],
            'week'  => ['key' => $d->format('oW'),     'label' => $d->format('M j') . ' (Wk ' . $d->format('W') . ')'],
            'month' => ['key' => $d->format('Y-m'),    'label' => $d->format('M Y')],
        };
    }
    return $periods;
}

function groupExpr(string $granularity): string {
    return match ($granularity) {
        'day'   => "DATE(created_at)",
        'week'  => "DATE_FORMAT(created_at, '%x%v')",
        'month' => "DATE_FORMAT(created_at, '%Y-%m')",
    };
}

$INTERVALS = ['day' => '13 DAY', 'week' => '13 WEEK', 'month' => '13 MONTH'];
$TABLES    = ['signups' => 'pc_users', 'logins' => 'pc_login_history', 'searches' => 'pc_query_log', 'whatsapp_clicks' => 'pc_whatsapp_clicks'];

$data = cacheGet('admin_activity_trend', 'all', 600, function () use ($INTERVALS, $TABLES) {
    $result = [];
    foreach (['day', 'week', 'month'] as $granularity) {
        $periods = activityPeriods($granularity);
        $expr    = groupExpr($granularity);
        $interval = $INTERVALS[$granularity];

        $countsByMetric = [];
        foreach ($TABLES as $metric => $table) {
            $rows = db()->query(
                "SELECT $expr AS period_key, COUNT(*) AS c FROM $table
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                 GROUP BY period_key"
            )->fetchAll();
            $countsByMetric[$metric] = array_column($rows, 'c', 'period_key');
        }

        $rows = [];
        foreach ($periods as $p) {
            $row = ['label' => $p['label']];
            foreach ($TABLES as $metric => $table) {
                $row[$metric] = (int)($countsByMetric[$metric][$p['key']] ?? 0);
            }
            $rows[] = $row;
        }
        $result[$granularity] = $rows;
    }
    return $result;
});

jsonResponse($data);
