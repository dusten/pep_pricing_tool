<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/claude.php';
require_once dirname(__DIR__, 2) . '/lib/xlsx_reader.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_file_processor.php';

method('POST');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);
$model = (input()['model'] ?? '') === 'opus' ? CLAUDE_MODEL_HARD : CLAUDE_MODEL_DEFAULT;

// 99-tmgb.ini sets max_execution_time=30, well under callClaudeMessages()'s
// own 400s curl timeout — PHP would kill a slow extraction before curl ever
// got the chance to. Extend past the curl timeout for this request only.
set_time_limit(450);

$stmt = db()->prepare('SELECT * FROM pc_vendor_files WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) jsonResponse(['error' => 'File not found.'], 404);

// Large image-scanned PDFs risk the request timeout — queue those for the
// async cron worker instead of calling Claude inline. Everything else stays
// synchronous: mark "processing" and do the work in this same request.
db()->prepare('UPDATE pc_vendor_files SET processing_status = ? WHERE id = ?')->execute(['processing', $id]);

if (vendorFileQualifiesForAsync($file)) {
    logAdminAction((int)$admin['id'], 'queue_vendor_file_async', ['file_id' => $id]);
    jsonResponse(['message' => 'Large PDF queued for background processing. Check back shortly.', 'queued' => true]);
}

try {
    $result = processVendorFile($file, $model);
    logAdminAction((int)$admin['id'], 'process_vendor_file', [
        'file_id' => $id, 'imported' => $result['imported'], 'pending' => $result['pending'], 'warnings' => count($result['warnings']),
    ]);
    $msg = "Imported {$result['imported']} price rows.";
    if ($result['pending'] > 0) $msg .= " {$result['pending']} row(s) sent to the review queue.";
    jsonResponse(['message' => $msg] + $result);
} catch (Throwable $e) {
    db()->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ? WHERE id = ?')
        ->execute(['failed', $e->getMessage(), $id]);
    jsonResponse(['error' => 'Processing failed.', 'message' => $e->getMessage()], 500);
}
