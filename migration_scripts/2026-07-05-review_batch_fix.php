<?php
declare(strict_types=1);
// One-off follow-up to 2026-07-05-review_batch.php: retries the 11 rows that
// run failed on (spec_label exceeded VARCHAR(50) because the extracted
// "spec" for these multi-ingredient wellness blends was the full ingredient
// breakdown, not a short dose string). Truncates to fit rather than widening
// the schema - a 50-char column is the right size for every other product on
// the platform; these blends are the only outliers.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/price_import.php';

$adminId = 4;
$pdo = db();

function resolveProductId(PDO $pdo, string $name): int {
    return findExactProductMatch($pdo, $name) ?? createProduct($pdo, $name);
}

$ids = [1786, 1787, 1789, 2045, 2118, 2119, 2121, 2189, 2197, 2199, 2200];
foreach ($ids as $id) {
    $stmt = $pdo->prepare('SELECT * FROM pc_pending_imports WHERE id = ? AND status = ? LIMIT 1');
    $stmt->execute([$id, 'pending']);
    $row = $stmt->fetch();
    if (!$row) { echo "id $id: already handled, skip\n"; continue; }

    $raw   = json_decode($row['raw_json'], true);
    $name  = trim((string)($raw['canonical_name'] ?? ''));
    $label = trim((string)($raw['spec_label'] ?? ''));
    if (mb_strlen($label) > 50) $label = mb_substr($label, 0, 47) . '...';
    $value         = (float)($raw['numeric_value'] ?? 0);
    $price         = (float)($raw['price_usd'] ?? 0);
    $unit          = (string)($raw['unit'] ?? 'mg');
    $kitCount      = (int)($raw['kit_vial_count'] ?? 10);
    $tierSize      = min(65535, max(1, (int)($raw['tier_kit_size'] ?? 1)));
    $vendorSku     = trim((string)($raw['vendor_sku'] ?? '')) ?: null;
    $nonStandard   = !empty($raw['non_standard_kit']);
    $isRawMaterial = !empty($raw['is_raw_material']);

    // HHB (2199) and SHB (2200) are cluster followers from the first pass -
    // their leaders (HHB->product 194, SHB->product 198) already succeeded
    // and created the real product; resolve by the cluster's established
    // canonical name instead of this row's own extracted name.
    $clusterName = match ($id) {
        2199 => 'Healthy Hair Skin Nails Blend', // leader id 2071's created name
        2200 => 'SHB',                            // leader id 2310's created name
        default => null,
    };

    $pdo->beginTransaction();
    try {
        $productId = $clusterName ? resolveProductId($pdo, $clusterName) : resolveProductId($pdo, $name);
        $specId = findOrCreateSpec($pdo, $productId, $label, $value, $unit, $isRawMaterial);
        commitPriceRow($pdo, (int)$row['vendor_id'], $productId, $specId, $price, $value, $kitCount, $tierSize, $nonStandard, (int)$row['vendor_file_id'], $vendorSku);
        $pdo->prepare('UPDATE pc_pending_imports SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute(['approved', $adminId, $id]);
        $pdo->commit();
        logAdminAction($adminId, 'approve_pending_import', ['pending_import_id' => $id, 'product_id' => $productId]);
        echo "id $id: OK -> product $productId ($name), spec truncated to \"$label\"\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "id $id: FAILED again - " . $e->getMessage() . "\n";
    }
}

cacheBust('pricing_data');
cacheBust('admin_products');
echo "=== fix batch done ===\n";
