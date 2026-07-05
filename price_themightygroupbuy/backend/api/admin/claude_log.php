<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/claude-log — recent raw Claude API call history (backlog #24).
// Response previews are truncated; fetch /admin/claude-log/{id} for the full text.
method('GET');
requireAdmin();

$rows = db()->query(
    "SELECT ccl.id, ccl.vendor_file_id, vf.original_filename, v.display_name AS vendor_name,
            ccl.call_type, ccl.model, ccl.http_status, ccl.stop_reason,
            ccl.input_tokens, ccl.output_tokens, ccl.cache_creation_input_tokens, ccl.cache_read_input_tokens,
            ccl.parsed_ok, ccl.error_message, ccl.created_at,
            LEFT(ccl.raw_response_text, 200) AS response_preview
     FROM pc_claude_call_log ccl
     LEFT JOIN pc_vendor_files vf ON vf.id = ccl.vendor_file_id
     LEFT JOIN pc_vendors v       ON v.id = vf.vendor_id
     ORDER BY ccl.id DESC LIMIT 200"
)->fetchAll();

foreach ($rows as &$r) {
    $r['id']                          = (int)$r['id'];
    $r['vendor_file_id']              = $r['vendor_file_id'] !== null ? (int)$r['vendor_file_id'] : null;
    $r['http_status']                 = $r['http_status'] !== null ? (int)$r['http_status'] : null;
    $r['input_tokens']                = $r['input_tokens'] !== null ? (int)$r['input_tokens'] : null;
    $r['output_tokens']               = $r['output_tokens'] !== null ? (int)$r['output_tokens'] : null;
    $r['cache_creation_input_tokens'] = $r['cache_creation_input_tokens'] !== null ? (int)$r['cache_creation_input_tokens'] : null;
    $r['cache_read_input_tokens']     = $r['cache_read_input_tokens'] !== null ? (int)$r['cache_read_input_tokens'] : null;
    $r['parsed_ok']                   = (bool)$r['parsed_ok'];
}

jsonResponse(['calls' => $rows]);
