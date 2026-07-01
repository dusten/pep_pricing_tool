<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

method('GET', 'PATCH', 'DELETE');
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Password-confirmed self-service deletion. FKs on pc_sessions, pc_query_log,
    // pc_comparison_log, pc_login_history, pc_referrals cascade automatically;
    // pc_feedback/pc_perf_metrics/pc_billing_events keep the row with user_id
    // set NULL (kept for triage/billing records, just anonymized).
    // Keep this cascade in sync with what me/export.php enumerates.
    $d = input();
    if (!password_verify((string)($d['password'] ?? ''), $user['password_hash'])) {
        jsonResponse(['error' => 'Password is incorrect.'], 401);
    }
    db()->prepare('DELETE FROM pc_users WHERE id = ?')->execute([$user['id']]);
    jsonResponse(['message' => 'Account deleted.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $d      = input();
    $fields = [];
    $vals   = [];

    if (isset($d['theme']) && in_array($d['theme'], ['system','light','dark'], true)) {
        $fields[] = 'theme = ?';
        $vals[]   = $d['theme'];
    }
    if (isset($d['display_name']) && mb_strlen(trim($d['display_name'])) >= 2) {
        $fields[] = 'display_name = ?';
        $vals[]   = trim($d['display_name']);
    }
    if (isset($d['timezone']) && preg_match('#^[A-Za-z0-9_+\-/]{1,64}$#', $d['timezone'])) {
        $fields[] = 'timezone = ?';
        $vals[]   = $d['timezone'];
    }
    if (isset($d['push_enabled'])) {
        $fields[] = 'push_enabled = ?';
        $vals[]   = (bool)$d['push_enabled'] ? 1 : 0;
    }

    if ($fields) {
        $vals[] = $user['id'];
        db()->prepare('UPDATE pc_users SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($vals);
    }

    // Re-fetch updated user
    $stmt = db()->prepare('SELECT * FROM pc_users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $user = $stmt->fetch();
}

jsonResponse(userShape($user));
