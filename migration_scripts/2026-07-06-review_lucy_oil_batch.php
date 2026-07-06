<?php
declare(strict_types=1);
// One-off (2026-07-06). Continuing the Review Queue duplicate-naming cleanup
// pattern established 2026-07-05 (see review_batch.php/review_batch_fix.php)
// on the 64 pending rows from vendor file 32 (Lucy-Oil Updated List.pdf).
// Same root cause as before: this vendor's own wording for a product doesn't
// exact/fuzzy-match the existing catalog entry's canonical_name (which often
// has vendor-specific abbreviations/parenthetical annotations baked in,
// e.g. "B300 (BLEND 300, Trenbolone Acetate 100mg + ...)" vs this vendor's
// bare "BLEND 300"), so findExactProductMatch()/findFuzzyProductCandidate()
// correctly report no match and it lands as new_product - a human call, not
// a bug. Each $forceMap pair below was confirmed by direct lookup (existing
// spec doses matching exactly for Lipo-C/SHRED variants, or the product's
// own creation history for "SHB" via pc_pending_imports raw_json), not
// guessed. $forceNewDistinct is a real identity bug: pending row 2484
// ("L-Carnitine 500mg") had a stale candidate_product_id (116, "L-Carnitine
// 600mg") from extraction-time fuzzy matching - approving normally would
// silently record a 500mg price under the 600mg product. Forced to create
// its own product instead, same class of bug as the Dulaglutide/Liraglutide/
// Semaglutide case from 2026-07-05.
// Left pending (not touched): 2487 "SUPER SHRED" (553mg/ml) - no existing
// spec confirms it's the same blend as SHR-Shred Blend at a different dose
// vs. a genuinely distinct formulation; flagging for the user's own call
// rather than guessing, per backlog #28's established practice.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/price_import.php';

$adminId = 4;
$pdo = db();

$forceMap = [
    2475 => 205, 2453 => 242, 2454 => 243, 2455 => 244, 2467 => 215, 2469 => 215,
    2468 => 215, 2458 => 247, 2459 => 248, 2476 => 202, 2474 => 233, 2480 => 211,
    2492 => 192, 2473 => 199, 2472 => 232, 2471 => 231, 2456 => 245, 2457 => 246,
    2478 => 209, 2479 => 4,   2463 => 200, 2464 => 200, 2465 => 201, 2466 => 201,
    2442 => 257, 2444 => 259, 2443 => 258, 2435 => 249, 2437 => 251, 2436 => 250,
    2441 => 256, 2445 => 234, 2439 => 253, 2446 => 235, 2452 => 241, 2447 => 236,
    2448 => 237, 2450 => 239, 2451 => 240, 2449 => 238, 2440 => 255, 2438 => 252,
    2489 => 198, 2477 => 208, 2493 => 193, 2485 => 33,  2486 => 33,
];
$forceNewDistinct = [2484]; // ignore stale candidate_product_id, always create fresh
$skip             = [2487]; // left pending for the user

function approveRow(PDO $pdo, array $row, int $adminId, ?int $forceProductId, bool $forceNewDistinct): array {
    $raw   = json_decode($row['raw_json'], true);
    $name  = trim((string)($raw['canonical_name'] ?? ''));
    $label = trim((string)($raw['spec_label'] ?? ''));
    $value = (float)($raw['numeric_value'] ?? 0);
    $price = (float)($raw['price_usd'] ?? 0);
    $unit  = (string)($raw['unit'] ?? 'mg');
    $kitCount = (int)($raw['kit_vial_count'] ?? 10);
    $tierSize = min(65535, max(1, (int)($raw['tier_kit_size'] ?? 1)));
    $vendorSku = trim((string)($raw['vendor_sku'] ?? '')) ?: null;
    $nonStandard = !empty($raw['non_standard_kit']);
    $isRawMaterial = !empty($raw['is_raw_material']);

    if (!$name || !$label || $value <= 0 || $price <= 0) {
        return ['ok' => false, 'msg' => "row {$row['id']}: invalid data, skipped"];
    }

    $pdo->beginTransaction();
    try {
        if ($forceNewDistinct) {
            $productId = createProduct($pdo, $name);
        } elseif ($forceProductId) {
            $productId = $forceProductId;
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
            ->execute(['approved', $adminId, $row['id']]);
        $pdo->commit();
        logAdminAction($adminId, 'approve_pending_import', ['pending_import_id' => (int)$row['id'], 'product_id' => $productId]);
        return ['ok' => true, 'msg' => "row {$row['id']}: \"$name\" ($label) -> product $productId"];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'msg' => "row {$row['id']}: FAILED - " . $e->getMessage()];
    }
}

$stmt = $pdo->prepare("SELECT * FROM pc_pending_imports WHERE vendor_file_id = 32 AND status = 'pending' ORDER BY id");
$stmt->execute();
$rows = $stmt->fetchAll();

$approved = 0;
foreach ($rows as $row) {
    $id = (int)$row['id'];
    if (in_array($id, $skip, true)) { echo "row $id: left pending (flagged ambiguous)\n"; continue; }
    $result = approveRow($pdo, $row, $adminId, $forceMap[$id] ?? null, in_array($id, $forceNewDistinct, true));
    echo $result['msg'] . "\n";
    if ($result['ok']) $approved++;
}

cacheBust('pricing_data');
cacheBust('admin_products');
echo "=== approved $approved of " . count($rows) . " rows ===\n";
