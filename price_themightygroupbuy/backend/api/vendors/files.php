<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/malware_scan.php';
require_once dirname(__DIR__, 2) . '/lib/zip_reader.php';

// GET  /vendors/{id}/files  — list, optional ?category=price_list|coa|other
// POST /vendors/{id}/files  — upload (multipart: file, category)
method('GET', 'POST');
$admin    = requireAdmin();
$vendorId = (int)($PARAMS['id'] ?? 0);

$vendorStmt = db()->prepare('SELECT id FROM pc_vendors WHERE id = ? LIMIT 1');
$vendorStmt->execute([$vendorId]);
if (!$vendorStmt->fetch()) jsonResponse(['error' => 'Vendor not found.'], 404);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $category = in_array($_GET['category'] ?? '', ['price_list', 'coa', 'other'], true) ? $_GET['category'] : null;
    $sql = 'SELECT * FROM pc_vendor_files WHERE vendor_id = ?' . ($category ? ' AND category = ?' : '') . ' ORDER BY uploaded_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($category ? [$vendorId, $category] : [$vendorId]);
    jsonResponse(['files' => $stmt->fetchAll()]);
}

// ── Upload ────────────────────────────────────────────────────────
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'A file is required.'], 422);
}

$category = in_array($_POST['category'] ?? '', ['price_list', 'coa', 'other'], true) ? $_POST['category'] : 'price_list';

$original = $_FILES['file']['name'];
$ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
$typeMap  = ['pdf' => 'pdf', 'xlsx' => 'xlsx', 'csv' => 'csv', 'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'zip' => 'zip'];
if (!isset($typeMap[$ext])) {
    jsonResponse(['error' => 'Only PDF, XLSX, CSV, JPG, PNG, and ZIP files are supported.'], 422);
}

$dir = dirname(__DIR__, 2) . "/storage/vendor_files/$vendorId";
if (!is_dir($dir)) mkdir($dir, 0770, true);

$storedName = generateToken(16) . ".$ext";
$storedPath = "$dir/$storedName";
if (!move_uploaded_file($_FILES['file']['tmp_name'], $storedPath)) {
    jsonResponse(['error' => 'Failed to save the uploaded file.'], 500);
}

// Zip structural validation up front — cheap (statIndex() only, no
// decompression) and rejecting a bad zip immediately is much better UX than
// accepting the upload and only failing later when "Process" is clicked.
if ($typeMap[$ext] === 'zip') {
    $zipCheck = new ZipArchive();
    if ($zipCheck->open($storedPath) !== true) {
        unlink($storedPath);
        jsonResponse(['error' => 'Could not open the uploaded ZIP file.'], 422);
    }
    try {
        validateZipEntries($zipCheck);
    } catch (Throwable $e) {
        $zipCheck->close();
        unlink($storedPath);
        jsonResponse(['error' => 'Invalid ZIP.', 'message' => $e->getMessage()], 422);
    }
    $zipCheck->close();
}

// Malware scan gate — every upload, every category, before the row is ever
// marked available for processing/cataloging. Positive match quarantines the
// file (kept for inspection, not deleted) and records a failed file row so
// the reason is visible in the admin UI rather than the upload silently
// vanishing. Zips are scanned entry-by-entry (see scanZipEntriesForMalware)
// rather than as a whole, regardless of clamd's own archive-format support.
$clean = true;
if (MALWARE_SCAN_ENABLED) {
    try {
        $clean = $typeMap[$ext] === 'zip' ? scanZipEntriesForMalware($storedPath) : scanFileForMalware($storedPath);
    } catch (Throwable $e) {
        jsonResponse(['error' => 'Malware scan could not run. Upload rejected.', 'message' => $e->getMessage()], 503);
    }
}
if (!$clean) {
    $quarantinedPath = quarantineFile($storedPath);
    db()->prepare(
        'INSERT INTO pc_vendor_files (vendor_id, original_filename, stored_path, file_type, category, file_size_bytes, processing_status, processing_notes)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $vendorId, $original, $quarantinedPath, $typeMap[$ext], $category, (int)$_FILES['file']['size'],
        'failed', 'Malware scan rejected this file — quarantined, not processed.',
    ]);
    logAdminAction((int)$admin['id'], 'upload_vendor_file_rejected', ['vendor_id' => $vendorId, 'filename' => $original]);
    jsonResponse(['error' => 'This file was flagged by malware scanning and was not accepted.'], 422);
}

// A new price-list upload supersedes the previous "current" price list for
// this vendor. COA/other uploads just accumulate — they're not "current" vs
// "superseded", they're a running document history.
if ($category === 'price_list') {
    db()->prepare("UPDATE pc_vendor_files SET is_current = 0 WHERE vendor_id = ? AND category = 'price_list'")->execute([$vendorId]);
}

// Backlog #14, layer 1 — a byte-identical re-upload of a price_list file
// that was already successfully processed skips extraction outright. Scoped
// per vendor and only to price_list (COA/other are never reprocessed, so a
// hash check buys nothing there). Only matches a PRIOR file that was
// actually handled ('complete'/'skipped_duplicate') — one still pending,
// processing, or failed isn't "already handled," and the vendor may be
// intentionally retrying.
$contentHash = hash('sha256', (string)file_get_contents($storedPath));
$duplicateOf = null;
if ($category === 'price_list') {
    $dup = db()->prepare(
        "SELECT id, uploaded_at FROM pc_vendor_files
         WHERE vendor_id = ? AND category = 'price_list' AND content_hash = ?
           AND processing_status IN ('complete', 'skipped_duplicate')
         ORDER BY uploaded_at DESC LIMIT 1"
    );
    $dup->execute([$vendorId, $contentHash]);
    $duplicateOf = $dup->fetch() ?: null;
}

if ($duplicateOf) {
    $processingStatus = 'skipped_duplicate';
    $notes = "Duplicate of file #{$duplicateOf['id']}, uploaded {$duplicateOf['uploaded_at']} — already processed, extraction skipped.";
} else {
    $processingStatus = $category === 'price_list' ? 'pending' : 'complete';
    $notes = null;
}

$stmt = db()->prepare(
    'INSERT INTO pc_vendor_files (vendor_id, original_filename, stored_path, file_type, category, file_size_bytes, content_hash, processing_status, processing_notes, processed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
$stmt->execute([
    $vendorId, $original, "vendor_files/$vendorId/$storedName", $typeMap[$ext], $category, (int)$_FILES['file']['size'],
    $contentHash, $processingStatus, $notes,
    ($processingStatus === 'pending') ? null : date('Y-m-d H:i:s'),
]);
$fileId = (int)db()->lastInsertId();
cacheBust('admin_vendors'); // last_upload changed

logAdminAction((int)$admin['id'], 'upload_vendor_file', ['vendor_id' => $vendorId, 'file_id' => $fileId, 'filename' => $original, 'category' => $category]);

if ($duplicateOf) {
    $msg = "This file is identical to a previously processed upload (File #{$duplicateOf['id']}, uploaded {$duplicateOf['uploaded_at']}) — skipping extraction.";
} else {
    $msg = $category === 'price_list' ? 'File uploaded. Trigger processing separately.' : 'File uploaded and cataloged.';
}
jsonResponse(['id' => $fileId, 'message' => $msg, 'duplicate' => $duplicateOf !== null], 201);
