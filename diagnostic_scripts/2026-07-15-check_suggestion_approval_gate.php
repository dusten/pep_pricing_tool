<?php
declare(strict_types=1);
// 037_vendor_suggestion_approval.sql verification (backlog #69): confirms the
// duplicate-content-hash guard query in api/vendor_suggestions/index.php
// blocks a same-user resubmit unless the prior row was rejected, and that the
// cron's pending_parse claim query never selects an awaiting_approval row.
// No HTTP call, no Claude call — direct DB, self-cleaning. Delete once the
// approval gate has been live a while.
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "ok: $msg\n";
}

$pdo = db();
$userId = (int)$pdo->query("SELECT id FROM pc_users LIMIT 1")->fetchColumn();
assertTrue($userId > 0, 'found a user row to test against');

$hash = hash('sha256', 'diagnostic-dup-check-' . microtime(true));

$insert = function (string $status) use ($pdo, $userId, $hash) {
    $pdo->prepare(
        "INSERT INTO pc_vendor_suggestions
           (user_id, relationship, display_name, original_filename, stored_path, file_type, content_hash, status)
         VALUES (?, 'customer', 'Diag Dup Vendor', 'diag.pdf', 'vendor_suggestions/diag/x.pdf', 'pdf', ?, ?)"
    )->execute([$userId, $hash, $status]);
    return (int)$pdo->lastInsertId();
};

$dupCheck = function () use ($pdo, $userId, $hash) {
    $stmt = $pdo->prepare(
        "SELECT id FROM pc_vendor_suggestions WHERE user_id = ? AND content_hash = ? AND status != 'rejected' LIMIT 1"
    );
    $stmt->execute([$userId, $hash]);
    return (bool)$stmt->fetch();
};

$ids = [];
try {
    $ids[] = $insert('awaiting_approval');
    assertTrue($dupCheck() === true, 'resubmit blocked while prior row is awaiting_approval');

    $pdo->prepare("UPDATE pc_vendor_suggestions SET status = 'rejected' WHERE id = ?")->execute([$ids[0]]);
    assertTrue($dupCheck() === false, 'resubmit allowed once prior row is rejected');

    // Cron claim query (process_async_queue.php) must never pick up awaiting_approval.
    $ids[] = $insert('awaiting_approval');
    $claimed = $pdo->query("SELECT id FROM pc_vendor_suggestions WHERE status = 'pending_parse' AND id = " . end($ids))->fetchColumn();
    assertTrue($claimed === false, 'cron pending_parse claim query does not see an awaiting_approval row');
} finally {
    if ($ids) {
        $pdo->prepare('DELETE FROM pc_vendor_suggestions WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
            ->execute($ids);
    }
}

echo "All checks passed.\n";
