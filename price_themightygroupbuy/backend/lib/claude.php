<?php
declare(strict_types=1);

const CLAUDE_MODEL_DEFAULT = 'claude-sonnet-5';
const CLAUDE_MODEL_HARD    = 'claude-opus-4-8'; // admin override for messy image-scanned PDFs

// Variant-compound watchlist (see Obsidian_pep_pricing_tool/wiki/concepts/variant-compounds.md,
// which is a dev-only knowledge base, never deployed) — hardcoded here since the
// extraction prompt needs it in production where that vault doesn't exist on disk.
const VARIANT_COMPOUND_WATCH_NAMES = [
    'epitalon', 'epithalon', 'epithalone', 'aedg', 'n-acetyl epitalon', 'acetyl epitalon amidate', 'ac-aedg',
    'tb-500', 'tb500', 'tb 500', 'thymosin beta-4', 'thymosin b4', 'thymosin b5', 'tb-500 fragment',
    'tb500 fragment', 'tb frag', '17-23', 'lkktetq', 'fequesetide', 'tb5', 'tb-5',
];

function buildExtractionSystemPrompt(): string {
    $rows = db()->query(
        "SELECT p.canonical_name, GROUP_CONCAT(a.alias SEPARATOR ', ') AS aliases
         FROM pc_products p LEFT JOIN pc_product_aliases a ON a.product_id = p.id
         GROUP BY p.id ORDER BY p.canonical_name"
    )->fetchAll();
    $list = [];
    foreach ($rows as $r) {
        $list[] = $r['aliases'] ? "{$r['canonical_name']} ({$r['aliases']})" : $r['canonical_name'];
    }
    $canonicalList = implode("\n", $list);
    $watchNames    = implode(', ', VARIANT_COMPOUND_WATCH_NAMES);

    return <<<PROMPT
You are a peptide vendor price list parser. Extract all products and prices from the
attached content and return ONLY a valid JSON object — no preamble, no markdown fences.

Rules:
1. Tiered pricing (1-kit / 10-kit / 100-kit columns): emit ONE row per tier column present,
   each with its own tier_kit_size (1, 10, or 100) and its own price_usd for that tier.
   Do not collapse to just the 1-kit price.
2. USD only. If only RMB/CNY is present, convert at 7.2.
3. Skip entries marked X, —, or blank price.
4. Non-standard kit sizes (1, 5, 6, 11, 12 vials): set non_standard_kit=true, include a warning, still include the row.
5. Normalize specs: numeric value + unit; convert 100mcg -> 0.1mg.
6. Combo products (e.g. "BPC 5mg + TB500 5mg"): spec = total mg (10mg).
7. Canonical names to map common variants to (name and known aliases in parens):
{$canonicalList}
8. Variant-compound watchlist — these common names cover multiple, meaningfully different
   molecules (e.g. "TB-500" usually means the 7aa fragment, not full Thymosin Beta-4; "TB5"
   can mean an unrelated small-molecule MAO-B inhibitor). If a listing uses one of these names
   AND no CAS number is given to disambiguate, still extract the row normally but add a
   warning string naming the ambiguity — do not block or drop the row:
   {$watchNames}

Return exactly this shape:
{
  "contact": {"name": "", "email": "", "whatsapp": "", "website": ""},
  "warnings": ["..."],
  "prices": [{"canonical_name":"","is_new_product":false,"spec_label":"","numeric_value":0,"unit":"mg",
              "price_usd":0,"kit_vial_count":10,"tier_kit_size":1,"non_standard_kit":false}]
}
PROMPT;
}

function buildVendorContactParsePrompt(): string {
    return <<<PROMPT
You are extracting vendor contact/payment details from a freeform reply that did not
follow the expected template exactly. Return ONLY a valid JSON object — no preamble,
no markdown fences.

Extract whatever of these fields you can find (omit keys you can't determine):
{
  "display_name": "", "contact_name": "", "email": "", "whatsapp": "", "discord": "",
  "telegram": "", "website": "", "phones": ["..."], "shipping_note": "carrier/timeframe/cost details as free text, verbatim if multi-line",
  "payment_methods": ["usdt_sol|usdc_sol|usdt_trc20|usdc_trc20|usdt_erc20|usdc_erc20|btc|eth|sol|paypal|wise|alipay|alibaba|wire|western_union|zelle|cashapp|credit_card, ...pick matching ones only"]
}
PROMPT;
}

/**
 * Calls the Anthropic Messages API and returns the decoded JSON object from
 * the response text. Shared by extraction and the vendor-contact-parse
 * fallback — the only difference between callers is systemPrompt/userContent.
 */
function callClaudeMessages(string $systemPrompt, array $userContent, string $model = CLAUDE_MODEL_DEFAULT): array {
    if (!ANTHROPIC_API_KEY) throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');

    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 16000,
        // This is deterministic extraction/parsing, not reasoning — Sonnet 5
        // runs adaptive thinking by default when 'thinking' is omitted (unlike
        // Opus 4.7/4.8, where omitting means no thinking), silently burning
        // the max_tokens budget on unrequested thinking tokens instead of the
        // actual JSON output. Disable it explicitly.
        'thinking'   => ['type' => 'disabled'],
        // The system prompt (product catalog + rules) is byte-identical across
        // every file processed until the catalog changes — cache it so a batch
        // of files processed back-to-back only pays full price on the first one.
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [['role' => 'user', 'content' => $userContent]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$result) {
        throw new RuntimeException("Claude API error (HTTP $code): " . substr((string)$result, 0, 500));
    }

    $decoded = json_decode($result, true);
    // content[] can carry a thinking block before the text block — index [0]
    // isn't reliably the text block even with thinking disabled (a refusal or
    // future block types could shift it). Find the actual text block instead.
    $text = '';
    foreach (($decoded['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') { $text = $block['text']; break; }
    }
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('Claude response was not valid JSON: ' . substr($text, 0, 300));
    }
    return $parsed;
}

/**
 * Calls the Anthropic Messages API with a base64 PDF document block, a base64
 * image block (phone screenshot of a price list), or plain extracted text
 * (csv/xlsx) — exactly one of $pdfBase64/$image/$plainText should be set.
 * Returns the decoded JSON payload.
 */
function callClaudeExtraction(string $systemPrompt, ?string $pdfBase64, ?string $plainText, string $model = CLAUDE_MODEL_DEFAULT, ?array $image = null): array {
    $userContent = [];
    if ($pdfBase64 !== null) {
        $userContent[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfBase64]];
        $userContent[] = ['type' => 'text', 'text' => 'Please extract all pricing data from this vendor price list.'];
    } elseif ($image !== null) {
        $userContent[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['media_type'], 'data' => $image['base64']]];
        $userContent[] = ['type' => 'text', 'text' => 'Please extract all pricing data from this vendor price list image.'];
    } else {
        $userContent[] = ['type' => 'text', 'text' => "Vendor price list (extracted text):\n\n{$plainText}\n\nPlease extract all pricing data from this vendor price list."];
    }

    $parsed = callClaudeMessages($systemPrompt, $userContent, $model);
    if (!isset($parsed['prices'])) {
        throw new RuntimeException('Claude response was not valid extraction JSON.');
    }
    return $parsed;
}

/** Fallback for the paste-to-parse vendor intake box when the regex pass can't resolve enough fields. */
function callClaudeVendorContactParse(string $pastedText): array {
    $userContent = [['type' => 'text', 'text' => "Vendor's reply:\n\n{$pastedText}"]];
    return callClaudeMessages(buildVendorContactParsePrompt(), $userContent, CLAUDE_MODEL_DEFAULT);
}
