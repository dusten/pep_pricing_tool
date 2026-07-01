<?php
declare(strict_types=1);

const CLAUDE_MODEL_DEFAULT = 'claude-sonnet-5';
const CLAUDE_MODEL_HARD    = 'claude-opus-4-8'; // admin override for messy image-scanned PDFs

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

    return <<<PROMPT
You are a peptide vendor price list parser. Extract all products and prices from the
attached content and return ONLY a valid JSON object — no preamble, no markdown fences.

Rules:
1. Tiered pricing (1-kit / 10-kit / 100-kit): use the 1-kit price.
2. USD only. If only RMB/CNY is present, convert at 7.2.
3. Skip entries marked X, —, or blank price.
4. Non-standard kit sizes (1, 5, 6, 11, 12 vials): set non_standard_kit=true, include a warning, still include the row.
5. Normalize specs: numeric value + unit; convert 100mcg -> 0.1mg.
6. Combo products (e.g. "BPC 5mg + TB500 5mg"): spec = total mg (10mg).
7. Canonical names to map common variants to (name and known aliases in parens):
{$canonicalList}

Return exactly this shape:
{
  "contact": {"name": "", "email": "", "whatsapp": "", "website": ""},
  "warnings": ["..."],
  "prices": [{"canonical_name":"","is_new_product":false,"spec_label":"","numeric_value":0,"unit":"mg",
              "price_usd":0,"kit_vial_count":10,"non_standard_kit":false}]
}
PROMPT;
}

/**
 * Calls the Anthropic Messages API with either a base64 PDF document block
 * or plain extracted text (csv/xlsx), and returns the decoded JSON payload.
 */
function callClaudeExtraction(string $systemPrompt, ?string $pdfBase64, ?string $plainText, string $model = CLAUDE_MODEL_DEFAULT): array {
    if (!ANTHROPIC_API_KEY) throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');

    $userContent = [];
    if ($pdfBase64 !== null) {
        $userContent[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfBase64]];
        $userContent[] = ['type' => 'text', 'text' => 'Please extract all pricing data from this vendor price list.'];
    } else {
        $userContent[] = ['type' => 'text', 'text' => "Vendor price list (extracted text):\n\n{$plainText}\n\nPlease extract all pricing data from this vendor price list."];
    }

    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 8000,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userContent]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
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
    $text    = $decoded['content'][0]['text'] ?? '';
    $text    = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));

    $parsed = json_decode($text, true);
    if (!is_array($parsed) || !isset($parsed['prices'])) {
        throw new RuntimeException('Claude response was not valid extraction JSON: ' . substr($text, 0, 300));
    }
    return $parsed;
}
