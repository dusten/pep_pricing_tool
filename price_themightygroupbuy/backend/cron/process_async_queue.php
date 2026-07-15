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
require_once dirname(__DIR__) . '/lib/vendor_helpers.php';
require_once dirname(__DIR__) . '/lib/vendor_suggestions.php';
require_once dirname(__DIR__) . '/email.php';

// Overlap guard (backlog #31): one extraction can run longer than the cron
// interval (curl timeout is 400s), so without this two ticks could process the
// same still-'processing' file twice — double Claude spend, double history
// writes. GET_LOCK over a schema claim column on purpose: the lock releases
// automatically when the holder's connection closes, so a crashed run can
// never leave a file stuck in a claimed state.
if (!(int)db()->query("SELECT GET_LOCK('pc_process_async_queue', 0)")->fetchColumn()) {
    db()->prepare('INSERT INTO pc_maintenance_runs (job, status, details) VALUES (?,?,?)')
        ->execute(['process_async_vendor_files', 'ok', 'skipped — previous run still in progress']);
    exit;
}

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

// ── Vendor suggestions (backlog #69 Phase 2) ─────────────────────────
// Unlike vendor files above (already marked 'processing' by the synchronous
// request that queued them), suggestions land here straight from submit
// with no prior claim, so the claim itself has to be atomic: UPDATE...LIMIT
// picks the row set, then a second SELECT by id fetches the rows this
// process actually won, closing the race a plain SELECT-then-UPDATE has
// against a second cron tick under the same GET_LOCK window (belt-and-braces
// with the lock, not a replacement for it).
$pending = db()->query(
    "SELECT id FROM pc_vendor_suggestions WHERE status = 'pending_parse' ORDER BY created_at LIMIT 20"
)->fetchAll(PDO::FETCH_COLUMN);

$suggestionsRan = 0;
if ($pending) {
    $claim = db()->prepare("UPDATE pc_vendor_suggestions SET status = 'processing' WHERE id = ? AND status = 'pending_parse'");
    foreach ($pending as $id) {
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) continue; // lost the race to another tick

        $stmt = db()->prepare('SELECT * FROM pc_vendor_suggestions WHERE id = ?');
        $stmt->execute([$id]);
        $suggestion = $stmt->fetch();
        if (!$suggestion) continue;

        // processSuggestion() catches its own failures (-> parse_failed), never throws.
        processSuggestion($suggestion, CLAUDE_MODEL_DEFAULT);
        $suggestionsRan++;
    }
}

db()->prepare('INSERT INTO pc_maintenance_runs (job, status, details) VALUES (?,?,?)')
    ->execute(['process_vendor_suggestions', 'ok', "processed=$suggestionsRan queued=" . count($pending)]);
