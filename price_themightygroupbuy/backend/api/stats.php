<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/config.php';
require_once dirname(__DIR__, 1) . '/helpers.php';

// GET /stats — dashboard summary tiles (active vendors, products tracked,
// active price entries). Was a hardcoded placeholder ("ponytail: Phase 2")
// on the frontend since Phase 1 — this is that endpoint.
method('GET');
requireAuth();

$stats = cacheGet('stats', 'summary', 60, function () {
    $pdo = db();
    return [
        'vendors'  => (int)$pdo->query('SELECT COUNT(*) FROM pc_vendors WHERE is_active = 1')->fetchColumn(),
        'products' => (int)$pdo->query('SELECT COUNT(*) FROM pc_products')->fetchColumn(),
        'prices'   => (int)$pdo->query('SELECT COUNT(*) FROM pc_prices WHERE is_active = 1')->fetchColumn(),
    ];
});

jsonResponse($stats);
