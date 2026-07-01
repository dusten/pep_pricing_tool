<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/backend/config.php';
require_once dirname(__DIR__, 3) . '/backend/helpers.php';
require_once dirname(__DIR__, 3) . '/backend/email.php';

method('POST');
$d     = input();
$email = trim(strtolower($d['email'] ?? ''));
$name  = trim($d['name'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'A valid email address is required.'], 422);
}

rateLimit('waitlist_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 3600);

try {
    db()->prepare(
        'INSERT INTO pc_waitlist (email, name, referral_code, confirmation_emails_sent) VALUES (?, ?, ?, 1)'
    )->execute([$email, $name ?: null, trim($d['referral_code'] ?? '') ?: null]);
    sendWaitlistConfirmationEmail($email, $name);
} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        $row = db()->prepare('SELECT name, confirmation_emails_sent FROM pc_waitlist WHERE email = ? LIMIT 1');
        $row->execute([$email]);
        $entry = $row->fetch();
        if ($entry && $entry['confirmation_emails_sent'] < 5) {
            db()->prepare('UPDATE pc_waitlist SET confirmation_emails_sent = confirmation_emails_sent + 1 WHERE email = ?')
               ->execute([$email]);
            sendWaitlistConfirmationEmail($email, $entry['name'] ?? $name);
        }
        jsonResponse(['message' => "You're on the list! We'll be in touch."]);
    }
    throw $e;
}

jsonResponse(['message' => "You're on the list! We'll be in touch."]);
