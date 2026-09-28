<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_exec_helpers.php';

tools_require_access('doctor/run_doctor.php');
$argv = $_SERVER['argv'] ?? [];
$safeMode = in_array('--safe', $argv, true);

function doctor_uuid(): string
{
    return 'doctor-' . date('YmdHis') . '-' . substr(hash('sha256', (string)microtime(true)), 0, 10);
}

$requestId = doctor_uuid();
$lastPath = APP_ROOT . '/storage/logs/doctor_last.json';
$histPath = APP_ROOT . '/storage/logs/doctor_history.jsonl';

$lock = tools_lock_acquire('doctor', 900);
if (!$lock['ok']) {
    $state = [
        'state_version' => 1,
        'request_id' => $requestId,
        'ts' => date(DateTimeInterface::ATOM),
        'status' => 'IN_PROGRESS',
        'steps' => [],
        'overall' => ['ok' => false, 'critical_fail_count' => 1, 'warn_count' => 0],
        'note' => 'doctor lock already active',
    ];
    tools_json_write_atomic($lastPath, $state);
    echo json_encode($state, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

$cfg = tools_json_read_safe(APP_ROOT . '/tools/doctor/doctor_steps_v1.json');
$steps = array_values((array)($cfg['steps'] ?? []));
$inProgress = [
    'state_version' => 1,
    'request_id' => $requestId,
    'ts' => date(DateTimeInterface::ATOM),
    'status' => 'IN_PROGRESS',
    'steps' => [],
    'overall' => ['ok' => false, 'critical_fail_count' => 0, 'warn_count' => 0],
];
tools_json_write_atomic($lastPath, $inProgress);

$outSteps = [];
$critical = 0;
$warn = 0;
foreach ($steps as $s) {
    $name = (string)($s['name'] ?? 'step');
    $cmdRaw = trim((string)($s['cmd'] ?? ''));
    if ($cmdRaw === '') {
        $critical++;
        $outSteps[] = ['name' => $name, 'result' => 'FAIL', 'elapsed_ms' => 0, 'log_masked' => 'missing cmd'];
        continue;
    }
    $timeout = (int)($s['timeout'] ?? 120);
    $idempotent = (bool)($s['idempotent'] ?? true);
    $maxRetry = (int)($s['max_retry'] ?? 1);
    $cmd = str_starts_with($cmdRaw, 'php ')
        ? escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' . substr($cmdRaw, 4)
        : $cmdRaw;
    $run = tools_run_step($cmd, $timeout, ['idempotent' => $idempotent, 'max_retry' => $maxRetry]);
    $result = 'OK';
    if (!empty($run['timeout'])) {
        $result = 'FAIL';
    } elseif (empty($run['ok'])) {
        $result = 'FAIL';
    } elseif ((int)($run['attempts_used'] ?? 1) > 1) {
        $result = 'WARN';
    }
    if ($result === 'FAIL') $critical++;
    if ($result === 'WARN') $warn++;
    $log = trim((string)($run['stderr_masked'] ?? ''));
    if ($log === '') {
        $log = trim((string)($run['stdout_masked'] ?? ''));
    }
    $outSteps[] = [
        'name' => $name,
        'result' => $result,
        'elapsed_ms' => (int)($run['elapsed_ms'] ?? 0),
        'log_masked' => substr($log, 0, 1000),
    ];
}

$done = [
    'state_version' => 1,
    'request_id' => $requestId,
    'ts' => date(DateTimeInterface::ATOM),
    'status' => $critical > 0 ? 'FAILED' : 'DONE',
    'steps' => $outSteps,
    'overall' => [
        'ok' => $critical === 0,
        'critical_fail_count' => $critical,
        'warn_count' => $warn,
    ],
    'mode' => $safeMode ? 'safe' : 'default',
];
tools_json_write_atomic($lastPath, $done);
@file_put_contents($histPath, json_encode($done, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
tools_lock_release('doctor');
echo json_encode($done, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($critical === 0 ? 0 : 1);
