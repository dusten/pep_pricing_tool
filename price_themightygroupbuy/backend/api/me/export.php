<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /me/export — every table referencing this user, as a downloadable JSON file.
// This is also the reference list for what account deletion (me/index.php DELETE) must cascade.
method('GET');
$user = requireAuth();
$id   = $user['id'];

$data = [
    'profile' => userShape($user),
    'login_history' => q('SELECT ip, user_agent, created_at FROM pc_login_history WHERE user_id = ? ORDER BY created_at DESC', $id),
    'comparison_queries' => q('SELECT selection_params, duration_ms, result_count, created_at FROM pc_comparison_log WHERE user_id = ? ORDER BY created_at DESC', $id),
    'feedback' => q('SELECT type, message, url, created_at FROM pc_feedback WHERE user_id = ? ORDER BY created_at DESC', $id),
    'referrals_made' => q('SELECT referee_id, created_at FROM pc_referrals WHERE referrer_id = ?', $id),
    'referral_credits_earned' => q('SELECT amount_usd, granted_at FROM pc_referral_credits WHERE referrer_id = ?', $id),
];

function q(string $sql, int $id): array {
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

header('Content-Disposition: attachment; filename="my-data-export.json"');
jsonResponse($data);
