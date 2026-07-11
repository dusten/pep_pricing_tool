<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET', 'POST');
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = cacheGet('admin_products', 'all', 600, function () {
        $rows = db()->query(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM pc_product_aliases a WHERE a.product_id = p.id) AS alias_count,
                    (SELECT COUNT(DISTINCT pr.vendor_id) FROM pc_prices pr WHERE pr.product_id = p.id AND pr.is_active = 1) AS vendor_count
             FROM pc_products p
             ORDER BY p.canonical_name ASC"
        )->fetchAll();
        $byId = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['id']              = (int)$r['id'];
            $rows[$i]['alias_count']     = (int)$r['alias_count'];
            $rows[$i]['vendor_count']    = (int)$r['vendor_count'];
            $rows[$i]['aliases']         = [];
            $rows[$i]['classifications'] = [];
            $byId[(int)$r['id']]         = $i;
        }

        // Bulk-fetch aliases/classifications for every product in 2 queries total,
        // grouped in PHP — was previously a per-product GET /api/products/{id} loop
        // in the frontend (194 sequential round trips just to render this list;
        // every merge/alias/edit action re-triggered the whole loop on refresh).
        // Specs+per-spec prices stay out of this payload; they're only shown for
        // one expanded row at a time, so ProductsTab.vue fetches those on demand.
        foreach (db()->query('SELECT product_id, id, alias FROM pc_product_aliases ORDER BY alias') as $a) {
            if (isset($byId[$a['product_id']])) $rows[$byId[$a['product_id']]]['aliases'][] = ['id' => (int)$a['id'], 'alias' => $a['alias']];
        }
        foreach (db()->query(
            'SELECT pc.product_id, c.id, c.name FROM pc_classifications c
             JOIN pc_product_classifications pc ON pc.classification_id = c.id
             ORDER BY c.name'
        ) as $c) {
            if (isset($byId[$c['product_id']])) $rows[$byId[$c['product_id']]]['classifications'][] = ['id' => (int)$c['id'], 'name' => $c['name']];
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
cacheBust('comparison_data');
logAdminAction((int)$admin['id'], 'create_product', ['product_id' => $id, 'canonical_name' => $name]);

jsonResponse(['id' => $id], 201);
