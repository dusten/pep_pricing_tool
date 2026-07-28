<?php
declare(strict_types=1);
// One-off (2026-07-27). Fix 3 of the Review Queue batch (backend/lib/vendor_file_processor.php)
// changed commitExtractionResult() to auto-commit 'new_spec' rows going forward instead of
// parking them for review — by the time that code path is reached, candidate_product_id is
// already a CONFIRMED exact product match (findExactProductMatch() succeeded), so a missing
// spec on it is just "this dose hasn't been priced yet by any vendor", not an identity risk.
// This backfills the 31 pre-existing pending/new_spec rows the same way: commits each straight
// through via findOrCreateSpec()+commitPriceRow(), same as the approve endpoint does, then
// marks it approved. reviewed_by is left NULL — no real admin reviewed these individually,
// and pc_pending_imports.reviewed_by is nullable, so leaving it NULL is the honest record
// rather than faking an admin id (logAdminAction() isn't called here either, for the same
// reason — this isn't an admin-initiated action).
//
// Usage: php 2026-07-27-backfill_new_spec_auto_commit.php [--dry-run]
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/price_import.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = db();

$stmt = $pdo->prepare("SELECT * FROM pc_pending_imports WHERE status = 'pending' AND match_type = 'new_spec' ORDER BY id");
$stmt->execute();
$rows = $stmt->fetchAll();

echo "Found " . count($rows) . " pending new_spec row(s).\n";

$committed = 0;
$failed = 0;
foreach ($rows as $row) {
    $id = (int)$row['id'];
    $raw = json_decode($row['raw_json'], true) ?: [];

    $name        = trim((string)($raw['canonical_name'] ?? ''));
    $label       = trim((string)($raw['spec_label'] ?? ''));
    $value       = (float)($raw['numeric_value'] ?? 0);
    $price       = (float)($raw['price_usd'] ?? 0);
    $unit        = (string)($raw['unit'] ?? 'mg');
    $kitCount    = (int)($raw['kit_vial_count'] ?? 10);
    $tierSize    = min(65535, max(1, (int)($raw['tier_kit_size'] ?? 1)));
    $vendorSku   = trim((string)($raw['vendor_sku'] ?? ''));
    $nonStandard = !empty($raw['non_standard_kit']);
    $isRawMaterial = !empty($raw['is_raw_material']);
    $isTablet      = !empty($raw['is_tablet']);
    // candidate_product_id on a new_spec row IS the confirmed exact-match product id —
    // that's what new_spec means (see Fix 3's context in vendor_file_processor.php).
    $productId = (int)($row['candidate_product_id'] ?? 0) ?: null;

    if (!$name || !$label || $value <= 0 || $price <= 0 || !$productId) {
        echo "  row $id: SKIPPED — invalid/incomplete data (name=\"$name\" label=\"$label\" value=$value price=$price product_id=" . ($productId ?? 'null') . ")\n";
        $failed++;
        continue;
    }

    $exists = $pdo->prepare('SELECT 1 FROM pc_products WHERE id = ?');
    $exists->execute([$productId]);
    if (!$exists->fetchColumn()) {
        echo "  row $id: SKIPPED — candidate_product_id $productId no longer exists\n";
        $failed++;
        continue;
    }

    if ($dryRun) {
        echo "  row $id: \"$name\" ($label) -> product $productId [dry-run, not applied]\n";
        continue;
    }

    $pdo->beginTransaction();
    try {
        $specId = findOrCreateSpec($pdo, $productId, $label, $value, $unit, $isRawMaterial, $isTablet);
        commitPriceRow($pdo, (int)$row['vendor_id'], $productId, $specId, $price, $value, $kitCount, $tierSize, $nonStandard, (int)$row['vendor_file_id'], $vendorSku);
        $pdo->prepare("UPDATE pc_pending_imports SET status = 'approved', reviewed_by = NULL, reviewed_at = NOW() WHERE id = ?")
            ->execute([$id]);
        $pdo->commit();
        $committed++;
        echo "  row $id: \"$name\" ($label) -> product $productId, spec $specId : committed\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        $failed++;
        echo "  row $id: FAILED - " . $e->getMessage() . "\n";
    }
}

if (!$dryRun) {
    cacheBust('comparison_data');
    cacheBust('calendar_data');
    cacheBust('admin_vendors');
    cacheBust('admin_products');
}

echo "\n=== committed $committed, failed/skipped $failed, of " . count($rows) . " row(s) ===" . ($dryRun ? " (dry-run — nothing written)\n" : "\n");
