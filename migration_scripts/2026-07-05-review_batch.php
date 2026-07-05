<?php
declare(strict_types=1);
// One-off: cleared the 371-item Review Queue backlog (2026-07-05). Mirrors
// backend/api/vendors/pending_imports.php's approve logic exactly, with two
// deliberate overrides found by inspecting the pending rows before running:
// - $clusters: real products listed under inconsistent casing/wording across
//   different vendor files (e.g. "HHB" / "Healthy Hair Skin Nails Blend") -
//   approve the first row normally, force the rest onto that same product id
//   instead of letting each create its own duplicate.
// - $noCandidateIds: 10 rows where the fuzzy matcher false-positived
//   Dulaglutide/Liraglutide onto Semaglutide (three genuinely different GLP-1
//   drugs sharing only a naming-convention suffix) - forced to create their
//   own distinct products instead of merging real drugs together.
// See migration_scripts/2026-07-05-review_batch_fix.php for the follow-up
// that retried 11 rows this run failed on (spec_label too long).
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/price_import.php';

$adminId = 4;
$pdo = db();

$clusters = [
  [2071, 1769, 2101, 2105, 2199, 2309, 1773], // HHB / Healthy Hair Skin Nails Blend
  [1775, 1771, 2107],                          // GAZ / immunological enhancement blend
  [1770, 2072, 2102, 2106, 1774],               // Lipo Mino Mix / LMX
  [1776, 2104, 1772, 2074, 2108],               // SHRED
  [2310, 2200],                                  // SHB / SuperHuanBlend
  [2147, 1817],                                  // Mast Blend-200
  [1807, 1808, 2137, 2138],                      // Stanozolol oil base
  [1809, 1810, 2139, 2140],                      // Stanozolol suspension
  [1778, 1792, 2110, 2123],                      // DHB
];

$noCandidateIds = [1963, 1964, 1966, 1967, 1968, 2290, 2291, 2298, 2299, 2300];

function approveRow(PDO $pdo, int $id, int $adminId, ?int $forceProductId, bool $forceNoCandidate): ?int {
    $stmt = $pdo->prepare('SELECT * FROM pc_pending_imports WHERE id = ? AND status = ? LIMIT 1');
    $stmt->execute([$id, 'pending']);
    $row = $stmt->fetch();
    if (!$row) { echo "id $id: already handled, skip\n"; return null; }

    $raw           = json_decode($row['raw_json'], true);
    $name          = trim((string)($raw['canonical_name'] ?? ''));
    $label         = trim((string)($raw['spec_label'] ?? ''));
    $value         = (float)($raw['numeric_value'] ?? 0);
    $price         = (float)($raw['price_usd'] ?? 0);
    $unit          = (string)($raw['unit'] ?? 'mg');
    $kitCount      = (int)($raw['kit_vial_count'] ?? 10);
    $tierSize      = min(65535, max(1, (int)($raw['tier_kit_size'] ?? 1)));
    $vendorSku     = trim((string)($raw['vendor_sku'] ?? '')) ?: null;
    $nonStandard   = !empty($raw['non_standard_kit']);
    $isRawMaterial = !empty($raw['is_raw_material']);

    if (!$name || !$label || $value <= 0 || $price <= 0) { echo "id $id: invalid data, skip\n"; return null; }

    $pdo->beginTransaction();
    try {
        if ($forceProductId) {
            $productId = $forceProductId;
        } elseif ($forceNoCandidate) {
            $productId = findExactProductMatch($pdo, $name) ?? createProduct($pdo, $name);
        } else {
            $productId = (int)($row['candidate_product_id'] ?? 0) ?: null;
            if ($productId) {
                $exists = $pdo->prepare('SELECT 1 FROM pc_products WHERE id = ?');
                $exists->execute([$productId]);
                if (!$exists->fetchColumn()) $productId = null;
            }
            if (!$productId) $productId = findExactProductMatch($pdo, $name) ?? createProduct($pdo, $name);
        }

        $specId = findOrCreateSpec($pdo, $productId, $label, $value, $unit, $isRawMaterial);
        commitPriceRow($pdo, (int)$row['vendor_id'], $productId, $specId, $price, $value, $kitCount, $tierSize, $nonStandard, (int)$row['vendor_file_id'], $vendorSku);

        $pdo->prepare('UPDATE pc_pending_imports SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute(['approved', $adminId, $id]);
        $pdo->commit();
        logAdminAction($adminId, 'approve_pending_import', ['pending_import_id' => $id, 'product_id' => $productId]);
        echo "id $id: OK -> product $productId ($name)\n";
        return $productId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "id $id: FAILED - " . $e->getMessage() . "\n";
        return null;
    }
}

foreach ($clusters as $cluster) {
    $leaderId  = $cluster[0];
    $productId = approveRow($pdo, $leaderId, $adminId, null, false);
    if ($productId) {
        foreach (array_slice($cluster, 1) as $followerId) {
            approveRow($pdo, $followerId, $adminId, $productId, false);
        }
    }
}

foreach ($noCandidateIds as $id) {
    approveRow($pdo, $id, $adminId, null, true);
}

$rest = $pdo->query("SELECT id FROM pc_pending_imports WHERE status='pending'")->fetchAll(PDO::FETCH_COLUMN);
echo "=== " . count($rest) . " remaining rows, standard logic ===\n";
foreach ($rest as $id) {
    approveRow($pdo, (int)$id, $adminId, null, false);
}

cacheBust('pricing_data');
cacheBust('admin_products');
echo "=== review batch done ===\n";
