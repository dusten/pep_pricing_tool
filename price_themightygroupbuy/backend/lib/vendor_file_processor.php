<?php
declare(strict_types=1);

/**
 * Large image-scanned PDFs are the files that risk hitting PHP's request
 * timeout inside a synchronous Claude call. Everything else (csv, xlsx,
 * smaller pdfs) stays synchronous — the admin still clicks Process and
 * gets an inline result. Threshold is a starting point, not tuned against
 * real vendor files yet.
 */
const ASYNC_PDF_SIZE_THRESHOLD_BYTES = 2 * 1024 * 1024;

function vendorFileQualifiesForAsync(array $file): bool {
    return $file['file_type'] === 'pdf' && (int)$file['file_size_bytes'] > ASYNC_PDF_SIZE_THRESHOLD_BYTES;
}

/**
 * Runs Claude extraction against a vendor's uploaded price list and commits
 * the results: exact product+spec matches upsert straight into pc_prices
 * (existing behavior); anything new or mismatched (new product, new spec on
 * an existing product, or a close-but-not-exact name) is parked in
 * pc_pending_imports for admin review instead of being written directly.
 * Shared by the synchronous path (files/process.php) and the async cron
 * worker (cron/process_async_queue.php) so the two never drift apart.
 */
function processVendorFile(array $file, string $model): array {
    $fullPath = dirname(__DIR__) . '/storage/' . $file['stored_path'];
    if (!is_file($fullPath)) throw new RuntimeException('Stored file is missing from disk.');

    $pdfBase64 = null;
    $plainText = null;
    if ($file['file_type'] === 'pdf') {
        $pdfBase64 = base64_encode((string)file_get_contents($fullPath));
    } elseif ($file['file_type'] === 'xlsx') {
        $plainText = xlsxToText($fullPath);
    } else {
        $plainText = (string)file_get_contents($fullPath);
    }

    $result   = callClaudeExtraction(buildExtractionSystemPrompt(), $pdfBase64, $plainText, $model);
    $warnings = $result['warnings'] ?? [];
    $contact  = $result['contact'] ?? [];

    $pdo = db();
    $pdo->beginTransaction();

    // Fill in any missing vendor contact fields from the extracted document.
    if ($contact) {
        $fillable = [];
        $vals     = [];
        foreach (['contact_name' => 'name', 'email' => 'email', 'whatsapp' => 'whatsapp', 'website' => 'website'] as $col => $key) {
            if (!empty($contact[$key])) { $fillable[] = "$col = COALESCE($col, ?)"; $vals[] = $contact[$key]; }
        }
        if ($fillable) {
            $vals[] = $file['vendor_id'];
            $pdo->prepare('UPDATE pc_vendors SET ' . implode(', ', $fillable) . ' WHERE id = ?')->execute($vals);
        }
    }

    $insertPending = $pdo->prepare(
        'INSERT INTO pc_pending_imports (vendor_file_id, vendor_id, raw_json, match_type, candidate_product_id)
         VALUES (?,?,?,?,?)'
    );

    $imported = 0;
    $pending  = 0;
    foreach ($result['prices'] as $p) {
        $name  = trim((string)($p['canonical_name'] ?? ''));
        $label = trim((string)($p['spec_label'] ?? ''));
        $value = (float)($p['numeric_value'] ?? 0);
        $price = (float)($p['price_usd'] ?? 0);
        if (!$name || !$label || $value <= 0 || $price <= 0) continue;

        $unit        = (string)($p['unit'] ?? 'mg');
        $kitCount    = (int)($p['kit_vial_count'] ?? 10);
        $tierSize    = in_array((int)($p['tier_kit_size'] ?? 1), [1, 10, 100], true) ? (int)($p['tier_kit_size'] ?? 1) : 1;
        $nonStandard = !empty($p['non_standard_kit']);

        $productId = findExactProductMatch($pdo, $name);

        if ($productId === null) {
            // No exact product match — brand-new product, or a close-but-not-exact name.
            $candidate  = findFuzzyProductCandidate($pdo, $name);
            $matchType  = $candidate ? 'name_mismatch' : 'new_product';
            $insertPending->execute([
                $file['id'], $file['vendor_id'],
                json_encode($p + ['tier_kit_size' => $tierSize]),
                $matchType, $candidate['id'] ?? null,
            ]);
            $pending++;
            continue;
        }

        $findSpec = $pdo->prepare('SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ?');
        $findSpec->execute([$productId, $label]);
        $specId = $findSpec->fetchColumn();

        if (!$specId) {
            // Existing product, but this spec doesn't exist on it yet — review before adding.
            $insertPending->execute([
                $file['id'], $file['vendor_id'],
                json_encode($p + ['tier_kit_size' => $tierSize]),
                'new_spec', $productId,
            ]);
            $pending++;
            continue;
        }

        commitPriceRow($pdo, (int)$file['vendor_id'], $productId, (int)$specId, $price, $value, $kitCount, $tierSize, $nonStandard, (int)$file['id']);
        $imported++;
    }

    $notes = $warnings ? implode(' | ', $warnings) : null;
    if ($pending > 0) {
        $notes = trim(($notes ? "$notes | " : '') . "$pending row(s) awaiting review in the pending imports queue.");
    }
    $pdo->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ?, processed_at = NOW() WHERE id = ?')
        ->execute(['complete', $notes, $file['id']]);
    $pdo->prepare("UPDATE pc_vendor_files SET is_current = 1 WHERE id = ? AND category = 'price_list'")->execute([$file['id']]);
    $pdo->commit();

    // The main price-writing path — touches pc_prices/pc_products/pc_specifications
    // and the vendor's price_count/last_upload, so all three cached views go stale.
    cacheBust('pricing_data');
    cacheBust('admin_vendors');
    cacheBust('admin_products');

    return ['imported' => $imported, 'pending' => $pending, 'warnings' => $warnings];
}
