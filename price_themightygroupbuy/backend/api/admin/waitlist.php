<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/email.php';

// GET    /admin/waitlist       — list all entries
// POST   /admin/waitlist       body: { ids: [...] } — bulk-invite (generates + emails invite links)
// DELETE /admin/waitlist       body: { ids: [...] } — bulk delete
method('GET', 'POST', 'DELETE');
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = cacheGet('admin_waitlist', 'all', 600, fn() => db()->query('SELECT * FROM pc_waitlist ORDER BY created_at DESC')->fetchAll());
    jsonResponse(['waitlist' => $rows]);
}

$ids = array_map('intval', (array)(input()['ids'] ?? []));
if (!$ids) jsonResponse(['error' => 'ids array is required.'], 422);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $stmt = db()->prepare('DELETE FROM pc_waitlist WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $deleted = $stmt->rowCount();
    cacheBust('admin_waitlist');
    logAdminAction((int)$admin['id'], 'bulk_delete_waitlist', ['ids' => $ids, 'deleted' => $deleted]);
    jsonResponse(['message' => "Removed $deleted entr" . ($deleted === 1 ? 'y' : 'ies') . '.', 'deleted' => $deleted]);
}

$stmt = db()->prepare(
    'SELECT * FROM pc_waitlist WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND joined_at IS NULL'
);
$stmt->execute($ids);
$entries = $stmt->fetchAll();

$invited = 0;
foreach ($entries as $entry) {
    $token = generateToken();
    db()->prepare('UPDATE pc_waitlist SET invite_token = ?, invited_at = NOW() WHERE id = ?')
        ->execute([$token, $entry['id']]);
    $inviteUrl = APP_URL . '/register?invite=' . $token;
    sendWaitlistInviteEmail($entry['email'], $entry['name'] ?? '', $inviteUrl);
    $invited++;
}

cacheBust('admin_waitlist');
logAdminAction((int)$admin['id'], 'invite_waitlist', ['ids' => $ids, 'invited' => $invited]);
jsonResponse(['message' => "Invited $invited entr" . ($invited === 1 ? 'y' : 'ies') . '.', 'invited' => $invited]);
