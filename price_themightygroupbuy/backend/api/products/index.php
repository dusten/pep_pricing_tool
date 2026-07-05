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

$stmt = db()->prepare('INSERT INTO pc_products (canonical_name, notes) VALUES (?,?)');
$stmt->execute([$name, trim($d['notes'] ?? '') ?: null]);
$id = (int)db()->lastInsertId();

$classificationIds = array_values(array_unique(array_map('intval', (array)($d['classification_ids'] ?? []))));
if ($classificationIds) {
    $inClause = implode(',', array_fill(0, count($classificationIds), '?'));
    $ins = db()->prepare("INSERT IGNORE INTO pc_product_classifications (product_id, classification_id) SELECT ?, id FROM pc_classifications WHERE id IN ($inClause)");
    $ins->execute([$id, ...$classificationIds]);
}

cacheBust('admin_products');
cacheBust('pricing_data');
logAdminAction((int)$admin['id'], 'create_product', ['product_id' => $id, 'canonical_name' => $name]);

jsonResponse(['id' => $id], 201);
