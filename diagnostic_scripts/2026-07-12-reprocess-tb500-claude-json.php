<?php
declare(strict_types=1);

/**
 * Read-only reprocessing of every stored Claude call-log raw JSON response
 * mentioning TB-500/TB500/Thymosin/17-23 across all vendors. Used to
 * re-evaluate whether product 2 "TB-500" is the full-length Thymosin Beta-4
 * (B4) or the 7aa fragment, going back to each vendor's original extraction
 * text rather than the current (at-the-time incorrect) product assignment.
 * Found the large majority of vendors' plain "TB-500" line is actually B4 —
 * see Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-tb500-b4-reidentification.md
 * for the full per-vendor breakdown and the resulting fix
 * (migration_scripts/2026-07-12-reidentify_tb500_as_b4.php).
 */
chdir('/home/ec2-user/price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$stmt = db()->query(
    "SELECT l.id, l.vendor_file_id, l.created_at, l.call_type, l.parsed_ok, l.raw_response_text, f.vendor_id, v.display_name
     FROM pc_claude_call_log l
     LEFT JOIN pc_vendor_files f ON f.id = l.vendor_file_id
     LEFT JOIN pc_vendors v ON v.id = f.vendor_id
     WHERE l.raw_response_text LIKE '%TB-500%' OR l.raw_response_text LIKE '%TB500%' OR l.raw_response_text LIKE '%Thymosin%' OR l.raw_response_text LIKE '%17-23%'
     ORDER BY v.display_name, l.created_at"
);
$rows = $stmt->fetchAll();
echo "Found " . count($rows) . " Claude call log rows mentioning TB-500/TB500/Thymosin/17-23\n\n";

foreach ($rows as $r) {
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $r['raw_response_text']));
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded) || !isset($decoded['prices'])) {
        continue;
    }
    $matches = [];
    foreach ($decoded['prices'] as $p) {
        $name = $p['canonical_name'] ?? $p['name'] ?? '';
        $blob = json_encode($p);
        if (stripos($name, 'tb-500') !== false || stripos($name, 'tb500') !== false || stripos($blob, 'thymosin') !== false || stripos($blob, '17-23') !== false) {
            $matches[] = $p;
        }
    }
    if (!$matches) continue;
    echo "=== vendor={$r['display_name']} (vendor_id={$r['vendor_id']}) file_id={$r['vendor_file_id']} call_log_id={$r['id']} at {$r['created_at']} ===\n";
    foreach ($matches as $p) {
        echo "  RAW: " . json_encode($p) . "\n";
    }
    echo "\n";
}
