<?php
declare(strict_types=1);

/**
 * Shared product/spec/price commit logic used by both the auto-commit
 * exact-match path (files/process.php) and the pending-import approval
 * path (vendors/pending_imports.php) — one place for the insert shape so
 * the two never drift.
 */

/** Exact case-insensitive match against canonical_name or an existing alias. */
function findExactProductMatch(PDO $pdo, string $name): ?int {
    $stmt = $pdo->prepare(
        'SELECT id FROM pc_products WHERE LOWER(canonical_name) = LOWER(?)
         UNION SELECT product_id FROM pc_product_aliases WHERE LOWER(alias) = LOWER(?) LIMIT 1'
    );
    $stmt->execute([$name, $name]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * Closest existing product by Levenshtein distance against canonical_name
 * and aliases — used to suggest a candidate on the review queue for names
 * that are close-but-not-exact (typos, spacing/hyphen variants). Threshold
 * is generous-but-bounded: within 3 edits AND under 30% of the name's length,
 * so "Sermolina" -> "Sermorelin" surfaces but unrelated short names don't.
 * ponytail: levenshtein() is stdlib, no fuzzy-matching library needed at this scale.
 */
function findFuzzyProductCandidate(PDO $pdo, string $name): ?array {
    $rows = $pdo->query(
        "SELECT p.id, p.canonical_name AS name FROM pc_products p
         UNION SELECT a.product_id, a.alias FROM pc_product_aliases a"
    )->fetchAll();

    $best = null;
    $needle = strtolower($name);
    foreach ($rows as $r) {
        $candidate = strtolower($r['name']);
        $distance  = levenshtein($needle, $candidate);
        if ($distance === 0) continue; // would have been an exact match already
        if ($distance > 3 || $distance > 0.3 * max(strlen($needle), strlen($candidate))) continue;
        if ($best === null || $distance < $best['distance']) {
            $best = ['id' => (int)$r['id'], 'canonical_name' => $r['name'], 'distance' => $distance];
        }
    }
    return $best;
}

function createProduct(PDO $pdo, string $name): int {
    $pdo->prepare('INSERT INTO pc_products (canonical_name) VALUES (?)')->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function findOrCreateSpec(PDO $pdo, int $productId, string $label, float $value, string $unit): int {
    $find = $pdo->prepare('SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ?');
    $find->execute([$productId, $label]);
    $specId = $find->fetchColumn();
    if ($specId) return (int)$specId;

    $unit = in_array($unit, ['mg', 'iu', 'ml'], true) ? $unit : 'other';
    $pdo->prepare('INSERT INTO pc_specifications (product_id, spec_label, numeric_value, unit) VALUES (?,?,?,?)')
        ->execute([$productId, $label, $value, $unit]);
    return (int)$pdo->lastInsertId();
}

function commitPriceRow(
    PDO $pdo, int $vendorId, int $productId, int $specId,
    float $price, float $numericValue, int $kitCount, int $tierKitSize, bool $nonStandard, ?int $sourceFileId,
    ?string $vendorSku = null
): void {
    $pdo->prepare(
        'INSERT INTO pc_prices (vendor_id, product_id, specification_id, price_usd, price_per_unit, kit_vial_count, tier_kit_size, vendor_sku, non_standard_kit, source_file_id, is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE price_usd = VALUES(price_usd), price_per_unit = VALUES(price_per_unit),
           kit_vial_count = VALUES(kit_vial_count), vendor_sku = VALUES(vendor_sku), non_standard_kit = VALUES(non_standard_kit),
           source_file_id = VALUES(source_file_id), is_active = 1, created_at = NOW()'
    )->execute([
        $vendorId, $productId, $specId, $price, pricePerUnit($price, $kitCount, $numericValue),
        $kitCount, $tierKitSize, $vendorSku ?: null, $nonStandard ? 1 : 0, $sourceFileId,
    ]);
}
