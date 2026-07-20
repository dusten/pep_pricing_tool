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
if (strlen($password) > 200)                      $errors['password']     = 'Password is too long.'; // cap before bcrypt — bcrypt cost scales with input length
if (mb_strlen($displayName) < 2)                  $errors['display_name'] = 'Display name must be at least 2 characters.';

if ($errors) jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);

rateLimit('register_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 3600);

// ── Duplicate check ───────────────────────────────────────────────
// An existing, verified account gets a real "already exists" error (can't be
// avoided without breaking the login flow's usefulness). An existing but
// never-verified account is treated like a fresh signup and just gets its
// verification email re-sent (throttled) — this doesn't hand an attacker a
// working oracle for "is this email registered" the way a distinct message
// would, and matches what a legitimate owner of that email actually wants.
$stmt = db()->prepare('SELECT * FROM pc_users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$existing = $stmt->fetch();
if ($existing) {
    if ($existing['email_verified_at']) {
        jsonResponse(['error' => 'An account with this email already exists.'], 409);
    }
    rateLimit('resend_verify_' . $email, 1, 3600);
    $verifyUrl = APP_URL . '/verify-email?token=' . $existing['email_token'];
    sendVerificationEmail($email, $existing['display_name'], $verifyUrl);
    jsonResponse(['message' => 'Account created. Check your email for a verification link.'], 201);
}

// ── Referral handling ─────────────────────────────────────────────
// Guard against self-referral farming: don't credit a referral edge if the
// registrant's IP matches any IP the referrer has ever logged in from —
// that's a second account created by the same person to farm their own link.
$referrerId   = null;
$referralCode = trim($d['referral_code'] ?? '');
$remoteIp     = $_SERVER['REMOTE_ADDR'] ?? null; // never trust X-Forwarded-For for this — trivially spoofable
if ($referralCode) {
    $stmt = db()->prepare('SELECT id FROM pc_users WHERE referral_code = ? LIMIT 1');
    $stmt->execute([$referralCode]);
    $referrer = $stmt->fetch();
    if ($referrer) {
        $referrerId = (int)$referrer['id'];
        if ($remoteIp) {
            $ipMatch = db()->prepare('SELECT 1 FROM pc_login_history WHERE user_id = ? AND ip = ? LIMIT 1');
            $ipMatch->execute([$referrerId, $remoteIp]);
            if ($ipMatch->fetch()) {
                error_log("[register] suspected self-referral: code owner $referrerId, registrant IP $remoteIp — edge not recorded");
                $referrerId = null;
            }
        }
    }
}

// ── Create user ───────────────────────────────────────────────────
$myCode     = generateToken(12); // 24-char unique hash
$emailToken = generateToken(16); // 32-char hex; stored plain for simpler verify URL

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
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
    $userId = (int)$pdo->lastInsertId();

    if ($referrerId) {
        $pdo->prepare('INSERT IGNORE INTO pc_referrals (referrer_id, referee_id) VALUES (?,?)')
            ->execute([$referrerId, $userId]);
    }

    if ($waitlistEntry) {
        $pdo->prepare('UPDATE pc_waitlist SET joined_at = NOW() WHERE id = ?')
            ->execute([$waitlistEntry['id']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[register] ' . $e->getMessage());
    jsonResponse(['error' => 'Something went wrong. Please try again.'], 500);
}
cacheBust('admin_activity_trend'); // so the admin Activity dashboard reflects this signup immediately

// ── Send verification email ───────────────────────────────────────
$verifyUrl = APP_URL . '/verify-email?token=' . $emailToken;
sendVerificationEmail($email, $displayName, $verifyUrl);

jsonResponse([
    'message' => 'Account created. Check your email for a verification link.',
], 201);
