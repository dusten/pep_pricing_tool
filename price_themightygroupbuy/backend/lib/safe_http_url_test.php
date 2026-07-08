<?php
declare(strict_types=1);
// Runnable self-check for the href-destined URL sanitizer (XSS gate — coa_url,
// vendor website). Run: php backend/lib/safe_http_url_test.php
require_once dirname(__DIR__) . '/helpers.php';

// The attack shapes FILTER_VALIDATE_URL alone lets through
assert(safeHttpUrl('javascript://%0aalert(1)') === null);
assert(safeHttpUrl('javascript:alert(1)') === null);
assert(safeHttpUrl('data:text/html,<script>') === null);
assert(safeHttpUrl('vbscript:x') === null);
// Legit URLs pass through untouched, any http(s) casing
assert(safeHttpUrl('https://labs.example.com/coa/123.pdf') === 'https://labs.example.com/coa/123.pdf');
assert(safeHttpUrl('HTTP://example.com/x') === 'HTTP://example.com/x');
// Bare domain (what people actually type) gets https:// prepended
assert(safeHttpUrl('example.com/report') === 'https://example.com/report');
// Garbage and empties reject
assert(safeHttpUrl('') === null);
assert(safeHttpUrl(null) === null);
assert(safeHttpUrl('not a url') === null);

echo "safe_http_url: all assertions passed\n";
