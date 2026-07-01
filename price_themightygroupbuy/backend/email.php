<?php
declare(strict_types=1);

function sendEmail(string $toEmail, string $toName, string $subject, string $html): bool {
    if (MAIL_DRIVER === 'log') {
        $entry = sprintf("[%s] TO:%s SUBJECT:%s\n%s\n%s\n",
            date('Y-m-d H:i:s'), $toEmail, $subject, str_repeat('-', 60), $html);
        file_put_contents(dirname(__DIR__) . '/log/mail.log', $entry, FILE_APPEND | LOCK_EX);
        return true;
    }
    if (!BREVO_API_KEY) {
        error_log("[email] BREVO_API_KEY not set — skipping send to $toEmail");
        return false;
    }
    $payload = json_encode([
        'sender'      => ['email' => MAIL_FROM, 'name' => MAIL_FROM_NAME],
        'to'          => [['email' => $toEmail, 'name' => $toName ?: $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ]);
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        error_log("[email] Brevo error $code sending to $toEmail: $result");
        return false;
    }
    return true;
}

function loadTemplate(string $name, array $vars = []): string {
    $path = dirname(__DIR__) . '/backend/email_templates/' . $name . '.html';
    $body = file_get_contents($path);
    foreach ($vars as $k => $v) {
        $body = str_replace('{{' . $k . '}}', $v, $body);
    }
    return $body;
}

// ── Base email wrapper ────────────────────────────────────────────

function emailTemplate(string $title, string $body): string {
    $navy   = '#0E2245';
    $gold   = '#C8A227';
    $appUrl = APP_URL;
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title></head>
<body style="margin:0;padding:0;background:#F2EBD9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F2EBD9;padding:40px 16px">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%">
  <tr><td style="background:{$navy};padding:28px 32px;border-radius:8px 8px 0 0;text-align:center">
    <div style="color:{$gold};font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:700;margin-bottom:6px">TheMightyGroupBuy</div>
    <div style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:-0.3px">{$title}</div>
  </td></tr>
  <tr><td style="background:#ffffff;padding:36px 32px;border-radius:0 0 8px 8px">
    {$body}
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:28px 0 20px">
    <p style="color:#9ca3af;font-size:11px;margin:0;text-align:center">
      TheMightyGroupBuy Price Comparison &bull;
      <a href="{$appUrl}" style="color:#9ca3af;text-decoration:none">{$appUrl}</a>
    </p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function _btn(string $href, string $label): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0">
<tr><td align="center">
  <a href="{$href}" style="display:inline-block;background:#0E2245;color:#ffffff;text-decoration:none;padding:13px 30px;border-radius:6px;font-weight:600;font-size:14px;letter-spacing:0.2px">{$label}</a>
</td></tr>
</table>
HTML;
}

// ── Specific emails ───────────────────────────────────────────────

function sendVerificationEmail(string $email, string $name, string $verifyUrl): bool {
    $body = loadTemplate('verification', ['name' => $name, 'url' => $verifyUrl, 'button' => _btn($verifyUrl, 'Verify Email Address')]);
    return sendEmail($email, $name, 'Verify your email — TheMightyGroupBuy Prices',
                     emailTemplate('Verify Your Email', $body));
}

function sendPasswordResetEmail(string $email, string $name, string $resetUrl): bool {
    $body = loadTemplate('password_reset', ['name' => $name, 'url' => $resetUrl, 'button' => _btn($resetUrl, 'Reset Password')]);
    return sendEmail($email, $name, 'Reset your password — TheMightyGroupBuy Prices',
                     emailTemplate('Reset Password', $body));
}

function sendWelcomeEmail(string $email, string $name): bool {
    $body = loadTemplate('welcome', ['name' => $name, 'button' => _btn(APP_URL, 'Go to Dashboard')]);
    return sendEmail($email, $name, 'Welcome to TheMightyGroupBuy Price Comparison',
                     emailTemplate('Welcome!', $body));
}

function sendWaitlistConfirmationEmail(string $email, string $name): bool {
    $greeting = $name ? "Hi {$name}," : 'Hi there,';
    $body = loadTemplate('waitlist_confirmation', ['greeting' => $greeting]);
    return sendEmail($email, $name, "You're on the waitlist — TheMightyGroupBuy Prices",
                     emailTemplate("You're on the list!", $body));
}

function sendWaitlistInviteEmail(string $email, string $name, string $inviteUrl): bool {
    $body = loadTemplate('waitlist_invite', ['name' => $name, 'url' => $inviteUrl, 'button' => _btn($inviteUrl, 'Accept Invite & Sign Up')]);
    return sendEmail($email, $name, "You're invited — TheMightyGroupBuy Price Comparison",
                     emailTemplate("You're In!", $body));
}
