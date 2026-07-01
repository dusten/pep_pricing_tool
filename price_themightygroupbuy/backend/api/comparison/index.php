<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
$user = requireAuth();

$productIds = array_map('intval', (array)($_GET['products'] ?? []));
$vendorIds  = array_map('intval', (array)($_GET['vendors']  ?? []));
$specIds    = array_map('intval', (array)($_GET['specs']    ?? []));
$category   = in_array($_GET['category'] ?? '', ['glp1','peptide','hormone','blend','consumable','other'], true)
    ? $_GET['category'] : null;
$multiOnly  = in_array($_GET['multi_only'] ?? '', ['1', 'true'], true);

// ── Free-tier metering ──────────────────────────────────────────────
$isAdmin = !empty($user['is_admin']);
$isFree  = !$isAdmin && ($user['tier'] === 'free' || !in_array($user['tier_status'], ['active', 'trialing'], true));

sort($productIds); sort($vendorIds); sort($specIds); // normalize before hashing so param order doesn't matter
$filterHash = sha1(json_encode(compact('productIds', 'vendorIds', 'specIds', 'category', 'multiOnly')));

if ($isFree) {
    $limit = (int)getAppSetting('free_tier_query_limit', '3');
    $hours = (int)getAppSetting('free_tier_window_hours', '72');
    $stmt  = db()->prepare(
        'SELECT DISTINCT filter_hash FROM pc_query_log WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)'
    );
    $stmt->execute([$user['id'], $hours]);
    $usedHashes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $isNewQuery = !in_array($filterHash, $usedHashes, true);

    if ($isNewQuery && count($usedHashes) >= $limit) {
        $oldest = db()->prepare(
            'SELECT MIN(created_at) FROM pc_query_log WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)'
        );
        $oldest->execute([$user['id'], $hours]);
        $resetsAt = date('Y-m-d H:i:s', strtotime((string)$oldest->fetchColumn()) + $hours * 3600);
        jsonResponse([
            'error' => 'query_limit', 'remaining' => 0, 'resets_at' => $resetsAt,
            'message' => 'You have used all comparisons for this period. Upgrade for unlimited access.',
        ], 402);
    }
    if ($isNewQuery) {
        db()->prepare('INSERT INTO pc_query_log (user_id, filter_hash) VALUES (?,?)')->execute([$user['id'], $filterHash]);
    }
}

// ── Build query ──────────────────────────────────────────────────────
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

jsonResponse(['rows' => $rows]);
