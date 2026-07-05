<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/claude-log/{id} — full raw response text for one logged call
method('GET');
requireAdmin();
$id = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare(
    "SELECT ccl.*, vf.original_filename, v.display_name AS vendor_name
     FROM pc_claude_call_log ccl
     LEFT JOIN pc_vendor_files vf ON vf.id = ccl.vendor_file_id
     LEFT JOIN pc_vendors v       ON v.id = vf.vendor_id
     WHERE ccl.id = ? LIMIT 1"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) jsonResponse(['error' => 'Not found.'], 404);

$row['id']             = (int)$row['id'];
$row['vendor_file_id'] = $row['vendor_file_id'] !== null ? (int)$row['vendor_file_id'] : null;
$row['http_status']    = $row['http_status'] !== null ? (int)$row['http_status'] : null;
$row['parsed_ok']      = (bool)$row['parsed_ok'];

jsonResponse($row);
