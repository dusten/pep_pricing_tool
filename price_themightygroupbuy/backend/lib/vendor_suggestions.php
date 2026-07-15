<?php
declare(strict_types=1);

// User-suggested vendors (backlog #69), Phase 1. Template CSV parse + score
// live here; Phase 2 adds processSuggestion() (Claude-pipeline fallback) to
// this same file. Reuses the extraction price-row shape everywhere so
// commitExtractionResult() (backend/lib/vendor_file_processor.php) works
// unmodified at accept time regardless of which path produced the rows.

const SUGGESTION_TEMPLATE_CSV_HEADER = ['product_name', 'spec', 'price_usd', 'kit_vial_count', 'tier_kit_size', 'vendor_sku'];

/**
 * Strict-template CSV parser — the downloadable header from
 * SuggestVendorView.vue. Deliberately much stricter than the Claude
 * extraction path (no tiered-pricing inference, no unit conversion beyond
 * mcg->mg): a bad row is skipped + warned rather than guessed at, since the
 * whole point of the template is an instant, no-Claude parse. Rule 5 of
 * buildExtractionSystemPrompt() covers spec normalization; mirrored here for
 * the mcg case since that's the one non-mg unit worth supporting in a
 * hand-filled template.
 */
function parseSuggestionTemplateCsv(string $path): array {
    $rows = [];
    $warnings = [];
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('Could not open the uploaded file.');

    try {
        $header = fgetcsv($fh);
        if ($header === false) throw new RuntimeException('CSV file is empty.');
        $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
        if ($header !== SUGGESTION_TEMPLATE_CSV_HEADER) {
            throw new RuntimeException('Header does not match the CSV template.');
        }

        $lineNo = 1;
        while (($cols = fgetcsv($fh)) !== false) {
            $lineNo++;
            if (count($cols) === 1 && trim((string)$cols[0]) === '') continue; // blank line

            $row = array_combine(SUGGESTION_TEMPLATE_CSV_HEADER, array_pad($cols, 6, ''));
            $name  = trim((string)$row['product_name']);
            $spec  = trim((string)$row['spec']);
            $price = (float)$row['price_usd'];

            if (!$name || $price <= 0) {
                $warnings[] = "Row $lineNo skipped: missing product name or price.";
                continue;
            }
            if (!preg_match('/^(\d+(?:\.\d+)?)\s*(mg|mcg|iu|ml)$/i', $spec, $m)) {
                $warnings[] = "Row $lineNo skipped: spec \"$spec\" doesn't match a recognized dose format (e.g. 10mg).";
                continue;
            }
            $value = (float)$m[1];
            $unit  = strtolower($m[2]);
            if ($unit === 'mcg') { $value /= 1000; $unit = 'mg'; } // matches extraction prompt rule 5

            $rows[] = [
                'canonical_name'   => $name,
                'spec_label'       => $value . $unit,
                'numeric_value'    => $value,
                'unit'             => $unit,
                'price_usd'        => $price,
                'kit_vial_count'   => max(1, (int)$row['kit_vial_count'] ?: 1),
                'tier_kit_size'    => max(1, (int)$row['tier_kit_size'] ?: 1),
                'vendor_sku'       => trim((string)$row['vendor_sku']),
                'non_standard_kit' => false,
            ];
        }
    } finally {
        fclose($fh);
    }

    if (!$rows) throw new RuntimeException('No valid price rows found in the file.');

    return ['contact' => [], 'warnings' => $warnings, 'prices' => $rows];
}

/**
 * Scores tier-1 rows against the live market so a submitter/admin sees where
 * these prices would land without waiting for acceptance. Mirrors the
 * min-ppu-per-(product,spec) subquery in getVendorScorecard()
 * (backend/lib/vendor_helpers.php) but pulls the whole distribution (not
 * just the min) since percentiles need it.
 * ponytail: naive composite, reweight when real submissions exist.
 */
function scoreSuggestionPrices(PDO $pdo, array $prices): array {
    $tier1 = array_values(array_filter($prices, fn($p) => (int)($p['tier_kit_size'] ?? 1) === 1));

    $totalRows = count($prices);
    $matched = [];       // list of ['ppu' => float, 'percentile' => float]
    $unmatchedNames = [];

    // Resolve (product_id, spec_label) pairs first so one query can pull
    // every market ppu needed instead of one query per row.
    $resolved = [];
    foreach ($tier1 as $p) {
        $productId = findExactProductMatch($pdo, $p['canonical_name']);
        if ($productId === null) {
            $unmatchedNames[] = $p['canonical_name'];
            continue;
        }
        $ppu = pricePerUnit((float)$p['price_usd'], (int)$p['kit_vial_count'], (float)$p['numeric_value']);
        $resolved[] = ['product_id' => $productId, 'spec_label' => $p['spec_label'], 'ppu' => $ppu];
    }

    if ($resolved) {
        $pairs = [];
        $params = [];
        foreach ($resolved as $r) {
            $pairs[] = '(pr.product_id = ? AND s.spec_label = ?)';
            $params[] = $r['product_id'];
            $params[] = $r['spec_label'];
        }
        $stmt = $pdo->prepare(
            "SELECT pr.product_id, s.spec_label, pr.price_per_unit
             FROM pc_prices pr
             JOIN pc_specifications s ON s.id = pr.specification_id
             JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1
             WHERE pr.is_active = 1 AND pr.tier_kit_size = 1 AND (" . implode(' OR ', $pairs) . ')'
        );
        $stmt->execute($params);
        $market = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['product_id'] . '|' . $row['spec_label'];
            $market[$key][] = (float)$row['price_per_unit'];
        }

        foreach ($resolved as $r) {
            $key = $r['product_id'] . '|' . $r['spec_label'];
            $marketPpus = $market[$key] ?? [];
            if (!$marketPpus) {
                // Matched product/spec exists, but no active market listing to compare
                // against — count as matched (it IS a known product) but skip percentile.
                continue;
            }
            sort($marketPpus);
            $n = count($marketPpus);
            $below = 0;
            foreach ($marketPpus as $ppu) { if ($r['ppu'] > $ppu) $below++; }
            $percentile = round($below / $n * 100, 1); // 0 = cheapest, 100 = priciest
            $matched[] = $percentile;
        }
    }

    $matchedRows = count($resolved);
    if ($matchedRows === 0) {
        return [
            'total_rows' => $totalRows, 'matched_rows' => 0, 'unmatched_names' => $unmatchedNames,
            'would_be_cheapest_pct' => null, 'below_median_pct' => null, 'avg_percentile' => null,
            'vendor_score' => null, 'note' => 'Not enough catalog overlap to score this vendor yet.',
        ];
    }

    $withPercentile = count($matched);
    $avgPercentile = $withPercentile ? round(array_sum($matched) / $withPercentile, 1) : null;
    $cheapestCount = count(array_filter($matched, fn($p) => $p <= 0.000001));
    $belowMedianCount = count(array_filter($matched, fn($p) => $p < 50));

    return [
        'total_rows'    => $totalRows,
        'matched_rows'  => $matchedRows,
        'unmatched_names' => $unmatchedNames,
        'would_be_cheapest_pct' => $withPercentile ? round($cheapestCount / $withPercentile * 100, 1) : null,
        'below_median_pct'      => $withPercentile ? round($belowMedianCount / $withPercentile * 100, 1) : null,
        'avg_percentile' => $avgPercentile,
        'vendor_score'   => $avgPercentile !== null ? (int)round(100 - $avgPercentile) : null,
    ];
}

/**
 * Build-phase gate (backlog #69) — the feature is visible only to
 * test_account users and admins while Phase 2/3 aren't built yet. 404 (not
 * 403) so the endpoint's existence isn't disclosed to accounts outside the
 * gate, matching the router guard's silent redirect on the frontend.
 * ponytail: build-phase gate, delete at launch (Phase 3).
 */
function requireSuggestionAccess(): array {
    $user = requireAuth();
    if (empty($user['test_account']) && empty($user['is_admin'])) {
        jsonResponse(['error' => 'Not found.'], 404);
    }
    return $user;
}
