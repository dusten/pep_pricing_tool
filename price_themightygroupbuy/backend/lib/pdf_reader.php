<?php
declare(strict_types=1);

/**
 * Below this many extracted characters, treat the PDF as having no real text
 * layer (scanned/flattened image) rather than genuine but sparse content —
 * a 2026-08-06 audit of every vendor PDF on file found a clean split: the
 * two actually-scanned files extracted 0 characters, every real text-layer
 * file extracted 400+ (smallest real one was 451, for a single-page,
 * dozen-row list). This threshold has margin on both sides of that gap.
 */
const PDF_MIN_TEXT_CHARS = 150;

/**
 * Pre-extracts a PDF's text layer via pdftotext (poppler-utils) so a
 * text-native vendor price list can be sent to Claude as plain text instead
 * of the full PDF document — cheaper (no page-image rendering on Claude's
 * side) and, per the 2026-08-06 IGF-1/MOTS-c incident, less prone to
 * table-row misalignment than vision-reading a rendered page image.
 * Returns null (never throws) on any failure — missing binary, corrupt PDF,
 * a genuinely scanned/image-only PDF — so the caller can fall back to
 * sending the raw PDF document exactly as before.
 */
function pdfToText(string $path): ?string {
    $escaped = escapeshellarg($path);
    exec("pdftotext {$escaped} - 2>/dev/null", $output, $exitCode);
    if ($exitCode !== 0) return null;

    $text = implode("\n", $output);
    if (mb_strlen(trim(preg_replace('/\s+/', '', $text) ?? '')) < PDF_MIN_TEXT_CHARS) return null;

    return $text;
}
