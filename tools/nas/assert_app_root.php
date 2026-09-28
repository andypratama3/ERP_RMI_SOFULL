<?php
/**
 * assert_app_root.php — Assert APP_ROOT = /volume4/web/ERP_RMI_SOFULL (NAS).
 * Exit 2 = path mismatch, Exit 3 = sentinel missing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$EXPECTED_ROOT = '/volume4/web/ERP_RMI_SOFULL';
$actualRoot = realpath(__DIR__ . '/../..') ?: '';
$logsDir = $actualRoot !== '' ? $actualRoot . '/storage/logs' : '';
$mask = '[APP_ROOT]';

$writeAssumptions = static function (string $reason, string $nextAction) use ($logsDir, $mask, $actualRoot): void {
    if ($logsDir === '' || !is_dir($logsDir)) {
        @mkdir($logsDir, 0775, true);
    }
    $path = $logsDir . '/assumptions_log_last.json';
    $actualMasked = $actualRoot === '' ? '[UNKNOWN]' : '[MISMATCH]';
    $payload = [
        'generated_at' => date('c'),
        'expected_root' => $mask,
        'actual_root' => $actualMasked,
        'reason' => $reason,
        'next_action' => $nextAction,
    ];
    @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};

if ($actualRoot === '' || $actualRoot !== $EXPECTED_ROOT) {
    $writeAssumptions(
        $actualRoot === '' ? 'realpath failed' : 'path_mismatch',
        'cd /volume4/web/ERP_RMI_SOFULL && php tools/nas/assert_app_root.php'
    );
    fwrite(STDERR, "FAIL: APP_ROOT mismatch. Expected [APP_ROOT], actual differs.\n");
    exit(2);
}

$sentinels = [
    'tools/index.php',
    'master/login.php',
    'api/v1/health.php',
    'storage/logs',
    'storage/backups',
    'storage/uploads',
];
$missing = [];
foreach ($sentinels as $rel) {
    if (!file_exists($actualRoot . '/' . $rel)) {
        $missing[] = $rel;
    }
}
if ($missing !== []) {
    $writeAssumptions('sentinel_missing: ' . implode(', ', $missing), 'Verify project structure on NAS');
    fwrite(STDERR, "FAIL: Missing sentinels: " . implode(', ', $missing) . "\n");
    exit(3);
}

$lockPath = $logsDir . '/app_root_lock_last.json';
$lock = [
    'state_version' => 'app_root_lock_v1',
    'ok' => true,
    'expected_root' => $EXPECTED_ROOT,
    'actual_root' => $actualRoot,
    'checked_at' => date('c'),
    'request_id' => bin2hex(random_bytes(8)),
];
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0775, true);
}
@file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "OK: APP_ROOT locked\n";
exit(0);
