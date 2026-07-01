<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
requireAdmin();
$id = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT id, processing_status, processing_notes, processed_at FROM pc_vendor_files WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) jsonResponse(['error' => 'File not found.'], 404);

jsonResponse($file);
