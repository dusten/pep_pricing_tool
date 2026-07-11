<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/overview — aggregate dashboard
method('GET');
requireAdmin();

// Aggregates over tables written from many scattered places (registration,
// referral crediting, waitlist joins, every admin action). Wiring precise
// bust hooks into all of them isn't worth it for a dashboard tile — a short
// TTL is the right tradeoff here, not exact invalidation.
$data = cacheGet('admin_overview', 'all', 600, function () {
    $users = db()->query(
        "SELECT COUNT(*) AS total,
                SUM(email_verified_at IS NOT NULL) AS verified,
                SUM(tier_status IN ('active','trialing')) AS active_subs,
                SUM(tier = 'free') AS free_tier,
                SUM(tier = 'advanced') AS advanced_tier,
                SUM(tier = 'pro') AS pro_tier,
                SUM(tier = 'expert') AS expert_tier,
                SUM(test_account) AS test_accounts
         FROM pc_users"
    )->fetch();
    foreach ($users as $k => $v) $users[$k] = (int)$v;

    $referrals = db()->query(
        "SELECT COUNT(*) AS total_referrals, COUNT(DISTINCT referrer_id) AS unique_referrers
         FROM pc_referrals"
    )->fetch();
    foreach ($referrals as $k => $v) $referrals[$k] = (int)$v;

    $credits = db()->query(
        "SELECT COUNT(*) AS credited_count, COALESCE(SUM(amount_usd), 0) AS total_credited_usd
         FROM pc_referral_credits WHERE granted_at IS NOT NULL"
    )->fetch();
    $referrals['credited_count']     = (int)$credits['credited_count'];
    $referrals['total_credited_usd'] = (float)$credits['total_credited_usd'];

    $waitlistPending = (int)db()->query('SELECT COUNT(*) FROM pc_waitlist WHERE joined_at IS NULL')->fetchColumn();

    $recentActivity = db()->query(
        "SELECT a.action, a.details, a.created_at, u.email AS admin_email
         FROM pc_admin_audit_log a JOIN pc_users u ON u.id = a.admin_id
         ORDER BY a.created_at DESC LIMIT 25"
    )->fetchAll();

    return [
        'users'             => $users,
        'referrals'         => $referrals,
        'waitlist_pending'  => $waitlistPending,
        'recent_activity'   => $recentActivity,
    ];
});

jsonResponse($data);
