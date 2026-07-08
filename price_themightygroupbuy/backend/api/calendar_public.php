<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

// GET /calendar/public?month=YYYY-MM — no auth. Marketing teaser for the
// price-change ledger: aggregate counts, a classification breakdown, and
// per-day product/spec names — deliberately no vendor name or dollar
// amount, that's reserved for signed-in users (backlog "public calendar",
// options 1+2+3; options 4+5 — a rotating fully-open featured product, and
// milestone callouts like all-time-lows — are backlogged, not built here).
method('GET');

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonResponse(['error' => 'month must be YYYY-MM.'], 422);

$data = cacheGet('pricing_data', "calendar_public:$month", 300, function () use ($month) {
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

// ── Featured product (backlog #18) ────────────────────────────────
// Admin-picked, per day: the ONE product fully revealed to anonymous
// visitors (vendor, exact price, delta) while everything else stays teased.
$featured = cacheGet('pricing_data', "calendar_featured:$month", 300, function () use ($month) {
    $stmt = db()->prepare(
        "SELECT feature_date, product_id, specification_id, note
         FROM pc_calendar_features WHERE DATE_FORMAT(feature_date, '%Y-%m') = ?"
    );
    $stmt->execute([$month]);

    $out = [];
    foreach ($stmt->fetchAll() as $f) {
        // Cheapest current active listing for the featured product — the spec
        // is pinned if the admin chose one, else the lowest across all specs.
        $specFilter = $f['specification_id'] !== null ? 'AND pr.specification_id = ?' : '';
        $params     = [(int)$f['product_id']];
        if ($f['specification_id'] !== null) $params[] = (int)$f['specification_id'];

        $priceStmt = db()->prepare(
            "SELECT v.display_name AS vendor, p.canonical_name AS product, s.spec_label AS spec,
                    pr.product_id, pr.specification_id, pr.price_usd
             FROM pc_prices pr
             JOIN pc_vendors v        ON v.id = pr.vendor_id AND v.is_active = 1
             JOIN pc_products p       ON p.id = pr.product_id
             JOIN pc_specifications s ON s.id = pr.specification_id
             WHERE pr.product_id = ? $specFilter AND pr.is_active = 1 AND pr.tier_kit_size = 1
             ORDER BY pr.price_usd ASC LIMIT 1"
        );
        $priceStmt->execute($params);
        $best = $priceStmt->fetch();
        if (!$best) continue; // featured product has no current listing — show nothing rather than a broken card

        // Delta: most recent recorded change for this exact product+spec.
        $histStmt = db()->prepare(
            "SELECT old_price_usd, new_price_usd FROM pc_price_history
             WHERE product_id = ? AND specification_id = ? ORDER BY changed_at DESC LIMIT 1"
        );
        $histStmt->execute([(int)$best['product_id'], (int)$best['specification_id']]);
        $hist = $histStmt->fetch();

        $out[substr($f['feature_date'], 0, 10)] = [
            'product'   => $best['product'],
            'spec'      => $best['spec'],
            'vendor'    => $best['vendor'],
            'price'     => (float)$best['price_usd'],
            'old_price' => ($hist && $hist['old_price_usd'] !== null) ? (float)$hist['old_price_usd'] : null,
            'note'      => $f['note'],
        ];
    }
    return $out;
});

// ── All-time-low milestones (backlog #19) ─────────────────────────
// Name-only teaser (no vendor/price): which product+spec hit a new recorded
// all-time low this month. "New" low, so a pair with only ever one price
// doesn't count — there has to be a prior, higher price.
$milestones = cacheGet('pricing_data', "calendar_milestones:$month", 300, function () use ($month) {
    // Every (product, spec) pair that changed this month.
    $pairsStmt = db()->prepare(
        "SELECT DISTINCT product_id, specification_id FROM pc_price_history
         WHERE DATE_FORMAT(changed_at, '%Y-%m') = ?"
    );
    $pairsStmt->execute([$month]);
    $pairs = $pairsStmt->fetchAll();
    if (!$pairs) return [];

    $byDay   = [];
    $histAll = db()->prepare(
        "SELECT new_price_usd, changed_at FROM pc_price_history
         WHERE product_id = ? AND specification_id = ? ORDER BY changed_at ASC"
    );
    $nameStmt = db()->prepare(
        "SELECT p.canonical_name AS product, s.spec_label AS spec
         FROM pc_products p JOIN pc_specifications s ON s.id = ?
         WHERE p.id = ?"
    );
    foreach ($pairs as $pair) {
        $histAll->execute([(int)$pair['product_id'], (int)$pair['specification_id']]);
        $rows = $histAll->fetchAll();
        $min  = null; $hadHigher = false; $lowDay = null;
        foreach ($rows as $r) {
            $price = (float)$r['new_price_usd'];
            if ($min === null || $price < $min) { $min = $price; $lowDay = substr($r['changed_at'], 0, 10); }
            elseif ($price > $min) { $hadHigher = true; }
        }
        // Milestone only if the record low was first set this month AND some
        // earlier price was higher (a genuine new low, not the only data point).
        if ($lowDay !== null && str_starts_with($lowDay, $month) && $hadHigher) {
            $nameStmt->execute([(int)$pair['specification_id'], (int)$pair['product_id']]);
            $n = $nameStmt->fetch();
            if ($n) $byDay[$lowDay][] = ['product' => $n['product'], 'spec' => $n['spec']];
        }
    }
    return $byDay;
});

jsonResponse(['month' => $month, 'featured' => $featured, 'milestones' => $milestones] + $data);
