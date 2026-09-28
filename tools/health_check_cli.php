<?php
declare(strict_types=1);

/**
 * CLI: Run health check (DB, storage, cron) and write health.last.json.
 * Mirrors logic from tools/health.php for automation (run_all_tools_auto.sh).
 * Readiness score uses health.last.json (40 pts) — this keeps it fresh.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();
require_once $root . '/_shared/db.php';
require_once $root . '/tools/tools_state_lib.php';

$storageLogs = $root . '/storage/logs';
$storageBackups = $root . '/storage/backups';
$storageUploads = $root . '/storage/uploads';
$stateFile = $storageBackups . '/autobackup_schedule_2300.state';

$dbOk = false;
$dbError = '';
$workerNa = false;

try {
    $pdo = rmi_db_pdo();
    $dbOk = (bool)$pdo->query('SELECT 1')->fetchColumn();
    try {
        $pdo->query("SELECT COUNT(*) FROM jobs WHERE UPPER(status) IN ('PENDING','RETRY')")->fetchColumn();
    } catch (Throwable $e) {
        $workerNa = true;
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$cronEnabled = false;
if (is_file($stateFile) && is_readable($stateFile)) {
    $lines = @file($stateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        [$k, $v] = array_pad(explode('=', (string)$line, 2), 2, '');
        if (trim($k) === 'enabled') {
            $cronEnabled = (trim($v) === '1');
            break;
        }
    }
}

$rowsStorage = [
    ['path' => $storageLogs, 'exists' => is_dir($storageLogs), 'writable' => is_dir($storageLogs) && is_writable($storageLogs)],
    ['path' => $storageBackups, 'exists' => is_dir($storageBackups), 'writable' => is_dir($storageBackups) && is_writable($storageBackups)],
    ['path' => $storageUploads, 'exists' => is_dir($storageUploads), 'writable' => is_dir($storageUploads) && is_writable($storageUploads)],
];
$storageOk = true;
foreach ($rowsStorage as $it) {
    if (!$it['exists'] || !$it['writable']) {
        $storageOk = false;
        break;
    }
}

$healthOverall = ($dbOk && $storageOk);
$payload = [
    'ok' => $healthOverall,
    'checked_at' => date(DateTimeInterface::ATOM),
    'error' => ts_mask($dbError),
    'summary' => $healthOverall ? 'Health OK' : 'Health check has failures',
    'meta' => [
        'db_ok' => $dbOk,
        'storage_ok' => $storageOk,
        'cron_enabled' => $cronEnabled,
        'worker_na' => $workerNa,
    ],
];

$path = ts_storage_logs_dir() . '/health.last.json';
ts_write_json($path, $payload);

echo "OK: health.last.json written, ok=" . ($healthOverall ? 'true' : 'false') . ", db_ok=" . ($dbOk ? '1' : '0') . ", storage_ok=" . ($storageOk ? '1' : '0') . "\n";
exit($healthOverall ? 0 : 1);
