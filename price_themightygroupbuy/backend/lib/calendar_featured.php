<?php
declare(strict_types=1);

/**
 * Shared by the authenticated and public calendar endpoints — the
 * admin-picked featured product (backlog #18) and all-time-low milestones
 * (backlog #19) apply the same regardless of who's looking; only the
 * price-change ledger itself differs (full detail vs. teased).
 */

function getCalendarFeatured(string $month): array {
    return cacheGet('calendar_data', "calendar_featured:$month", 600, function () use ($month) {
        $stmt = db()->prepare(
            "SELECT feature_date, product_id, specification_id, note
             FROM pc_calendar_features WHERE DATE_FORMAT(feature_date, '%Y-%m') = ?"
        );
        $stmt->execute([$month]);

        $out = [];
        foreach ($stmt->fetchAll() as $f) {
            // Cheapest current active listing for the featured product — the spec
            // is pinned if the admin chose one, else the lowest across all specs.
            $specFilter = $f['specification_id'] !== null ? 'AND pr.specification_id = ?' : '';
            $params     = [(int)$f['product_id']];
            if ($f['specification_id'] !== null) $params[] = (int)$f['specification_id'];

            $priceStmt = db()->prepare(
                "SELECT v.display_name AS vendor, p.canonical_name AS product, s.spec_label AS spec,
                        pr.product_id, pr.specification_id, pr.price_usd
                 FROM pc_prices pr
                 JOIN pc_vendors v        ON v.id = pr.vendor_id AND v.is_active = 1
                 JOIN pc_products p       ON p.id = pr.product_id
                 JOIN pc_specifications s ON s.id = pr.specification_id
                 WHERE pr.product_id = ? $specFilter AND pr.is_active = 1 AND pr.tier_kit_size = 1
                 ORDER BY pr.price_usd ASC LIMIT 1"
            );
            $priceStmt->execute($params);
            $best = $priceStmt->fetch();
            if (!$best) continue; // featured product has no current listing — show nothing rather than a broken card

            // Delta: most recent recorded change for this exact product+spec,
            // tier 1 only (matching the "best" query above) -- otherwise a
            // bulk-tier vendor's own history could be shown as this card's
            // delta even though the displayed price/vendor is tier-1.
            $histStmt = db()->prepare(
                "SELECT old_price_usd, new_price_usd FROM pc_price_history
                 WHERE product_id = ? AND specification_id = ? AND tier_kit_size = 1 ORDER BY changed_at DESC LIMIT 1"
            );
            $histStmt->execute([(int)$best['product_id'], (int)$best['specification_id']]);
            $hist = $histStmt->fetch();

            $out[substr($f['feature_date'], 0, 10)] = [
                'product_id' => (int)$best['product_id'],
                'product'   => $best['product'],
                'spec'      => $best['spec'],
                'vendor'    => $best['vendor'],
                'price'     => (float)$best['price_usd'],
                'old_price' => ($hist && $hist['old_price_usd'] !== null) ? (float)$hist['old_price_usd'] : null,
                'note'      => $f['note'],
            ];
        }
        return $out;
    });
}

function getCalendarMilestones(string $month): array {
    return cacheGet('calendar_data', "calendar_milestones:$month", 600, function () use ($month) {
        // Sargable range instead of DATE_FORMAT(changed_at, '%Y-%m') = ? --
        // wrapping the indexed column in a function defeats the index outright,
        // forcing a full-table scan just to find this month's pairs.
        $monthStart = $month . '-01 00:00:00';
        $nextMonth  = date('Y-m-01 00:00:00', strtotime("$monthStart +1 month"));

        // Every (product, spec) pair that changed this month, tier 1 only --
        // otherwise a bulk-tier price (trivially lower than a 1-kit price)
        // would register as a fake "all-time low" milestone.
        $pairsStmt = db()->prepare(
            "SELECT DISTINCT product_id, specification_id FROM pc_price_history
             WHERE changed_at >= ? AND changed_at < ? AND tier_kit_size = 1"
        );
        $pairsStmt->execute([$monthStart, $nextMonth]);
        $pairs = $pairsStmt->fetchAll();
        if (!$pairs) return [];

        // One batched query for every pair's full ALL-TIME history (needed to
        // correctly detect an ALL-TIME low, not just a low within the month) --
        // was previously a per-pair loop, N separate near-full-table scans
        // (pc_price_history had no index usable without a vendor_id in the
        // WHERE clause; see migration 043). Ordered so each pair's rows land
        // contiguously and chronologically, letting one pass below track a
        // running min per pair instead of a query per pair.
        $tuples = implode(',', array_fill(0, count($pairs), '(?,?)'));
        $params = [];
        foreach ($pairs as $p) { $params[] = (int)$p['product_id']; $params[] = (int)$p['specification_id']; }
        $histStmt = db()->prepare(
            "SELECT product_id, specification_id, new_price_usd, changed_at FROM pc_price_history
             WHERE (product_id, specification_id) IN ($tuples) AND tier_kit_size = 1
             ORDER BY product_id, specification_id, changed_at ASC"
        );
        $histStmt->execute($params);
        $rows   = $histStmt->fetchAll();
        $rows[] = null; // sentinel so the loop below flushes the final pair's group

        $nameStmt = db()->prepare(
            "SELECT p.canonical_name AS product, s.spec_label AS spec
             FROM pc_products p JOIN pc_specifications s ON s.id = ?
             WHERE p.id = ?"
        );

        $byDay = [];
        $curKey = null; $min = null; $hadHigher = false; $lowDay = null;
        foreach ($rows as $r) {
            $key = $r ? $r['product_id'] . ':' . $r['specification_id'] : null;
            if ($key !== $curKey) {
                // Milestone only if the record low was first set this month AND
                // some earlier price was higher (a genuine new low, not the
                // only data point) -- same rule as before, now evaluated once
                // per pair-group boundary instead of once per query.
                if ($curKey !== null && $lowDay !== null && str_starts_with($lowDay, $month) && $hadHigher) {
                    [$pid, $sid] = explode(':', $curKey);
                    $nameStmt->execute([(int)$sid, (int)$pid]);
                    $n = $nameStmt->fetch();
                    if ($n) $byDay[$lowDay][] = ['product' => $n['product'], 'spec' => $n['spec']];
                }
                if ($r === null) break;
                $curKey = $key; $min = null; $hadHigher = false; $lowDay = null;
            }
            $price = (float)$r['new_price_usd'];
            if ($min === null || $price < $min) { $min = $price; $lowDay = substr($r['changed_at'], 0, 10); }
            elseif ($price > $min) { $hadHigher = true; }
        }
        return $byDay;
    });
}
