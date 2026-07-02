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
    'shipping price' => 'shipping_note',
    'shipping note'  => 'shipping_note',
    'shipping'       => 'shipping_note',
];

// Vendors routinely answer an individual payment method as its own label
// line instead of (or in addition to) the summary "Payment Methods:" line —
// e.g. "Paypal: name@x.com", "BTC: 1Km...". Only the chain-unambiguous
// methods are listed here; bare "USDT"/"USDC" (could be Sol/Tron/ERC20) are
// deliberately excluded — see the ambiguous-ticker handling below.
const VENDOR_PAYMENT_LABEL_ALIASES = [
    'paypal' => 'paypal', 'btc' => 'btc', 'bitcoin' => 'btc', 'eth' => 'eth', 'ethereum' => 'eth',
    'sol' => 'sol', 'solana' => 'sol', 'wise' => 'wise', 'alipay' => 'alipay', 'alibaba' => 'alibaba',
    'wire' => 'wire', 'wire transfer' => 'wire', 'western union' => 'western_union', 'zelle' => 'zelle',
    'cashapp' => 'cashapp', 'cash app' => 'cashapp', 'credit card' => 'credit_card', 'card' => 'credit_card',
];

// Tokens vendors use to mean "not applicable" for a field they left blank on
// purpose (e.g. "Discord: /") — same convention as price data treating X/—/
// blank as unavailable. Checked against the whole trimmed value, not a substring.
const VENDOR_INTAKE_BLANK_TOKENS = ['', 'x', 'n/a', 'na', 'none', '-', '/'];

function isBlankIntakeValue(string $value): bool {
    return in_array(strtolower(trim($value)), VENDOR_INTAKE_BLANK_TOKENS, true);
}

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
 * phones[], payment_methods[], shipping_note, notes_append) with only the
 * fields it could resolve — caller decides whether that's "enough" before
 * falling back to Claude.
 */
function parseVendorIntakeText(string $text): array {
    $fields      = [];
    $notesAppend = [];
    $lines       = preg_split('/\r\n|\r|\n/', $text);

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        // Label class includes ',' because the Payment Methods template line
        // embeds a comma-separated hint in parentheses before its own colon
        // (e.g. "Payment Methods (USDT/USDC Solana, ..., Credit Card): PayPal, Zelle") —
        // without it the whole line fails to match at all, not just the value.
        if (!preg_match('/^([\w \/\(\)\-,]+):\s*(.*)$/u', $line, $m)) continue;
        $label = normalizeIntakeLabel($m[1]);
        $value = trim($m[2]);

        // Shipping is always the template's last field. Real replies answer
        // it with multi-line prose (carrier, timeframe, weight-tiered cost
        // breaks) rather than a single number, so once we hit this label,
        // absorb its own value plus every remaining line as free text
        // instead of trying to parse the rest line by line.
        if ((VENDOR_INTAKE_LABELS[$label] ?? null) === 'shipping_note') {
            $rest      = array_slice($lines, $i + 1);
            $noteLines = array_filter(array_merge([$value], $rest), fn($l) => trim($l) !== '');
            if ($noteLines) $fields['shipping_note'] = trim(implode("\n", $noteLines));
            break;
        }

        if (isBlankIntakeValue($value)) continue;

        if (isset(VENDOR_INTAKE_LABELS[$label])) {
            $field = VENDOR_INTAKE_LABELS[$label];
            if ($field === 'phones') {
                $fields['phones'] = array_values(array_filter(array_map('trim', preg_split('/[,\/]/', $value))));
            } elseif ($field === 'payment_methods') {
                $fields['payment_methods'] = array_values(array_unique(array_merge($fields['payment_methods'] ?? [], matchPaymentMethods($value))));
            } else {
                $fields[$field] = $value;
            }
            continue;
        }

        // Vendor answered an individual payment method as its own label
        // instead of the summary line (e.g. "BTC: 1Km...") — real data,
        // don't drop it. Chain-unambiguous methods also flip the checkbox;
        // bare USDT/USDC can't be safely assigned to a chain, so only the
        // raw line gets recorded for admin review.
        if (isset(VENDOR_PAYMENT_LABEL_ALIASES[$label])) {
            $fields['payment_methods'] = array_values(array_unique(array_merge($fields['payment_methods'] ?? [], [VENDOR_PAYMENT_LABEL_ALIASES[$label]])));
            $notesAppend[] = "{$m[1]}: $value";
        } elseif (in_array($label, ['usdt', 'usdc'], true)) {
            $notesAppend[] = "{$m[1]}: $value";
        }
    }

    if ($notesAppend) $fields['notes_append'] = implode("\n", $notesAppend);
    return $fields;
}

function matchPaymentMethods(string $value): array {
    $value = strtolower($value);
    // Our own template's hint (and most vendor replies copying it) writes
    // "USDT/USDC <chain>" as one combined mention — the chain name sits
    // right after USDC, so a plain substring check on "usdt <chain>" never
    // matches. Expand it to "usdt <chain> usdc <chain>" first so both
    // tickers are visible to the per-keyword checks below.
    $value = preg_replace_callback(
        '/\b(usdt|usdc)\s*\/\s*(usdt|usdc)\s+(solana|tron|trc20|erc20)\b/',
        fn($m) => "$m[1] $m[3] $m[2] $m[3]",
        $value
    );
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
        'display_name', 'contact_name', 'email', 'whatsapp', 'discord', 'telegram', 'website', 'phones', 'payment_methods', 'shipping_note',
    ])));
}
