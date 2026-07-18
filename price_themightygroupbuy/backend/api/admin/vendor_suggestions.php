<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_helpers.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_file_processor.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';
require_once dirname(__DIR__, 2) . '/lib/claude_pricing.php';
require_once dirname(__DIR__, 2) . '/email.php';

// GET  /admin/vendor-suggestions[?status=]      — list
// POST /admin/vendor-suggestions/{id}/accept    — create/commit real vendor
// POST /admin/vendor-suggestions/{id}/reject
// POST /admin/vendor-suggestions/{id}/queue     — awaiting_approval -> pending_parse (admin approves Claude extraction)
method('GET', 'POST');
$admin  = requireAdmin();
$id     = isset($PARAMS['id']) ? (int)$PARAMS['id'] : null;
$action = $PARAMS['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $where = [];
    $params = [];
    if (in_array($_GET['status'] ?? '', ['pending_parse', 'awaiting_approval', 'processing', 'scored', 'parse_failed', 'virus_detected', 'accepted', 'rejected'], true)) {
        $where[] = 'vs.status = ?';
        $params[] = $_GET['status'];
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare(
        "SELECT vs.*, u.email AS user_email, u.display_name AS user_display_name, dv.display_name AS duplicate_vendor_name
         FROM pc_vendor_suggestions vs
         JOIN pc_users u ON u.id = vs.user_id
         LEFT JOIN pc_vendors dv ON dv.id = vs.duplicate_of_vendor_id
         $whereSql
         ORDER BY vs.created_at DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Claude cost (backlog #69) — only the Phase 2 async path ever logs a call
    // against a suggestion, so most rows have zero matching pc_claude_call_log
    // rows (template-CSV suggestions never call Claude at all). Reprocessing
    // is possible, so a suggestion can have more than one log row; sum per-row
    // cost rather than summing tokens first, since a reprocess could use a
    // different model with a different rate.
    $costByRow = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $callStmt = db()->prepare(
            "SELECT vendor_suggestion_id, model, input_tokens, output_tokens,
                    cache_creation_input_tokens, cache_read_input_tokens
             FROM pc_claude_call_log
             WHERE vendor_suggestion_id IN ($placeholders)"
        );
        $callStmt->execute($ids);
        foreach ($callStmt->fetchAll() as $call) {
            $cost = estimateClaudeCallCostUsd(
                $call['model'], $call['input_tokens'], $call['output_tokens'],
                $call['cache_creation_input_tokens'], $call['cache_read_input_tokens']
            );
            if ($cost === null) continue;
            $sid = (int)$call['vendor_suggestion_id'];
            $costByRow[$sid] = ($costByRow[$sid] ?? 0) + $cost;
        }
    }

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['file_size_bytes'] = $row['file_size_bytes'] !== null ? (int)$row['file_size_bytes'] : null;
        $row['is_template_csv'] = (bool)$row['is_template_csv'];
        $row['duplicate_of_vendor_id'] = $row['duplicate_of_vendor_id'] !== null ? (int)$row['duplicate_of_vendor_id'] : null;
        $row['vendor_id'] = $row['vendor_id'] !== null ? (int)$row['vendor_id'] : null;
        $row['extracted_json'] = $row['extracted_json'] ? json_decode($row['extracted_json'], true) : null;
        $row['score_json'] = $row['score_json'] ? json_decode($row['score_json'], true) : null;
        $row['estimated_cost_usd'] = isset($costByRow[$row['id']]) ? round($costByRow[$row['id']], 4) : null;
    }
    jsonResponse(['suggestions' => $rows]);
}

if (!$id || !in_array($action, ['accept', 'reject', 'queue'], true)) {
    jsonResponse(['error' => 'Not found.'], 404);
}

$stmt = db()->prepare('SELECT * FROM pc_vendor_suggestions WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$suggestion = $stmt->fetch();
if (!$suggestion) jsonResponse(['error' => 'Suggestion not found.'], 404);

$d = input();

if ($action === 'queue') {
    if ($suggestion['status'] !== 'awaiting_approval') {
        jsonResponse(['error' => 'Only suggestions awaiting approval can be queued.'], 422);
    }
    db()->prepare("UPDATE pc_vendor_suggestions SET status = 'pending_parse' WHERE id = ?")->execute([$id]);
    logAdminAction((int)$admin['id'], 'queue_vendor_suggestion', ['suggestion_id' => $id]);
    jsonResponse(['message' => 'Suggestion queued for processing.']);
}

if ($action === 'reject') {
    db()->prepare('UPDATE pc_vendor_suggestions SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_note = ? WHERE id = ?')
        ->execute(['rejected', $admin['id'], trim((string)($d['admin_note'] ?? '')) ?: $suggestion['admin_note'], $id]);
    logAdminAction((int)$admin['id'], 'reject_vendor_suggestion', ['suggestion_id' => $id]);
    sendSuggestionRejectedEmail(
        $suggestion['email'] ?: null, $suggestion['contact_name'] ?: '', $suggestion['display_name'],
        trim((string)($d['admin_note'] ?? ''))
    );
    jsonResponse(['message' => 'Suggestion rejected.']);
}

// ── Accept ────────────────────────────────────────────────────────
if (!in_array($suggestion['status'], ['scored', 'parse_failed'], true)) {
    jsonResponse(['error' => 'Only scored or parse_failed suggestions can be accepted.'], 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO pc_vendors (display_name, contact_name, email, whatsapp, discord, telegram, website, notes, is_active, is_verified, is_hidden)
         VALUES (?,?,?,?,?,?,?,?,1,0,0)'
    )->execute([
        $suggestion['display_name'], $suggestion['contact_name'], $suggestion['email'], $suggestion['whatsapp'],
        $suggestion['discord'], $suggestion['telegram'], $suggestion['website'], $suggestion['notes'],
    ]);
    $vendorId = (int)$pdo->lastInsertId();
    saveVendorPhonesAndPaymentMethods($pdo, $vendorId, [
        'phones' => $suggestion['phones'] ? explode(',', $suggestion['phones']) : [],
        'payment_methods' => $suggestion['payment_methods'] ? explode(',', $suggestion['payment_methods']) : [],
    ]);

    // Move the suggestion's stored file into the real vendor_files tree and
    // give it a genuine pc_vendor_files row so it shows up in the vendor's
    // file history like any other upload.
    $srcPath = dirname(__DIR__, 2) . '/storage/' . $suggestion['stored_path'];
    $destDir = dirname(__DIR__, 2) . "/storage/vendor_files/$vendorId";
    if (!is_dir($destDir)) mkdir($destDir, 0770, true);
    $destRelative = "vendor_files/$vendorId/" . basename($suggestion['stored_path']);
    $destPath = dirname(__DIR__, 2) . '/storage/' . $destRelative;
    if (is_file($srcPath) && !rename($srcPath, $destPath)) {
        throw new RuntimeException('Failed to move suggestion file into vendor storage.');
    }

    $pdo->prepare(
        'INSERT INTO pc_vendor_files (vendor_id, original_filename, stored_path, file_type, category, file_size_bytes, processing_status, is_current)
         VALUES (?,?,?,?,?,?,?,1)'
    )->execute([
        $vendorId, $suggestion['original_filename'], $destRelative, $suggestion['file_type'], 'price_list',
        $suggestion['file_size_bytes'], $suggestion['status'] === 'scored' ? 'processing' : 'failed',
    ]);
    $fileId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE pc_vendor_suggestions SET status = ?, vendor_id = ?, reviewed_by = ?, reviewed_at = NOW(), admin_note = ? WHERE id = ?')
        ->execute([
            'accepted', $vendorId, $admin['id'],
            trim((string)($d['admin_note'] ?? '')) ?: $suggestion['admin_note'], $id,
        ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['error' => 'Failed to accept suggestion.', 'message' => $e->getMessage()], 500);
}

// commitExtractionResult() opens/commits its own transaction and touches
// pc_pending_imports / pc_prices / cache — must run after the suggestion's
// own transaction above is safely committed, not nested inside it (its
// catch block only marks pc_vendor_files failed, it doesn't roll back the
// vendor row we just created).
$commitResult = ['imported' => 0, 'unchanged' => 0, 'pending' => 0, 'warnings' => []];
if ($suggestion['status'] === 'scored' && $suggestion['extracted_json']) {
    $extracted = json_decode($suggestion['extracted_json'], true);
    $fileRow = ['id' => $fileId, 'vendor_id' => $vendorId];
    try {
        $commitResult = commitExtractionResult($fileRow, $extracted);
    } catch (Throwable $e) {
        db()->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ? WHERE id = ?')
            ->execute(['failed', $e->getMessage(), $fileId]);
    }
}

logAdminAction((int)$admin['id'], 'accept_vendor_suggestion', ['suggestion_id' => $id, 'vendor_id' => $vendorId]);
sendSuggestionAcceptedEmail($suggestion['email'] ?: null, $suggestion['contact_name'] ?: '', $suggestion['display_name']);

jsonResponse(['message' => 'Suggestion accepted.', 'vendor_id' => $vendorId, 'import' => $commitResult]);
