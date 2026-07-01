<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
requireAdmin();
$id = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pc_vendor_files WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) jsonResponse(['error' => 'File not found.'], 404);

$fullPath = dirname(__DIR__, 2) . '/storage/' . $file['stored_path'];
if (!is_file($fullPath)) jsonResponse(['error' => 'File missing from storage.'], 404);

$mime = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv'][$file['file_type']] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($file['original_filename']) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
