<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';

// PUT /prices/{id}  body: { price_usd?, kit_vial_count?, vendor_sku?, tier_kit_size?,
//   non_standard_kit?, is_active?, spec_label?, numeric_value?, unit?, is_raw_material?, is_tablet? }
// Edits one vendor's existing price line directly (Inventory tab). spec_label
// (+ numeric_value/unit) lets an admin correct a mislabeled dose on THIS ONE
// row (e.g. a vendor's line was entered as "10mg" but is actually "15mg") —
// resolved via the same findOrCreateSpec() every import path uses, so it
// repoints onto an existing sibling spec on this product if one already
// matches, or creates a new one otherwise. This is still scoped to one row;
// see products/spec_move.php for moving a whole EXISTING spec (and everyone's
// prices under it) to a different product.
// is_active=false ("hide") excludes the row from every calculation query
// (comparison, cart, stacks, calendar) without deleting it — reimporting the
// same vendor file later won't silently un-hide it (see commitPriceRow()).
method('PUT');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT pr.*, s.numeric_value FROM pc_prices pr
     JOIN pc_specifications s ON s.id = pr.specification_id
     WHERE pr.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$price = $stmt->fetch();
if (!$price) jsonResponse(['error' => 'Price row not found.'], 404);

$d      = input();
$fields = [];
$vals   = [];

// Spec reassignment (mislabeled dose correction) — resolved BEFORE the
// price_per_unit computation below, since a pure relabel (no price/kit
// change) still needs $/unit recomputed against the corrected dose value.
// findOrCreateSpec() repoints onto an existing sibling spec on this product
// if the label already matches one (the common case — another vendor
// already sells the real dose), ignoring whatever numeric_value/unit the
// client sent for that case; only used to create a brand-new spec row does
// the client's numeric_value/unit actually take effect.
$effectiveNumericValue = (float)$price['numeric_value'];
$newSpecId    = null;
$resolvedSpec = null; // returned to the client so it can trust the server's resolution over its own typed input
if (array_key_exists('spec_label', $d) && trim((string)$d['spec_label']) !== '') {
    $label = trim((string)$d['spec_label']);
    $value = (float)($d['numeric_value'] ?? 0);
    $unit  = (string)($d['unit'] ?? 'mg');
    if ($value <= 0) jsonResponse(['error' => 'A positive dose value is required to change the spec.'], 422);

    $isRawMaterial = array_key_exists('is_raw_material', $d) ? !empty($d['is_raw_material']) : false;
    $isTablet      = array_key_exists('is_tablet', $d) ? !empty($d['is_tablet']) : false;
    $resolvedId    = findOrCreateSpec(db(), (int)$price['product_id'], $label, $value, $unit, $isRawMaterial, $isTablet);

    if ($resolvedId !== (int)$price['specification_id']) {
        $newSpecId = $resolvedId;
        $fields[]  = 'specification_id = ?';
        $vals[]    = $newSpecId;
    }
    $specRow = db()->prepare('SELECT spec_label, numeric_value, unit FROM pc_specifications WHERE id = ?');
    $specRow->execute([$resolvedId]);
    $resolvedSpec = $specRow->fetch(); // real DB values, not unvalidated client input — same whether newly repointed or unchanged
    $effectiveNumericValue = (float)$resolvedSpec['numeric_value'];
}

$newPrice = null;
if (array_key_exists('price_usd', $d) && (float)$d['price_usd'] > 0) {
    $newPrice = (float)$d['price_usd'];
    $fields[] = 'price_usd = ?';
    $vals[]   = $newPrice;
}
$newKit = null;
if (array_key_exists('kit_vial_count', $d) && (int)$d['kit_vial_count'] >= 1 && (int)$d['kit_vial_count'] <= 65535) {
    $newKit = (int)$d['kit_vial_count'];
    $fields[] = 'kit_vial_count = ?';
    $vals[]   = $newKit;
}
// price_per_unit is computed at write-time (not a generated column) and depends
// on price, kit_vial_count, AND the spec's dose value — recompute if any of
// the three changed (a pure relabel with the same price/kit still needs a new
// $/unit once the dose is corrected).
$newPricePerUnit = (float)$price['price_per_unit'];
if ($newPrice !== null || $newKit !== null || $newSpecId !== null) {
    $newPricePerUnit = pricePerUnit(
        $newPrice ?? (float)$price['price_usd'],
        $newKit   ?? (int)$price['kit_vial_count'],
        $effectiveNumericValue
    );
    $fields[] = 'price_per_unit = ?';
    $vals[]   = $newPricePerUnit;
}
if (array_key_exists('tier_kit_size', $d) && (int)$d['tier_kit_size'] >= 1 && (int)$d['tier_kit_size'] <= 65535) {
    $fields[] = 'tier_kit_size = ?';
    $vals[]   = (int)$d['tier_kit_size'];
}
if (array_key_exists('vendor_sku', $d)) {
    $fields[] = 'vendor_sku = ?';
    $vals[]   = trim((string)$d['vendor_sku']);
}
if (array_key_exists('non_standard_kit', $d)) {
    $fields[] = 'non_standard_kit = ?';
    $vals[]   = !empty($d['non_standard_kit']) ? 1 : 0;
}
if (array_key_exists('is_active', $d)) {
    $fields[] = 'is_active = ?';
    $vals[]   = !empty($d['is_active']) ? 1 : 0;
}
if (!$fields) jsonResponse(['error' => 'Nothing to update.'], 422);

$vals[] = $id;
try {
    db()->prepare('UPDATE pc_prices SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
} catch (Throwable $e) {
    // Most likely uq_price (vendor_id, product_id, specification_id, tier_kit_size, vendor_sku) —
    // either the edited tier_kit_size/vendor_sku collides with another existing
    // line, or (if spec_label was sent) this vendor already has a separate real
    // line at the target spec/tier/sku.
    jsonResponse(['error' => 'Update failed — check for a duplicate tier size/SKU/spec on this vendor.', 'message' => $e->getMessage()], 409);
}

// Only a real price/kit-count change is a history event — a re-save of the
// same value, or an edit that only touched tier_kit_size/vendor_sku, isn't.
// A pure spec relabel (newSpecId set, price/kit untouched) is deliberately
// NOT logged either — it's a data-correctness fix ("this was always 15mg,
// just mislabeled"), not a real price-change event on the calendar/ledger.
$priceActuallyChanged = ($newPrice !== null && $newPrice !== (float)$price['price_usd'])
    || ($newKit !== null && $newKit !== (int)$price['kit_vial_count']);
if ($priceActuallyChanged) {
    // Use the NEW spec id if this request also relabeled the row — the
    // history entry describes a listing keyed by (vendor, product, spec,
    // tier), and that listing now lives under the new spec id going forward.
    logPriceHistory(
        db(), (int)$price['vendor_id'], (int)$price['product_id'], $newSpecId ?? (int)$price['specification_id'], (int)$price['tier_kit_size'],
        (float)$price['price_usd'], (float)$price['price_per_unit'], (int)$price['kit_vial_count'],
        $newPrice ?? (float)$price['price_usd'], $newPricePerUnit, $newKit ?? (int)$price['kit_vial_count'],
        'manual_edit', (int)$admin['id']
    );
}

cacheBust('comparison_data');
cacheBust('calendar_data'); // a price edit that changes the price also writes a pc_price_history row
logAdminAction((int)$admin['id'], 'update_price', ['price_id' => $id, 'fields' => array_keys($d)]);
jsonResponse([
    'message'        => 'Price updated.',
    'price_per_unit' => $newPricePerUnit,
    // Only set when spec_label was part of the request — the server's own
    // resolution, which the client should trust over its own typed input.
    'spec_label'      => $resolvedSpec !== null ? $resolvedSpec['spec_label'] : null,
    'numeric_value'   => $resolvedSpec !== null ? (float)$resolvedSpec['numeric_value'] : null,
    'unit'            => $resolvedSpec !== null ? $resolvedSpec['unit'] : null,
]);
