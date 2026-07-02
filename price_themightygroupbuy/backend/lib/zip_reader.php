<?php
declare(strict_types=1);

// Deliberately tight, not padded — this is for the real "WhatsApp
// auto-zipped a few shared images into one download" case, not a general
// multi-page-document uploader. See Obsidian_pep_pricing_tool/wiki/analyses/
// 2026-07-02-zip-upload-spec.md for the reasoning (dev-only vault, not
// deployed — no code here should assume it exists on disk).
const ZIP_MAX_ENTRIES     = 3;
const ZIP_MAX_TOTAL_BYTES = 12 * 1024 * 1024;

const ZIP_ENTRY_MEDIA_TYPES = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf',
];

/**
 * Validates a zip's entries using statIndex() only — no decompression, so
 * safe to call at upload time before any content is trusted. Throws with an
 * admin-facing message on the first violation: a nested zip, a non-image/PDF
 * entry, too many entries, or too much combined uncompressed size. Rejects
 * rather than silently truncating to the first N entries — truncating risks
 * quietly dropping real pricing data with nobody noticing.
 * Returns the validated entries (name/index/ext), sorted into natural
 * filename order — vendors who split a price list into per-page files
 * almost always name them in page order ("page2.jpg" before "page10.jpg").
 */
function validateZipEntries(ZipArchive $zip): array {
    $entries   = [];
    $totalSize = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false || str_ends_with($stat['name'], '/')) continue;

        $ext = strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            throw new RuntimeException("ZIP contains a nested zip ({$stat['name']}) — not supported, re-upload without nesting.");
        }
        if (!isset(ZIP_ENTRY_MEDIA_TYPES[$ext])) {
            throw new RuntimeException("ZIP contains an unsupported file ({$stat['name']}) — only JPG, PNG, and PDF are allowed inside a price-list ZIP.");
        }

        $entries[]  = ['name' => $stat['name'], 'index' => $i, 'ext' => $ext];
        $totalSize += $stat['size'];
    }

    if (!$entries) {
        throw new RuntimeException('ZIP has no image or PDF files inside it.');
    }
    if (count($entries) > ZIP_MAX_ENTRIES) {
        throw new RuntimeException('ZIP has ' . count($entries) . ' files — max is ' . ZIP_MAX_ENTRIES . '. Split into multiple uploads.');
    }
    if ($totalSize > ZIP_MAX_TOTAL_BYTES) {
        $mb    = round($totalSize / 1024 / 1024, 1);
        $capMb = (int)(ZIP_MAX_TOTAL_BYTES / 1024 / 1024);
        throw new RuntimeException("ZIP contents are {$mb}MB uncompressed — max is {$capMb}MB. Split into multiple uploads.");
    }

    usort($entries, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $entries;
}

/**
 * Extracts a zip's image/PDF entries into Claude content blocks, treating
 * them as pages of one document rather than N separate uploads — one
 * extraction call covering all of them, in page order, so Claude can use
 * context from one page (e.g. a header) when reading another.
 */
function zipToContentBlocks(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open ZIP file.');
    }

    $entries = validateZipEntries($zip);

    $blocks = [];
    foreach ($entries as $entry) {
        $data = $zip->getFromIndex($entry['index']);
        if ($data === false) continue;
        $mediaType = ZIP_ENTRY_MEDIA_TYPES[$entry['ext']];
        $blockType = $entry['ext'] === 'pdf' ? 'document' : 'image';
        $blocks[]  = ['type' => $blockType, 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => base64_encode($data)]];
    }
    $zip->close();

    return $blocks;
}
