<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/lib/calendar_featured.php';

// GET /calendar/public?month=YYYY-MM — no auth. Marketing teaser for the
// price-change ledger: aggregate counts, a classification breakdown, and
// per-day product/spec names — deliberately no vendor name or dollar
// amount, that's reserved for signed-in users (backlog "public calendar",
// options 1+2+3; options 4+5 — a rotating fully-open featured product, and
// milestone callouts like all-time-lows — are backlogged, not built here).
method('GET');

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonResponse(['error' => 'month must be YYYY-MM.'], 422);

$data = cacheGet('calendar_data', "calendar_public:$month", 600, function () use ($month) {
    $stmt = db()->prepare(
        "SELECT ph.changed_at, ph.vendor_id, ph.product_id, p.canonical_name AS product,
                s.spec_label AS spec, ph.old_price_usd
         FROM pc_price_history ph
         LEFT JOIN pc_products p       ON p.id = ph.product_id
         LEFT JOIN pc_specifications s ON s.id = ph.specification_id
         WHERE DATE_FORMAT(ph.changed_at, '%Y-%m') = ?
         ORDER BY ph.changed_at DESC"
    );
    $stmt->execute([$month]);
    $rows = $stmt->fetchAll();

    $days       = [];
    $vendorIds  = [];
    $productIds = [];
    foreach ($rows as $r) {
        $day = substr($r['changed_at'], 0, 10);
        $days[$day][] = [
            'product' => $r['product'] ?? '(product removed)',
            'spec'    => $r['spec'] ?? '',
            'is_new'  => $r['old_price_usd'] === null,
        ];
        $vendorIds[(int)$r['vendor_id']] = true;
        if ($r['product_id'] !== null) $productIds[(int)$r['product_id']] = true;
    }

    // Classification breakdown — weighted by actual change events, not just
    // distinct products, so a product that changed 5 times contributes 5 to
    // its category, not 1. Inclusive/OR: a change counts toward every
    // classification its product carries (same semantics as the Comparison
    // filter), so counts across categories can sum to more than the total.
    $classificationsByProduct = [];
    if ($productIds) {
        $ids  = array_keys($productIds);
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT pcs.product_id, c.name
             FROM pc_product_classifications pcs
             JOIN pc_classifications c ON c.id = pcs.classification_id
             WHERE pcs.product_id IN ($in)"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $r) $classificationsByProduct[(int)$r['product_id']][] = $r['name'];
    }
    $classificationCounts = [];
    foreach ($rows as $r) {
        if ($r['product_id'] === null) continue;
        foreach ($classificationsByProduct[(int)$r['product_id']] ?? [] as $name) {
            $classificationCounts[$name] = ($classificationCounts[$name] ?? 0) + 1;
        }
    }
    arsort($classificationCounts);
    $byClassification = [];
    foreach ($classificationCounts as $name => $cnt) $byClassification[] = ['name' => $name, 'count' => $cnt];

    return [
        'summary' => [
            'total_changes'     => count($rows),
            'vendor_count'      => count($vendorIds),
            'by_classification' => $byClassification,
        ],
        'days' => $days,
    ];
});

// Featured product (backlog #18) and all-time-low milestones (backlog #19)
// are shared with the authenticated /calendar endpoint — see calendar_featured.php.
jsonResponse(['month' => $month, 'featured' => getCalendarFeatured($month), 'milestones' => getCalendarMilestones($month)] + $data);
