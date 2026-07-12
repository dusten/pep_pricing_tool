<?php
declare(strict_types=1);

/**
 * Read-only post-fix verification for
 * migration_scripts/2026-07-12-untangle_lipoc_mic_specs.php. Re-runs the
 * same dedup-group and orphaned-spec checks used to find the mess in the
 * first place, plus a database-wide pc_prices.product_id vs
 * pc_specifications.product_id consistency check (added after that
 * migration was found to have left one row, price_id 4966, desynced —
 * caught here, fixed with a direct UPDATE, and folded back into the
 * migration script itself). See
 * Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-duplicate-product-audit.md.
 */
chdir('/home/ec2-user/price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$pdo = db();

echo "=== 1. DB-wide product_id consistency (pc_prices vs its spec) ===\n";
$r = $pdo->query("SELECT pr.id, pr.product_id AS price_pid, s.product_id AS spec_pid FROM pc_prices pr JOIN pc_specifications s ON s.id=pr.specification_id WHERE pr.is_active=1 AND pr.product_id != s.product_id")->fetchAll();
echo count($r) === 0 ? "CLEAN - no mismatches\n" : print_r($r, true);

echo "\n=== 2. Dedup groups on products 33/55 (vendor_id, normalized sku, price) ===\n";
$rows = $pdo->query("SELECT id, vendor_id, product_id, vendor_sku, price_usd FROM pc_prices WHERE product_id IN (33,55) AND is_active=1")->fetchAll();
$groups = [];
foreach ($rows as $row) {
    $sku = strtoupper(preg_replace("/[^A-Za-z0-9]/", "", (string)$row["vendor_sku"]));
    $key = $row["vendor_id"] . "|" . $sku . "|" . $row["price_usd"];
    $groups[$key][] = $row["id"];
}
$dupes = array_filter($groups, fn($g) => count($g) > 1);
echo count($dupes) === 0 ? "CLEAN - no dedup groups\n" : print_r($dupes, true);

echo "\n=== 3. Orphaned specs (zero active prices) on 33/55 ===\n";
$specs = $pdo->query("SELECT id FROM pc_specifications WHERE product_id IN (33,55)")->fetchAll(PDO::FETCH_COLUMN);
$orphans = [];
foreach ($specs as $sid) {
    $cnt = $pdo->query("SELECT COUNT(*) FROM pc_prices WHERE specification_id = $sid AND is_active=1")->fetchColumn();
    if ($cnt == 0) $orphans[] = $sid;
}
echo count($orphans) === 0 ? "CLEAN - no orphaned specs\n" : "orphans: " . implode(",", $orphans) . "\n";

echo "\n=== 4. Total product count ===\n";
echo $pdo->query("SELECT COUNT(*) FROM pc_products")->fetchColumn() . "\n";

echo "\n=== 5. Guangzhou 396/526mg rows show correct product_id ===\n";
$r = $pdo->query("SELECT pr.id, pr.product_id, pr.vendor_sku, pr.price_usd, s.product_id AS spec_pid, s.spec_label FROM pc_prices pr JOIN pc_specifications s ON s.id=pr.specification_id WHERE pr.id IN (4966,4967,5133)")->fetchAll();
print_r($r);
