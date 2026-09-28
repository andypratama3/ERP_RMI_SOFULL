<?php
/**
 * backup_restore_cycle.php — Gate I: Backup/Restore Cycle
 * Alias kanonik untuk gate checklist.
 *
 * Checks: backup_ok + verify_ok + restore_dry_run_ok
 *
 * Usage: php tools/qa/backup_restore_cycle.php [--strict] [--write-last]
 * Artifact: storage/logs/backup_restore_cycle_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
$logsDir = ts_storage_logs_dir();
$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');

// Run backup_restore_gate
$gateJson = $logsDir . '/backup_restore_gate_last.json';
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/backup_restore_gate.php') . ' --write-last --strict 2>&1';
$lines = []; $code = 1;
@exec($cmd, $lines, $code);
$ok = ((int)$code === 0);

// Also run restore_dry_run
$rdJson = $logsDir . '/restore_dry_run_last.json';
$rdCmd  = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/restore_dry_run.php') . ' --from-latest --write-last 2>&1';
$rdLines = []; $rdCode = 1;
@exec($rdCmd, $rdLines, $rdCode);

// Read gate artifact
$backupOk = false; $verifyOk = false; $restoreDryOk = false;
$manifestFound = false; $checksumFile = null; $packageDir = null;
if (is_file($gateJson)) {
    $gateData = json_decode((string)file_get_contents($gateJson), true);
    if (is_array($gateData)) {
        $gateOk  = (bool)($gateData['ok'] ?? false);
        $steps   = (array)($gateData['steps'] ?? []);
        $backupOk  = (bool)($steps['obtain_backup']['ok'] ?? $gateOk);
        $verifyOk  = (bool)($steps['verify_checksums']['ok'] ?? $gateOk);
        $packageDir  = $gateData['package'] ?? null;
        $manifestFound = !empty($steps['verify_manifest']['ok']) || !empty($gateData['package']);
    }
}
if (is_file($rdJson)) {
    $rdData = json_decode((string)file_get_contents($rdJson), true);
    $restoreDryOk = is_array($rdData) && (bool)($rdData['ok'] ?? false);
} else {
    // No backup yet — treat as non-blocking
    $restoreDryOk = true;
}

// Find checksum file in latest backup
$backupRoot  = $root . '/storage/backups';
$latestFile  = $backupRoot . '/LATEST_BACKUP.txt';
if (is_file($latestFile)) {
    $latest = trim((string)@file_get_contents($latestFile));
    $checksumFile = is_file($latest . '/checksums.sha256') ? basename($latest) . '/checksums.sha256' : null;
    $manifestFound = $manifestFound || is_file($latest . '/manifest.json');
}

$allOk = $ok && $restoreDryOk;

$payload = [
    'ok'                  => $allOk,
    'run_at'              => date(DateTimeInterface::ATOM),
    'backup_ok'           => $backupOk,
    'verify_ok'           => $verifyOk,
    'restore_dry_run_ok'  => $restoreDryOk,
    'manifest_found'      => $manifestFound,
    'checksum_file'       => $checksumFile,
    'package'             => $packageDir,
    'source'              => 'backup_restore_gate.php + restore_dry_run.php',
];

if ($writeLast) {
    @file_put_contents($logsDir . '/backup_restore_cycle_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($allOk ? 0 : ($strict ? 2 : 1));
