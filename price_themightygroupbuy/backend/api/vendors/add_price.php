<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';

// POST /vendors/{id}/prices — add a brand-new price line to this vendor's
// inventory directly (Inventory tab "+ Add product"), bypassing the file
// upload/Claude extraction pipeline for a one-off manual add.
// body: { product_id?, create_new?, canonical_name?, spec_label, numeric_value,
//   unit, price_usd, kit_vial_count?, tier_kit_size?, vendor_sku?,
//   non_standard_kit?, is_raw_material?, is_tablet? }
//   — product_id: existing product to add this price under.
//   — create_new: true (+ canonical_name): create a brand-new product instead
//     — same two-way choice as pending_imports.php's approve action, and for
//     the same reason: omitting product_id must not silently fall back to
//     some other guess.
method('POST');
$admin    = requireAdmin();
$vendorId = (int)($PARAMS['id'] ?? 0);

$vendor = db()->prepare('SELECT id FROM pc_vendors WHERE id = ? LIMIT 1');
$vendor->execute([$vendorId]);
if (!$vendor->fetchColumn()) jsonResponse(['error' => 'Vendor not found.'], 404);

$d = input();
$label         = trim((string)($d['spec_label'] ?? ''));
$value         = (float)($d['numeric_value'] ?? 0);
$unit          = (string)($d['unit'] ?? 'mg');
$price         = (float)($d['price_usd'] ?? 0);
$kitCount      = (int)($d['kit_vial_count'] ?? 10);
// Vendor-defined tier breakpoints (see vendor_file_processor.php) — clamp to
// the SMALLINT UNSIGNED column range only, don't assume 1/10/100.
$tierSize      = min(65535, max(1, (int)($d['tier_kit_size'] ?? 1)));
$vendorSku     = trim((string)($d['vendor_sku'] ?? ''));
$nonStandard   = !empty($d['non_standard_kit']);
$isRawMaterial = !empty($d['is_raw_material']);
$isTablet      = !empty($d['is_tablet']);

if (!$label || $value <= 0 || $price <= 0) {
    jsonResponse(['error' => 'Spec label, a positive dose value, and a positive price are required.'], 422);
}

$pdo      = db();
$forceNew = !empty($d['create_new']);
$productId = $forceNew ? null : ((int)($d['product_id'] ?? 0) ?: null);

if ($productId) {
    $exists = $pdo->prepare('SELECT 1 FROM pc_products WHERE id = ?');
    $exists->execute([$productId]);
    if (!$exists->fetchColumn()) $productId = null; // stale id (deleted/merged since the picker loaded) — fall through to name resolution
}

if (!$productId) {
    $name = trim((string)($d['canonical_name'] ?? ''));
    if (!$name) jsonResponse(['error' => 'Product name is required to create a new product.'], 422);
    $productId = findExactProductMatch($pdo, $name) ?? createProduct($pdo, $name);
}

$specId = findOrCreateSpec($pdo, $productId, $label, $value, $unit, $isRawMaterial, $isTablet);
// source_file_id is null — this row didn't come from any uploaded file.
// 'manual_edit' (not 'import') so the price-history ledger correctly
// attributes this to an admin action, matching prices/update.php's own edits.
commitPriceRow($pdo, $vendorId, $productId, $specId, $price, $value, $kitCount, $tierSize, $nonStandard, null, $vendorSku, 'manual_edit', (int)$admin['id']);

cacheBust('comparison_data');
cacheBust('calendar_data'); // commits a price + likely a pc_price_history row
cacheBust('admin_products'); // vendor_count feeds the Products-tab list
cacheBust('admin_vendors');  // price_count feeds the Vendors-tab list
logAdminAction((int)$admin['id'], 'add_vendor_price', ['vendor_id' => $vendorId, 'product_id' => $productId, 'spec_label' => $label]);

jsonResponse(['message' => 'Price added.', 'product_id' => $productId], 201);
