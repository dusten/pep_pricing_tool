<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// PATCH /admin/users/{id}  body: { is_admin?, tier?, tier_status? }
method('PATCH');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);
$d     = input();

$target = db()->prepare('SELECT id, tier_status, referred_by_id FROM pc_users WHERE id = ? LIMIT 1');
$target->execute([$id]);
$before = $target->fetch();
if (!$before) jsonResponse(['error' => 'User not found.'], 404);

$fields = [];
$vals   = [];
if (array_key_exists('is_admin', $d)) {
    // Last-admin lockout guard (backlog #37): refuse to demote the only admin —
    // nobody would be left who can reach /admin to undo it.
    if (!(bool)$d['is_admin']) {
        $otherAdmins = db()->prepare('SELECT COUNT(*) FROM pc_users WHERE is_admin = 1 AND id != ?');
        $otherAdmins->execute([$id]);
        if ((int)$otherAdmins->fetchColumn() === 0) {
            jsonResponse(['error' => 'Cannot remove the last admin — make another account admin first.'], 422);
        }
    }
    $fields[] = 'is_admin = ?';
    $vals[]   = (bool)$d['is_admin'] ? 1 : 0;
}
if (array_key_exists('tier', $d) && in_array($d['tier'], ['free','advanced','pro','expert'], true)) {
    $fields[] = 'tier = ?';
    $vals[]   = $d['tier'];
}
if (array_key_exists('tier_status', $d) && in_array($d['tier_status'], ['active','past_due','canceled','trialing','none'], true)) {
    $fields[] = 'tier_status = ?';
    $vals[]   = $d['tier_status'];
}
if (array_key_exists('test_account', $d)) {
    $fields[] = 'test_account = ?';
    $vals[]   = (bool)$d['test_account'] ? 1 : 0;
}
if (!$fields) jsonResponse(['error' => 'No valid fields to update.'], 422);

$vals[] = $id;
db()->prepare('UPDATE pc_users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
cacheBust('admin_users');
logAdminAction((int)$admin['id'], 'update_user', ['user_id' => $id, 'fields' => $d]);

// Referral reward (backlog #4 pre-Stripe correction): grant the referrer free
// months on the referee's first genuine activation. Only fires on a real
// none/past_due/canceled/trialing -> active transition, not a redundant PATCH.
if (($d['tier_status'] ?? null) === 'active' && $before['tier_status'] !== 'active' && $before['referred_by_id']) {
    $already = db()->prepare('SELECT 1 FROM pc_referral_credits WHERE referee_id = ? AND granted_at IS NOT NULL');
    $already->execute([$id]);
    if (!$already->fetch()) {
        $referrerId = (int)$before['referred_by_id'];
        $months     = (int)getAppSetting('referral_months_free', '2');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO pc_referral_credits (referrer_id, referee_id, months_granted, granted_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE months_granted = VALUES(months_granted), granted_at = VALUES(granted_at)'
            )->execute([$referrerId, $id, $months]);

            $pdo->prepare(
                'UPDATE pc_users SET
                    tier_renews_at = DATE_ADD(GREATEST(COALESCE(tier_renews_at, NOW()), NOW()), INTERVAL ? MONTH),
                    tier_status = IF(tier_status IN (\'active\',\'trialing\'), tier_status, \'active\')
                 WHERE id = ?'
            )->execute([$months, $referrerId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        cacheBust('admin_users');
        logAdminAction((int)$admin['id'], 'referral_reward_granted', [
            'referrer_id' => $referrerId, 'referee_id' => $id, 'months' => $months,
        ]);
    }
}

jsonResponse(['message' => 'User updated.']);
