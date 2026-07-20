<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /me/referral-stats — counts for the "Refer a Friend" card
method('GET');
$user = requireAuth();

$joined = db()->prepare('SELECT COUNT(*) FROM pc_referrals WHERE referrer_id = ?');
$joined->execute([$user['id']]);

$credits = db()->prepare(
    'SELECT COUNT(*) AS converted, COALESCE(SUM(months_granted), 0) AS total
     FROM pc_referral_credits WHERE referrer_id = ? AND granted_at IS NOT NULL'
);
$credits->execute([$user['id']]);
$c = $credits->fetch();

jsonResponse([
    'joined'        => (int)$joined->fetchColumn(),
    'converted'     => (int)$c['converted'],
    'months_earned' => (int)$c['total'],
]);
