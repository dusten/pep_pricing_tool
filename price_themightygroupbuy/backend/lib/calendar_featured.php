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
        // Every (product, spec) pair that changed this month, tier 1 only --
        // otherwise a bulk-tier price (trivially lower than a 1-kit price)
        // would register as a fake "all-time low" milestone.
        $pairsStmt = db()->prepare(
            "SELECT DISTINCT product_id, specification_id FROM pc_price_history
             WHERE DATE_FORMAT(changed_at, '%Y-%m') = ? AND tier_kit_size = 1"
        );
        $pairsStmt->execute([$month]);
        $pairs = $pairsStmt->fetchAll();
        if (!$pairs) return [];

        $byDay   = [];
        $histAll = db()->prepare(
            "SELECT new_price_usd, changed_at FROM pc_price_history
             WHERE product_id = ? AND specification_id = ? AND tier_kit_size = 1 ORDER BY changed_at ASC"
        );
        $nameStmt = db()->prepare(
            "SELECT p.canonical_name AS product, s.spec_label AS spec
             FROM pc_products p JOIN pc_specifications s ON s.id = ?
             WHERE p.id = ?"
        );
        foreach ($pairs as $pair) {
            $histAll->execute([(int)$pair['product_id'], (int)$pair['specification_id']]);
            $rows = $histAll->fetchAll();
            $min  = null; $hadHigher = false; $lowDay = null;
            foreach ($rows as $r) {
                $price = (float)$r['new_price_usd'];
                if ($min === null || $price < $min) { $min = $price; $lowDay = substr($r['changed_at'], 0, 10); }
                elseif ($price > $min) { $hadHigher = true; }
            }
            // Milestone only if the record low was first set this month AND some
            // earlier price was higher (a genuine new low, not the only data point).
            if ($lowDay !== null && str_starts_with($lowDay, $month) && $hadHigher) {
                $nameStmt->execute([(int)$pair['specification_id'], (int)$pair['product_id']]);
                $n = $nameStmt->fetch();
                if ($n) $byDay[$lowDay][] = ['product' => $n['product'], 'spec' => $n['spec']];
            }
        }
        return $byDay;
    });
}
