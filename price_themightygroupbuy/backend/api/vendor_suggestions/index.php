<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/malware_scan.php';
require_once dirname(__DIR__, 2) . '/lib/zip_reader.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_helpers.php';
require_once dirname(__DIR__, 2) . '/lib/price_import.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_suggestions.php';
require_once dirname(__DIR__, 2) . '/email.php';

// GET  /vendor-suggestions — caller's own suggestions
// POST /vendor-suggestions — submit a new one (multipart: contact fields + file)
method('GET', 'POST');
$user = requireSuggestionAccess();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->prepare(
        'SELECT id, display_name, status, score_json, admin_note,
                (duplicate_of_vendor_id IS NOT NULL) AS is_duplicate, created_at
         FROM pc_vendor_suggestions WHERE user_id = ? ORDER BY created_at DESC'
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_duplicate'] = (bool)$row['is_duplicate'];
        $row['score_json'] = $row['score_json'] ? json_decode($row['score_json'], true) : null;
    }
    jsonResponse(['suggestions' => $rows]);
}

// ── Submit ────────────────────────────────────────────────────────
rateLimit('vendor_suggest_' . $user['id'], 3, 3600);

// Durable 3-per-7-days count — memcached counters reset on restart, this
// doesn't. Separate from the hourly rate limit above (that one guards burst
// abuse, this one guards sustained abuse across restarts).
$weekCount = db()->prepare(
    "SELECT COUNT(*) FROM pc_vendor_suggestions WHERE user_id = ? AND created_at > NOW() - INTERVAL 7 DAY"
);
$weekCount->execute([$user['id']]);
if ((int)$weekCount->fetchColumn() >= 3) {
    jsonResponse(['error' => 'You can suggest up to 3 vendors per week. Please try again later.'], 429);
}

$relationship = in_array($_POST['relationship'] ?? '', ['vendor_rep', 'customer', 'other'], true) ? $_POST['relationship'] : 'other';
$displayName  = trim((string)($_POST['display_name'] ?? ''));
$contactName  = trim((string)($_POST['contact_name'] ?? ''));
$email        = trim((string)($_POST['email'] ?? ''));
$whatsapp     = trim((string)($_POST['whatsapp'] ?? ''));
$discord      = trim((string)($_POST['discord'] ?? ''));
$telegram     = trim((string)($_POST['telegram'] ?? ''));
$website      = safeHttpUrl((string)($_POST['website'] ?? ''));
$notes        = trim((string)($_POST['notes'] ?? ''));

if (!$displayName) jsonResponse(['error' => 'Vendor name is required.'], 422);
if (!$email && !$whatsapp && !$discord && !$telegram && !$website) {
    jsonResponse(['error' => 'At least one contact method is required.'], 422);
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'A pricing file is required.'], 422);
}

// ── File gate — same shape as vendors/files.php ──────────────────
$original = $_FILES['file']['name'];
$ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
$typeMap  = ['pdf' => 'pdf', 'xlsx' => 'xlsx', 'csv' => 'csv', 'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'zip' => 'zip'];
if (!isset($typeMap[$ext])) {
    jsonResponse(['error' => 'Only PDF, XLSX, CSV, JPG, PNG, and ZIP files are supported.'], 422);
}
if ((int)$_FILES['file']['size'] > 5 * 1024 * 1024) {
    jsonResponse(['error' => 'File is too large (5MB max).'], 422);
}

$dir = dirname(__DIR__, 2) . "/storage/vendor_suggestions/{$user['id']}";
if (!is_dir($dir)) mkdir($dir, 0770, true);

$contentHash = hash_file('sha256', $_FILES['file']['tmp_name']);

// Same user, same bytes, not already rejected → block a resubmit before it
// can burn another Claude call (rejected is the one status where retrying
// after fixing the file is expected).
$dupStmt = db()->prepare(
    "SELECT id FROM pc_vendor_suggestions WHERE user_id = ? AND content_hash = ? AND status != 'rejected' LIMIT 1"
);
$dupStmt->execute([$user['id'], $contentHash]);
if ($dupStmt->fetch()) {
    jsonResponse(['error' => "You've already submitted this exact file."], 422);
}

$storedName = generateToken(16) . ".$ext";
$storedPath = "$dir/$storedName";
if (!move_uploaded_file($_FILES['file']['tmp_name'], $storedPath)) {
    jsonResponse(['error' => 'Failed to save the uploaded file.'], 500);
}

if ($typeMap[$ext] === 'zip') {
    $zipCheck = new ZipArchive();
    if ($zipCheck->open($storedPath) !== true) {
        unlink($storedPath);
        jsonResponse(['error' => 'Could not open the uploaded ZIP file.'], 422);
    }
    try {
        validateZipEntries($zipCheck);
    } catch (Throwable $e) {
        $zipCheck->close();
        unlink($storedPath);
        jsonResponse(['error' => 'Invalid ZIP.', 'message' => $e->getMessage()], 422);
    }
    $zipCheck->close();
}

$clean = true;
if (MALWARE_SCAN_ENABLED) {
    try {
        $clean = $typeMap[$ext] === 'zip' ? scanZipEntriesForMalware($storedPath) : scanFileForMalware($storedPath);
    } catch (Throwable $e) {
        jsonResponse(['error' => 'Malware scan could not run. Upload rejected.', 'message' => $e->getMessage()], 503);
    }
}

$relativePath = "vendor_suggestions/{$user['id']}/$storedName";

if (!$clean) {
    $quarantinedPath = quarantineFile($storedPath);
    db()->prepare(
        'INSERT INTO pc_vendor_suggestions
           (user_id, relationship, display_name, contact_name, email, whatsapp, discord, telegram, website, notes,
            original_filename, stored_path, file_type, file_size_bytes, content_hash, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $user['id'], $relationship, $displayName, $contactName ?: null, $email ?: null, $whatsapp ?: null,
        $discord ?: null, $telegram ?: null, $website, $notes ?: null,
        $original, $quarantinedPath, $typeMap[$ext], (int)$_FILES['file']['size'], $contentHash, 'virus_detected',
    ]);
    jsonResponse(['error' => 'This file was flagged by malware scanning and was not accepted.'], 422);
}

// Soft dedup flag — display_name/website host, against catalog (sets
// duplicate_of_vendor_id) AND other pending suggestions for the same vendor
// (noted in admin_note instead — there's no vendor row yet to point at).
// Never blocks submission, admin decides.
$duplicateVendorId = findDuplicateVendorForSuggestion($displayName, $website);
$adminNote = findDuplicateSuggestionNote($displayName, $website, (int)$user['id']);

// Template CSV detection: header match, not just the .csv extension — a
// vendor's own export with a different column layout still needs the
// Claude pipeline (Phase 2), it just isn't built yet, so it lands pending_parse.
$isTemplate = false;
$status = 'awaiting_approval'; // non-template files need an admin to queue them for Claude (backlog #69)
$extractedJson = null;
$scoreJson = null;

if ($typeMap[$ext] === 'csv') {
    $fh = fopen($storedPath, 'r');
    $header = $fh ? fgetcsv($fh) : false;
    if ($fh) fclose($fh);
    $isTemplate = $header !== false && array_map(fn($h) => strtolower(trim((string)$h)), $header) === SUGGESTION_TEMPLATE_CSV_HEADER;
}

if ($isTemplate) {
    try {
        $extracted = parseSuggestionTemplateCsv($storedPath);
        $score = scoreSuggestionPrices(db(), $extracted['prices']);
        $extractedJson = json_encode($extracted);
        $scoreJson = json_encode($score);
        $status = 'scored';
    } catch (Throwable $e) {
        $status = 'parse_failed';
        $extractedJson = json_encode(['contact' => [], 'warnings' => [$e->getMessage()], 'prices' => []]);
    }
}

$stmt = db()->prepare(
    'INSERT INTO pc_vendor_suggestions
       (user_id, relationship, display_name, contact_name, email, whatsapp, discord, telegram, website, notes,
        original_filename, stored_path, file_type, file_size_bytes, content_hash, is_template_csv, status,
        extracted_json, score_json, duplicate_of_vendor_id, admin_note)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$stmt->execute([
    $user['id'], $relationship, $displayName, $contactName ?: null, $email ?: null, $whatsapp ?: null,
    $discord ?: null, $telegram ?: null, $website, $notes ?: null,
    $original, $relativePath, $typeMap[$ext], (int)$_FILES['file']['size'], $contentHash, $isTemplate ? 1 : 0, $status,
    $extractedJson, $scoreJson, $duplicateVendorId, $adminNote,
]);
$suggestionId = (int)db()->lastInsertId();

if ($status === 'scored') {
    sendSuggestionScoredEmail($email ?: $user['email'], $contactName ?: $user['display_name'], $displayName, json_decode($scoreJson, true));
}

jsonResponse([
    'id' => $suggestionId,
    'status' => $status,
    'message' => $status === 'scored'
        ? 'Scored instantly from the template file.'
        : 'File received — an admin will review it before processing.',
], 201);

/**
 * Soft-flag match against the catalog AND other pending suggestions — phone
 * isn't collected on this form (Phase 1 keeps the form light; admin can add
 * phones at accept time), so this checks display_name (case-insensitive) and
 * website host only. Never auto-rejects; admin decides.
 */
function findDuplicateVendorForSuggestion(string $displayName, ?string $website): ?int {
    $host = null;
    if ($website) {
        $host = strtolower((string)parse_url($website, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
    }

    $stmt = db()->prepare('SELECT id, website FROM pc_vendors WHERE LOWER(display_name) = LOWER(?) LIMIT 1');
    $stmt->execute([$displayName]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];

    if ($host) {
        $rows = db()->query('SELECT id, website FROM pc_vendors WHERE website IS NOT NULL')->fetchAll();
        foreach ($rows as $r) {
            $h = strtolower((string)parse_url($r['website'], PHP_URL_HOST));
            $h = preg_replace('/^www\./', '', $h);
            if ($h && $h === $host) return (int)$r['id'];
        }
    }
    return null;
}

/** "Also suggested by user #N" note when another pending suggestion looks like the same vendor. */
function findDuplicateSuggestionNote(string $displayName, ?string $website, int $userId): ?string {
    $host = null;
    if ($website) {
        $host = strtolower((string)parse_url($website, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
    }

    $stmt = db()->prepare(
        "SELECT user_id FROM pc_vendor_suggestions
         WHERE user_id != ? AND status NOT IN ('rejected', 'virus_detected')
           AND LOWER(display_name) = LOWER(?) LIMIT 1"
    );
    $stmt->execute([$userId, $displayName]);
    $row = $stmt->fetch();
    if ($row) return "Also suggested by user #{$row['user_id']}.";

    if ($host) {
        $rows = db()->prepare(
            "SELECT user_id, website FROM pc_vendor_suggestions
             WHERE user_id != ? AND status NOT IN ('rejected', 'virus_detected') AND website IS NOT NULL"
        );
        $rows->execute([$userId]);
        foreach ($rows->fetchAll() as $r) {
            $h = strtolower((string)parse_url($r['website'], PHP_URL_HOST));
            $h = preg_replace('/^www\./', '', $h);
            if ($h && $h === $host) return "Also suggested by user #{$r['user_id']}.";
        }
    }
    return null;
}
