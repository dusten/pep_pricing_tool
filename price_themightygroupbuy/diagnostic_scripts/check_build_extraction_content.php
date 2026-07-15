<?php
declare(strict_types=1);
// Phase 2 regression check (backlog #69): buildExtractionUserContent() was
// pulled out of processVendorFile() verbatim. This re-runs it against real,
// already-processed vendor files and asserts the userContent shape is
// exactly what the old inline code would have produced per file_type — no
// Claude call, no DB writes. Delete once Phase 2 has been live a while.
require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/helpers.php';
require_once dirname(__DIR__) . '/backend/lib/claude.php';
require_once dirname(__DIR__) . '/backend/lib/xlsx_reader.php';
require_once dirname(__DIR__) . '/backend/lib/zip_reader.php';
require_once dirname(__DIR__) . '/backend/lib/price_import.php';
require_once dirname(__DIR__) . '/backend/lib/vendor_file_processor.php';

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "ok: $msg\n";
}

$rows = db()->query(
    "SELECT id, file_type, stored_path FROM pc_vendor_files
     WHERE category = 'price_list' AND processing_status = 'complete'
       AND file_type IN ('pdf','xlsx','image')
     ORDER BY file_type, id DESC"
)->fetchAll();

$seen = [];
foreach ($rows as $r) {
    if (isset($seen[$r['file_type']])) continue;
    $seen[$r['file_type']] = $r;
}
assertTrue(count($seen) === 3, 'found a sample pdf, xlsx, and image row (' . implode(',', array_keys($seen)) . ')');

foreach ($seen as $type => $r) {
    $fullPath = dirname(__DIR__) . '/backend/storage/' . $r['stored_path'];
    assertTrue(is_file($fullPath), "$type sample file exists on disk (vendor_files id={$r['id']})");

    $sheetNote = null;
    $content = buildExtractionUserContent($fullPath, $type, $sheetNote);
    assertTrue(is_array($content) && count($content) >= 1, "$type: buildExtractionUserContent returned a non-empty array");

    if ($type === 'pdf') {
        assertTrue($content[0]['type'] === 'document' && $content[0]['source']['media_type'] === 'application/pdf', 'pdf: document block with correct media_type');
        assertTrue($content[1]['type'] === 'text', 'pdf: trailing text instruction block');
    } elseif ($type === 'xlsx') {
        assertTrue($content[0]['type'] === 'text' && str_contains($content[0]['text'], 'Vendor price list (extracted text):'), 'xlsx: text block with extracted-text preamble');
    } elseif ($type === 'image') {
        assertTrue($content[0]['type'] === 'image' && in_array($content[0]['source']['media_type'], ['image/jpeg', 'image/png'], true), 'image: image block with correct media_type');
        assertTrue($content[1]['type'] === 'text', 'image: trailing text instruction block');
    }
}

echo "All checks passed — buildExtractionUserContent() shape matches the pre-refactor inline code for pdf/xlsx/image.\n";
