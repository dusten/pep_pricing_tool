<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_helpers.php';

// GET /vendors/{id}/contact — customer-facing contact card, any authed user
// (not admin-only, not tier-gated — contacting a vendor is core to the
// product). Deliberately excludes the internal admin `notes` field and
// everything else vendors/show.php exposes (files, full price list).
method('GET');
requireAuth();
$id = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT id, display_name, contact_name, email, whatsapp, discord, telegram, website, shipping_note, is_verified
     FROM pc_vendors WHERE id = ? AND is_active = 1 LIMIT 1'
);
$stmt->execute([$id]);
$vendor = $stmt->fetch();
if (!$vendor) jsonResponse(['error' => 'Vendor not found.'], 404);

$vendor['id']          = (int)$vendor['id'];
$vendor['is_verified'] = (bool)$vendor['is_verified'];
$vendor = array_merge($vendor, loadVendorPhonesAndPaymentMethods(db(), $id));
// Backlog #51 — synthesizes bell-curve competitiveness + COA approval +
// price-history activity into one "should I trust/buy from this vendor" view.
$vendor['scorecard'] = cacheGet('comparison_data', "vendor_scorecard:$id", 600, fn() => getVendorScorecard(db(), $id));

jsonResponse($vendor);
