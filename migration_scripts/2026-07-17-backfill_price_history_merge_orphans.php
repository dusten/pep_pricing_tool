<?php
declare(strict_types=1);
// One-off backfill (2026-07-17): merge.php / spec_merge.php / vendors/merge.php
// never repointed pc_price_history onto the winner id before deleting the loser
// row (fixed same day — see those three files). This repoints every historical
// merge's orphaned history rows retroactively, replaying every merge_product /
// merge_specification / merge_vendor event ever logged in pc_admin_audit_log.
//
// Idempotent: re-running finds nothing to do, because after the first pass no
// pc_price_history row still points at the old loser id (it's been repointed to
// the winner). Chases transitive merges: if a winner was itself later merged
// into something else, keep following winner_id -> its own later winner_id
// until a row is found that was never itself later merged as a loser.
//
// Usage: php backfill_price_history_merge_orphans.php [--dry-run]
require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = db();

$ACTIONS = [
    'merge_product'       => ['col' => 'product_id',       'label' => 'product'],
    'merge_specification'  => ['col' => 'specification_id', 'label' => 'specification'],
    'merge_vendor'         => ['col' => 'vendor_id',        'label' => 'vendor'],
];

// Resolve a loser id through any chain of later merges where it became a winner
// under a *different* later loser (i.e. id X was merged away as a loser into Y,
// but Y was itself later merged into Z as a loser -> follow to Z). Returns the
// final id, or null if it can't be resolved further (dead end, no later merge).
function resolveFinalWinner(array $mergesByLoser, int $id, string $col): int {
    $seen = [];
    while (isset($mergesByLoser[$col][$id]) && !isset($seen[$id])) {
        $seen[$id] = true;
        $id = $mergesByLoser[$col][$id];
    }
    return $id;
}

$log = $pdo->query(
    "SELECT id, action, details, created_at FROM pc_admin_audit_log
     WHERE action IN ('merge_product','merge_specification','merge_vendor')
     ORDER BY created_at ASC"
)->fetchAll();

echo "Found " . count($log) . " historical merge events.\n";

// Build loser_id -> winner_id map per column, so we can chase transitive merges.
$mergesByLoser = ['product_id' => [], 'specification_id' => [], 'vendor_id' => []];
$events = [];
foreach ($log as $row) {
    $details = json_decode((string)$row['details'], true) ?: [];
    $winnerId = (int)($details['winner_id'] ?? 0);
    $loserId  = (int)($details['loser_id'] ?? 0);
    if (!$winnerId || !$loserId) {
        echo "  SKIP audit_log id={$row['id']} action={$row['action']} — missing winner_id/loser_id in details\n";
        continue;
    }
    $col = $ACTIONS[$row['action']]['col'];
    $mergesByLoser[$col][$loserId] = $winnerId;
    $events[] = ['audit_id' => $row['id'], 'action' => $row['action'], 'col' => $col, 'winner' => $winnerId, 'loser' => $loserId, 'created_at' => $row['created_at']];
}

$totalRepointed = 0;
$unresolved = [];

foreach ($events as $ev) {
    $col = $ev['col'];
    $table = $col === 'vendor_id' ? 'pc_vendors' : ($col === 'product_id' ? 'pc_products' : 'pc_specifications');
    $finalWinner = resolveFinalWinner($mergesByLoser, $ev['winner'], $col);

    // Confirm the final winner id still exists — if it too was deleted (merged
    // away without a matching audit row, or something else), we can't safely repoint.
    $exists = $pdo->prepare("SELECT 1 FROM $table WHERE id = ?");
    $exists->execute([$finalWinner]);
    if (!$exists->fetchColumn()) {
        $unresolved[] = $ev + ['reason' => "final winner id $finalWinner not found in $table"];
        echo "  UNRESOLVED audit_id={$ev['audit_id']} {$ev['action']} loser={$ev['loser']} -> winner chain ends at {$finalWinner}, but that row doesn't exist\n";
        continue;
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM pc_price_history WHERE $col = ?");
    $count->execute([$ev['loser']]);
    $n = (int)$count->fetchColumn();

    if ($n === 0) {
        continue; // nothing orphaned under this loser id (already repointed, or never had history)
    }

    echo "  audit_id={$ev['audit_id']} {$ev['created_at']} {$ev['action']}: loser={$ev['loser']} -> winner={$finalWinner}"
        . ($finalWinner !== $ev['winner'] ? " (chased through {$ev['winner']})" : "") . " : $n row(s)";

    if ($dryRun) {
        echo " [dry-run, not applied]\n";
    } else {
        $pdo->prepare("UPDATE pc_price_history SET $col = ? WHERE $col = ?")->execute([$finalWinner, $ev['loser']]);
        echo " : repointed\n";
    }
    $totalRepointed += $n;
}

echo "\nTotal rows repointed: $totalRepointed" . ($dryRun ? " (dry-run — nothing written)\n" : "\n");
if ($unresolved) {
    echo count($unresolved) . " unresolved event(s) — see UNRESOLVED lines above.\n";
} else {
    echo "No unresolved events.\n";
}
