<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

// GET /stacks — active stacks for the "Buy This Stack" Dashboard card (any authed user)
method('GET');
requireAuth();

$rows = cacheGet('stacks_data', 'stacks_active', 600, function () {
    $rows = db()->query(
        "SELECT s.id, s.name, s.description, (SELECT COUNT(*) FROM pc_stack_items si WHERE si.stack_id = s.id) AS item_count
         FROM pc_stacks s WHERE s.is_active = 1 ORDER BY s.name"
    )->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['item_count'] = (int)$r['item_count']; }
    return $rows;
});

jsonResponse(['stacks' => $rows]);
