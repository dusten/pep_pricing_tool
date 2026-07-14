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
    $watchNames = implode(', ', VARIANT_COMPOUND_WATCH_NAMES);

    return <<<PROMPT
You are a vendor price list parser. Extract EVERY priced product from the attached
content and return ONLY a valid JSON object — no preamble, no markdown fences. Vendors
on this platform sell peptides primarily, but also steroids/hormones, vitamin/wellness
blends, cosmetic products, lab supplies, and anything else they price — if the vendor
lists a price for it, extract it. Do not omit a row just because it isn't a peptide.

Rules:
1. Tiered/quantity-break pricing (e.g. "1-kit / 10-kit / 100-kit" or "≥1kit / ≥10kits /
   ≥50kits" columns — breakpoints vary by vendor, don't assume 1/10/100): emit ONE row per
   tier column present, each with its own tier_kit_size set to that column's minimum kit
   quantity as a plain integer, and its own price_usd for that tier. Do not collapse to
   just the lowest tier's price. This only applies when the source shows genuinely separate
   prices for different order quantities. A vial count baked into the spec/dose text itself
   (e.g. "10mg*10vials", "5mg *10 vials") describes packaging — how many vials come bundled
   in one kit — not a purchase-quantity tier: record that number as kit_vial_count only.
   tier_kit_size stays 1 unless the source separately shows more than one price for that
   same item at different order quantities.
2. USD only. If only RMB/CNY is present, convert at 7.2.
3. Skip entries marked X, —, or blank price.
4. Non-standard kit sizes (1, 5, 6, 11, 12 vials): set non_standard_kit=true, include a warning, still include the row.
5. Normalize specs: numeric value + unit; convert 100mcg -> 0.1mg. spec_label itself must
   be just the dose ("10mg"), never the full source text — strip any packaging/vial-count
   suffix baked into the spec column (e.g. "10mg*10vials" -> spec_label "10mg"; that vial
   count already goes in kit_vial_count per rule 1, not into spec_label too).
6. Combo products (e.g. "BPC 5mg + TB500 5mg"): spec = total mg (10mg).
7. canonical_name = the product name exactly as this vendor writes it (trim whitespace,
   fix obvious casing) — do not rename, merge, or annotate it with other names/aliases
   you may know. Matching this name to existing products/aliases is handled entirely by
   the software after extraction, not by you.
8. Variant-compound watchlist — these common names cover multiple, meaningfully different
   molecules, and which one a given vendor means is NOT predictable from the name alone (e.g.
   "TB-500"/"TB500" is used across this market for both the full 43aa Thymosin Beta-4 AND the
   7aa fragment — do not assume either one by default; "TB5" can also mean an unrelated
   small-molecule MAO-B inhibitor). If a listing uses one of these names AND no CAS number is
   given to disambiguate: still extract canonical_name EXACTLY as the vendor wrote it, per
   rule 7 — do NOT append a guessed qualifier like "(Frag)" or "(Thymosin B4)" to the name
   itself, even if you have an opinion about which molecule it likely is. Put that opinion (if
   any) ONLY in the warning field, never folded into canonical_name — an annotated name gets
   matched by the software as if it were a distinct product, which silently miscategorizes the
   listing instead of flagging it for review. If the SAME source lists two distinctly-priced
   rows under names from this watchlist (e.g. a plain "TB-500" line and a separate
   "TB-500(Frag)"/"...17-23" line), extract both exactly as written — that vendor is
   distinguishing two real SKUs, don't collapse them.
   {$watchNames}
9. If the source has its own catalog code for this row (column header like "Cat No.",
   "Abbreviation", "SKU", "Model#", e.g. "TR5", "NJ100"), capture it verbatim as
   vendor_sku. Leave it "" if the source has no such column.
10. Raw/bulk powder priced by weight, not a finished vial (column header like "$/G",
    "price per gram", "bulk", "raw powder" — no kit/vial count given at all): emit one row
    with spec_label="1g", numeric_value=1000, unit="mg", kit_vial_count=1, tier_kit_size=1,
    price_usd=the given per-gram price, is_raw_material=true. If a source shows more than one
    weight-break price (e.g. separate 1g/10g/100g rates), still extract every row exactly as
    given but add a warning naming the product — multi-tier bulk pricing isn't fully modeled
    yet, so it needs a human to decide the right rows rather than guessing here. Every other
    row (finished vials/kits) gets is_raw_material=false.
11. If a source lists more than one price for the same item (e.g. a regular/list price
    column alongside a limited-time sale/discount price column), use the regular/list price
    as price_usd, not the promotional one — a time-limited discount going stale as the
    standing recorded price is worse than under-discounting. Note the sale price and its
    name/duration in a warning instead of using it, so a human can decide whether to apply
    it manually.
12. If a source prices PER VIAL explicitly (e.g. column header "Price/vial") alongside a
    minimum order quantity given in raw vial units (e.g. "MOQ/vial" = 100, 500 — not a kit
    count), that MOQ is not tier_kit_size directly. A standard kit is 10 vials unless the
    source states a different bundle size elsewhere (e.g. a "Package" column reading
    "15vial/bag"). Set kit_vial_count to that bundle size (10 if unstated), tier_kit_size =
    ceil(MOQ / kit_vial_count) — the number of kits needed to meet the MOQ, rounding up since
    a fractional kit can't be ordered — and price_usd = the given per-vial price × kit_vial_count
    (the price for one whole kit, matching how every other vendor's price_usd is recorded).

Return exactly this shape:
{
  "contact": {"name": "", "email": "", "whatsapp": "", "website": ""},
  "warnings": ["..."],
  "prices": [{"canonical_name":"","spec_label":"","numeric_value":0,"unit":"mg",
              "price_usd":0,"kit_vial_count":10,"tier_kit_size":1,"vendor_sku":"","non_standard_kit":false,
              "is_raw_material":false,"warning":null}]
}
Per-price "warning" is a single string or null — never fold it into canonical_name (rules
4, 8, 10 above are the only cases that set it). Omit only if there's truly nothing to flag.
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
  "payment_methods": ["usdt_sol|usdc_sol|usdt_trc20|usdc_trc20|usdt_erc20|usdc_erc20|btc|eth|sol|paypal|wise|alipay|alibaba|wire|western_union|zelle|cashapp|credit_card|remitly|pyusd, ...pick matching ones only"]
}
PROMPT;
}

/**
 * Calls the Anthropic Messages API and returns the decoded JSON object from
 * the response text. Shared by extraction and the vendor-contact-parse
 * fallback — the only difference between callers is systemPrompt/userContent.
 */
/**
 * Best-effort call log (backlog #24) — never let a logging failure break
 * the actual extraction flow, so this swallows its own exceptions.
 */
function logClaudeCall(
    ?int $vendorFileId, string $callType, string $model, ?int $httpStatus,
    ?array $decoded, ?string $rawText, bool $parsedOk, ?string $errorMessage
): void {
    try {
        $usage = $decoded['usage'] ?? [];
        db()->prepare(
            'INSERT INTO pc_claude_call_log
               (vendor_file_id, call_type, model, http_status, stop_reason, input_tokens, output_tokens,
                cache_creation_input_tokens, cache_read_input_tokens, raw_response_text, parsed_ok, error_message)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $vendorFileId, $callType, $model, $httpStatus, $decoded['stop_reason'] ?? null,
            $usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null,
            $usage['cache_creation_input_tokens'] ?? null, $usage['cache_read_input_tokens'] ?? null,
            $rawText, $parsedOk ? 1 : 0, $errorMessage,
        ]);
    } catch (Throwable $e) {
        error_log('[claude_call_log] failed to persist: ' . $e->getMessage());
    }
}

function callClaudeMessages(
    string $systemPrompt, array $userContent, string $model = CLAUDE_MODEL_DEFAULT,
    string $callType = 'extraction', ?int $vendorFileId = null
): array {
    if (!ANTHROPIC_API_KEY) throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');

    $payload = json_encode([
        'model'      => $model,
        // Confirmed truncating (stop_reason: max_tokens, output_tokens exactly
        // 48000) on a real ~399-row file after the extraction prompt widened
        // from peptides-only to every priced product — same file now emits
        // more rows per response. Raised with headroom; if this starts
        // truncating too, worth revisiting whether tiers should collapse to
        // one output row with 3 price fields instead of 3 separate rows.
        'max_tokens' => 64000,
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
        CURLOPT_TIMEOUT        => 400,
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
        logClaudeCall($vendorFileId, $callType, $model, $code, null, (string)$result, false, "HTTP error $code");
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
    $rawText = $text; // exactly what Claude returned in the text block, pre-cleanup — what gets logged
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));

    // Despite "no preamble" in the system prompt, Claude occasionally adds a
    // sentence before the JSON anyway — seen in practice on a large (149-row
    // tiered) extraction, not on smaller ones. Extract the outermost {...}
    // rather than trust the whole response body is pure JSON; harmless no-op
    // when the response already starts with '{'.
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
    }

    $parsed = json_decode($text, true);
    $ok = is_array($parsed);
    logClaudeCall($vendorFileId, $callType, $model, $code, $decoded, $rawText, $ok, $ok ? null : 'Claude response was not valid JSON');
    if (!$ok) {
        throw new RuntimeException('Claude response was not valid JSON: ' . substr($text, 0, 300));
    }
    return $parsed;
}

/**
 * Calls the Anthropic Messages API for price-list extraction. $userContent is
 * the full content-block array (document/image/text blocks plus the trailing
 * instruction text) — the caller builds it, since what it looks like now
 * varies by source (one PDF block, one image block, several image/document
 * blocks for a multi-page zip, or a plain text block for xlsx/csv). Used to
 * be four separate mutually-exclusive params here (one per source type);
 * collapsed to this when zip support made that shape awkward — one more
 * source type would've meant a fifth bolted-on param. Returns the decoded
 * JSON payload.
 */
function callClaudeExtraction(string $systemPrompt, array $userContent, string $model = CLAUDE_MODEL_DEFAULT, ?int $vendorFileId = null): array {
    $parsed = callClaudeMessages($systemPrompt, $userContent, $model, 'extraction', $vendorFileId);
    if (!isset($parsed['prices'])) {
        throw new RuntimeException('Claude response was not valid extraction JSON.');
    }
    return $parsed;
}

/** Fallback for the paste-to-parse vendor intake box when the regex pass can't resolve enough fields. */
function callClaudeVendorContactParse(string $pastedText): array {
    $userContent = [['type' => 'text', 'text' => "Vendor's reply:\n\n{$pastedText}"]];
    return callClaudeMessages(buildVendorContactParsePrompt(), $userContent, CLAUDE_MODEL_DEFAULT, 'vendor_contact_parse');
}
