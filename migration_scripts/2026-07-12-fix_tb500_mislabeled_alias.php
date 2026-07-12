<?php
declare(strict_types=1);
// Backlog #57 follow-up (2026-07-12 duplicate-product audit). Product 2
// "TB-500" correctly self-aliases as the 7aa fragment (aliases 79/80/93:
// "TB-500(Frag/B5/889/17-23)", "Frag 17-23") but ALSO carried alias 144,
// "TB500(Thymosin B4 Acetate）" — which incorrectly implies product 2 is
// the full-length 43aa Thymosin Beta-4 (a genuinely different molecule,
// see Obsidian_pep_pricing_tool/wiki/entities/tb-500.md — this project's
// own research: "TB-500" in vendor listings almost always means the
// fragment; the wiki explicitly warns vendors sometimes mislabel the
// fragment as the full compound). Checked product 2's price data for a
// hidden full-length listing (full TB4 should price noticeably higher per
// mg) — no such outlier found, so this is a bad alias, not a folded-in
// real product. User confirmed: fix it (remove the bad alias only, no
// merge — the catalog has no separately-modeled full-length TB4 product
// to merge with anyway).
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$pdo = db();

$check = $pdo->prepare("SELECT id, product_id, alias FROM pc_product_aliases WHERE id = 144 AND alias = 'TB500(Thymosin B4 Acetate\xef\xbc\x89' AND product_id = 2");
$check->execute();
$row = $check->fetch();
if (!$row) {
    echo "alias 144 not found or doesn't match expected text — nothing done\n";
    exit(1);
}

$pdo->prepare('DELETE FROM pc_product_aliases WHERE id = ?')->execute([144]);
echo "deleted alias 144 (\"{$row['alias']}\") from product {$row['product_id']}\n";

cacheBust('admin_products');
cacheBust('comparison_data');
echo "done\n";
