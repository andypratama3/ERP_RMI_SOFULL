<?php
/**
 * Restore Dry-Run — CLI. Jalankan restore_now.sh --dry-run pada backup terakhir.
 *
 * Command: php tools/ops/restore_dry_run.php --from-latest --write-last
 * Artifact: storage/logs/restore_dry_run_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args = $_SERVER['argv'] ?? [];
$fromLatest = in_array('--from-latest', $args, true);
$writeLast = in_array('--write-last', $args, true);

$backupRoot = $root . '/storage/backups';
$latestFile = $backupRoot . '/LATEST_BACKUP.txt';
$logsDir = $root . '/storage/logs';

$result = [
    'ok' => false,
    'run_at' => date(DateTimeInterface::ATOM),
    'package' => null,
    'error' => null,
];

if (!$fromLatest) {
    $result['error'] = '--from-latest required';
    goto output;
}

if (!is_file($latestFile)) {
    $result['error'] = 'LATEST_BACKUP.txt not found; run backup first';
    goto output;
}

$packageDir = trim((string)@file_get_contents($latestFile));
if ($packageDir === '' || !is_dir($packageDir)) {
    $result['error'] = 'latest backup package not found or empty';
    goto output;
}

$restoreScript = $root . '/tools/restore_now.sh';
if (!is_file($restoreScript) || !is_executable($restoreScript)) {
    $result['error'] = 'restore_now.sh not found or not executable';
    goto output;
}

$cmd = escapeshellarg($restoreScript) . ' --from ' . escapeshellarg($packageDir) . ' --dry-run --restore-db --restore-files 2>&1';
$lines = [];
$code = 1;
@exec($cmd, $lines, $code);

$result['ok'] = ((int)$code === 0);
$result['package'] = basename($packageDir);
$result['exit_code'] = (int)$code;
if (!$result['ok']) {
    $result['error'] = 'restore dry-run failed: ' . implode(' ', array_slice($lines, -3));
}

output:
if ($writeLast) {
    @mkdir($logsDir, 0775, true);
    @file_put_contents($logsDir . '/restore_dry_run_last.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => $result['ok'], 'error' => $result['error']], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
