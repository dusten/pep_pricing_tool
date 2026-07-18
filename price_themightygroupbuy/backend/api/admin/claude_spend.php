<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/claude-spend — org-wide Claude spend via the Admin API cost_report
// (distinct from the per-call estimates in /admin/claude-log, which are computed
// from local token counts). Requires ANTHROPIC_ADMIN_API_KEY in .env_pricetool;
// gracefully reports "not configured" instead of erroring when it's absent.
method('GET');
requireAdmin();

if (!ANTHROPIC_ADMIN_API_KEY) {
    jsonResponse(['configured' => false, 'spend_this_month_usd' => null, 'spend_today_usd' => null, 'as_of' => date('c')]);
}

/** One cost_report call for a UTC time window; returns total USD or null on failure. */
function fetchClaudeCostReportUsd(string $startingAt, string $endingAt): ?float {
    $url = 'https://api.anthropic.com/v1/organizations/cost_report?' . http_build_query([
        'starting_at' => $startingAt,
        'ending_at'   => $endingAt,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . ANTHROPIC_ADMIN_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$result) {
        error_log("[claude_spend] cost_report HTTP $code: " . substr((string)$result, 0, 300));
        return null;
    }
    $decoded = json_decode($result, true);
    $total = 0.0;
    foreach (($decoded['data'] ?? []) as $bucket) {
        foreach (($bucket['results'] ?? []) as $entry) {
            $total += (float)($entry['amount'] ?? 0);
        }
    }
    return $total;
}

// ponytail: 10 min TTL, admin dashboard not a live ticker; Anthropic docs say
// poll cost_report at most once/minute anyway. Keyed per-day so "today" naturally
// rolls over at midnight instead of serving yesterday's stale total.
$cacheVariant = 'spend_' . gmdate('Y-m-d');
$data = cacheGet('claude_spend', $cacheVariant, 600, function () {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $todayStart = $now->setTime(0, 0, 0);

    $monthSpend = fetchClaudeCostReportUsd($monthStart->format('Y-m-d\TH:i:s\Z'), $now->format('Y-m-d\TH:i:s\Z'));
    $todaySpend = fetchClaudeCostReportUsd($todayStart->format('Y-m-d\TH:i:s\Z'), $now->format('Y-m-d\TH:i:s\Z'));

    return [
        'configured' => true,
        'spend_this_month_usd' => $monthSpend !== null ? round($monthSpend, 2) : null,
        'spend_today_usd'      => $todaySpend !== null ? round($todaySpend, 2) : null,
        'as_of' => $now->format('c'),
    ];
});

jsonResponse($data);
