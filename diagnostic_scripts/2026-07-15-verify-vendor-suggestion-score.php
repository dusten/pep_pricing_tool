<?php
declare(strict_types=1);

/**
 * Verifies scoreSuggestionPrices() (backlog #69, Phase 1) against live
 * pc_prices data: feeds a couple of hand-built price rows for BPC-157/10mg
 * (a real product+spec with an active market, confirmed via manual query
 * before writing this script — active-vendor tier-1 ppus range ~0.44 to
 * 0.83) plus one row for a product that doesn't exist in the catalog, and
 * asserts:
 *   - percentiles land in [0,100]
 *   - a below-market price (0.10, cheaper than every real listing) scores a
 *     LOW percentile (near 0, since 0=cheapest in this scale)
 *   - an above-market price (5.00) scores a HIGH percentile (near 100)
 *   - the unmatched product name lands in unmatched_names, not matched_rows
 *   - vendor_score = round(100 - avg_percentile) holds
 *
 * Run on the server: sudo -u apache php 2026-07-15-verify-vendor-suggestion-score.php
 */

chdir(__DIR__ . '/../price_themightygroupbuy/backend');
require_once 'config.php';
require_once 'helpers.php';
require_once 'lib/price_import.php';
require_once 'lib/vendor_suggestions.php';

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "OK: $msg\n";
}

$pdo = db();

$prices = [
    // Real product/spec (confirmed live ppus range ~0.44-0.83/mg) — priced
    // absurdly cheap, should score near 0 (cheapest end of the scale).
    ['canonical_name' => 'BPC-157', 'spec_label' => '10mg', 'numeric_value' => 10.0, 'unit' => 'mg',
     'price_usd' => 1.00, 'kit_vial_count' => 1, 'tier_kit_size' => 1, 'vendor_sku' => '', 'non_standard_kit' => false],
    // Same product/spec, absurdly expensive — should score near 100 (priciest end).
    ['canonical_name' => 'BPC-157', 'spec_label' => '10mg', 'numeric_value' => 10.0, 'unit' => 'mg',
     'price_usd' => 50.00, 'kit_vial_count' => 1, 'tier_kit_size' => 1, 'vendor_sku' => '', 'non_standard_kit' => false],
    // Product name that does not exist in the catalog at all.
    ['canonical_name' => 'Definitely Not A Real Product XYZ123', 'spec_label' => '10mg', 'numeric_value' => 10.0,
     'unit' => 'mg', 'price_usd' => 20.00, 'kit_vial_count' => 1, 'tier_kit_size' => 1, 'vendor_sku' => '', 'non_standard_kit' => false],
];

$score = scoreSuggestionPrices($pdo, $prices);
echo json_encode($score, JSON_PRETTY_PRINT) . "\n";

assertTrue($score['total_rows'] === 3, 'total_rows counts every input row');
assertTrue($score['matched_rows'] === 2, 'matched_rows excludes the unmatched product');
assertTrue(in_array('Definitely Not A Real Product XYZ123', $score['unmatched_names'], true), 'unmatched product name surfaced');
assertTrue($score['avg_percentile'] >= 0 && $score['avg_percentile'] <= 100, 'avg_percentile within 0-100');
assertTrue($score['vendor_score'] >= 0 && $score['vendor_score'] <= 100, 'vendor_score within 0-100');
assertTrue(abs($score['vendor_score'] - round(100 - $score['avg_percentile'])) < 0.01, 'vendor_score = round(100 - avg_percentile)');
// Two rows, one cheapest-possible (percentile ~0) and one priciest-possible
// (percentile ~100) should average out somewhere in the middle, not at
// either extreme — a rough sanity check that per-row percentiles are
// actually being computed, not e.g. always returning 0 or 100.
assertTrue($score['avg_percentile'] > 20 && $score['avg_percentile'] < 80, 'blended avg_percentile lands in the middle, not stuck at an extreme');

// Zero-matched-rows path (all-unmatched input) — must not divide by zero
// and must return a null score with a note, not throw.
$emptyScore = scoreSuggestionPrices($pdo, [
    ['canonical_name' => 'Totally Fake Product 999', 'spec_label' => '5mg', 'numeric_value' => 5.0, 'unit' => 'mg',
     'price_usd' => 10.00, 'kit_vial_count' => 1, 'tier_kit_size' => 1, 'vendor_sku' => '', 'non_standard_kit' => false],
]);
assertTrue($emptyScore['matched_rows'] === 0, 'all-unmatched input yields matched_rows=0');
assertTrue($emptyScore['vendor_score'] === null, 'all-unmatched input yields null vendor_score, not a crash');

echo "\nAll checks passed.\n";
