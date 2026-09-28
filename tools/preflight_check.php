<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_ui_helpers.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);
function pf_mask(string $p, string $root): string {
    return str_replace($root, '[APP_ROOT]', $p);
}
$paths = [
    'storage/logs' => $root . '/storage/logs',
    'storage/backups' => $root . '/storage/backups',
    'storage/uploads' => $root . '/storage/uploads',
];

echo "=== ERP_RMI_SOFULL PREFLIGHT CHECK ===\n";
echo 'Time: ' . date(DateTimeInterface::ATOM) . "\n\n";

echo "[DB]\n";
try {
    $t0 = microtime(true);
    $pdo = rmi_db_pdo();
    $ok = (bool)$pdo->query('SELECT 1')->fetchColumn();
    $ms = (int)round((microtime(true) - $t0) * 1000);
    echo '- Connection: ' . ($ok ? 'OK' : 'FAIL') . " ({$ms} ms)\n";
} catch (Throwable $e) {
    $dbErr = tools_mask_sensitive($e->getMessage());
    echo '- Connection: FAIL (' . $dbErr . ")\n";
}

echo "\n[STORAGE]\n";
foreach ($paths as $key => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    echo '- ' . $key . ': exists=' . ($exists ? 'yes' : 'no') . ', writable=' . ($writable ? 'yes' : 'no') . "\n";
}

echo "\n[BACKUP SCHEDULER]\n";
$state = $root . '/storage/backups/autobackup_schedule_2300.state';
if (is_file($state)) {
    echo '- State file: OK (' . pf_mask($state, $root) . ")\n";
    foreach ((file($state, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        echo '  ' . ts_mask((string)$line) . "\n";
    }
} else {
    echo "- State file: missing\n";
}

echo "\nDone.\n";
$allWritable = true;
$storagePathsDetail = [];
foreach ($paths as $key => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $storagePathsDetail[$key] = ['exists' => $exists, 'writable' => $writable, 'path' => pf_mask($path, $root)];
    if (!$exists || !$writable) {
        $allWritable = false;
    }
}
$overallOk = (empty($dbErr ?? '') && $allWritable);
ts_write_json(ts_storage_logs_dir() . '/preflight_check.last.json', [
    'ok' => $overallOk,
    'checked_at' => date(DateTimeInterface::ATOM),
    'error' => ts_mask((string)($dbErr ?? '')),
    'summary' => $overallOk ? 'Preflight OK' : 'Preflight has failures',
    'meta' => [
        'storage_all_writable' => $allWritable,
        'storage_paths_detail' => $storagePathsDetail,
        'state_file_present' => is_file($state),
    ],
]);
ts_append_run_history('preflight_check', $overallOk ? 'ok' : 'fail', [
    'actor_username' => PHP_SAPI === 'cli' ? (getenv('USER') ?: 'SYSTEM') : (string)($_SESSION['username'] ?? 'SYSTEM'),
    'source' => 'tools/preflight_check.php',
]);
