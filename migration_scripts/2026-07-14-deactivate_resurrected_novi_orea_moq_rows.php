<?php
/**
 * 2026-07-14-deactivate_resurrected_novi_orea_moq_rows.php
 *
 * User asked about vendor file 28 ("WhatsApp Image 2026-06-04 at 12.43.49
 * PM.jpeg", NOVI OREA INTERNATIONAL LIMITED, vendor 12) — a "MOQ/vial" +
 * "Price/vial" layout that Claude's original 2026-07-05 extraction got
 * wrong (stuffed the raw MOQ vial-count directly into kit_vial_count and
 * tier_kit_size, and treated the per-vial price as a whole-kit price).
 *
 * That exact issue was already found and fixed once: rule 12 in claude.php
 * (added 2026-07-06) and migration_scripts/2026-07-06-fix_novi_orea_moq.php
 * corrected this file's rows at the time — deleted 6 that duplicated an
 * already-correct CSV from the same vendor (file 27), corrected 5 others in
 * place (Retatrutide 60mg, GHK-Cu 100mg, all 3 Botulinum toxin specs).
 *
 * The 2026-07-14 broad "reprocess every file" mishap (see
 * wiki/analyses/2026-07-14-incomplete-spec-drop-bug.md) reprocessed file 28
 * again using its old, pre-fix raw extraction text and resurrected the
 * original wrong data as 8 new active price rows, sitting alongside the
 * correct tiers with bogus "100-kit"/"500-kit" prices far below the real
 * tiers (e.g. Tirzepatide 10mg's legitimate 50-kit price is $49.50; the
 * resurrected row showed a fake "100-kit" price of $5) -- live and would
 * have wrongly won the "lowest price" highlight on the Comparison page.
 * Botox was unaffected: its resurrected pending-import rows were separately
 * rejected already, and the live Botox prices were never duplicated.
 *
 * Every one of these already has a correct tier from either the 2026-07-06
 * fix or this vendor's other files (27/29), so nothing needed recalculating
 * -- just deactivated.
 *
 * Executed live via PUT /api/prices/{id} {is_active:false}. Kept here as
 * the archived record per this project's convention.
 */
declare(strict_types=1);
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

$toDeactivate = [
    9666 => 'Tirzepatide 10mg — bogus 100-kit $5 (correct tiers: 1/10/50-kit $50/$62.50/$49.50)',
    9667 => 'Tirzepatide 30mg — bogus 100-kit $7 (correct tiers: 1/10/50-kit $80/$112/$90)',
    9668 => 'Tirzepatide 60mg — bogus 500-kit $10 (correct tiers: 1/10/50-kit $120/$211/$169)',
    9669 => 'Retatrutide 10mg — bogus 500-kit $6 (correct tiers: 1/10/50-kit $60/$81.50/$65.50)',
    9670 => 'Retatrutide 30mg — bogus 500-kit $9 (correct tiers: 1/10/50-kit $100/$174.50/$139.50)',
    9671 => 'Retatrutide 60mg — bogus 100-kit $11 (duplicate of the already-correct 10-kit $110, row 4115)',
    9672 => 'GHK-Cu 50mg — bogus 100-kit $4 (correct tiers: 1/10/50-kit $50/$44/$35)',
    9673 => 'GHK-Cu 100mg — bogus 100-kit $6 (duplicate of the already-correct 10-kit $60, row 4117)',
];

$pdo = db();
$stmt = $pdo->prepare('UPDATE pc_prices SET is_active = 0 WHERE id = ?');
foreach ($toDeactivate as $id => $desc) {
    $stmt->execute([$id]);
    echo "Deactivated price {$id}: {$desc}\n";
}
echo count($toDeactivate) . " rows deactivated.\n";
