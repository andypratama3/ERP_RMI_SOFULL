<?php
/**
 * backup_verify_cli.php — CLI: verify latest backup package (manifest + checksums).
 *
 * Usage: php tools/ops/backup_verify_cli.php [--write-last] [--strict]
 * Artifact: storage/logs/backup_verify_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);
$strict = in_array('--strict', $args, true);

$backupRoot = $root . '/storage/backups';
$latestFile = $backupRoot . '/LATEST_BACKUP.txt';

$result = [
    'ok' => false,
    'run_at' => date(DateTimeInterface::ATOM),
    'package' => null,
    'manifest_found' => false,
    'checksums_ok' => false,
    'db_sql_gz_found' => false,
    'error' => null,
];

if (!is_file($latestFile)) {
    $result['error'] = 'LATEST_BACKUP.txt not found — run backup first';
    goto output;
}

$packageDir = trim((string)@file_get_contents($latestFile));
if ($packageDir === '' || !is_dir($packageDir)) {
    $result['error'] = 'Latest backup package directory not found: ' . basename($packageDir);
    goto output;
}

$result['package'] = basename($packageDir);

// 1) manifest.json
$manifestPath = $packageDir . '/manifest.json';
if (!is_file($manifestPath)) {
    $result['error'] = 'manifest.json not found in backup package';
    goto output;
}
$result['manifest_found'] = true;

// 2) checksums.sha256
$checksumsPath = $packageDir . '/checksums.sha256';
if (!is_file($checksumsPath)) {
    $result['error'] = 'checksums.sha256 not found';
    goto output;
}

// Verify checksums (PHP-native, no shasum dependency)
$checksumsRaw = (string)@file_get_contents($checksumsPath);
$lines = array_filter(array_map('trim', explode("\n", $checksumsRaw)));
$failed = [];
foreach ($lines as $line) {
    if (!preg_match('/^([a-f0-9]{64})\s+(.+)$/', $line, $m)) continue;
    $expectedHash = $m[1];
    $filename = $m[2];
    $filepath = $packageDir . '/' . ltrim($filename, './');
    if (!is_file($filepath)) {
        $failed[] = $filename . ':missing';
        continue;
    }
    $actualHash = hash_file('sha256', $filepath);
    if ($actualHash !== $expectedHash) {
        $failed[] = $filename . ':mismatch';
    }
}
$result['checksums_ok'] = count($failed) === 0;
if (!$result['checksums_ok']) {
    $result['error'] = 'checksum mismatch: ' . implode(', ', array_slice($failed, 0, 3));
    goto output;
}

// 3) db.sql.gz readable (optional but log)
$dbPath = $packageDir . '/db.sql.gz';
$result['db_sql_gz_found'] = is_file($dbPath) && filesize($dbPath) > 0;

$result['ok'] = true;

output:
if ($writeLast) {
    $logsDir = ts_storage_logs_dir();
    @file_put_contents($logsDir . '/backup_verify_last.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : ($strict ? 2 : 1));
