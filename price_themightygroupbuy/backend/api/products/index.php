<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET', 'POST');
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = cacheGet('admin_products', 'all', 60, function () {
        $rows = db()->query(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM pc_product_aliases a WHERE a.product_id = p.id) AS alias_count,
                    (SELECT COUNT(DISTINCT pr.vendor_id) FROM pc_prices pr WHERE pr.product_id = p.id AND pr.is_active = 1) AS vendor_count
             FROM pc_products p
             ORDER BY p.canonical_name ASC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id']           = (int)$r['id'];
            $r['alias_count']  = (int)$r['alias_count'];
            $r['vendor_count'] = (int)$r['vendor_count'];
        }
        return $rows;
    });
    jsonResponse(['products' => $rows]);
}

$d    = input();
$name = trim($d['canonical_name'] ?? '');
if (mb_strlen($name) < 2) jsonResponse(['error' => 'Canonical name is required.'], 422);
$category = in_array($d['category'] ?? '', ['glp1','peptide','hormone','blend','consumable','other'], true)
    ? $d['category'] : 'peptide';

$stmt = db()->prepare('INSERT INTO pc_products (canonical_name, category, notes) VALUES (?,?,?)');
$stmt->execute([$name, $category, trim($d['notes'] ?? '') ?: null]);
$id = (int)db()->lastInsertId();
cacheBust('admin_products');
logAdminAction((int)$admin['id'], 'create_product', ['product_id' => $id, 'canonical_name' => $name]);

jsonResponse(['id' => $id], 201);
