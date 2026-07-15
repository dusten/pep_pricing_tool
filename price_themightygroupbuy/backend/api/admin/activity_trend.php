<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/activity-trend — last 13 days, last 13 weeks, and last 13
// months of signups/logins/searches/link clicks/downloads, one row per
// period, for the admin Overview tab's per-card bar charts.
method('GET');
requireAdmin();

// Builds the canonical, always-13-long period list (oldest first) so a
// period with zero activity still shows a row instead of vanishing — the
// DB query below only returns periods that have at least one row.
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

// Each metric: which table, and an optional extra WHERE fragment (link_type
// filter for the shared clicks table, action-prefix filter for the shared
// audit log). No params needed in the fragments — every value here is a
// fixed literal this file itself wrote, never user input.
$METRICS = [
    'signups'         => ['table' => 'pc_users',               'where' => null],
    'logins'          => ['table' => 'pc_login_history',       'where' => null],
    'searches'        => ['table' => 'pc_query_log',            'where' => null],
    'whatsapp_clicks' => ['table' => 'pc_outbound_link_clicks', 'where' => "link_type = 'whatsapp'"],
    'website_clicks'  => ['table' => 'pc_outbound_link_clicks', 'where' => "link_type = 'website'"],
    'cas_clicks'      => ['table' => 'pc_outbound_link_clicks', 'where' => "link_type = 'cas'"],
    'downloads'       => ['table' => 'pc_user_audit_log',       'where' => "action LIKE 'export%'"],
];

$data = cacheGet('admin_activity_trend', 'all', 600, function () use ($INTERVALS, $METRICS) {
    $result = [];
    foreach (['day', 'week', 'month'] as $granularity) {
        $periods  = activityPeriods($granularity);
        $expr     = groupExpr($granularity);
        $interval = $INTERVALS[$granularity];

        $countsByMetric = [];
        foreach ($METRICS as $metric => $def) {
            $where = "created_at >= DATE_SUB(NOW(), INTERVAL $interval)";
            if ($def['where']) $where .= " AND {$def['where']}";
            $rows = db()->query(
                "SELECT $expr AS period_key, COUNT(*) AS c FROM {$def['table']}
                 WHERE $where GROUP BY period_key"
            )->fetchAll();
            $countsByMetric[$metric] = array_column($rows, 'c', 'period_key');
        }

        $rows = [];
        foreach ($periods as $p) {
            $row = ['label' => $p['label']];
            foreach ($METRICS as $metric => $def) {
                $row[$metric] = (int)($countsByMetric[$metric][$p['key']] ?? 0);
            }
            $rows[] = $row;
        }
        $result[$granularity] = $rows;
    }
    return $result;
});

jsonResponse($data);
