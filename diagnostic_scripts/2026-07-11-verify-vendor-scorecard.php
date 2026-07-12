<?php
declare(strict_types=1);

/**
 * Verifies the vendor scorecard (backlog #51) math after building it:
 * cross-checks getVendorScorecard()'s competitiveness (cheapest_count/
 * total_listings) and COA counts against direct manual queries for a real
 * vendor (Purelypep Factory).
 *
 * Run on the server: sudo -u apache php 2026-07-11-verify-vendor-scorecard.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';
require_once 'lib/vendor_helpers.php';

$v = db()->query("SELECT id, display_name FROM pc_vendors WHERE display_name = 'Purelypep Factory' LIMIT 1")->fetch();
echo "Vendor: {$v['display_name']} (id={$v['id']})\n";
$sc = getVendorScorecard(db(), (int)$v['id']);
echo json_encode($sc, JSON_PRETTY_PRINT) . "\n";

$stmt = db()->prepare(
    "SELECT COUNT(*) total, SUM(CASE WHEN pr.price_per_unit <= m.min_ppu + 0.000001 THEN 1 ELSE 0 END) cheapest
     FROM pc_prices pr
     JOIN (SELECT pr2.product_id, pr2.specification_id, MIN(pr2.price_per_unit) min_ppu
           FROM pc_prices pr2 JOIN pc_vendors v2 ON v2.id = pr2.vendor_id AND v2.is_active = 1
           WHERE pr2.is_active = 1 AND pr2.tier_kit_size = 1 GROUP BY pr2.product_id, pr2.specification_id) m
       ON m.product_id = pr.product_id AND m.specification_id = pr.specification_id
     WHERE pr.vendor_id = ? AND pr.is_active = 1 AND pr.tier_kit_size = 1"
);
$stmt->execute([$v['id']]);
$manual = $stmt->fetch();
echo "manual cross-check: total={$manual['total']} cheapest={$manual['cheapest']}\n";

$coa = db()->query("SELECT COUNT(*) total, SUM(status='approved') approved FROM pc_coa_submissions WHERE vendor_id={$v['id']}")->fetch();
echo "manual COA check: total={$coa['total']} approved={$coa['approved']}\n";
