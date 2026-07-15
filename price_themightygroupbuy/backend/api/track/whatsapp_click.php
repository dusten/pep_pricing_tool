<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /track/whatsapp-click  body: { vendor_id }
// Fired from VendorCard.vue's WhatsApp link — best-effort, doesn't block
// the outbound navigation. Feeds the admin activity dashboard.
method('POST');
$user = requireAuth();
rateLimit('whatsapp_click_' . $user['id'], 30, 300);

$vendorId = (int)(input()['vendor_id'] ?? 0);
if (!$vendorId) jsonResponse(['error' => 'vendor_id is required.'], 422);

db()->prepare('INSERT INTO pc_whatsapp_clicks (vendor_id, user_id) VALUES (?, ?)')
    ->execute([$vendorId, (int)$user['id']]);

jsonResponse(['message' => 'ok']);
