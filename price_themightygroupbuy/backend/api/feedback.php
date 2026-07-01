<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

method('POST');
$user = requireAuth();
$d    = input();

$type    = in_array($d['type'] ?? '', ['general','ui_ux','feature','bug','performance'], true) ? $d['type'] : 'general';
$message = trim($d['message'] ?? '');
$url     = trim($d['url'] ?? '');

if (strlen($message) < 5) jsonResponse(['error' => 'Message is too short.'], 422);

rateLimit('feedback_' . $user['id'], 10, 3600);

db()->prepare(
    'INSERT INTO pc_feedback (user_id, type, message, url) VALUES (?,?,?,?)'
)->execute([$user['id'], $type, $message, $url ?: null]);
cacheBust('admin_feedback');

jsonResponse(['message' => 'Feedback submitted. Thank you!'], 201);
