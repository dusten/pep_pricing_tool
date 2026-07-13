<?php
declare(strict_types=1);
// Backlog #59 follow-up (2026-07-12). Migration 029 added tier_kit_size to
// pc_price_history and safely backfilled the ~64% of rows belonging to a
// (vendor, product, spec) combo that's only ever sold one tier -- the
// remaining rows (multi-tier combos) were left NULL since they couldn't be
// disambiguated from current pc_prices data alone.
//
// This reprocesses those NULL rows against their ORIGINAL source data
// instead: pc_claude_call_log.raw_response_text for the auto-commit path
// (files/process.php), and pc_pending_imports.raw_json for the
// manual-review-approval path (vendors/pending_imports.php) -- both still
// carry the per-price tier_kit_size Claude actually extracted. Matched by
// vendor + timestamp proximity (call_log.created_at or
// pending_imports.reviewed_at within 3s of the history row's changed_at) +
// exact price match, and dose/unit match when the specification hasn't
// since been deleted by one of this session's product-merge passes.
//
// Deliberately NOT resolved, left NULL: rows where multiple different tiers
// matched the same price within the candidate source (genuinely ambiguous,
// not guessed), and rows with no candidate source found within the time
// window at all (mostly very old data or edge cases in the commit timing).
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';

function decodePrices(string $raw): array {
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw));
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) $text = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($text, true);
    return (is_array($decoded) && isset($decoded['prices'])) ? $decoded['prices'] : [];
}

$pdo = db();

$rows = $pdo->query(
    "SELECT ph.id, ph.vendor_id, ph.product_id, ph.specification_id, ph.new_price_usd, ph.changed_at,
            s.numeric_value, s.unit
     FROM pc_price_history ph
     LEFT JOIN pc_specifications s ON s.id = ph.specification_id
     WHERE ph.tier_kit_size IS NULL AND ph.source = 'import'"
)->fetchAll();

$callLogCache = [];
$pendingCache = [];
$resolutions = [];
$ambiguous = 0;
$noMatch = 0;

foreach ($rows as $r) {
    $vendorId = (int)$r['vendor_id'];
    $changedAt = $r['changed_at'];

    $clKey = $vendorId . ':' . $changedAt;
    if (!isset($callLogCache[$clKey])) {
        $stmt = $pdo->prepare(
            "SELECT l.raw_response_text FROM pc_claude_call_log l
             JOIN pc_vendor_files vf ON vf.id = l.vendor_file_id
             WHERE vf.vendor_id = ? AND ABS(TIMESTAMPDIFF(SECOND, l.created_at, ?)) <= 3"
        );
        $stmt->execute([$vendorId, $changedAt]);
        $all = [];
        foreach ($stmt->fetchAll() as $c) foreach (decodePrices($c['raw_response_text']) as $p) $all[] = $p;
        $callLogCache[$clKey] = $all;
    }
    $candidates = $callLogCache[$clKey];

    if (!$candidates) {
        $piKey = $vendorId . ':' . $changedAt;
        if (!isset($pendingCache[$piKey])) {
            $stmt = $pdo->prepare(
                "SELECT raw_json FROM pc_pending_imports
                 WHERE vendor_id = ? AND status = 'approved' AND ABS(TIMESTAMPDIFF(SECOND, reviewed_at, ?)) <= 3"
            );
            $stmt->execute([$vendorId, $changedAt]);
            $all = [];
            foreach ($stmt->fetchAll() as $c) {
                $d = json_decode($c['raw_json'], true);
                if (is_array($d)) $all[] = $d;
            }
            $pendingCache[$piKey] = $all;
        }
        $candidates = $pendingCache[$piKey];
    }
    if (!$candidates) { $noMatch++; continue; }

    $matches = [];
    foreach ($candidates as $p) {
        if (abs((float)($p['price_usd'] ?? -1) - (float)$r['new_price_usd']) >= 0.005) continue;
        if ($r['numeric_value'] !== null) {
            if (abs((float)($p['numeric_value'] ?? -1) - (float)$r['numeric_value']) >= 0.0001) continue;
            if (($p['unit'] ?? '') !== $r['unit']) continue;
        }
        $matches[] = (int)($p['tier_kit_size'] ?? 1);
    }
    $uniqueTiers = array_unique($matches);
    if (count($uniqueTiers) === 1) {
        $resolutions[(int)$r['id']] = $uniqueTiers[0];
    } elseif (count($uniqueTiers) > 1) {
        $ambiguous++;
    } else {
        $noMatch++;
    }
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE pc_price_history SET tier_kit_size = ? WHERE id = ?');
    foreach ($resolutions as $id => $tier) {
        $stmt->execute([$tier, $id]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

echo "resolved " . count($resolutions) . " rows, ambiguous $ambiguous, no match $noMatch\n";

cacheBust('comparison_data');
cacheBust('calendar_data');
echo "cache busted\n";
