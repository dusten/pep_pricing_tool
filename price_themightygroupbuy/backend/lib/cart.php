<?php
declare(strict_types=1);

/**
 * Shared by cart/index.php (view) and cart/add_stack.php (bulk-add-then-view) —
 * one place for the "items + cheapest-vendor-total" response shape.
 *
 * A cart item's specification_id can be NULL — "just this product, any
 * size" (backlog: cart size-optional request). For those items, coverage
 * means "vendor carries ANY size of this product", and the price that
 * counts is the vendor's (or, for the mix-and-match panel, the market's)
 * CHEAPEST $/unit listing for that product — not raw kit price, which
 * isn't a fair comparison across different doses.
 */
function getCartSnapshot(PDO $pdo, int $userId): array {
    $items = $pdo->prepare(
        'SELECT ci.id, ci.product_id, ci.specification_id, p.canonical_name AS product, s.spec_label AS spec
         FROM pc_cart_items ci
         JOIN pc_products p            ON p.id = ci.product_id
         LEFT JOIN pc_specifications s ON s.id = ci.specification_id
         WHERE ci.user_id = ? ORDER BY ci.added_at'
    );
    $items->execute([$userId]);
    $items = $items->fetchAll();
    foreach ($items as &$it) {
        $it['id']               = (int)$it['id'];
        $it['product_id']       = (int)$it['product_id'];
        $it['specification_id'] = $it['specification_id'] !== null ? (int)$it['specification_id'] : null;
    }
    unset($it);

    // Only vendors that fully cover the cart are "the" answer; partial-coverage
    // vendors are still surfaced (ranked same way), naming what's missing, so
    // an uncoverable cart isn't just an empty result — see the shopping-cart
    // spec, decision #1. Fetched as raw rows (not GROUP BY) so missing items
    // can be named per vendor, not just counted.
    $vendors = [];
    if ($items) {
        // Every cart item's own "key": product_id:specification_id for an
        // exact size, or product_id:any for "any size of this product" — used
        // throughout below to track coverage/pricing per cart line.
        $keyOf = fn(array $it) => $it['product_id'] . ':' . ($it['specification_id'] ?? 'any');

        $exactPairs      = [];
        $anyProductIds   = [];
        foreach ($items as $it) {
            if ($it['specification_id'] !== null) {
                $exactPairs[] = [$it['product_id'], $it['specification_id']];
            } else {
                $anyProductIds[$it['product_id']] = true;
            }
        }
        $anyProductIds = array_keys($anyProductIds);

        $where  = [];
        $params = [];
        if ($exactPairs) {
            $where[] = '(pr.product_id, pr.specification_id) IN (' . implode(',', array_fill(0, count($exactPairs), '(?,?)')) . ')';
            foreach ($exactPairs as $pair) array_push($params, ...$pair);
        }
        if ($anyProductIds) {
            $where[] = 'pr.product_id IN (' . implode(',', array_fill(0, count($anyProductIds), '?')) . ')';
            array_push($params, ...$anyProductIds);
        }
        // $where is never empty here — $items is non-empty, and every item is
        // either an exact pair or an any-product id.
        $stmt = $pdo->prepare(
            "SELECT pr.vendor_id, v.display_name AS vendor_name, pr.product_id, pr.specification_id,
                    pr.price_usd, pr.price_per_unit, pr.vendor_sku
             FROM pc_prices pr
             JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1
             WHERE pr.is_active = 1 AND pr.tier_kit_size = 1 AND (" . implode(' OR ', $where) . ")"
        );
        $stmt->execute($params);
        $priceRows = $stmt->fetchAll();

        $exactKeySet   = array_flip(array_map(fn($p) => $p[0] . ':' . $p[1], $exactPairs));
        $anyProductSet = array_flip($anyProductIds);

        $byVendor      = [];
        // Cheapest single vendor PER item (mix-and-match across vendors) —
        // exact-spec items: lowest kit price_usd wins (same spec everywhere,
        // so kit price is directly comparable). Any-size items: lowest $/unit
        // wins instead, since kit price alone would just favor whichever
        // listing happens to be the smallest dose.
        $cheapestByKey = [];
        // Per (vendor, any-size product): the vendor's own cheapest-$/unit row
        // — folded into $byVendor/$cheapestByKey after the main loop, since a
        // vendor can carry several specs of the same "any size" product and
        // only their best one should count once.
        $anyBestPerVendor = [];

        foreach ($priceRows as $r) {
            $vid  = (int)$r['vendor_id'];
            $pid  = (int)$r['product_id'];
            $sid  = (int)$r['specification_id'];
            $price = (float)$r['price_usd'];
            $ppu   = (float)$r['price_per_unit'];

            $exactKey = $pid . ':' . $sid;
            if (isset($exactKeySet[$exactKey])) {
                $byVendor[$vid] ??= ['vendor_name' => $r['vendor_name'], 'covered' => [], 'total' => 0.0];
                $byVendor[$vid]['covered'][$exactKey] = $r['vendor_sku'];
                $byVendor[$vid]['total'] += $price;

                if (!isset($cheapestByKey[$exactKey]) || $price < $cheapestByKey[$exactKey]['price']) {
                    $cheapestByKey[$exactKey] = [
                        'vendor_id' => $vid, 'vendor_name' => $r['vendor_name'],
                        'price' => $price, 'vendor_sku' => $r['vendor_sku'], 'spec_label' => null,
                    ];
                }
            }

            if (isset($anyProductSet[$pid])) {
                $vpKey = $vid . ':' . $pid;
                if (!isset($anyBestPerVendor[$vpKey]) || $ppu < $anyBestPerVendor[$vpKey]['ppu']) {
                    $anyBestPerVendor[$vpKey] = [
                        'vendor_name' => $r['vendor_name'], 'price' => $price, 'ppu' => $ppu,
                        'vendor_sku' => $r['vendor_sku'], 'specification_id' => $sid,
                    ];
                }
            }
        }

        // Resolve each cart item's real spec_label for its "any size" winner
        // (the buyer needs to know what size they'd actually receive) —
        // cheap one-shot lookup rather than joining pc_specifications in the
        // main price query, which only needs the id.
        $specLabels = [];
        if ($anyBestPerVendor) {
            // array_values() to reindex — array_unique() preserves original
            // (possibly non-sequential) keys, and PDO's positional `?`
            // binding needs a plain 0-indexed array or execute() throws.
            $specIds = array_values(array_unique(array_column($anyBestPerVendor, 'specification_id')));
            $stmt = $pdo->prepare('SELECT id, spec_label FROM pc_specifications WHERE id IN (' . implode(',', array_fill(0, count($specIds), '?')) . ')');
            $stmt->execute($specIds);
            foreach ($stmt->fetchAll() as $s) $specLabels[(int)$s['id']] = $s['spec_label'];
        }

        foreach ($anyBestPerVendor as $vpKey => $best) {
            [$vid, $pid] = array_map('intval', explode(':', $vpKey));
            $anyKey = $pid . ':any';
            $byVendor[$vid] ??= ['vendor_name' => $best['vendor_name'], 'covered' => [], 'total' => 0.0];
            $byVendor[$vid]['covered'][$anyKey] = $best['vendor_sku'];
            $byVendor[$vid]['total'] += $best['price'];

            if (!isset($cheapestByKey[$anyKey]) || $best['ppu'] < $cheapestByKey[$anyKey]['ppu']) {
                $cheapestByKey[$anyKey] = [
                    'vendor_id' => $vid, 'vendor_name' => $best['vendor_name'],
                    'price' => $best['price'], 'vendor_sku' => $best['vendor_sku'],
                    'spec_label' => $specLabels[$best['specification_id']] ?? null, 'ppu' => $best['ppu'],
                ];
            }
        }

        $labelByKey = [];
        foreach ($items as $it) $labelByKey[$keyOf($it)] = $it['product'] . ' — ' . ($it['spec'] ?? 'any size');
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

    // Per-item cheapest breakdown ("buy each item from whoever's cheapest",
    // vs. the single-vendor totals above). Aligned to cart order; an item no
    // vendor carries is included with vendor_id null so the UI can show it as
    // unavailable rather than silently dropping it.
    $cheapestByItem = [];
    $cheapestTotal  = 0.0;
    foreach ($items as $it) {
        $key  = $it['product_id'] . ':' . ($it['specification_id'] ?? 'any');
        $best = $cheapestByKey[$key] ?? null;
        if ($best) $cheapestTotal += $best['price'];
        $cheapestByItem[] = [
            'product'          => $it['product'],
            'spec'             => $it['spec'], // null for an "any size" item
            'product_id'       => $it['product_id'],
            'specification_id' => $it['specification_id'],
            'vendor_id'        => $best['vendor_id'] ?? null,
            'vendor_name'      => $best['vendor_name'] ?? null,
            'price'            => $best['price'] ?? null,
            'vendor_sku'       => $best['vendor_sku'] ?? null,
            // Only set for "any size" items — the real spec_label the cheapest
            // $/unit vendor would actually ship, since the cart item itself
            // doesn't name one.
            'resolved_spec'    => $best['spec_label'] ?? null,
        ];
    }

    return [
        'items'            => $items,
        'vendors'          => $vendors,
        'cheapest_by_item' => $cheapestByItem,
        'cheapest_total'   => round($cheapestTotal, 2),
    ];
}
