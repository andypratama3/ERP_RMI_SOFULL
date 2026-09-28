<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

$root = ts_root();
$logs = ts_storage_logs_dir();
$args = $_SERVER['argv'] ?? [];
$runSmoke = !in_array('--no-smoke', $args, true);

/**
 * @return array{ok:bool,exit_code:int,output:string}
 */
function hc_run_cmd(string $cmd): array
{
    $out = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $out, $code);
    return [
        'ok' => ((int)$code === 0),
        'exit_code' => (int)$code,
        'output' => tools_mask_sensitive(implode("\n", $out)),
    ];
}

$results = [];
$results['contract_check'] = hc_run_cmd('php ' . escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last');
$results['cutover_checks'] = hc_run_cmd('php ' . escapeshellarg($root . '/tools/qa/run_cutover_checks.php') . ' --write-last');
if ($runSmoke) {
    $results['tools_dashboard_smoke'] = hc_run_cmd('php ' . escapeshellarg($root . '/tools/qa/tools_dashboard_smoke.php'));
}

$contract = ts_read_json($logs . '/contract_check_last.json');
$cutover = ts_read_json($logs . '/cutover_checks.last.json');
$readiness = ts_read_json($logs . '/readiness_report_last.json');
$smoke = ts_read_json($logs . '/smoke_http_last.json');
$backup = ts_latest_backup_meta();
$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
$actor = getenv('USER') ?: 'SYSTEM';

$contractOk = (bool)($contract['ok'] ?? false);
$smokeFail = (int)($smoke['fail'] ?? -1);
$readinessScore = (int)($readiness['score'] ?? 0);
$backupAgeHours = (float)($backup['age_hours'] ?? 9999.0);
$backupThresholdHours = (float)(getenv('HYPERCARE_BACKUP_MAX_AGE_HOURS') ?: '72');
$cutoverOk = (bool)($cutover['overall_ok'] ?? false) && (int)($cutover['summary']['fail_count'] ?? 1) === 0;

$overallOk = $contractOk && $smokeFail === 0 && $readinessScore === 100 && $backupAgeHours <= $backupThresholdHours && $cutoverOk;

$errorsMasked = [];
foreach ($results as $name => $r) {
    if (!$r['ok']) {
        $errorsMasked[] = $name . ':exit_' . (int)$r['exit_code'];
        $snippet = trim((string)$r['output']);
        if ($snippet !== '') {
            $errorsMasked[] = $name . ':' . tools_mask_sensitive(substr($snippet, -500));
        }
    }
}

$event = [
    'event_version' => 1,
    'ts' => date(DateTimeInterface::ATOM),
    'env' => $appEnv,
    'actor' => $actor,
    'overall_ok' => $overallOk,
    'gates' => [
        'contract_ok' => $contractOk,
        'smoke_fail' => $smokeFail,
        'readiness_score' => $readinessScore,
        'backup_age_hours' => $backupAgeHours,
        'cutover_ok' => $cutoverOk,
    ],
    'artifacts' => [
        'contract' => ts_mask($logs . '/contract_check_last.json'),
        'cutover' => ts_mask($logs . '/cutover_checks.last.json'),
        'readiness' => ts_mask($logs . '/readiness_report_last.json'),
        'smoke' => ts_mask($logs . '/smoke_http_last.json'),
    ],
    'errors_masked' => $errorsMasked,
];

$jsonlPath = $logs . '/hypercare_checkpoints.jsonl';
@file_put_contents($jsonlPath, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
ts_append_run_history('hypercare_checkpoint', $overallOk ? 'OK' : 'FAIL', [
    'actor_username' => $actor,
    'source' => 'tools/qa/hypercare_checkpoint.php',
    'env' => $appEnv,
]);

echo json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
