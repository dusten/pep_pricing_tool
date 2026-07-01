<?php
declare(strict_types=1);

const VENDOR_PAYMENT_METHODS = [
    'usdt_sol','usdc_sol','usdt_trc20','usdc_trc20','usdt_erc20','usdc_erc20',
    'btc','eth','sol','paypal','wise','alipay','alibaba','wire','western_union',
    'zelle','cashapp','credit_card',
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
