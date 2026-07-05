<?php
declare(strict_types=1);

/**
 * Shared by cart/index.php (view) and cart/add_stack.php (bulk-add-then-view) —
 * one place for the "items + cheapest-vendor-total" response shape.
 */
function getCartSnapshot(PDO $pdo, int $userId): array {
    $items = $pdo->prepare(
        'SELECT ci.id, ci.product_id, ci.specification_id, p.canonical_name AS product, s.spec_label AS spec
         FROM pc_cart_items ci
         JOIN pc_products p       ON p.id = ci.product_id
         JOIN pc_specifications s ON s.id = ci.specification_id
         WHERE ci.user_id = ? ORDER BY ci.added_at'
    );
    $items->execute([$userId]);
    $items = $items->fetchAll();
    foreach ($items as &$it) {
        $it['id']               = (int)$it['id'];
        $it['product_id']       = (int)$it['product_id'];
        $it['specification_id'] = (int)$it['specification_id'];
    }
    unset($it);

    // Only vendors that fully cover the cart are "the" answer; partial-coverage
    // vendors are still surfaced (ranked same way), naming what's missing, so
    // an uncoverable cart isn't just an empty result — see the shopping-cart
    // spec, decision #1. Fetched as raw rows (not GROUP BY) so missing items
    // can be named per vendor, not just counted.
    $vendors = [];
    if ($items) {
        $pairs  = array_map(fn($it) => [$it['product_id'], $it['specification_id']], $items);
        $tuples = implode(',', array_fill(0, count($pairs), '(?,?)'));
        $params = [];
        foreach ($pairs as $pair) array_push($params, ...$pair);

        $stmt = $pdo->prepare(
            "SELECT pr.vendor_id, v.display_name AS vendor_name, pr.product_id, pr.specification_id, pr.price_usd, pr.vendor_sku
             FROM pc_prices pr
             JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1
             WHERE pr.is_active = 1 AND pr.tier_kit_size = 1
               AND (pr.product_id, pr.specification_id) IN ($tuples)"
        );
        $stmt->execute($params);

        $byVendor = [];
        foreach ($stmt->fetchAll() as $r) {
            $vid = (int)$r['vendor_id'];
            $byVendor[$vid] ??= ['vendor_name' => $r['vendor_name'], 'covered' => [], 'total' => 0.0];
            $byVendor[$vid]['covered'][$r['product_id'] . ':' . $r['specification_id']] = $r['vendor_sku'];
            $byVendor[$vid]['total'] += (float)$r['price_usd'];
        }

        $labelByKey = [];
        foreach ($items as $it) $labelByKey[$it['product_id'] . ':' . $it['specification_id']] = $it['product'] . ' ' . $it['spec'];
        $allKeys = array_keys($labelByKey);

        foreach ($byVendor as $vid => $v) {
            $missingKeys = array_diff($allKeys, array_keys($v['covered']));
            $vendors[] = [
                'vendor_id'     => $vid,
                'vendor_name'   => $v['vendor_name'],
                'items_covered' => count($v['covered']),
                'total_items'   => count($items),
                'full_coverage' => count($missingKeys) === 0,
                'total_usd'     => round($v['total'], 2),
                'missing'       => array_values(array_map(fn($k) => $labelByKey[$k], $missingKeys)),
                // Cat No per covered item — used to pre-fill the "message this
                // vendor" WhatsApp text. Falls back to the product/spec label
                // when this vendor didn't give this row a SKU.
                'covered_items' => array_values(array_map(
                    fn($k, $sku) => ['label' => $labelByKey[$k], 'sku' => $sku ?: $labelByKey[$k]],
                    array_keys($v['covered']), $v['covered']
                )),
            ];
        }
        usort($vendors, fn($a, $b) => $b['items_covered'] <=> $a['items_covered'] ?: $a['total_usd'] <=> $b['total_usd']);
    }

    return ['items' => $items, 'vendors' => $vendors];
}
