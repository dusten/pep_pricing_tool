<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /track/link-click  body: { link_type: 'whatsapp'|'website'|'cas', vendor_id?, product_id? }
// Fired from VendorCard.vue's WhatsApp/Website links and ComparisonView.vue's
// CAS/PubChem link — best-effort, never blocks the actual outbound
// navigation. Feeds the admin activity dashboard.
method('POST');
$user = requireAuth();
rateLimit('link_click_' . $user['id'], 60, 300);

$LINK_TYPES = ['whatsapp', 'website', 'cas'];
$d         = input();
$linkType  = (string)($d['link_type'] ?? '');
$vendorId  = (int)($d['vendor_id'] ?? 0) ?: null;
$productId = (int)($d['product_id'] ?? 0) ?: null;

if (!in_array($linkType, $LINK_TYPES, true)) jsonResponse(['error' => 'Invalid link_type.'], 422);
if (!$vendorId && !$productId) jsonResponse(['error' => 'vendor_id or product_id is required.'], 422);

db()->prepare('INSERT INTO pc_outbound_link_clicks (link_type, vendor_id, product_id, user_id) VALUES (?, ?, ?, ?)')
    ->execute([$linkType, $vendorId, $productId, (int)$user['id']]);

jsonResponse(['message' => 'ok']);
