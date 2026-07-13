<?php
declare(strict_types=1);

/**
 * Read-only: scans every parsed Claude call-log response for price entries
 * that collide on (canonical_name, spec_label, tier_kit_size) but carry
 * different vendor_sku values within the SAME extraction -- i.e. a vendor's
 * price list genuinely lists the same product/spec/tier twice under
 * different catalog codes. Before migration 030
 * (database/migrations/030_vendor_sku_uniqueness.sql), pc_prices' uniqueness
 * constraint didn't include vendor_sku, so the second entry silently
 * overwrote the first on every (re)import, discarding one real listing and
 * logging a fake "price change" in pc_price_history. Found via Purelypep
 * Factory's KPV 10mg (sku "KPV10" vs "KP10") -- see
 * Obsidian_pep_pricing_tool/wiki/analyses/2026-07-12-price-history-tier-and-sku-collision.md.
 * Confirmed 115 colliding pairs across 26 distinct extraction runs, not
 * isolated to this one vendor/product.
 */
chdir('/home/ec2-user/price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';

$rows = db()->query("SELECT id, raw_response_text FROM pc_claude_call_log WHERE parsed_ok=1")->fetchAll();
$collisions = 0; $filesWithCollision = [];
foreach ($rows as $r) {
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $r['raw_response_text']));
    $start = strpos($text, '{'); $end = strrpos($text, '}');
    if ($start === false || $end === false) continue;
    $text = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($text, true);
    if (!is_array($decoded) || !isset($decoded['prices'])) continue;
    $seen = [];
    foreach ($decoded['prices'] as $p) {
        $key = strtolower(trim($p['canonical_name'] ?? '')) . '|' . strtolower(trim($p['spec_label'] ?? '')) . '|' . ($p['tier_kit_size'] ?? 1);
        $sku = $p['vendor_sku'] ?? '';
        if (isset($seen[$key]) && $seen[$key] !== $sku) {
            $collisions++;
            $filesWithCollision[$r['id']] = true;
        }
        $seen[$key] = $sku;
    }
}
echo "Total colliding price-slot pairs: $collisions\n";
echo "Distinct call_log entries affected: " . count($filesWithCollision) . "\n";
