<?php
/**
 * make_deploy_zip.php — CLI Standalone Deploy ZIP Generator
 * Jalankan: php tools/release/make_deploy_zip.php
 *
 * Membuat zip bersih siap deploy ke server/hosting.
 * Tidak butuh web server aktif. Jalankan langsung dari terminal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Hanya bisa dijalankan via CLI.\nGunakan: php tools/release/make_deploy_zip.php\n");
}

// ─── Konfigurasi ─────────────────────────────────────────────────────────────
$root = realpath(__DIR__ . '/../../');
if (!$root) {
    fwrite(STDERR, "[ERROR] Root project tidak ditemukan.\n");
    exit(1);
}

// Folder dan prefix yang TIDAK dimasukkan ke deploy zip
$excludePrefixes = [
    '.git/',
    '.cursor/',
    '.vscode/',
    'node_modules/',
    'storage/logs/',
    'storage/backups/',
    'storage/exports/',
    'storage/framework/cache/data/',
    'storage/framework/sessions/',
    'storage/framework/views/',
    'exports/',
    'playwright-report/',
    'tests-output/',
    'android_app/',
    'tests/',
    '_examples/',
    '_tracker/',
    'TEMPLATES/',
];

// File yang selalu DIMASUKKAN meski namanya mirip exclude (gitkeep untuk folder structure)
$forceInclude = [
    'storage/logs/.gitkeep',
    'storage/backups/.gitkeep',
    'storage/uploads/.gitkeep',
    'storage/signoffs/.gitkeep',
];

// ─── Fungsi Skip ─────────────────────────────────────────────────────────────
function should_skip(string $rel, array $excludePrefixes, array $forceInclude): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '') return true;

    foreach ($forceInclude as $keep) {
        if ($rel === $keep) return false;
    }

    foreach ($excludePrefixes as $prefix) {
        if (str_starts_with($rel, $prefix)) return true;
    }

    $base = strtolower(basename($rel));
    if ($base === '.ds_store') return true;
    if (str_starts_with($base, '.env') && $base !== '.env.example') return true;
    if (str_ends_with($base, '.zip')) return true;
    if (str_ends_with($base, '.log')) return true;

    return false;
}

// ─── Mulai proses ────────────────────────────────────────────────────────────
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "[ERROR] PHP extension ZipArchive tidak aktif.\n");
    exit(1);
}

$bucket   = date('Y-m');
$stamp    = date('Ymd_His');
$outDir   = $root . '/exports/deploy/' . $bucket;
$zipName  = 'ERP_RMI_SOFULL_deploy_clean_' . $stamp . '.zip';
$zipPath  = $outDir . '/' . $zipName;

@mkdir($root . '/exports', 0775, true);
@mkdir($root . '/exports/deploy', 0775, true);
@mkdir($outDir, 0775, true);
@file_put_contents($root . '/exports/.htaccess', "Deny from all\n");
@file_put_contents($root . '/exports/deploy/.htaccess', "Deny from all\n");
@file_put_contents($outDir . '/.htaccess', "Deny from all\n");

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "[ERROR] Gagal membuat file ZIP: $zipPath\n");
    exit(1);
}

echo "\n";
echo "============================================================\n";
echo "  ERP_RMI_SOFULL — Generate Clean Deploy ZIP\n";
echo "============================================================\n";
echo "  Root   : $root\n";
echo "  Output : exports/deploy/$bucket/$zipName\n";
echo "============================================================\n\n";
echo "Memproses file...\n";

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);

$filesAdded = 0;
$bytesAdded = 0;
$skipped    = 0;
$lastPrint  = time();

foreach ($it as $path => $info) {
    if (!$info->isFile()) continue;

    $abs = (string)$path;
    $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
    if ($rel === '') continue;
    if ($abs === $zipPath) continue;

    if (should_skip($rel, $excludePrefixes, $forceInclude)) {
        $skipped++;
        continue;
    }

    $zip->addFile($abs, $rel);
    $filesAdded++;
    $bytesAdded += (int)$info->getSize();

    // Progress setiap 3 detik
    if (time() - $lastPrint >= 3) {
        $mb = round($bytesAdded / 1024 / 1024, 1);
        echo "  ... $filesAdded file ditambahkan ({$mb} MB raw)...\n";
        $lastPrint = time();
    }
}

// Tambahkan README dan checklist ke dalam zip
$readme = implode("\n", [
    '# Deploy Clean Package',
    '',
    '- Generated   : ' . date('Y-m-d H:i:s'),
    '- Files       : ' . $filesAdded,
    '- Skipped     : ' . $skipped,
    '- Raw size    : ' . round($bytesAdded / 1024 / 1024, 2) . ' MB',
    '- Note        : logs/backups/exports/android_app/tests telah di-exclude.',
    '',
]) . "\n";
$zip->addFromString('DEPLOY_PACKAGE_README.md', $readme);

$checklist = implode("\n", [
    '# Post Deploy Checklist',
    '',
    '1. Upload dan extract zip ini ke docroot server/hosting.',
    '2. Set .env produksi di server (jangan copy dari local).',
    '3. Pastikan folder writable: storage/ dan uploads/',
    '   chmod -R 775 storage/ uploads/',
    '4. Import database: sql/ERP_RMI_SOFULL.sql (fresh install)',
    '   atau jalankan migrations dari sql/migrations/ (update)',
    '5. Buka /tools/health.php untuk sanity check.',
    '6. Jalankan smoke: php tools/smoke_http.php',
    '',
    'Jika ada error: cek storage/logs/ untuk detail.',
    '',
]) . "\n";
$zip->addFromString('POST_DEPLOY_CHECKLIST.md', $checklist);

$zip->close();

$zipSizeBytes = (int)@filesize($zipPath);
$zipSizeMB    = round($zipSizeBytes / 1024 / 1024, 2);
$rawMB        = round($bytesAdded / 1024 / 1024, 2);

// Buat TAR.GZ untuk Synology (File Station sering gagal extract ZIP besar)
$tarName = 'ERP_RMI_SOFULL_deploy_' . date('Ymd') . '.tar.gz';
$tarPath = $outDir . '/' . $tarName;
$extractDir = sys_get_temp_dir() . '/erp_deploy_' . getmypid();
@mkdir($extractDir, 0755, true);
$zip2 = new ZipArchive();
if ($zip2->open($zipPath) === true) {
    $zip2->extractTo($extractDir);
    $zip2->close();
    $tarCmd = 'tar -czf ' . escapeshellarg($tarPath) . ' -C ' . escapeshellarg($extractDir) . ' .';
    @exec($tarCmd);
    @exec('rm -rf ' . escapeshellarg($extractDir));
}
$tarSizeMB = is_file($tarPath) ? round(filesize($tarPath) / 1024 / 1024, 2) : 0;

echo "\n============================================================\n";
echo "  SELESAI!\n";
echo "============================================================\n";
echo "  File zip   : exports/deploy/$bucket/$zipName\n";
echo "  File tar.gz: exports/deploy/$bucket/$tarName (untuk Synology)\n";
echo "  Ukuran zip : {$zipSizeMB} MB\n";
echo "  Ukuran tar : {$tarSizeMB} MB\n";
echo "  Raw total  : {$rawMB} MB\n";
echo "  File added : $filesAdded\n";
echo "  Di-skip    : $skipped\n";
echo "============================================================\n\n";
echo "Lokasi lengkap:\n";
echo "$zipPath\n";
echo "$tarPath\n\n";
echo "Synology: Gunakan TAR.GZ jika ZIP tidak bisa di-extract!\n\n";

exit(0);
