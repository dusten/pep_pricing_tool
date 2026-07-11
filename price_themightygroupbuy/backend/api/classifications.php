<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

// GET  /classifications — list all tags (any authed user; filter UI + admin product form)
// POST /classifications — admin: add a new tag ad hoc, body: { name }
method('GET', 'POST');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAuth();
    $rows = cacheGet('classifications_data', 'classifications', 600, function () {
        $rows = db()->query('SELECT id, name FROM pc_classifications ORDER BY name')->fetchAll();
        foreach ($rows as &$r) $r['id'] = (int)$r['id'];
        return $rows;
    });
    jsonResponse(['classifications' => $rows]);
}

$admin = requireAdmin();
$name  = trim((string)(input()['name'] ?? ''));
if (mb_strlen($name) < 2) jsonResponse(['error' => 'Classification name is required.'], 422);

$stmt = db()->prepare('INSERT IGNORE INTO pc_classifications (name) VALUES (?)');
$stmt->execute([$name]);
$id = (int)db()->lastInsertId();
if (!$id) {
    // Already existed (INSERT IGNORE no-op) — look it up so the caller still gets an id.
    $existing = db()->prepare('SELECT id FROM pc_classifications WHERE name = ? LIMIT 1');
    $existing->execute([$name]);
    $id = (int)$existing->fetchColumn();
}

cacheBust('classifications_data');
logAdminAction((int)$admin['id'], 'create_classification', ['classification_id' => $id, 'name' => $name]);
jsonResponse(['id' => $id, 'name' => $name], 201);
