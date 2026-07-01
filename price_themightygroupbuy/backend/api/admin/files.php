<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

method('GET');
requireAdmin();

$rows = db()->query(
    "SELECT f.*, v.display_name AS vendor_name
     FROM pc_vendor_files f JOIN pc_vendors v ON v.id = f.vendor_id
     ORDER BY f.uploaded_at DESC"
)->fetchAll();

jsonResponse(['files' => $rows]);
