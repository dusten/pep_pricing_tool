<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /vendors/ranked — Dashboard "Active vendors" tile pop-up. Cross-vendor
// composite score, NOT getVendorScorecard() (that's one-vendor, used by the
// contact card) — this is a single aggregate query over every active vendor.
// score = round((cheapest_pct + coverage_pct) / 2, 1) + (10 if PayPal/PYUSD)
method('GET');
requireAuth();

$vendors = cacheGet('comparison_data', 'vendor_ranking', 600, function () {
    $pdo = db();

    // cheapest_pct: same "min $/unit per (product,spec) across active
    // vendors' tier-1 rows" logic as getVendorScorecard(), run once for
    // every vendor via GROUP BY instead of once per vendor.
    $cheapest = $pdo->query(
        "SELECT pr.vendor_id,
                COUNT(*) AS total_listings,
                SUM(CASE WHEN pr.price_per_unit <= m.min_ppu + 0.000001 THEN 1 ELSE 0 END) AS cheapest_count
         FROM pc_prices pr
         JOIN (
             SELECT pr2.product_id, pr2.specification_id, MIN(pr2.price_per_unit) AS min_ppu
             FROM pc_prices pr2
             JOIN pc_vendors v2 ON v2.id = pr2.vendor_id AND v2.is_active = 1 AND v2.is_hidden = 0
             WHERE pr2.is_active = 1 AND pr2.tier_kit_size = 1
             GROUP BY pr2.product_id, pr2.specification_id
         ) m ON m.product_id = pr.product_id AND m.specification_id = pr.specification_id
         JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1 AND v.is_hidden = 0
         WHERE pr.is_active = 1 AND pr.tier_kit_size = 1
         GROUP BY pr.vendor_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $cheapest = array_column($cheapest, null, 'vendor_id');

    // coverage_pct denominator: distinct (product,spec) pairs across the
    // whole active catalog, any vendor.
    $catalogTotal = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT DISTINCT pr.product_id, pr.specification_id
            FROM pc_prices pr
            JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1 AND v.is_hidden = 0
            WHERE pr.is_active = 1
         ) t"
    )->fetchColumn();

    $coverage = $pdo->query(
        "SELECT pr.vendor_id, COUNT(DISTINCT pr.product_id, pr.specification_id) AS covered
         FROM pc_prices pr
         JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1 AND v.is_hidden = 0
         WHERE pr.is_active = 1
         GROUP BY pr.vendor_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $coverage = array_column($coverage, null, 'vendor_id');

    $paypalVendorIds = $pdo->query(
        "SELECT DISTINCT vendor_id FROM pc_vendor_payment_methods WHERE method IN ('paypal', 'pyusd')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $paypalVendorIds = array_flip($paypalVendorIds);

    $vendorRows = $pdo->query(
        'SELECT id, display_name, is_verified FROM pc_vendors WHERE is_active = 1 AND is_hidden = 0'
    )->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($vendorRows as $v) {
        $id = $v['id'];
        $c  = $cheapest[$id] ?? null;
        $ov = $coverage[$id] ?? null;

        $cheapestPct = $c && (int)$c['total_listings'] > 0
            ? round((int)$c['cheapest_count'] / (int)$c['total_listings'] * 100, 1) : 0.0;
        $coveragePct = $catalogTotal > 0 && $ov
            ? round((int)$ov['covered'] / $catalogTotal * 100, 1) : 0.0;
        $hasPaypal = isset($paypalVendorIds[$id]);

        $result[] = [
            'id'           => (int)$id,
            'display_name' => $v['display_name'],
            'is_verified'  => (bool)$v['is_verified'],
            'score'        => round(($cheapestPct + $coveragePct) / 2, 1) + ($hasPaypal ? 10 : 0),
            'cheapest_pct' => $cheapestPct,
            'coverage_pct' => $coveragePct,
            'has_paypal'   => $hasPaypal,
        ];
    }

    usort($result, fn($a, $b) => $b['score'] <=> $a['score']);
    return $result;
});

jsonResponse(['vendors' => $vendors]);
