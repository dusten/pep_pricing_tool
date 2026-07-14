<?php
/**
 * 2026-07-14-deactivate_reprocess_duplicate_skus.php
 *
 * User spotted "Purelypep Factory" listed twice for BPC-157 5mg on the
 * Comparison page. Root cause: the 2026-07-14 broad "reprocess every file"
 * mishap (see wiki/analyses/2026-07-14-incomplete-spec-drop-bug.md) hit a
 * failure mode the earlier cleanup pass never checked for — when a
 * product+spec already existed (exact match), Claude's re-extraction of an
 * already-committed file sometimes wrote a different vendor_sku for the
 * exact same real listing (e.g. "BC5" vs "BP5", both BPC-157 5mg). Since
 * migration 030 made vendor_sku part of the price uniqueness key
 * (intentionally, so two genuinely different SKUs can coexist), this
 * inserted as a second, redundant active price row instead of updating the
 * existing one. Never touched pc_pending_imports, so the earlier
 * duplicate-cleanup pass (which only checked the pending queue) missed it
 * entirely.
 *
 * Found by grouping active pc_prices by (vendor, product, spec, tier,
 * price) and looking for >1 distinct vendor_sku — 24 groups found; 2 were
 * unrelated to this mishap (different timing / a separate likely
 * product-mismatch case) and deliberately left alone. The other 22 rows
 * (21 groups, one 3-way) all had one row whose auto-increment id was far
 * lower than the other(s) — created_at had been refreshed to the mishap
 * timestamp by the ON DUPLICATE KEY UPDATE branch on every row involved,
 * so created_at couldn't distinguish "original" from "reprocess artifact",
 * but the id (never changed by an UPDATE) could: the lowest id per group is
 * the original row, first inserted long before 2026-07-14.
 *
 * Executed live via PUT /api/prices/{id} {is_active:false} — same effect
 * as this script, just done through the real endpoint rather than raw SQL.
 * Kept here as the archived record per this project's convention. Ran once,
 * not idempotent (re-running would try to deactivate already-inactive rows,
 * harmless no-op).
 */
declare(strict_types=1);
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

// [price_id_to_deactivate => description] — the higher-id duplicate in each
// group; the lower-id row in each pair/triple is left active untouched.
$toDeactivate = [
    9245  => 'Golden Age / Acetic acid 10ml — dup of 3785 (AA10 vs BAC10(BA10))',
    9244  => 'Golden Age / Acetic acid 3ml — dup of 3784 (AA3 vs BAC3(BA3))',
    7793  => 'Guangzhou Guangjin / Bacteriostatic Water 10ml — dup of 7790 (BA10 vs WA10)',
    7791  => 'Guangzhou Guangjin / Bacteriostatic Water 3ml — dup of 7789 (BA3 vs WA3)',
    10798 => 'Jenny Peptide / Bacteriostatic Water 10ml — dup of 6282 (WA10 vs BA10)',
    8861  => 'LCN / Bacteriostatic Water 10ml — dup of 3356 (WA10 vs BA10)',
    8860  => 'LCN / Bacteriostatic Water 3ml — dup of 3355 (WA3 vs BA3)',
    8120  => 'Purelypep Factory / BPC-157 10mg tier1 — dup of 65 (BP10 vs BC10)',
    8121  => 'Purelypep Factory / BPC-157 10mg tier10 — dup of 66 (BP10 vs BC10)',
    8122  => 'Purelypep Factory / BPC-157 10mg tier50 — dup of 67 (BP10 vs BC10)',
    8117  => 'Purelypep Factory / BPC-157 5mg tier1 — dup of 62 (BP5 vs BC5) — the one the user actually spotted',
    8118  => 'Purelypep Factory / BPC-157 5mg tier10 — dup of 63 (BP5 vs BC5)',
    8119  => 'Purelypep Factory / BPC-157 5mg tier50 — dup of 64 (BP5 vs BC5)',
    8309  => 'Purelypep Factory / Mazdutide 10mg tier1 — dup of 1007 (MDT10 vs MZ10)',
    8310  => 'Purelypep Factory / Mazdutide 10mg tier10 — dup of 1010 (MDT10 vs MZ10)',
    8311  => 'Purelypep Factory / Mazdutide 10mg tier50 — dup of 1011 (MDT10 vs MZ10)',
    10604 => 'Tiancheng Biotechnology / Bacteriostatic Water 10ml — dup of 6122 (WA10 vs BA10)',
    10603 => 'Tiancheng Biotechnology / Bacteriostatic Water 3ml — dup of 6121 (WA3 vs BA3)',
    9974  => 'Tidetron Peptide / Bacteriostatic Water 10ml — dup of 9972 (BA10 vs WA10)',
    9973  => 'Tidetron Peptide / Bacteriostatic Water 3ml — dup of 9971 (BA3 vs WA3)',
    8624  => 'Tingpeptide / Lipo-c 10ml tier10 — 3-way dup of 934 (blank sku, the original)',
    8721  => 'Tingpeptide / Lipo-c 10ml tier10 — 3-way dup of 934 (blank sku, the original)',
];

// Deliberately NOT touched (found by the same query, but a different class
// of issue, needs its own look rather than this fix):
//   - Changsha Xjun Techonology / Melanotan 1 10mg — one SKU is blank, dated
//     2026-07-13, a day before this mishap; unrelated timing.
//   - CALLA / L-Carnitine 10mg — its two "SKU variants" (LC120, LC216) are
//     Lipo-C SKU codes, not L-Carnitine codes — looks like a possible
//     product mismatch, not a same-item duplicate-SKU case.

$pdo = db();
$stmt = $pdo->prepare('UPDATE pc_prices SET is_active = 0 WHERE id = ?');
foreach ($toDeactivate as $id => $desc) {
    $stmt->execute([$id]);
    echo "Deactivated price {$id}: {$desc}\n";
}
echo count($toDeactivate) . " rows deactivated.\n";
