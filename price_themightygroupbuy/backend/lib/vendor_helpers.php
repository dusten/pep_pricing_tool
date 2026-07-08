<?php
declare(strict_types=1);

const VENDOR_PAYMENT_METHODS = [
    'usdt_sol','usdc_sol','usdt_trc20','usdc_trc20','usdt_erc20','usdc_erc20',
    'btc','eth','sol','paypal','wise','alipay','alibaba','wire','western_union',
    'zelle','cashapp','credit_card','remitly',
];

/** Replaces a vendor's phone/payment-method rows from a create/update payload. Shared by vendors/index.php + show.php. */
function saveVendorPhonesAndPaymentMethods(PDO $pdo, int $vendorId, array $d): void {
    if (array_key_exists('phones', $d)) {
        $pdo->prepare('DELETE FROM pc_vendor_phones WHERE vendor_id = ?')->execute([$vendorId]);
        $insertPhone = $pdo->prepare('INSERT INTO pc_vendor_phones (vendor_id, phone) VALUES (?,?)');
        foreach ((array)$d['phones'] as $phone) {
            $phone = trim((string)$phone);
            if ($phone !== '') $insertPhone->execute([$vendorId, $phone]);
        }
    }

    if (array_key_exists('payment_methods', $d)) {
        $pdo->prepare('DELETE FROM pc_vendor_payment_methods WHERE vendor_id = ?')->execute([$vendorId]);
        $insertMethod = $pdo->prepare('INSERT IGNORE INTO pc_vendor_payment_methods (vendor_id, method) VALUES (?,?)');
        foreach ((array)$d['payment_methods'] as $method) {
            if (in_array($method, VENDOR_PAYMENT_METHODS, true)) $insertMethod->execute([$vendorId, $method]);
        }
    }
}

/** Updates a vendor's scalar contact/text fields from a create/update payload — only touches keys present in $d. Shared by vendors/index.php (name-collision update path) + show.php (PUT). */
function updateVendorScalarFields(PDO $pdo, int $vendorId, array $d): void {
    $fields = [];
    $vals   = [];
    foreach (['display_name', 'contact_name', 'email', 'whatsapp', 'discord', 'telegram', 'website', 'shipping_note', 'notes'] as $f) {
        if (array_key_exists($f, $d)) {
            $fields[] = "$f = ?";
            // website is rendered as :href in VendorCard — http(s) only (safeHttpUrl)
            $vals[]   = $f === 'website' ? safeHttpUrl((string)$d[$f]) : (trim((string)$d[$f]) ?: null);
        }
    }
    if (array_key_exists('is_active', $d))   { $fields[] = 'is_active = ?';   $vals[] = (bool)$d['is_active']   ? 1 : 0; }
    if (array_key_exists('is_verified', $d)) { $fields[] = 'is_verified = ?'; $vals[] = (bool)$d['is_verified'] ? 1 : 0; }
    if (!$fields) return;
    $vals[] = $vendorId;
    $pdo->prepare('UPDATE pc_vendors SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
}

/**
 * Last-10-digits comparison rather than exact string match — vendor phone
 * numbers get pasted in wildly different formats ("+1 555-123-4567" vs
 * "15551234567" vs "(555) 123-4567"), and this vendor base skews
 * international (varying country-code prefixes), so trailing-digit
 * comparison is the more robust match than a normalized-but-exact string.
 */
function normalizePhoneForMatch(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return substr($digits, -10);
}

/** Finds an existing vendor by phone number (last-10-digit match) — used to catch a vendor being re-added under a different name. Returns null if no phone in $phones matches, or if $phones normalize to nothing usable. */
function findVendorByPhone(PDO $pdo, array $phones): ?array {
    $targets = array_filter(array_map('normalizePhoneForMatch', $phones), fn($p) => strlen($p) >= 7);
    if (!$targets) return null;

    $rows = $pdo->query('SELECT vp.phone, v.id, v.display_name FROM pc_vendor_phones vp JOIN pc_vendors v ON v.id = vp.vendor_id')->fetchAll();
    foreach ($rows as $row) {
        if (in_array(normalizePhoneForMatch($row['phone']), $targets, true)) {
            return ['id' => (int)$row['id'], 'display_name' => $row['display_name']];
        }
    }
    return null;
}

/** Fetches a vendor's phone numbers and payment methods for a show/detail response. */
function loadVendorPhonesAndPaymentMethods(PDO $pdo, int $vendorId): array {
    $phones = $pdo->prepare('SELECT phone FROM pc_vendor_phones WHERE vendor_id = ? ORDER BY id');
    $phones->execute([$vendorId]);
    $methods = $pdo->prepare('SELECT method FROM pc_vendor_payment_methods WHERE vendor_id = ? ORDER BY method');
    $methods->execute([$vendorId]);
    return [
        'phones'          => $phones->fetchAll(PDO::FETCH_COLUMN),
        'payment_methods' => $methods->fetchAll(PDO::FETCH_COLUMN),
    ];
}
