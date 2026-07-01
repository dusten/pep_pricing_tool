<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/email.php';

method('POST');
$d = input();

// ── Waitlist gate ─────────────────────────────────────────────────
$waitlistEntry = null;
if (getAppSetting('waitlist_mode', '1') === '1') {
    $inviteToken = trim($d['invite_token'] ?? '');
    if (!$inviteToken) {
        jsonResponse(['error' => 'waitlist',
                      'message' => 'Registration is currently invite-only. Join the waitlist to get access.'], 403);
    }
    $stmt = db()->prepare(
        'SELECT * FROM pc_waitlist WHERE invite_token = ? AND joined_at IS NULL LIMIT 1'
    );
    $stmt->execute([$inviteToken]);
    $waitlistEntry = $stmt->fetch() ?: null;
    if (!$waitlistEntry) jsonResponse(['error' => 'Invalid or already-used invite token.'], 403);
}

// ── Input validation ──────────────────────────────────────────────
$email       = trim(strtolower($d['email'] ?? ''));
$password    = $d['password'] ?? '';
$displayName = trim($d['display_name'] ?? '');
$errors      = [];

if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors['email']        = 'A valid email address is required.';
if (strlen($password) < 8)                        $errors['password']     = 'Password must be at least 8 characters.';
if (mb_strlen($displayName) < 2)                  $errors['display_name'] = 'Display name must be at least 2 characters.';

if ($errors) jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);

rateLimit('register_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 3600);

// ── Duplicate check ───────────────────────────────────────────────
$stmt = db()->prepare('SELECT id FROM pc_users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($stmt->fetch()) jsonResponse(['error' => 'An account with this email already exists.'], 409);

// ── Referral handling ─────────────────────────────────────────────
$referrerId   = null;
$referralCode = trim($d['referral_code'] ?? '');
if ($referralCode) {
    $stmt = db()->prepare('SELECT id FROM pc_users WHERE referral_code = ? LIMIT 1');
    $stmt->execute([$referralCode]);
    $referrer = $stmt->fetch();
    if ($referrer) $referrerId = (int)$referrer['id'];
}

// ── Create user ───────────────────────────────────────────────────
$myCode     = generateToken(12); // 24-char unique hash
$emailToken = generateToken(16); // 32-char hex; stored plain for simpler verify URL

$stmt = db()->prepare(
    'INSERT INTO pc_users (email, password_hash, display_name, email_token, referral_code, referred_by_id)
     VALUES (?,?,?,?,?,?)'
);
$stmt->execute([
    $email,
    password_hash($password, PASSWORD_DEFAULT),
    $displayName,
    $emailToken,
    $myCode,
    $referrerId,
]);
$userId = (int)db()->lastInsertId();

// Record referral edge
if ($referrerId) {
    db()->prepare('INSERT IGNORE INTO pc_referrals (referrer_id, referee_id) VALUES (?,?)')
        ->execute([$referrerId, $userId]);
}

// Mark waitlist entry consumed
if ($waitlistEntry) {
    db()->prepare('UPDATE pc_waitlist SET joined_at = NOW() WHERE id = ?')
        ->execute([$waitlistEntry['id']]);
}

// ── Send verification email ───────────────────────────────────────
$verifyUrl = APP_URL . '/verify-email?token=' . $emailToken;
sendVerificationEmail($email, $displayName, $verifyUrl);

jsonResponse([
    'message' => 'Account created. Check your email for a verification link.',
], 201);
