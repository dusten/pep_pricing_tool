<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /coa/submit — any authenticated user vouches a vendor/product pairing
// with a third-party lab COA URL. Two paths: pick an existing product the
// vendor lists (product_id), or a custom-blend free-text name
// (custom_product_name) for something not on their price list. Either way
// this always lands pending — nothing here auto-posts.
method('POST');
$user = requireAuth();

$d          = input();
$vendorId   = (int)($d['vendor_id'] ?? 0);
$productId  = isset($d['product_id']) ? (int)$d['product_id'] : null;
$customName = trim((string)($d['custom_product_name'] ?? ''));
$coaUrl     = trim((string)($d['coa_url'] ?? ''));

if (!$vendorId) jsonResponse(['error' => 'A vendor is required.'], 422);
// safeHttpUrl, not bare FILTER_VALIDATE_URL — this URL is rendered as a
// clickable link in the admin Review Queue, so a javascript: scheme here
// would be stored XSS against whoever reviews it.
$coaUrl = safeHttpUrl($coaUrl);
if (!$coaUrl) jsonResponse(['error' => 'A valid COA URL is required (http/https only).'], 422);
if (!$productId && !$customName) jsonResponse(['error' => 'Choose a product or enter a custom product name.'], 422);
if ($productId && $customName) jsonResponse(['error' => 'Choose either an existing product or a custom name, not both.'], 422);

$vendorStmt = db()->prepare('SELECT id FROM pc_vendors WHERE id = ? AND is_active = 1 LIMIT 1');
$vendorStmt->execute([$vendorId]);
if (!$vendorStmt->fetch()) jsonResponse(['error' => 'Vendor not found.'], 404);

if ($productId) {
    // Must be a product this vendor actually has an active price listed for —
    // the whole point of the dropdown path is picking from their real offerings.
    $check = db()->prepare('SELECT 1 FROM pc_prices WHERE vendor_id = ? AND product_id = ? AND is_active = 1 LIMIT 1');
    $check->execute([$vendorId, $productId]);
    if (!$check->fetch()) jsonResponse(['error' => 'That product is not currently listed for this vendor.'], 422);
}

$stmt = db()->prepare(
    'INSERT INTO pc_coa_submissions (user_id, vendor_id, product_id, custom_product_name, coa_url) VALUES (?,?,?,?,?)'
);
$stmt->execute([$user['id'], $vendorId, $productId, $customName ?: null, $coaUrl]);

jsonResponse(['message' => 'Thanks — your COA submission is pending admin review.'], 201);
