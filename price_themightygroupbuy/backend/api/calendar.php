<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

// GET /calendar?month=YYYY-MM — real price-change events grouped by day for
// that month, from the pc_price_history ledger (backlog #3) rather than
// pc_prices.created_at — a no-op reimport no longer shows up as a "change".
method('GET');
requireAuth();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonResponse(['error' => 'month must be YYYY-MM.'], 422);

// Same for every user, only changes when prices change — shares the
// 'pricing_data' group with comparison/index.php and comparison/filters.php.
$byDay = cacheGet('pricing_data', "calendar:$month", 300, function () use ($month) {
    // LEFT JOIN, not JOIN: pc_price_history has no FKs by design and must
    // survive a vendor/product being deleted later — a historical event for
    // a since-purged vendor still shows, just without a resolvable name.
    $stmt = db()->prepare(
        "SELECT DATE(ph.changed_at) AS day, v.display_name AS vendor, p.canonical_name AS product,
                s.spec_label AS spec, ph.old_price_usd, ph.new_price_usd, ph.source
         FROM pc_price_history ph
         LEFT JOIN pc_vendors v        ON v.id = ph.vendor_id
         LEFT JOIN pc_products p       ON p.id = ph.product_id
         LEFT JOIN pc_specifications s ON s.id = ph.specification_id
         WHERE DATE_FORMAT(ph.changed_at, '%Y-%m') = ?
         ORDER BY ph.changed_at DESC"
    );
    $stmt->execute([$month]);

    $byDay = [];
    foreach ($stmt->fetchAll() as $r) {
        $byDay[$r['day']][] = [
            'vendor'         => $r['vendor'] ?? '(vendor removed)',
            'product'        => $r['product'] ?? '(product removed)',
            'spec'           => $r['spec'] ?? '(spec removed)',
            'old_price'      => $r['old_price_usd'] !== null ? (float)$r['old_price_usd'] : null,
            'new_price'      => (float)$r['new_price_usd'],
            'is_new'         => $r['old_price_usd'] === null,
            'source'         => $r['source'],
        ];
    }
    return $byDay;
});

// A separate signal from the price-change ledger above: how much new
// inventory got reviewed and approved out of the Review Queue that day.
// pc_price_history only exists from 2026-07-03 onward, so approvals from
// before then (or from before this feature shipped) have no history row —
// this reads pc_pending_imports directly instead, which has always recorded
// reviewed_at regardless of when the ledger came online.
$approvedByDay = cacheGet('pricing_data', "calendar_approved:$month", 300, function () use ($month) {
    $stmt = db()->prepare(
        "SELECT DATE(pi.reviewed_at) AS day, v.display_name AS vendor, pi.raw_json
         FROM pc_pending_imports pi
         LEFT JOIN pc_vendors v ON v.id = pi.vendor_id
         WHERE pi.status = 'approved' AND DATE_FORMAT(pi.reviewed_at, '%Y-%m') = ?
         ORDER BY pi.reviewed_at DESC"
    );
    $stmt->execute([$month]);

    $byDay = [];
    foreach ($stmt->fetchAll() as $r) {
        $raw = json_decode($r['raw_json'], true) ?? [];
        $byDay[$r['day']][] = [
            'vendor'  => $r['vendor'] ?? '(vendor removed)',
            'product' => $raw['canonical_name'] ?? '(unknown)',
            'spec'    => $raw['spec_label'] ?? '',
            'price'   => isset($raw['price_usd']) ? (float)$raw['price_usd'] : null,
        ];
    }
    return $byDay;
});

jsonResponse(['month' => $month, 'days' => $byDay, 'approved' => $approvedByDay]);
