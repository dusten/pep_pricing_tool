<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/helpers.php';

// GET    /admin/stacks/{id} — one stack + its component items
// PUT    /admin/stacks/{id} — update name/description/is_active
// DELETE /admin/stacks/{id} — delete the stack (cascades its items)
method('GET', 'PUT', 'DELETE');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pc_stacks WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$stack = $stmt->fetch();
if (!$stack) jsonResponse(['error' => 'Stack not found.'], 404);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    db()->prepare('DELETE FROM pc_stacks WHERE id = ?')->execute([$id]);
    cacheBust('pricing_data'); // GET /api/stacks (Dashboard card) shares this group
    logAdminAction((int)$admin['id'], 'delete_stack', ['stack_id' => $id, 'name' => $stack['name']]);
    jsonResponse(['message' => 'Stack deleted.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $items = db()->prepare(
        'SELECT si.id, si.product_id, si.specification_id, p.canonical_name AS product, s.spec_label AS spec
         FROM pc_stack_items si
         JOIN pc_products p       ON p.id = si.product_id
         JOIN pc_specifications s ON s.id = si.specification_id
         WHERE si.stack_id = ? ORDER BY p.canonical_name, s.numeric_value'
    );
    $items->execute([$id]);
    $items = $items->fetchAll();
    foreach ($items as &$it) {
        $it['id']               = (int)$it['id'];
        $it['product_id']       = (int)$it['product_id'];
        $it['specification_id'] = (int)$it['specification_id'];
    }
    $stack['id']        = (int)$stack['id'];
    $stack['is_active'] = (bool)$stack['is_active'];
    $stack['items']     = $items;
    jsonResponse($stack);
}

// PUT — update
$d      = input();
$fields = [];
$vals   = [];
if (array_key_exists('name', $d) && mb_strlen(trim($d['name'])) >= 2) {
    $fields[] = 'name = ?';
    $vals[]   = trim($d['name']);
}
if (array_key_exists('description', $d)) {
    $fields[] = 'description = ?';
    $vals[]   = trim((string)$d['description']) ?: null;
}
if (array_key_exists('is_active', $d)) {
    $fields[] = 'is_active = ?';
    $vals[]   = !empty($d['is_active']) ? 1 : 0;
}
if (!$fields) jsonResponse(['error' => 'Nothing to update.'], 422);

$vals[] = $id;
db()->prepare('UPDATE pc_stacks SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
cacheBust('pricing_data'); // GET /api/stacks (Dashboard card) shares this group
logAdminAction((int)$admin['id'], 'update_stack', ['stack_id' => $id, 'fields' => array_keys($d)]);
jsonResponse(['message' => 'Stack updated.']);
