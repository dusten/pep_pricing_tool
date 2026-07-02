<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';

// GET  /vendors/pending-imports              — next pending row (single-card queue)
// POST /vendors/pending-imports/{id}/approve — body: { product_id?, canonical_name?, spec_label?,
//   numeric_value?, unit?, price_usd?, kit_vial_count?, tier_kit_size?, vendor_sku?, non_standard_kit? }
//   — product_id: existing product to map onto, omit to create/match by canonical_name.
//   — everything else: admin edits from the review card; omit a key to keep the extracted value.
// POST /vendors/pending-imports/{id}/reject
method('GET', 'POST');
$admin  = requireAdmin();
$id     = isset($PARAMS['id']) ? (int)$PARAMS['id'] : null;
$action = $PARAMS['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->prepare(
        "SELECT pi.*, v.display_name AS vendor_name, vf.original_filename,
                cp.canonical_name AS candidate_name
         FROM pc_pending_imports pi
         JOIN pc_vendors v ON v.id = pi.vendor_id
         JOIN pc_vendor_files vf ON vf.id = pi.vendor_file_id
         LEFT JOIN pc_products cp ON cp.id = pi.candidate_product_id
         WHERE pi.status = 'pending'
         ORDER BY pi.created_at ASC
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['done' => true]);

    $row['id']                   = (int)$row['id'];
    $row['vendor_id']            = (int)$row['vendor_id'];
    $row['candidate_product_id'] = $row['candidate_product_id'] !== null ? (int)$row['candidate_product_id'] : null;
    $row['raw_json']             = json_decode($row['raw_json'], true);
    jsonResponse($row);
}

// ── POST approve/reject ──────────────────────────────────────────
if (!$id || !in_array($action, ['approve', 'reject'], true)) {
    jsonResponse(['error' => 'Not found.'], 404);
}

$stmt = db()->prepare('SELECT * FROM pc_pending_imports WHERE id = ? AND status = ? LIMIT 1');
$stmt->execute([$id, 'pending']);
$row = $stmt->fetch();
if (!$row) jsonResponse(['error' => 'Pending import not found (or already reviewed).'], 404);

$pdo = db();

if ($action === 'reject') {
    $pdo->prepare('UPDATE pc_pending_imports SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
        ->execute(['rejected', $admin['id'], $id]);
    logAdminAction((int)$admin['id'], 'reject_pending_import', ['pending_import_id' => $id]);
    jsonResponse(['message' => 'Rejected.']);
}

// approve — body may include edited values (admin corrected something in the
// review card before approving); anything not sent falls back to what was
// originally extracted.
$raw  = json_decode($row['raw_json'], true);
$body = input();
$field = fn(string $key, $default) => array_key_exists($key, $body) ? $body[$key] : ($raw[$key] ?? $default);

$name         = trim((string)$field('canonical_name', ''));
$label        = trim((string)$field('spec_label', ''));
$value        = (float)$field('numeric_value', 0);
$price        = (float)$field('price_usd', 0);
$unit         = (string)$field('unit', 'mg');
$kitCount     = (int)$field('kit_vial_count', 10);
// See vendor_file_processor.php — tier breakpoints are vendor-defined, not
// fixed to 1/10/100; clamp to the TINYINT UNSIGNED column range only.
$tierSize     = min(255, max(1, (int)$field('tier_kit_size', 1)));
$vendorSku    = trim((string)$field('vendor_sku', '')) ?: null;
$nonStandard  = !empty($field('non_standard_kit', false));
$mappedProduct = (int)($body['product_id'] ?? 0) ?: null;

if (!$name || !$label || $value <= 0 || $price <= 0) {
    jsonResponse(['error' => 'This pending row no longer has valid data to commit.'], 422);
}

$pdo->beginTransaction();
try {
    $productId = $mappedProduct ?: (int)($row['candidate_product_id'] ?? 0) ?: null;
    // candidate_product_id was computed at file-processing time, before this
    // review session started — it can't know about a product an earlier
    // approval in the same batch just created (e.g. NAD+ 100mg approved,
    // then NAD+ 500mg's stale candidate is still null). Re-check for an
    // exact name match right now rather than trusting the stale value, or
    // this throws on pc_products.canonical_name's UNIQUE constraint.
    if (!$productId) $productId = findExactProductMatch($pdo, $name) ?? createProduct($pdo, $name);

    $specId = findOrCreateSpec($pdo, $productId, $label, $value, $unit);
    commitPriceRow($pdo, (int)$row['vendor_id'], $productId, $specId, $price, $value, $kitCount, $tierSize, $nonStandard, (int)$row['vendor_file_id'], $vendorSku);

    $pdo->prepare('UPDATE pc_pending_imports SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
        ->execute(['approved', $admin['id'], $id]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['error' => 'Approve failed.', 'message' => $e->getMessage()], 500);
}

cacheBust('pricing_data');
cacheBust('admin_products');
logAdminAction((int)$admin['id'], 'approve_pending_import', ['pending_import_id' => $id, 'product_id' => $productId]);
jsonResponse(['message' => 'Approved and committed.']);
