<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_helpers.php';

// GET /vendors/find-by-phone?phone=... — reuses the same phone-match helper
// already powering parse-intake's "this looks like an existing vendor"
// check, as a standalone lookup for the Vendors tab's phone search box.
method('GET');
requireAdmin();

$phone = trim((string)($_GET['phone'] ?? ''));
if ($phone === '') jsonResponse(['error' => 'phone is required.'], 422);

$vendor = findVendorByPhone(db(), [$phone]);
jsonResponse(['vendor' => $vendor]);
