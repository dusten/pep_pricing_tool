<?php
declare(strict_types=1);

// Per-model $/million-token rates (backlog: per-call cost estimate). Sourced
// from the claude-api skill's cached model table (2026-06-24) — Sonnet 5's
// intro pricing ($2/$10) window closed 2026-08-31 is still in the future as
// of this writing, but the intro rate isn't guaranteed to still be billing by
// the time this runs, so standard $3/$15 is used to avoid under-estimating.
// ponytail: hardcoded table, move to a DB-editable settings row if pricing
// changes often enough to be annoying.
const CLAUDE_MODEL_RATES = [
    'claude-sonnet-5' => ['input' => 3.0, 'output' => 15.0],
    'claude-opus-4-8' => ['input' => 5.0, 'output' => 25.0],
];

/**
 * Estimated USD cost of one Claude call from its token usage. NOT billed
 * truth — Anthropic's Admin API cost_report is the real number if that's ever
 * needed per-call. cache_creation bills at ~1.25x input rate (5-min TTL
 * default), cache_read at ~0.1x input rate, plain input/output at their base
 * rates. Returns null for an unknown model rather than guessing a rate.
 */
function estimateClaudeCallCostUsd(
    string $model, ?int $inputTokens, ?int $outputTokens,
    ?int $cacheCreationTokens = null, ?int $cacheReadTokens = null
): ?float {
    $rates = CLAUDE_MODEL_RATES[$model] ?? null;
    if (!$rates) return null;

    $cost = ($inputTokens ?? 0) * $rates['input'] / 1_000_000
          + ($outputTokens ?? 0) * $rates['output'] / 1_000_000
          + ($cacheCreationTokens ?? 0) * $rates['input'] * 1.25 / 1_000_000
          + ($cacheReadTokens ?? 0) * $rates['input'] * 0.1 / 1_000_000;

    return round($cost, 4);
}
