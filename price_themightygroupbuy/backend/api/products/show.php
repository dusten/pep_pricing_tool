<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET', 'PUT');
$admin = requireAdmin();
$id    = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pc_products WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) jsonResponse(['error' => 'Product not found.'], 404);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $aliases = db()->prepare('SELECT id, alias FROM pc_product_aliases WHERE product_id = ? ORDER BY alias');
    $aliases->execute([$id]);
    $specs = db()->prepare('SELECT * FROM pc_specifications WHERE product_id = ? ORDER BY numeric_value');
    $specs->execute([$id]);
    $product['aliases']       = $aliases->fetchAll();
    $product['specifications'] = $specs->fetchAll();
    jsonResponse($product);
}

// PUT — update
$d      = input();
$fields = [];
$vals   = [];
if (array_key_exists('canonical_name', $d) && mb_strlen(trim($d['canonical_name'])) >= 2) {
    $fields[] = 'canonical_name = ?';
    $vals[]   = trim($d['canonical_name']);
}
if (array_key_exists('category', $d) && in_array($d['category'], ['glp1','peptide','hormone','blend','consumable','other'], true)) {
    $fields[] = 'category = ?';
    $vals[]   = $d['category'];
}
if (array_key_exists('notes', $d)) {
    $fields[] = 'notes = ?';
    $vals[]   = trim((string)$d['notes']) ?: null;
}
if ($fields) {
    $vals[] = $id;
    db()->prepare('UPDATE pc_products SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    cacheBust('admin_products');
    cacheBust('pricing_data'); // canonical_name/category feed comparison results
    logAdminAction((int)$admin['id'], 'update_product', ['product_id' => $id, 'fields' => array_keys($d)]);
}

jsonResponse(['message' => 'Product updated.']);
