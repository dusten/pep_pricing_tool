<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// POST /vendors/{winnerId}/merge  body: { loser_id }
// Folds a duplicate vendor into another: prices/files/pending-imports/COA
// submissions are re-homed onto the winner, phones/payment methods are
// merged (collisions dropped, since a vendor can't have the same payment
// method twice), and the loser's shipping/notes text is appended onto the
// winner's if it isn't already there. The loser row is then deleted.
method('POST');
$admin    = requireAdmin();
$winnerId = (int)($PARAMS['id'] ?? 0);
$loserId  = (int)(input()['loser_id'] ?? 0);

if (!$winnerId || !$loserId || $winnerId === $loserId) {
    jsonResponse(['error' => 'A distinct winner and loser_id are required.'], 422);
}

$pdo   = db();
$check = $pdo->prepare('SELECT id, display_name, shipping_note, notes FROM pc_vendors WHERE id IN (?,?)');
$check->execute([$winnerId, $loserId]);
$rows = [];
foreach ($check->fetchAll() as $r) $rows[(int)$r['id']] = $r;
if (!isset($rows[$winnerId]) || !isset($rows[$loserId])) {
    jsonResponse(['error' => 'Vendor not found.'], 404);
}

$pdo->beginTransaction();
try {
    // Phones: no uniqueness constraint, just re-home them all.
    $pdo->prepare('UPDATE pc_vendor_phones SET vendor_id = ? WHERE vendor_id = ?')->execute([$winnerId, $loserId]);

    // Payment methods: UNIQUE(vendor_id, method) — move what doesn't already
    // exist on the winner, cascade-delete the rest when the loser row goes.
    $pdo->prepare('UPDATE IGNORE pc_vendor_payment_methods SET vendor_id = ? WHERE vendor_id = ?')->execute([$winnerId, $loserId]);

    // Files: always safe to re-home, this is exactly the "there's an update"
    // history the merge is for.
    $pdo->prepare('UPDATE pc_vendor_files SET vendor_id = ? WHERE vendor_id = ?')->execute([$winnerId, $loserId]);

    // Prices: UNIQUE(vendor_id, product_id, specification_id, tier_kit_size)
    // — move what doesn't collide, drop the rest (winner's existing price
    // for that combo wins, same philosophy as the product merge tool).
    $pdo->prepare('UPDATE IGNORE pc_prices SET vendor_id = ? WHERE vendor_id = ?')->execute([$winnerId, $loserId]);

    // Pending imports / COA submissions: no uniqueness constraint, re-home directly.
    $pdo->prepare('UPDATE pc_pending_imports SET vendor_id = ? WHERE vendor_id = ?')->execute([$winnerId, $loserId]);
    $pdo->prepare('UPDATE pc_coa_submissions SET vendor_id = ? WHERE vendor_id = ?')->execute([$winnerId, $loserId]);

    // Preserve free-text fields instead of silently dropping the loser's —
    // append if the winner already has its own text, otherwise just copy.
    foreach (['shipping_note', 'notes'] as $col) {
        $winnerVal = trim((string)($rows[$winnerId][$col] ?? ''));
        $loserVal  = trim((string)($rows[$loserId][$col] ?? ''));
        if ($loserVal === '') continue;
        $merged = $winnerVal === '' ? $loserVal : "$winnerVal\n\n[merged from {$rows[$loserId]['display_name']}]\n$loserVal";
        $pdo->prepare("UPDATE pc_vendors SET $col = ? WHERE id = ?")->execute([$merged, $winnerId]);
    }

    $pdo->prepare('DELETE FROM pc_vendors WHERE id = ?')->execute([$loserId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[vendors/merge] ' . $e->getMessage());
    jsonResponse(['error' => 'Merge failed. Nothing was changed.'], 500);
}

cacheBust('admin_vendors');
cacheBust('comparison_data');
logAdminAction((int)$admin['id'], 'merge_vendor', ['winner_id' => $winnerId, 'loser_id' => $loserId, 'loser_name' => $rows[$loserId]['display_name']]);
jsonResponse(['message' => 'Vendors merged.']);
