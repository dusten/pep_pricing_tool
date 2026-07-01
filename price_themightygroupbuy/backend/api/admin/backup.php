<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// GET /admin/backup — streams a ZIP of database.sql + vendor_files/
method('GET');
$admin = requireAdmin();

$tmpDir = sys_get_temp_dir() . '/pc_backup_' . bin2hex(random_bytes(8));
mkdir($tmpDir, 0700, true);
$cnfPath = "$tmpDir/my.cnf";
$sqlPath = "$tmpDir/database.sql";
$zipPath = "$tmpDir/backup.zip";

// ponytail: mysqldump via an options file so the DB password never appears in argv/process list
file_put_contents($cnfPath, "[client]\nuser=" . DB_USER . "\npassword=" . DB_PASS . "\nhost=" . DB_HOST . "\nport=" . DB_PORT . "\n");
chmod($cnfPath, 0600);

exec('mysqldump --defaults-extra-file=' . escapeshellarg($cnfPath) . ' --single-transaction '
    . escapeshellarg(DB_NAME) . ' > ' . escapeshellarg($sqlPath) . ' 2>&1', $out, $exitCode);

if ($exitCode !== 0 || !is_file($sqlPath)) {
    array_map('unlink', glob("$tmpDir/*"));
    rmdir($tmpDir);
    jsonResponse(['error' => 'mysqldump failed.', 'detail' => implode("\n", $out)], 500);
}

$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);
$zip->addFile($sqlPath, 'database.sql');

$storageDir = dirname(__DIR__, 2) . '/storage/vendor_files';
if (is_dir($storageDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $localName = 'vendor_files/' . substr($file->getPathname(), strlen($storageDir) + 1);
            $zip->addFile($file->getPathname(), $localName);
        }
    }
}
$zip->close();
unlink($cnfPath);
unlink($sqlPath);

logAdminAction((int)$admin['id'], 'download_backup', []);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="price-backup-' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
unlink($zipPath);
rmdir($tmpDir);
exit;
