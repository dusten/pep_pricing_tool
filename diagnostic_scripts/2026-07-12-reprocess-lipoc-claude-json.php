<?php
declare(strict_types=1);

/**
 * Read-only reprocessing of every stored Claude call-log raw JSON response
 * mentioning Lipo-C/MIC/FAT BLASTER/FOCUS (see pc_claude_call_log,
 * populated by backend/lib/claude.php's logClaudeCall()). Used to resolve
 * the Lipo-C/MIC product-identity ambiguity (products 33/55) with ground
 * truth from the original extraction runs instead of guessing from the
 * current, already-tangled DB state. Confirmed Claude's own canonical_name
 * naming was inconsistent even for the identical real listing (same
 * vendor+file) reprocessed on different runs — see
 * Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-duplicate-product-audit.md
 * for the resulting fix (migration_scripts/2026-07-12-untangle_lipoc_mic_specs.php).
 */
chdir('/home/ec2-user/price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$stmt = db()->query(
    "SELECT l.id, l.vendor_file_id, l.created_at, l.call_type, l.parsed_ok, l.raw_response_text, f.vendor_id, v.display_name
     FROM pc_claude_call_log l
     LEFT JOIN pc_vendor_files f ON f.id = l.vendor_file_id
     LEFT JOIN pc_vendors v ON v.id = f.vendor_id
     WHERE l.raw_response_text LIKE '%lipo%' OR l.raw_response_text LIKE '%MIC(%' OR l.raw_response_text LIKE '%fat blaster%' OR l.raw_response_text LIKE '%FOCUS%'
     ORDER BY l.created_at"
);
$rows = $stmt->fetchAll();
echo "Found " . count($rows) . " Claude call log rows mentioning Lipo/MIC/FAT BLASTER/FOCUS\n\n";

foreach ($rows as $r) {
    echo "=== call_log_id={$r['id']} vendor={$r['display_name']} (vendor_id={$r['vendor_id']}) file_id={$r['vendor_file_id']} at {$r['created_at']} parsed_ok={$r['parsed_ok']} ===\n";

    // Same fence-stripping the live extractor does in lib/claude.php.
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $r['raw_response_text']));
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded) || !isset($decoded['prices'])) {
        echo "  (could not decode as extraction JSON, call_type={$r['call_type']}, skipping detail)\n\n";
        continue;
    }
    foreach ($decoded['prices'] as $p) {
        $name = $p['canonical_name'] ?? $p['name'] ?? '';
        if (stripos($name, 'lipo') !== false || stripos($name, 'MIC') !== false || stripos($name, 'fat blaster') !== false || stripos($name, 'focus') !== false) {
            echo "  RAW: " . json_encode($p) . "\n";
        }
    }
    echo "\n";
}
