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

function groupExpr(string $granularity, string $dateCol): string {
    return match ($granularity) {
        'day'   => "DATE($dateCol)",
        'week'  => "DATE_FORMAT($dateCol, '%x%v')",
        'month' => "DATE_FORMAT($dateCol, '%Y-%m')",
    };
}

$INTERVALS = ['day' => '13 DAY', 'week' => '13 WEEK', 'month' => '13 MONTH'];

// Whether to include admin/test-account activity (default: excluded).
$includeInternal = !empty($_GET['include_internal']);

// Each metric: which table, an optional extra WHERE fragment (link_type
// filter for the shared clicks table, action-prefix filter for the shared
// audit log), which column to group/filter by time on (date_col, default
// created_at), an optional distinct_col for COUNT(DISTINCT ...) instead of
// COUNT(*), and user_col — the column on `table` that references
// pc_users.id, used to build the internal-activity exclusion join. No
// params needed in the where fragments — every value here is a fixed
// literal this file itself wrote, never user input.
$METRICS = [
    'signups'            => ['table' => 'pc_users',               'where' => null],
    'logins'              => ['table' => 'pc_login_history',       'where' => null],
    'searches'            => ['table' => 'pc_search_log',          'where' => null],
    'whatsapp_clicks'     => ['table' => 'pc_outbound_link_clicks', 'where' => "link_type = 'whatsapp'"],
    'website_clicks'      => ['table' => 'pc_outbound_link_clicks', 'where' => "link_type = 'website'"],
    'cas_clicks'          => ['table' => 'pc_outbound_link_clicks', 'where' => "link_type = 'cas'"],
    'downloads'           => ['table' => 'pc_user_audit_log',       'where' => "action LIKE 'export%'"],
    'daily_active_users'  => ['table' => 'pc_sessions', 'where' => null, 'date_col' => 'last_seen_at', 'distinct_col' => 'user_id'],
];

$data = cacheGet('admin_activity_trend', $includeInternal ? 'with_internal' : 'external', 60, function () use ($INTERVALS, $METRICS, $includeInternal) {
    $result = [];
    foreach (['day', 'week', 'month'] as $granularity) {
        $periods  = activityPeriods($granularity);
        $interval = $INTERVALS[$granularity];

        $countsByMetric = [];
        foreach ($METRICS as $metric => $def) {
            $table      = $def['table'];
            // Table-qualified — pc_users has its own created_at/is_admin/test_account,
            // which becomes ambiguous once the exclusion JOIN below is in play.
            $dateCol    = "{$table}." . ($def['date_col'] ?? 'created_at');
            $userCol    = $def['user_col'] ?? 'user_id';
            $selectExpr = isset($def['distinct_col']) ? "COUNT(DISTINCT {$table}.{$def['distinct_col']})" : 'COUNT(*)';
            $expr       = groupExpr($granularity, $dateCol);

            $where = "$dateCol >= DATE_SUB(NOW(), INTERVAL $interval)";
            if ($def['where']) $where .= " AND {$def['where']}";

            $join = '';
            if (!$includeInternal) {
                if ($table === 'pc_users') {
                    $where .= ' AND pc_users.is_admin = 0 AND pc_users.test_account = 0';
                } else {
                    $join = "JOIN pc_users u ON u.id = {$table}.{$userCol}";
                    $where .= ' AND u.is_admin = 0 AND u.test_account = 0';
                }
            }

            $rows = db()->query(
                "SELECT $expr AS period_key, $selectExpr AS c FROM {$table} $join
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
