<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('POST');
$d = input();

$email    = trim(strtolower($d['email'] ?? ''));
$password = $d['password'] ?? '';

if (!$email || !$password) jsonResponse(['error' => 'Email and password are required.'], 422);

rateLimit('login_ip_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 20, 300);
rateLimit('login_email_' . $email, 10, 300);

$stmt = db()->prepare('SELECT * FROM pc_users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    // ponytail: same error for both cases — don't leak which is wrong
    jsonResponse(['error' => 'Invalid email or password.'], 401);
}

if (!$user['email_verified_at']) {
    jsonResponse(['error' => 'Please verify your email before logging in.',
                  'code'  => 'email_unverified'], 403);
}

$token      = generateToken();
$tokenHash  = hashToken($token);
$days       = (int)getAppSetting('session_lifetime_days', '30');
$expiresAt  = date('Y-m-d H:i:s', strtotime("+{$days} days"));
$userAgent  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
$ip         = $_SERVER['REMOTE_ADDR'] ?? null;

db()->prepare(
    'INSERT INTO pc_sessions (user_id, token_hash, expires_at, user_agent, ip) VALUES (?,?,?,?,?)'
)->execute([$user['id'], $tokenHash, $expiresAt, $userAgent, $ip]);

db()->prepare('INSERT INTO pc_login_history (user_id, ip, user_agent) VALUES (?,?,?)')
    ->execute([$user['id'], $ip, $userAgent]);

db()->prepare('UPDATE pc_users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

jsonResponse([
    'token'      => $token,
    'expires_at' => $expiresAt,
    'user'       => userShape($user),
]);
