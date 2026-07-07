<?php
declare(strict_types=1);
// One-off (2026-07-06). Rule 5 in the extraction prompt only told Claude to
// normalize numeric_value/unit, never spec_label itself - so a spec column
// literally reading "10mg*10vials" kept that whole string as spec_label even
// though numeric_value was correctly parsed to just 10. Fixed in claude.php
// (rule 5 now explicit: spec_label must be just the dose, packaging suffix
// stripped - that info already lives in kit_vial_count). This corrects the
// 90 existing spec rows with a clean, safe-to-strip pattern (a trailing
// "*Nvials" suffix, optionally followed by more packaging text like
// "/10ML") - found via a dry run first (migration_scripts/spec_label_check.php,
// not kept - it was just a read-only check). Excluded 16 rows that don't
// match this exact shape (e.g. "1200mg/vial", "3000iu (10 vials)", or blend
// "10ml x 150.3mg/ml/vial" specs) since those are either already clean or a
// different, legitimate pattern not caused by this bug.
//
// Nearly every one of the 90 already has a same-product sibling spec with the
// identical numeric_value/unit but a clean label (created by a different
// vendor's correctly-extracted row on the same product) - stripping the
// suffix in place would collide with that sibling on the (product_id,
// spec_label) UNIQUE key. So this merges: moves prices from the buggy spec
// onto the existing clean one (dropping any that collide on vendor+tier,
// same logic as products/spec_merge.php), then deletes the buggy spec. Only
// renames in place when no clean sibling exists.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pdo = db();
$rows = $pdo->query("SELECT id, product_id, spec_label, numeric_value, unit FROM pc_specifications WHERE spec_label LIKE '%vial%'")->fetchAll();

$merged = 0; $renamed = 0; $skipped = 0;
foreach ($rows as $r) {
    $clean = rtrim(preg_replace('/\*\s*\d+\s*vials?(\/\S+)?\s*$/i', '', $r['spec_label']));
    if ($clean === $r['spec_label']) { $skipped++; continue; } // doesn't match the bug shape, leave alone

    $sibling = $pdo->prepare(
        'SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ? AND id != ? LIMIT 1'
    );
    $sibling->execute([$r['product_id'], $clean, $r['id']]);
    $winnerId = $sibling->fetchColumn();

    if ($winnerId) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE IGNORE pc_prices SET specification_id = ? WHERE specification_id = ?')
                ->execute([$winnerId, $r['id']]);
            $pdo->prepare('DELETE FROM pc_specifications WHERE id = ?')->execute([$r['id']]);
            $pdo->commit();
            $merged++;
            echo "merged spec {$r['id']} (\"{$r['spec_label']}\") into existing spec $winnerId (\"$clean\")\n";
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "spec {$r['id']}: merge FAILED - " . $e->getMessage() . "\n";
        }
    } else {
        $pdo->prepare('UPDATE pc_specifications SET spec_label = ? WHERE id = ?')->execute([$clean, $r['id']]);
        $renamed++;
        echo "renamed spec {$r['id']}: \"{$r['spec_label']}\" -> \"$clean\" (no existing clean sibling)\n";
    }
}

cacheBust('admin_products');
cacheBust('pricing_data');
echo "\nmerged: $merged, renamed in place: $renamed, left alone (not the bug shape): $skipped\n";
