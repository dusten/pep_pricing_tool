<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/claude.php';
require_once dirname(__DIR__, 2) . '/lib/xlsx_reader.php';

method('POST');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);
$model = (input()['model'] ?? '') === 'opus' ? CLAUDE_MODEL_HARD : CLAUDE_MODEL_DEFAULT;

$stmt = db()->prepare('SELECT * FROM pc_vendor_files WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) jsonResponse(['error' => 'File not found.'], 404);

db()->prepare('UPDATE pc_vendor_files SET processing_status = ? WHERE id = ?')->execute(['processing', $id]);

$fullPath = dirname(__DIR__, 2) . '/storage/' . $file['stored_path'];
try {
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

    $findProduct = $pdo->prepare(
        'SELECT id FROM pc_products WHERE LOWER(canonical_name) = LOWER(?)
         UNION SELECT product_id FROM pc_product_aliases WHERE LOWER(alias) = LOWER(?) LIMIT 1'
    );
    $insertProduct = $pdo->prepare('INSERT INTO pc_products (canonical_name) VALUES (?)');
    $findSpec      = $pdo->prepare('SELECT id FROM pc_specifications WHERE product_id = ? AND spec_label = ?');
    $insertSpec    = $pdo->prepare('INSERT INTO pc_specifications (product_id, spec_label, numeric_value, unit) VALUES (?,?,?,?)');
    $upsertPrice   = $pdo->prepare(
        'INSERT INTO pc_prices (vendor_id, product_id, specification_id, price_usd, price_per_unit, kit_vial_count, non_standard_kit, source_file_id, is_active)
         VALUES (?,?,?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE price_usd = VALUES(price_usd), price_per_unit = VALUES(price_per_unit),
           kit_vial_count = VALUES(kit_vial_count), non_standard_kit = VALUES(non_standard_kit),
           source_file_id = VALUES(source_file_id), is_active = 1, created_at = NOW()'
    );

    $imported = 0;
    foreach ($result['prices'] as $p) {
        $name  = trim((string)($p['canonical_name'] ?? ''));
        $label = trim((string)($p['spec_label'] ?? ''));
        $value = (float)($p['numeric_value'] ?? 0);
        $price = (float)($p['price_usd'] ?? 0);
        if (!$name || !$label || $value <= 0 || $price <= 0) continue;

        $findProduct->execute([$name, $name]);
        $productId = $findProduct->fetchColumn();
        if (!$productId) {
            $insertProduct->execute([$name]);
            $productId = (int)$pdo->lastInsertId();
        }

        $findSpec->execute([$productId, $label]);
        $specId = $findSpec->fetchColumn();
        if (!$specId) {
            $unit = in_array($p['unit'] ?? '', ['mg', 'iu', 'ml'], true) ? $p['unit'] : 'other';
            $insertSpec->execute([$productId, $label, $value, $unit]);
            $specId = (int)$pdo->lastInsertId();
        }

        $kitCount = (int)($p['kit_vial_count'] ?? 10);
        $upsertPrice->execute([
            $file['vendor_id'], $productId, $specId, $price, round($price / $value, 6),
            $kitCount, !empty($p['non_standard_kit']) ? 1 : 0, $id,
        ]);
        $imported++;
    }

    $notes = $warnings ? implode(' | ', $warnings) : null;
    $pdo->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ?, processed_at = NOW() WHERE id = ?')
        ->execute(['complete', $notes, $id]);
    $pdo->prepare('UPDATE pc_vendor_files SET is_current = 1 WHERE id = ?')->execute([$id]);
    $pdo->commit();

    logAdminAction((int)$admin['id'], 'process_vendor_file', ['file_id' => $id, 'imported' => $imported, 'warnings' => count($warnings)]);
    jsonResponse(['message' => "Imported $imported price rows.", 'imported' => $imported, 'warnings' => $warnings]);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    db()->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ? WHERE id = ?')
        ->execute(['failed', $e->getMessage(), $id]);
    jsonResponse(['error' => 'Processing failed.', 'message' => $e->getMessage()], 500);
}
