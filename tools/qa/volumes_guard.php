<?php
/**
 * volumes_guard.php — QA check: block /Volumes, enforce NAS-only.
 *
 * Output: storage/logs/volumes_guard_last.json
 * CRITICAL FAIL jika ada /Volumes/ di path atau state.
 *
 * Usage: php tools/qa/volumes_guard.php [--write-last]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);

require_once $root . '/tools/_shared/workspace_lock.php';
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);
$requestId = 'req-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
$appRoot = tools_get_app_root();
$appRootMasked = str_replace($appRoot, '[APP_ROOT]', $appRoot);
if (strpos($appRoot, '/Volumes/') !== false) {
    $appRootMasked = '[APP_ROOT]';
}

$findings = [];
$criticalFailCount = 0;

// 1) WORKSPACE_LOCK_MISMATCH
$lockResult = tools_assert_workspace_lock();
if (!$lockResult['ok']) {
    $findings[] = [
        'severity' => $lockResult['severity'] ?? 'CRITICAL',
        'code' => $lockResult['code'] ?? 'WORKSPACE_LOCK_MISMATCH',
        'message' => $lockResult['message'] ?? 'Workspace lock mismatch',
        'meta' => [],
    ];
    $criticalFailCount++;
}

// 2) FORBIDDEN_PATH_DETECTED - APP_ROOT
if (tools_is_forbidden_path($appRoot)) {
    $findings[] = [
        'severity' => 'CRITICAL',
        'code' => 'FORBIDDEN_PATH_DETECTED',
        'message' => 'APP_ROOT contains /Volumes/',
        'meta' => ['context' => 'app_root'],
    ];
    $criticalFailCount++;
}

// 3) & 4) Artifact scan for /Volumes/ is delegated to path_police.php.
// volumes_guard only checks runtime environment (workspace lock + APP_ROOT path).
$logsDir = $root . '/storage/logs';

$ok = ($criticalFailCount === 0);
$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'env' => $appEnv,
    'app_root' => $appRootMasked,
    'ok' => $ok,
    'critical_fail_count' => $criticalFailCount,
    'findings' => $findings,
    'request_id' => $requestId,
];

$outPath = $logsDir . '/volumes_guard_last.json';
if ($writeLast && !tools_is_forbidden_path($root)) {
    $dir = dirname($outPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
