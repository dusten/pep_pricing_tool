<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/users/{id}/referrals — who referred this user, and who they referred
method('GET');
requireAdmin();
$id = (int)($PARAMS['id'] ?? 0);

$referredBy = db()->prepare(
    'SELECT u.id, u.email, u.display_name FROM pc_referrals r
     JOIN pc_users u ON u.id = r.referrer_id
     WHERE r.referee_id = ? LIMIT 1'
);
$referredBy->execute([$id]);

$referred = db()->prepare(
    'SELECT u.id, u.email, u.display_name, u.created_at, rc.months_granted, rc.granted_at
     FROM pc_referrals r
     JOIN pc_users u ON u.id = r.referee_id
     LEFT JOIN pc_referral_credits rc ON rc.referee_id = u.id AND rc.referrer_id = ?
     WHERE r.referrer_id = ? ORDER BY u.created_at DESC'
);
$referred->execute([$id, $id]);

jsonResponse([
    'referred_by' => $referredBy->fetch() ?: null,
    'referred'    => $referred->fetchAll(),
]);
