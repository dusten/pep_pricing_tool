<?php
declare(strict_types=1);
// One-off: reprocess all 26 uploaded vendor price-list files through the real
// extraction pipeline (used during the overnight full reimport + extraction-
// scope-broadening pass, 2026-07-05). Run via `sudo -u apache php` from the
// app root (mirrors backend/api/files/process.php's own code path exactly —
// not a shortcut around it, just a reliable transport for a long unattended
// batch after browser-tab automation proved flaky for a run this size).
require_once __DIR__ . '/../price_themightygroupbuy/backend/config.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/helpers.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/claude.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/xlsx_reader.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/zip_reader.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/price_import.php';
require_once __DIR__ . '/../price_themightygroupbuy/backend/lib/vendor_file_processor.php';

$adminId = 4; // the account driving this whole session
$model   = CLAUDE_MODEL_DEFAULT;
$ids     = [2, 3, 4, 5, 8, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31];

foreach ($ids as $id) {
    $stmt = db()->prepare('SELECT * FROM pc_vendor_files WHERE id = ?');
    $stmt->execute([$id]);
    $file = $stmt->fetch();
    if (!$file) { echo "file $id: not found\n"; continue; }

    db()->prepare('UPDATE pc_vendor_files SET processing_status = ? WHERE id = ?')->execute(['processing', $id]);

    if (vendorFileQualifiesForAsync($file)) {
        echo "file $id: qualifies for async (large PDF) - skipped, needs the real cron worker\n";
        continue;
    }

    try {
        $result = processVendorFile($file, $model);
        logAdminAction($adminId, 'process_vendor_file', [
            'file_id' => $id, 'imported' => $result['imported'], 'unchanged' => $result['unchanged'],
            'pending' => $result['pending'], 'warnings' => count($result['warnings']),
        ]);
        echo "file $id: OK imported={$result['imported']} unchanged={$result['unchanged']} pending={$result['pending']}\n";
    } catch (Throwable $e) {
        db()->prepare('UPDATE pc_vendor_files SET processing_status = ?, processing_notes = ? WHERE id = ?')
            ->execute(['failed', $e->getMessage(), $id]);
        echo "file $id: FAILED - " . $e->getMessage() . "\n";
    }
}
echo "=== batch done ===\n";
