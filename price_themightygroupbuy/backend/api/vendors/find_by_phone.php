<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/vendor_helpers.php';

// GET /vendors/find-by-phone?phone=... — substring match on digits (e.g. last 4)
// for the Vendors tab's manual search box. Returns every vendor whose phone
// contains the query; the caller auto-selects on a single match and shows a
// picker otherwise.
method('GET');
requireAdmin();

$phone = trim((string)($_GET['phone'] ?? ''));
if ($phone === '') jsonResponse(['error' => 'phone is required.'], 422);

jsonResponse(['vendors' => findVendorsByPhoneSubstring(db(), $phone)]);
