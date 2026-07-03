<?php
declare(strict_types=1);
// Runnable self-check for the $/unit money formula. Run: php backend/lib/price_per_unit_test.php
require_once dirname(__DIR__) . '/helpers.php';

// A 10-vial kit of 5mg vials for $200 → $200 / (10 * 5) = $4.00/mg
assert(pricePerUnit(200.0, 10, 5.0) === 4.0);
// Single vial (kit_vial_count = 1) → old formula's result, unchanged
assert(pricePerUnit(50.0, 1, 10.0) === 5.0);
// kit_vial_count is the factor that was missing: same price/spec, 10x vials → 10x cheaper per unit
assert(pricePerUnit(100.0, 1, 10.0) === 10.0);
assert(pricePerUnit(100.0, 10, 10.0) === 1.0);
// Zero denominator guarded — no DivisionByZeroError
assert(pricePerUnit(100.0, 0, 5.0) === 20.0);  // max(1, 0) * 5 = 5, so 100/5
assert(pricePerUnit(100.0, 10, 0.0) === 0.0);  // numericValue 0 → guarded to 0.0

echo "price_per_unit: all assertions passed\n";
