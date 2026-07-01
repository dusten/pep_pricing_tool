<?php
declare(strict_types=1);

/**
 * Shared by the comparison endpoint and the admin query-log "re-run" tool —
 * one place for the query shape so the two never drift out of sync.
 */
function runComparisonQuery(array $productIds, array $vendorIds, array $specIds, ?string $category, bool $multiOnly): array {
    $where  = ['pr.is_active = 1', 'v.is_active = 1'];
    $params = [];

    if ($category) { $where[] = 'p.category = ?'; $params[] = $category; }
    if ($productIds) { $where[] = 'pr.product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')'; array_push($params, ...$productIds); }
    if ($vendorIds)  { $where[] = 'pr.vendor_id IN ('  . implode(',', array_fill(0, count($vendorIds), '?'))  . ')'; array_push($params, ...$vendorIds); }
    if ($specIds)    { $where[] = 'pr.specification_id IN (' . implode(',', array_fill(0, count($specIds), '?')) . ')'; array_push($params, ...$specIds); }

    $sql = "SELECT pr.vendor_id, v.display_name AS vendor_name,
                   pr.product_id, p.canonical_name, p.category,
                   pr.specification_id, s.spec_label, s.numeric_value, s.unit,
                   pr.price_usd, pr.price_per_unit, pr.kit_vial_count, pr.non_standard_kit, pr.source_file_id
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
                'product'       => $r['canonical_name'],
                'product_id'    => (int)$r['product_id'],
                'category'      => $r['category'],
                'spec'          => $r['spec_label'],
                'unit'          => $r['unit'],
                'numeric_value' => (float)$r['numeric_value'],
                'vendors'       => [],
            ];
        }
        $grouped[$key]['vendors'][] = [
            'vendor_id'        => (int)$r['vendor_id'],
            'name'             => $r['vendor_name'],
            'price'            => (float)$r['price_usd'],
            'price_per_unit'   => (float)$r['price_per_unit'],
            'kit_vial_count'   => (int)$r['kit_vial_count'],
            'non_standard_kit' => (bool)$r['non_standard_kit'],
            'source_file_id'   => $r['source_file_id'] !== null ? (int)$r['source_file_id'] : null,
        ];
    }

    $rows = [];
    foreach ($grouped as $row) {
        if ($multiOnly && count($row['vendors']) < 2) continue;

        $ppus = array_column($row['vendors'], 'price_per_unit');
        sort($ppus);
        $n = count($ppus);
        $median = $n % 2 === 0 ? ($ppus[$n / 2 - 1] + $ppus[$n / 2]) / 2 : $ppus[(int)floor($n / 2)];
        $min = min($ppus);
        foreach ($row['vendors'] as &$v) {
            $v['is_lowest'] = abs($v['price_per_unit'] - $min) < 0.000001;
        }

        $row['stats'] = [
            'avg'    => round(array_sum($ppus) / $n, 6),
            'median' => round($median, 6),
            'min'    => $min,
            'max'    => max($ppus),
        ];
        $rows[] = $row;
    }

    return $rows;
}
