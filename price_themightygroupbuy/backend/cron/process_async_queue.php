<?php
declare(strict_types=1);
// Picks up large PDFs that files/process.php queued instead of processing
// inline (see vendorFileQualifiesForAsync()). Those rows sit at
// processing_status='processing' with no further action until this runs.
// ponytail: cron polling one table, not a message queue — matches the
// upload volume here (manual admin uploads, not high-throughput).
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/lib/claude.php';
require_once dirname(__DIR__) . '/lib/xlsx_reader.php';
require_once dirname(__DIR__) . '/lib/zip_reader.php';
require_once dirname(__DIR__) . '/lib/price_import.php';
require_once dirname(__DIR__) . '/lib/vendor_file_processor.php';

// Filter matches vendorFileQualifiesForAsync() exactly — files/process.php never
// leaves a small/non-pdf file sitting at 'processing' for long, but this guards
// against the cron picking up a file that's genuinely mid-flight in a concurrent
// synchronous request instead of one it actually queued.
$stmt = db()->query(
    "SELECT * FROM pc_vendor_files
     WHERE processing_status = 'processing' AND category = 'price_list'
       AND file_type = 'pdf' AND file_size_bytes > " . ASYNC_PDF_SIZE_THRESHOLD_BYTES
);
$queued = $stmt->fetchAll();

$ran = 0;
foreach ($queued as $file) {
    try {
        processVendorFile($file, CLAUDE_MODEL_DEFAULT);
        $ran++;
    } catch (Throwable $e) {
        db()->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ? WHERE id = ?')
            ->execute(['failed', $e->getMessage(), $file['id']]);
    }
}

db()->prepare('INSERT INTO pc_maintenance_runs (job, status, details) VALUES (?,?,?)')
    ->execute(['process_async_vendor_files', 'ok', "processed=$ran queued=" . count($queued)]);
