<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET  /vendors/{id}/files  — list
// POST /vendors/{id}/files  — upload (multipart: file)
method('GET', 'POST');
$admin    = requireAdmin();
$vendorId = (int)($PARAMS['id'] ?? 0);

$vendorStmt = db()->prepare('SELECT id FROM pc_vendors WHERE id = ? LIMIT 1');
$vendorStmt->execute([$vendorId]);
if (!$vendorStmt->fetch()) jsonResponse(['error' => 'Vendor not found.'], 404);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->prepare('SELECT * FROM pc_vendor_files WHERE vendor_id = ? ORDER BY uploaded_at DESC');
    $stmt->execute([$vendorId]);
    jsonResponse(['files' => $stmt->fetchAll()]);
}

// ── Upload ────────────────────────────────────────────────────────
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'A file is required.'], 422);
}

$original = $_FILES['file']['name'];
$ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
$typeMap  = ['pdf' => 'pdf', 'xlsx' => 'xlsx', 'csv' => 'csv'];
if (!isset($typeMap[$ext])) {
    jsonResponse(['error' => 'Only PDF, XLSX, and CSV files are supported.'], 422);
}

$dir = dirname(__DIR__, 2) . "/storage/vendor_files/$vendorId";
if (!is_dir($dir)) mkdir($dir, 0770, true);

$storedName = generateToken(16) . ".$ext";
$storedPath = "$dir/$storedName";
if (!move_uploaded_file($_FILES['file']['tmp_name'], $storedPath)) {
    jsonResponse(['error' => 'Failed to save the uploaded file.'], 500);
}

// A new upload supersedes the previous "current" file for this vendor.
db()->prepare('UPDATE pc_vendor_files SET is_current = 0 WHERE vendor_id = ?')->execute([$vendorId]);

$stmt = db()->prepare(
    'INSERT INTO pc_vendor_files (vendor_id, original_filename, stored_path, file_type, file_size_bytes)
     VALUES (?,?,?,?,?)'
);
$stmt->execute([$vendorId, $original, "vendor_files/$vendorId/$storedName", $typeMap[$ext], (int)$_FILES['file']['size']]);
$fileId = (int)db()->lastInsertId();
cacheBust('admin_vendors'); // last_upload changed

logAdminAction((int)$admin['id'], 'upload_vendor_file', ['vendor_id' => $vendorId, 'file_id' => $fileId, 'filename' => $original]);

jsonResponse(['id' => $fileId, 'message' => 'File uploaded. Trigger processing separately.'], 201);
