<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// Backlog #18 — admin-picked featured product for the public calendar.
// GET    /admin/calendar-features?month=YYYY-MM   — list features that month
// POST   /admin/calendar-features                 — set/replace one date's feature
//        body: { feature_date, product_id, specification_id?, note? }
// DELETE /admin/calendar-features/{YYYY-MM-DD}     — clear a date's feature
method('GET', 'POST', 'DELETE');
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonResponse(['error' => 'month must be YYYY-MM.'], 422);
    $stmt = db()->prepare(
        "SELECT cf.feature_date, cf.product_id, cf.specification_id, cf.note,
                p.canonical_name AS product, s.spec_label AS spec
         FROM pc_calendar_features cf
         JOIN pc_products p        ON p.id = cf.product_id
         LEFT JOIN pc_specifications s ON s.id = cf.specification_id
         WHERE DATE_FORMAT(cf.feature_date, '%Y-%m') = ?
         ORDER BY cf.feature_date ASC"
    );
    $stmt->execute([$month]);
    jsonResponse(['features' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $date = $PARAMS['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(['error' => 'Invalid date.'], 422);
    db()->prepare('DELETE FROM pc_calendar_features WHERE feature_date = ?')->execute([$date]);
    cacheBust('calendar_data'); // public calendar reads featured from this
    logAdminAction((int)$admin['id'], 'clear_calendar_feature', ['date' => $date]);
    jsonResponse(['message' => 'Feature cleared.']);
}

// POST — upsert one date's feature.
$d       = input();
$date    = trim((string)($d['feature_date'] ?? ''));
$product = (int)($d['product_id'] ?? 0);
$specId  = isset($d['specification_id']) && $d['specification_id'] !== '' ? (int)$d['specification_id'] : null;
$note    = trim((string)($d['note'] ?? '')) ?: null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(['error' => 'A valid date (YYYY-MM-DD) is required.'], 422);
if (!$product) jsonResponse(['error' => 'A product is required.'], 422);

$check = db()->prepare('SELECT 1 FROM pc_products WHERE id = ?');
$check->execute([$product]);
if (!$check->fetchColumn()) jsonResponse(['error' => 'Product not found.'], 404);

if ($specId !== null) {
    // Spec must belong to the chosen product — otherwise the public resolver
    // would find no price and silently show nothing.
    $specCheck = db()->prepare('SELECT 1 FROM pc_specifications WHERE id = ? AND product_id = ?');
    $specCheck->execute([$specId, $product]);
    if (!$specCheck->fetchColumn()) jsonResponse(['error' => 'That spec does not belong to the chosen product.'], 422);
}

db()->prepare(
    'INSERT INTO pc_calendar_features (feature_date, product_id, specification_id, note, created_by)
     VALUES (?,?,?,?,?)
     ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), specification_id = VALUES(specification_id),
       note = VALUES(note), created_by = VALUES(created_by), created_at = NOW()'
)->execute([$date, $product, $specId, $note, (int)$admin['id']]);

cacheBust('calendar_data');
logAdminAction((int)$admin['id'], 'set_calendar_feature', ['date' => $date, 'product_id' => $product, 'spec_id' => $specId]);
jsonResponse(['message' => 'Featured product set.']);
