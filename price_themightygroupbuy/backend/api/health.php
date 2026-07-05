<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

// GET /health — post-deploy smoke-check target. Exercises each real
// dependency (not just "PHP is alive"): a DB round-trip, a Memcached
// set/get round-trip, and mail config sanity. No auth — deploy.sh curls
// this from outside right after every deploy.
method('GET');

$db = false;
try {
    $db = (int)db()->query('SELECT 1')->fetchColumn() === 1;
} catch (Throwable $e) {
    $db = false;
}

$memcached = false;
$mcClient  = mc();
if ($mcClient) {
    $probeKey = 'health_probe';
    $mcClient->set($probeKey, 'ok', 10);
    $memcached = $mcClient->get($probeKey) === 'ok';
}

// ponytail: "reachability" here means the driver is actually configured to
// send, not a live network probe on every deploy — firing a real Brevo API
// call just to prove DNS/TLS works isn't worth the cost or rate-limit risk.
$email = MAIL_DRIVER === 'log' || (MAIL_DRIVER === 'brevo' && BREVO_API_KEY !== '');

$ok = $db && $memcached && $email;
jsonResponse([
    'ok'        => $ok,
    'db'        => $db        ? 'ok' : 'fail',
    'memcached' => $memcached ? 'ok' : 'fail',
    'email'     => $email     ? 'ok' : 'fail',
], $ok ? 200 : 503);
