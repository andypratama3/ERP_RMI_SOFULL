<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_exec_helpers.php';

tools_require_access('ops/run_daily_sop.php');

$dateKey = date('Ymd');
$steps = [
    ['name' => 'doctor_safe', 'cmd' => escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' . escapeshellarg(APP_ROOT . '/tools/doctor/run_doctor.php')],
    ['name' => 'sla_monitor', 'cmd' => escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' . escapeshellarg(APP_ROOT . '/tools/ops/sla_monitor_daily.php')],
    ['name' => 'manual_action_queue_build', 'cmd' => escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' -r ' . escapeshellarg("require 'tools/ops/manual_action_queue_lib.php'; maq_generate_from_triage();")],
    ['name' => 'weekly_trend_build', 'cmd' => escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' . escapeshellarg(APP_ROOT . '/tools/ops/weekly_trend_build.php')],
    ['name' => 'release_checklist_build', 'cmd' => escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' . escapeshellarg(APP_ROOT . '/tools/release/release_final_checklist_build.php')],
];

$results = [];
$fail = 0;
foreach ($steps as $step) {
    $run = tools_run_step((string)$step['cmd'], 240, ['idempotent' => true, 'max_retry' => 1]);
    $ok = !empty($run['ok']);
    if (!$ok) $fail++;
    $results[] = [
        'name' => (string)$step['name'],
        'ok' => $ok,
        'exit_code' => (int)($run['exit_code'] ?? 1),
        'elapsed_ms' => (int)($run['elapsed_ms'] ?? 0),
        'log_masked' => substr((string)(($run['stderr_masked'] ?? '') ?: ($run['stdout_masked'] ?? '')), 0, 1200),
    ];
}

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'date_key' => $dateKey,
    'summary' => ['total' => count($steps), 'fail' => $fail, 'ok' => $fail === 0],
    'steps' => $results,
];
$jsonPath = APP_ROOT . '/storage/logs/daily_sop_' . $dateKey . '.json';
$mdPath = APP_ROOT . '/storage/logs/daily_sop_' . $dateKey . '.md';
tools_json_write_atomic($jsonPath, $payload);

$md = "# Daily SOP {$dateKey}\n\n";
$md .= "- generated_at: " . $payload['generated_at'] . "\n";
$md .= "- status: " . ($fail === 0 ? 'OK' : 'FAIL') . "\n\n";
$md .= "| step | status | elapsed_ms |\n";
$md .= "|---|---|---:|\n";
foreach ($results as $r) {
    $md .= '| ' . $r['name'] . ' | ' . ($r['ok'] ? 'OK' : 'FAIL') . ' | ' . $r['elapsed_ms'] . " |\n";
}
@file_put_contents($mdPath, $md);
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
