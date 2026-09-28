<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';
$args = $_SERVER['argv'] ?? [];
$skipCutover = in_array('--skip-cutover', $args, true);
$skipRotate = in_array('--skip-rotate', $args, true);
$phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';

function nt_exec(string $cmd): array
{
    $out = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $out, $code);
    return ['code' => (int)$code, 'out' => $out];
}

$logsDir = ts_storage_logs_dir();
$results = [];
$errors = [];

// 1) Corrupt JSON state test (with restore)
$smokeFile = $logsDir . '/smoke_http_last.json';
$backupFile = $logsDir . '/smoke_http_last.json.neg.bak';
$okCorrupt = false;
$corruptErr = '';
if (is_file($smokeFile)) {
    @copy($smokeFile, $backupFile);
    $wrote = @file_put_contents($smokeFile, '{bad-json');
    if ($wrote === false) {
        // Fallback: use temp file if smoke file not writable (CLI env limitation)
        $tmpCorrupt = sys_get_temp_dir() . '/nt_corrupt_test_' . getmypid() . '.json';
        @file_put_contents($tmpCorrupt, '{bad-json');
        $r = tools_read_state_json($tmpCorrupt);
        @unlink($tmpCorrupt);
    } else {
        $r = tools_read_state_json($smokeFile);
        @rename($backupFile, $smokeFile);
    }
    $okCorrupt = (!$r['ok'] && ($r['error'] ?? '') === 'invalid_json');
    $corruptErr = (string)($r['error'] ?? '');
} else {
    $corruptErr = 'missing_smoke_file';
}
$results[] = ['name' => 'corrupt_json_state', 'ok' => $okCorrupt, 'detail' => $corruptErr];
if (!$okCorrupt) $errors[] = 'corrupt_json_state:' . $corruptErr;

// 2) Missing state file test
$missingPath = $logsDir . '/nonexistent_state_test.json';
@unlink($missingPath);
$rMissing = tools_read_state_json($missingPath);
$okMissing = (!$rMissing['ok'] && ($rMissing['error'] ?? '') === 'missing');
$results[] = ['name' => 'missing_state_file', 'ok' => $okMissing, 'detail' => (string)($rMissing['error'] ?? '')];
if (!$okMissing) $errors[] = 'missing_state_file:' . (string)($rMissing['error'] ?? '');

// 3) Backup lock anti-overlap
$backupLock = $logsDir . '/.backup_now.lock';
@mkdir($backupLock, 0775, true);
$rBackupLock = nt_exec('bash ' . escapeshellarg($root . '/tools/backup_now.sh') . ' --label neg_lock_test');
@rmdir($backupLock);
$joinedBackup = strtolower(implode(' | ', $rBackupLock['out']));
$okBackupLock = ($rBackupLock['code'] !== 0) && (str_contains($joinedBackup, 'lock exists') || str_contains($joinedBackup, 'lock_exists'));
$results[] = ['name' => 'backup_lock', 'ok' => $okBackupLock, 'detail' => tools_mask_sensitive(implode(' | ', array_slice($rBackupLock['out'], -3)))];
if (!$okBackupLock) $errors[] = 'backup_lock:' . tools_mask_sensitive(implode(' | ', array_slice($rBackupLock['out'], -3)));

// 4) Smoke lock anti-overlap
$smokeLock = $logsDir . '/.smoke_nightly.lock';
@mkdir($smokeLock, 0775, true);
$rSmokeLock = nt_exec('bash ' . escapeshellarg($root . '/tools/smoke_nightly.sh'));
@rmdir($smokeLock);
$joinedSmoke = strtolower(implode(' | ', $rSmokeLock['out']));
$okSmokeLock = ($rSmokeLock['code'] !== 0) && (str_contains($joinedSmoke, 'lock exists') || str_contains($joinedSmoke, 'lock_exists'));
$results[] = ['name' => 'smoke_lock', 'ok' => $okSmokeLock, 'detail' => tools_mask_sensitive(implode(' | ', array_slice($rSmokeLock['out'], -3)))];
if (!$okSmokeLock) $errors[] = 'smoke_lock:' . tools_mask_sensitive(implode(' | ', array_slice($rSmokeLock['out'], -3)));

// 5) Cutover wrapper baseline
if ($skipCutover) {
    $results[] = ['name' => 'run_cutover_checks', 'ok' => true, 'detail' => 'skipped_by_flag'];
} else {
    $rCutover = nt_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/run_cutover_checks.php') . ' --write-last');
    $okCutover = ($rCutover['code'] === 0);
    $results[] = ['name' => 'run_cutover_checks', 'ok' => $okCutover, 'detail' => tools_mask_sensitive(implode(' | ', array_slice($rCutover['out'], -2)))];
    if (!$okCutover) $errors[] = 'run_cutover_checks:' . tools_mask_sensitive(implode(' | ', array_slice($rCutover['out'], -3)));
}

// 6) Rotate history dry-run
if ($skipRotate) {
    $results[] = ['name' => 'rotate_history_dry_run', 'ok' => true, 'detail' => 'skipped_by_flag'];
} else {
    $rRotate = nt_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/state/rotate_history.php') . ' --days=30 --max-size-mb=10');
    $okRotate = ($rRotate['code'] === 0);
    $results[] = ['name' => 'rotate_history_dry_run', 'ok' => $okRotate, 'detail' => tools_mask_sensitive(implode(' | ', array_slice($rRotate['out'], -2)))];
    if (!$okRotate) $errors[] = 'rotate_history_dry_run:' . tools_mask_sensitive(implode(' | ', array_slice($rRotate['out'], -3)));
}

$failCount = count(array_filter($results, static fn(array $x): bool => empty($x['ok'])));
$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => $failCount === 0,
    'summary' => [
        'total' => count($results),
        'fail_count' => $failCount,
        'score' => max(0, 100 - ($failCount * 16)),
    ],
    'results' => $results,
    'errors_masked' => $errors,
];

ts_write_json($logsDir . '/negative_tests.last.json', $payload);

$md = "# Tools Negative Tests\n\n";
$md .= "- Run at: " . $payload['run_at'] . "\n";
$md .= "- Overall: " . ($payload['overall_ok'] ? 'PASS' : 'FAIL') . "\n";
$md .= "- Score: " . $payload['summary']['score'] . "/100\n\n";
$md .= "## Results\n";
foreach ($results as $it) {
    $md .= "- " . $it['name'] . ": " . ($it['ok'] ? 'PASS' : 'FAIL') . " (`" . $it['detail'] . "`)\n";
}
@file_put_contents($root . '/TOOLS_NEGATIVE_TEST_RESULTS.md', $md);

ts_append_run_history('negative_tests', $payload['overall_ok'] ? 'OK' : 'FAIL', [
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'source' => 'tools/qa/run_negative_tests.php',
    'score' => $payload['summary']['score'],
]);

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['overall_ok'] ? 0 : 1);
