<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_runtime_config.php';

tools_require_access('release/release_final_checklist_build.php');

$threshold = tools_json_read_safe(APP_ROOT . '/tools/release/release_thresholds_v1.json');
$freshMins = (int)($threshold['freshness_minutes'] ?? 180);
$rid = 'checklist-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

$doctor = tools_json_read_safe(APP_ROOT . '/storage/logs/doctor_last.json');
$triage = tools_json_read_safe(APP_ROOT . '/storage/logs/erp_hardening_triage_web.last.json');
$smoke = tools_json_read_safe(APP_ROOT . '/storage/logs/smoke_tools_regression_last.json');
$contract = tools_json_read_safe(APP_ROOT . '/storage/logs/contract_check.last.json');
$sla = tools_json_read_safe(APP_ROOT . '/storage/logs/sla_monitor_last.json');
$preflight = tools_json_read_safe(APP_ROOT . '/storage/logs/preflight_check.last.json');

$now = time();
$fresh = static function (array $state, int $limit, int $now): bool {
    $ts = (string)($state['generated_at'] ?? $state['ts'] ?? $state['run_at'] ?? '');
    if ($ts === '') return false;
    $epoch = strtotime($ts);
    if ($epoch === false) return false;
    return (($now - $epoch) <= ($limit * 60));
};

$doctorCritical = (int)($doctor['overall']['critical_fail_count'] ?? 1);
$triageP0 = (int)($triage['summary']['p0'] ?? 1);
$smokeFail = (int)($smoke['summary']['fail'] ?? 1);
$toolsBaseUrlValid = (bool)($smoke['tools_base_url_valid'] ?? tools_validate_base_url()['valid']);
$contractOk = (bool)($contract['ok'] ?? false);
$slaOk = !((bool)($sla['sla']['breach'] ?? true));
$inProgress = strtoupper((string)($doctor['status'] ?? '')) === 'IN_PROGRESS';

$items = [
    ['item' => 'Preflight OK', 'pass' => (bool)($preflight['ok'] ?? false), 'source' => 'preflight_check.last.json'],
    ['item' => 'Smoke tools regression fail==0', 'pass' => $smokeFail === 0, 'source' => 'smoke_tools_regression_last.json'],
    ['item' => 'Hardening triage P0==0', 'pass' => $triageP0 === 0, 'source' => 'erp_hardening_triage_web.last.json'],
    ['item' => 'Contract check OK', 'pass' => $contractOk, 'source' => 'contract_check.last.json'],
    ['item' => 'SLA monitor OK', 'pass' => $slaOk, 'source' => 'sla_monitor_last.json'],
    ['item' => 'Tools base URL valid', 'pass' => $toolsBaseUrlValid, 'source' => 'TOOLS_BASE_URL'],
    ['item' => 'Freshness within threshold', 'pass' => $fresh($doctor, $freshMins, $now) && $fresh($triage, $freshMins, $now), 'source' => 'doctor_last + triage_last'],
];

$rows = [];
foreach ($items as $it) {
    $status = 'DONE';
    if ($inProgress) {
        $status = 'IN_PROGRESS';
    } elseif (!$it['pass']) {
        $status = 'BLOCKED';
    }
    $rows[] = [
        'item' => $it['item'],
        'status' => $status,
        'source' => $it['source'],
        'ts' => date(DateTimeInterface::ATOM),
        'notes_masked' => tools_mask_sensitive($it['item'] . ': ' . ($it['pass'] ? 'pass' : 'fail')),
    ];
}

$noGo = ($doctorCritical > 0) || ($triageP0 > 0) || ($smokeFail > 0) || !$contractOk || !$toolsBaseUrlValid;
$go = !$noGo && $slaOk;

$payload = [
    'state_version' => 1,
    'request_id' => $rid,
    'generated_at' => date(DateTimeInterface::ATOM),
    'signature' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: ''),
    'rows' => $rows,
    'overall' => [
        'go' => $go,
        'decision' => $go ? 'GO' : 'NO-GO',
        'no_go' => $noGo,
        'critical_fail_count' => $doctorCritical,
        'triage_p0' => $triageP0,
        'smoke_fail' => $smokeFail,
        'contract_ok' => $contractOk,
        'tools_base_url_valid' => $toolsBaseUrlValid,
        'sla_ok' => $slaOk,
    ],
];

tools_json_write_atomic(APP_ROOT . '/storage/logs/release_final_checklist_last.json', $payload);
@file_put_contents(APP_ROOT . '/storage/logs/release_final_checklist_history.jsonl', json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
