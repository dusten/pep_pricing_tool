<?php
declare(strict_types=1);

/**
 * Shared GET-param parsing for every endpoint that runs a comparison query
 * (live view + both export formats) — one place so filter semantics can't
 * drift between them.
 */
function parseComparisonFiltersFromGet(): array {
    return [
        array_map('intval', (array)($_GET['products'] ?? [])),
        array_map('intval', (array)($_GET['vendors']  ?? [])),
        array_map('intval', (array)($_GET['specs']    ?? [])),
        array_map('intval', (array)($_GET['classification_ids'] ?? [])),
        in_array($_GET['multi_only'] ?? '', ['1', 'true'], true),
        in_array($_GET['verified_only'] ?? '', ['1', 'true'], true),
        max(1, (int)($_GET['tier'] ?? 1)),
        in_array($_GET['raw_material_only'] ?? '', ['1', 'true'], true),
    ];
}

/**
 * Shared by the comparison endpoint and the admin query-log "re-run" tool —
 * one place for the query shape so the two never drift out of sync.
 */
function runComparisonQuery(array $productIds, array $vendorIds, array $specIds, array $classificationIds, bool $multiOnly, bool $verifiedOnly = false, int $tierKitSize = 1, bool $rawMaterialOnly = false): array {
    $where  = ['pr.is_active = 1', 'v.is_active = 1', 'pr.tier_kit_size = ?'];
    $params = [$tierKitSize];

    if ($classificationIds) {
        // Inclusive (OR) match — a product tagged with ANY of the selected
        // classifications qualifies, not all of them.
        $where[] = 'EXISTS (SELECT 1 FROM pc_product_classifications pc WHERE pc.product_id = p.id AND pc.classification_id IN (' . implode(',', array_fill(0, count($classificationIds), '?')) . '))';
        array_push($params, ...$classificationIds);
    }
    if ($productIds) { $where[] = 'pr.product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')'; array_push($params, ...$productIds); }
    if ($vendorIds)  { $where[] = 'pr.vendor_id IN ('  . implode(',', array_fill(0, count($vendorIds), '?'))  . ')'; array_push($params, ...$vendorIds); }
    if ($specIds)    { $where[] = 'pr.specification_id IN (' . implode(',', array_fill(0, count($specIds), '?')) . ')'; array_push($params, ...$specIds); }
    if ($verifiedOnly) { $where[] = 'v.is_verified = 1'; }
    // Raw-ness lives on the spec, not a product-level classification tag — one
    // product can have both a finished-vial spec and a raw-powder spec, so
    // this must filter individual rows, not just narrow which products show.
    if ($rawMaterialOnly) { $where[] = 's.is_raw_material = 1'; }

    $sql = "SELECT pr.vendor_id, v.display_name AS vendor_name, v.is_verified,
                   pr.product_id, p.canonical_name,
                   pr.specification_id, s.spec_label, s.numeric_value, s.unit, s.is_raw_material,
                   pr.price_usd, pr.price_per_unit, pr.kit_vial_count, pr.non_standard_kit, pr.source_file_id, pr.vendor_sku
            FROM pc_prices pr
            JOIN pc_products p       ON p.id = pr.product_id
            JOIN pc_specifications s ON s.id = pr.specification_id
            JOIN pc_vendors v        ON v.id = pr.vendor_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.canonical_name ASC, s.numeric_value ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $grouped = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = $r['product_id'] . ':' . $r['specification_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'product'          => $r['canonical_name'],
                'product_id'       => (int)$r['product_id'],
                'specification_id' => (int)$r['specification_id'],
                'spec'             => $r['spec_label'],
                'unit'             => $r['unit'],
                'numeric_value'    => (float)$r['numeric_value'],
                'is_raw_material'  => (bool)$r['is_raw_material'],
                'vendors'          => [],
            ];
        }
        $grouped[$key]['vendors'][] = [
            'vendor_id'        => (int)$r['vendor_id'],
            'name'             => $r['vendor_name'],
            'is_verified'      => (bool)$r['is_verified'],
            'price'            => (float)$r['price_usd'],
            'price_per_unit'   => (float)$r['price_per_unit'],
            'kit_vial_count'   => (int)$r['kit_vial_count'],
            'non_standard_kit' => (bool)$r['non_standard_kit'],
            'source_file_id'   => $r['source_file_id'] !== null ? (int)$r['source_file_id'] : null,
            'vendor_sku'       => $r['vendor_sku'],
        ];
    }

    $rows = [];
    foreach ($grouped as $row) {
        if ($multiOnly && count($row['vendors']) < 2) continue;

        // $/unit drives is_lowest/min/max — the fair cross-vendor comparison
        // when kit sizes differ. Avg/Median are the summary columns next to
        // each vendor's kit Price column, so they average the kit price
        // (price_usd), not $/unit.
        $ppus = array_column($row['vendors'], 'price_per_unit');
        sort($ppus);
        $n = count($ppus);
        $min = min($ppus);
        foreach ($row['vendors'] as &$v) {
            $v['is_lowest'] = abs($v['price_per_unit'] - $min) < 0.000001;
        }

        $prices = array_column($row['vendors'], 'price');
        sort($prices);
        $priceMedian = $n % 2 === 0 ? ($prices[$n / 2 - 1] + $prices[$n / 2]) / 2 : $prices[(int)floor($n / 2)];

        $row['stats'] = [
            'avg'    => round(array_sum($prices) / $n, 6),
            'median' => round($priceMedian, 6),
            'min'    => $min,
            'max'    => max($ppus),
        ];
        $rows[] = $row;
    }

    return $rows;
}
