<?php
/**
 * volumes_police.php — QA "Polisi Terakhir" /Volumes write block.
 *
 * Runs: tools_assert_expected_app_root, tools_scan_forbidden_tokens_in_storage_logs,
 *       check forbidden directory exists with ERP footprint.
 *
 * Output: storage/logs/volumes_police_last.json
 *
 * Usage: php tools/qa/volumes_police.php [--write-last]
 *
 * CRITICAL: If ERP_EXPECTED_APP_ROOT is not set and not on NAS => FAIL.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);

require_once $root . '/tools/_shared/tools_path_policy.php';
require_once $root . '/tools/_shared/workspace_lock.php';
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);

$expectedEnv = getenv('ERP_EXPECTED_APP_ROOT');
if ($expectedEnv === false || trim($expectedEnv) === '') {
    $expectedEnv = null;
} else {
    $expectedEnv = rtrim(trim($expectedEnv), '/');
}

$violations = [];
$criticalFailCount = 0;

// ERP_EXPECTED_APP_ROOT must be set (do NOT guess)
if ($expectedEnv === null) {
    $violations[] = [
        'type' => 'ERP_EXPECTED_APP_ROOT_NOT_SET',
        'file_masked' => '[APP_ROOT]',
        'detail_masked' => 'ERP_EXPECTED_APP_ROOT env var must be set before running',
    ];
    $criticalFailCount++;
}

// A) tools_path_policy_assert_app_root
$assertResult = tools_path_policy_assert_app_root();
if (!$assertResult['ok']) {
    $violations[] = [
        'type' => 'APP_ROOT_MISMATCH',
        'file_masked' => '[APP_ROOT]',
        'detail_masked' => $assertResult['detail_masked'] ?? 'APP_ROOT mismatch',
    ];
    $criticalFailCount++;
}

// B) tools_scan_forbidden_tokens_in_storage_logs
$scanViolations = tools_scan_forbidden_tokens_in_storage_logs();
foreach ($scanViolations as $v) {
    $violations[] = [
        'type' => 'FORBIDDEN_TOKEN_IN_STORAGE',
        'file_masked' => $v['file_masked'],
        'detail_masked' => $v['line_snippet_masked'],
    ];
    $criticalFailCount++;
}

// C) Check if /Volumes/web/ERP_RMI_SOFULL exists AND contains storage/logs or storage/backups
$forbiddenDir = '/Volumes/web/ERP_RMI_SOFULL';
if (is_dir($forbiddenDir)) {
    $hasLogs = is_dir($forbiddenDir . '/storage/logs');
    $hasBackups = is_dir($forbiddenDir . '/storage/backups');
    if ($hasLogs || $hasBackups) {
        $violations[] = [
            'type' => 'FORBIDDEN_DIR_ERP_FOOTPRINT',
            'file_masked' => '[REDACTED]/storage/...',
            'detail_masked' => 'Forbidden directory exists with storage/logs or storage/backups',
        ];
        $criticalFailCount++;
    }
}

$overallOk = ($criticalFailCount === 0);
$expectedRootMasked = $expectedEnv ?? '[APP_ROOT]';

$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'expected_app_root' => $expectedRootMasked,
    'overall_ok' => $overallOk,
    'critical_fail_count' => $criticalFailCount,
    'violations' => $violations,
];

$outPath = ts_storage_logs_dir() . '/volumes_police_last.json';
if ($writeLast && !tools_is_forbidden_path($root)) {
    $dir = dirname($outPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
