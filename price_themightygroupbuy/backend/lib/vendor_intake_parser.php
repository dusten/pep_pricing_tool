<?php
declare(strict_types=1);

// Maps of the fixed template's labels (normalized: lowercased, trimmed,
// punctuation stripped) to our field names. Add tolerated synonyms as they
// come up in practice rather than trying to guess them all up front.
const VENDOR_INTAKE_LABELS = [
    'vendor name'    => 'display_name',
    'name'           => 'display_name',
    'contact name'   => 'contact_name',
    'contact'        => 'contact_name',
    'email'          => 'email',
    'whatsapp'       => 'whatsapp',
    'discord'        => 'discord',
    'telegram'       => 'telegram',
    'tele'           => 'telegram',
    'website'        => 'website',
    'site'           => 'website',
    'phone number'   => 'phones',
    'phone numbers'  => 'phones',
    'phone number(s)' => 'phones',
    'phone'          => 'phones',
    'payment methods' => 'payment_methods',
    'payment method'  => 'payment_methods',
    'shipping price' => 'shipping_price',
    'shipping'       => 'shipping_price',
];

const VENDOR_PAYMENT_KEYWORDS = [
    'usdt_sol'     => ['usdt sol', 'usdt (sol', 'usdt/sol', 'usdt solana'],
    'usdc_sol'     => ['usdc sol', 'usdc (sol', 'usdc/sol', 'usdc solana'],
    'usdt_trc20'   => ['usdt tron', 'usdt trc20', 'usdt (tron', 'usdt/tron'],
    'usdc_trc20'   => ['usdc tron', 'usdc trc20', 'usdc (tron', 'usdc/tron'],
    'usdt_erc20'   => ['usdt erc20', 'usdt eth', 'usdt (erc'],
    'usdc_erc20'   => ['usdc erc20', 'usdc eth', 'usdc (erc'],
    'btc'          => ['btc', 'bitcoin'],
    'eth'          => ['eth', 'ethereum'],
    'sol'          => ['sol', 'solana'],
    'paypal'       => ['paypal'],
    'wise'         => ['wise'],
    'alipay'       => ['alipay'],
    'alibaba'      => ['alibaba'],
    'wire'         => ['wire'],
    'western_union' => ['western union'],
    'zelle'        => ['zelle'],
    'cashapp'      => ['cashapp', 'cash app'],
    'credit_card'  => ['credit card', 'card'],
];

/** Strips punctuation/parenthetical detail off a template label for lookup. */
function normalizeIntakeLabel(string $label): string {
    $label = strtolower(trim($label));
    $label = preg_replace('/\(.*?\)/', '', $label); // drop "(list all that apply — ...)"
    $label = preg_replace('/[^a-z0-9 ]/', '', $label);
    return trim($label);
}

/**
 * Regex-first pass over the pasted template reply. Returns a field array
 * (display_name, contact_name, email, whatsapp, discord, telegram, website,
 * phones[], payment_methods[], shipping_price) with only the fields it
 * could resolve — caller decides whether that's "enough" before falling
 * back to Claude.
 */
function parseVendorIntakeText(string $text): array {
    $fields = [];
    $lines  = preg_split('/\r\n|\r|\n/', $text);

    foreach ($lines as $line) {
        // Label class includes ',' because the Payment Methods template line
        // embeds a comma-separated hint in parentheses before its own colon
        // (e.g. "Payment Methods (USDT/USDC Solana, ..., Credit Card): PayPal, Zelle") —
        // without it the whole line fails to match at all, not just the value.
        if (!preg_match('/^([\w \/\(\)\-,]+):\s*(.*)$/u', trim($line), $m)) continue;
        $label = normalizeIntakeLabel($m[1]);
        $value = trim($m[2]);
        if ($value === '' || !isset(VENDOR_INTAKE_LABELS[$label])) continue;

        $field = VENDOR_INTAKE_LABELS[$label];
        if ($field === 'phones') {
            $fields['phones'] = array_values(array_filter(array_map('trim', preg_split('/[,\/]/', $value))));
        } elseif ($field === 'payment_methods') {
            $fields['payment_methods'] = matchPaymentMethods($value);
        } elseif ($field === 'shipping_price') {
            $fields['shipping_price'] = (float)preg_replace('/[^0-9.]/', '', $value);
        } else {
            $fields[$field] = $value;
        }
    }

    return $fields;
}

function matchPaymentMethods(string $value): array {
    $value   = strtolower($value);
    $matched = [];
    foreach (VENDOR_PAYMENT_KEYWORDS as $method => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($value, $kw)) { $matched[] = $method; break; }
        }
    }
    return array_values(array_unique($matched));
}

/** How many of the "core" fields resolved — used to decide if the Claude fallback is needed. */
function vendorIntakeResolvedCount(array $fields): int {
    return count(array_intersect_key($fields, array_flip([
        'display_name', 'contact_name', 'email', 'whatsapp', 'discord', 'telegram', 'website', 'phones', 'payment_methods', 'shipping_price',
    ])));
}
