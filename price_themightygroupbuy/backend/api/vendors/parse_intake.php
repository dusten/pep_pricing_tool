<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_intake_parser.php';
require_once dirname(__DIR__, 2) . '/lib/claude.php';

// POST /vendors/parse-intake  body: { text }
// Regex-first parse of the pasted vendor reply; falls back to Claude only
// when the pattern match can't resolve enough fields. Preview only — the
// admin reviews/edits the result client-side before actually saving it via
// the normal vendor create/update endpoint.
method('POST');
requireAdmin();

$text = trim((string)(input()['text'] ?? ''));
if ($text === '') jsonResponse(['error' => 'Pasted text is required.'], 422);

$fields = parseVendorIntakeText($text);
$usedFallback = false;

// Fewer than 2 resolved fields (including the name) almost certainly means
// the vendor replied in prose rather than following the template.
if (vendorIntakeResolvedCount($fields) < 2) {
    try {
        $fields = callClaudeVendorContactParse($text);
        $usedFallback = true;
    } catch (Throwable $e) {
        jsonResponse(['error' => 'Could not parse this reply.', 'message' => $e->getMessage()], 422);
    }
}

jsonResponse(['fields' => $fields, 'used_ai_fallback' => $usedFallback]);
