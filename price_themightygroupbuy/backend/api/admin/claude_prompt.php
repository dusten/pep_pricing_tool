<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/claude.php';

// GET /admin/claude-prompt — the current system prompts, live (the extraction
// prompt embeds the current product catalog, so this reflects right now, not
// a stale cached copy).
method('GET');
requireAdmin();

jsonResponse([
    'extraction_prompt'           => buildExtractionSystemPrompt(),
    'vendor_contact_parse_prompt' => buildVendorContactParsePrompt(),
]);
