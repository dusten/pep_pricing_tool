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
        // HTML-escape every substitution — display names etc. are user-supplied
        // and land inside the HTML body. The one exception is 'button', which is
        // trusted HTML we build ourselves in _btn(), not user input.
        $safe = $k === 'button' ? $v : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $body = str_replace('{{' . $k . '}}', $safe, $body);
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

// ── Vendor suggestions (backlog #69) ─────────────────────────────
// Bodies built inline rather than as email_templates/*.html files — content
// is short and mostly dynamic (score numbers, admin notes), not worth a
// static template file for three one-paragraph emails.

function sendSuggestionScoredEmail(?string $email, string $name, string $vendorName, array $score): bool {
    if (!$email) return false;
    $greeting = $name ? "Hi {$name}," : 'Hi there,';
    $scoreLine = $score['vendor_score'] !== null
        ? "<p style=\"font-size:15px;color:#111827\">Vendor score: <strong>{$score['vendor_score']}/100</strong> ({$score['matched_rows']} of {$score['total_rows']} rows matched to catalog products).</p>"
        : '<p style="font-size:15px;color:#111827">Not enough catalog overlap to score this vendor yet.</p>';
    $body = "<p style=\"font-size:15px;color:#111827\">{$greeting}</p>
<p style=\"font-size:15px;color:#111827\">Your suggested vendor <strong>" . htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8') . "</strong> has been scored against current market prices.</p>
{$scoreLine}
<p style=\"font-size:14px;color:#6b7280\">See the full breakdown any time on the Suggest a Vendor page. An admin still needs to review before it appears in the catalog.</p>";
    return sendEmail($email, $name, 'Your vendor suggestion has been scored — TheMightyGroupBuy Prices',
                     emailTemplate('Suggestion Scored', $body));
}

function sendSuggestionAcceptedEmail(?string $email, string $name, string $vendorName): bool {
    if (!$email) return false;
    $greeting = $name ? "Hi {$name}," : 'Hi there,';
    $body = "<p style=\"font-size:15px;color:#111827\">{$greeting}</p>
<p style=\"font-size:15px;color:#111827\">Your suggested vendor <strong>" . htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8') . "</strong> has been accepted and is now live on TheMightyGroupBuy Prices. Thanks for the contribution!</p>"
        . _btn(APP_URL . '/comparison', 'View on Comparison');
    return sendEmail($email, $name, 'Your suggested vendor is now live — TheMightyGroupBuy Prices',
                     emailTemplate('Suggestion Accepted', $body));
}

function sendSuggestionRejectedEmail(?string $email, string $name, string $vendorName, string $adminNote = ''): bool {
    if (!$email) return false;
    $greeting = $name ? "Hi {$name}," : 'Hi there,';
    $noteLine = $adminNote ? "<p style=\"font-size:14px;color:#6b7280\">Note: " . htmlspecialchars($adminNote, ENT_QUOTES, 'UTF-8') . '</p>' : '';
    $body = "<p style=\"font-size:15px;color:#111827\">{$greeting}</p>
<p style=\"font-size:15px;color:#111827\">Your suggested vendor <strong>" . htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8') . "</strong> was not accepted into the catalog.</p>
{$noteLine}";
    return sendEmail($email, $name, 'Update on your vendor suggestion — TheMightyGroupBuy Prices',
                     emailTemplate('Suggestion Not Accepted', $body));
}
