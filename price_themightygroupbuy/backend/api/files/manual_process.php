<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_file_processor.php';

// POST /files/{id}/manual-process — commit a hand-supplied extraction JSON
// (e.g. run through Grok or any other tool, or hand-corrected) through the
// exact same commit logic real Claude extractions use, without calling
// Claude at all. body: { json: "<the JSON text>" }
method('POST');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pc_vendor_files WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) jsonResponse(['error' => 'File not found.'], 404);

$body   = input();
$raw    = (string)($body['json'] ?? '');
$parsed = json_decode($raw, true);
if (!is_array($parsed) || !isset($parsed['prices']) || !is_array($parsed['prices'])) {
    jsonResponse(['error' => 'Pasted JSON must decode to an object with a "prices" array — same shape the extraction pipeline expects.'], 422);
}

db()->prepare('UPDATE pc_vendor_files SET processing_status = ? WHERE id = ?')->execute(['processing', $id]);
try {
    $result = commitExtractionResult($file, $parsed);
    logAdminAction((int)$admin['id'], 'manual_process_vendor_file', [
        'file_id' => $id, 'imported' => $result['imported'], 'unchanged' => $result['unchanged'], 'pending' => $result['pending'],
    ]);
    $msg = "Imported {$result['imported']} price row(s).";
    if ($result['unchanged'] > 0) $msg .= " ({$result['unchanged']} unchanged)";
    if ($result['pending'] > 0) $msg .= " {$result['pending']} row(s) sent to the review queue.";
    jsonResponse(['message' => $msg] + $result);
} catch (Throwable $e) {
    db()->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ? WHERE id = ?')
        ->execute(['failed', $e->getMessage(), $id]);
    jsonResponse(['error' => 'Manual processing failed.', 'message' => $e->getMessage()], 500);
}
