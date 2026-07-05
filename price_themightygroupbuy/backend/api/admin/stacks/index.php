<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/helpers.php';

// GET  /admin/stacks — list all stacks (active + inactive), admin management view
// POST /admin/stacks — create a new stack, body: { name, description? }
method('GET', 'POST');
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = db()->query(
        "SELECT s.*, (SELECT COUNT(*) FROM pc_stack_items si WHERE si.stack_id = s.id) AS item_count
         FROM pc_stacks s ORDER BY s.name"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['is_active']  = (bool)$r['is_active'];
        $r['item_count'] = (int)$r['item_count'];
    }
    jsonResponse(['stacks' => $rows]);
}

$d    = input();
$name = trim($d['name'] ?? '');
if (mb_strlen($name) < 2) jsonResponse(['error' => 'Stack name is required.'], 422);

$stmt = db()->prepare('INSERT INTO pc_stacks (name, description) VALUES (?,?)');
$stmt->execute([$name, trim($d['description'] ?? '') ?: null]);
$id = (int)db()->lastInsertId();

cacheBust('pricing_data'); // GET /api/stacks (Dashboard card) shares this group
logAdminAction((int)$admin['id'], 'create_stack', ['stack_id' => $id, 'name' => $name]);
jsonResponse(['id' => $id], 201);
