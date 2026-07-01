<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('POST', 'DELETE');
$admin     = requireAdmin();
$productId = (int)($PARAMS['id'] ?? 0);
$aliasId   = isset($PARAMS['aliasId']) ? (int)$PARAMS['aliasId'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!$aliasId) jsonResponse(['error' => 'Alias id required.'], 422);
    db()->prepare('DELETE FROM pc_product_aliases WHERE id = ? AND product_id = ?')->execute([$aliasId, $productId]);
    logAdminAction((int)$admin['id'], 'delete_alias', ['product_id' => $productId, 'alias_id' => $aliasId]);
    jsonResponse(['message' => 'Alias removed.']);
}

$d     = input();
$alias = trim($d['alias'] ?? '');
if (mb_strlen($alias) < 2) jsonResponse(['error' => 'Alias is required.'], 422);

$exists = db()->prepare('SELECT id FROM pc_product_aliases WHERE alias = ? LIMIT 1');
$exists->execute([$alias]);
if ($exists->fetch()) jsonResponse(['error' => 'That alias is already in use.'], 409);

db()->prepare('INSERT INTO pc_product_aliases (product_id, alias) VALUES (?,?)')->execute([$productId, $alias]);
logAdminAction((int)$admin['id'], 'add_alias', ['product_id' => $productId, 'alias' => $alias]);
jsonResponse(['message' => 'Alias added.'], 201);
