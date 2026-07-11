<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_helpers.php';

method('GET', 'POST');
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Hidden vendors (backlog #9) stay out of the list unless explicitly asked
    // for (?include_hidden=1 — the admin "Show hidden" toggle, for audit/unhide).
    $includeHidden = ($_GET['include_hidden'] ?? '') === '1';
    $rows = cacheGet('admin_vendors', $includeHidden ? 'all_with_hidden' : 'all', 600, function () use ($includeHidden) {
        $rows = db()->query(
            "SELECT v.*, COUNT(p.id) AS price_count, MAX(f.uploaded_at) AS last_upload
             FROM pc_vendors v
             LEFT JOIN pc_prices p ON p.vendor_id = v.id AND p.is_active = 1
             LEFT JOIN pc_vendor_files f ON f.vendor_id = v.id
             " . ($includeHidden ? '' : 'WHERE v.is_hidden = 0') . "
             GROUP BY v.id
             ORDER BY v.display_name ASC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['is_active']   = (bool)$r['is_active'];
            $r['is_hidden']   = (bool)$r['is_hidden'];
            $r['is_verified'] = (bool)$r['is_verified'];
            $r['price_count'] = (int)$r['price_count'];
        }
        return $rows;
    });
    jsonResponse(['vendors' => $rows]);
}

// POST — create (or update in place if display_name already matches an
// existing vendor — case-insensitive — so re-pasting the same vendor's
// intake reply doesn't silently create a duplicate row)
$d           = input();
$displayName = trim($d['display_name'] ?? '');
if (mb_strlen($displayName) < 2) jsonResponse(['error' => 'Display name is required.'], 422);

$pdo = db();

$existing = $pdo->prepare('SELECT id FROM pc_vendors WHERE LOWER(display_name) = LOWER(?) LIMIT 1');
$existing->execute([$displayName]);
$existingId = $existing->fetchColumn();

if ($existingId) {
    $id = (int)$existingId;
    $pdo->beginTransaction();
    updateVendorScalarFields($pdo, $id, $d);
    saveVendorPhonesAndPaymentMethods($pdo, $id, $d);
    $pdo->commit();
    cacheBust('admin_vendors');
    logAdminAction((int)$admin['id'], 'update_vendor', ['vendor_id' => $id, 'display_name' => $displayName, 'via' => 'create_name_match']);
    jsonResponse(['id' => $id, 'updated_existing' => true]);
}

$pdo->beginTransaction();
$stmt = $pdo->prepare(
    'INSERT INTO pc_vendors (display_name, contact_name, email, whatsapp, discord, telegram, website, shipping_note, notes)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$stmt->execute([
    $displayName,
    trim($d['contact_name'] ?? '') ?: null,
    trim($d['email'] ?? '') ?: null,
    trim($d['whatsapp'] ?? '') ?: null,
    trim($d['discord'] ?? '') ?: null,
    trim($d['telegram'] ?? '') ?: null,
    safeHttpUrl($d['website'] ?? null), // rendered as :href in VendorCard — http(s) only
    trim($d['shipping_note'] ?? '') ?: null,
    trim($d['notes'] ?? '') ?: null,
]);
$id = (int)$pdo->lastInsertId();

saveVendorPhonesAndPaymentMethods($pdo, $id, $d);

$pdo->commit();
cacheBust('admin_vendors');
logAdminAction((int)$admin['id'], 'create_vendor', ['vendor_id' => $id, 'display_name' => $displayName]);

jsonResponse(['id' => $id, 'updated_existing' => false], 201);
