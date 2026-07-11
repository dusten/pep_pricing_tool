<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /vendors/{id}/recalc-prices
// Backfill: recompute price_per_unit for every active-or-not price row of one
// vendor with the corrected formula (price_usd / (kit_vial_count * numeric_value)).
// Existing rows written before the kit-factor fix keep a stale $/unit until they're
// re-imported or edited — this is the admin-triggered per-vendor catch-up job.
// Mirrors pricePerUnit()/spec_update.php: GREATEST(kit_vial_count,1) and the
// numeric_value>0 filter guard a zero denominator.
method('POST');
$admin    = requireAdmin();
$vendorId = (int)($PARAMS['id'] ?? 0);

$vendor = db()->prepare('SELECT display_name FROM pc_vendors WHERE id = ? LIMIT 1');
$vendor->execute([$vendorId]);
$vendor = $vendor->fetch();
if (!$vendor) jsonResponse(['error' => 'Vendor not found.'], 404);

$stmt = db()->prepare(
    'UPDATE pc_prices pr
     JOIN pc_specifications s ON s.id = pr.specification_id
     SET pr.price_per_unit = ROUND(pr.price_usd / (GREATEST(pr.kit_vial_count, 1) * s.numeric_value), 6)
     WHERE pr.vendor_id = ? AND s.numeric_value > 0'
);
$stmt->execute([$vendorId]);
$changed = $stmt->rowCount();

if ($changed > 0) cacheBust('comparison_data'); // $/unit feeds comparison ranking/highlight
logAdminAction((int)$admin['id'], 'recalc_vendor_prices', ['vendor_id' => $vendorId, 'rows_changed' => $changed]);

jsonResponse(['message' => "Recalculated \$/unit for {$vendor['display_name']} — {$changed} row(s) updated.", 'rows_changed' => $changed]);
