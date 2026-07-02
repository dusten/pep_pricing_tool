<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/claude.php';
require_once dirname(__DIR__, 2) . '/lib/xlsx_reader.php';
require_once dirname(__DIR__, 2) . '/lib/zip_reader.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_file_processor.php';

// POST /files/process-all — processes every pending vendor file, one at a
// time, in a single request. Deliberately sequential rather than parallel:
// consecutive calls share the exact same system prompt text, so everything
// after the first file reads the prompt cache instead of paying full price,
// as long as the whole batch finishes inside the cache's 5-minute TTL.
//
// This can legitimately run for minutes across a real batch upload — see
// price.conf's matching <Location> block for the paired Apache timeout.
method('POST');
$admin = requireAdmin();
$model = (input()['model'] ?? '') === 'opus' ? CLAUDE_MODEL_HARD : CLAUDE_MODEL_DEFAULT;

set_time_limit(900);

$files = db()->query("SELECT * FROM pc_vendor_files WHERE processing_status = 'pending' ORDER BY uploaded_at ASC")->fetchAll();

$results = [];
foreach ($files as $file) {
    db()->prepare('UPDATE pc_vendor_files SET processing_status = ? WHERE id = ?')->execute(['processing', $file['id']]);

    if (vendorFileQualifiesForAsync($file)) {
        $results[] = ['id' => (int)$file['id'], 'filename' => $file['original_filename'], 'queued' => true];
        continue;
    }

    try {
        $r = processVendorFile($file, $model);
        $results[] = [
            'id' => (int)$file['id'], 'filename' => $file['original_filename'],
            'imported' => $r['imported'], 'pending' => $r['pending'],
        ];
    } catch (Throwable $e) {
        db()->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ? WHERE id = ?')
            ->execute(['failed', $e->getMessage(), $file['id']]);
        $results[] = ['id' => (int)$file['id'], 'filename' => $file['original_filename'], 'error' => $e->getMessage()];
    }
}

logAdminAction((int)$admin['id'], 'process_all_vendor_files', ['count' => count($results)]);
jsonResponse(['message' => count($results) . ' file(s) processed.', 'results' => $results]);
