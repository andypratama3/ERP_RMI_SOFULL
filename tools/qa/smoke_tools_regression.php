<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_runtime_config.php';

tools_require_access('qa/smoke_tools_regression.php');

$checks = [
    ['id' => 'index_exists', 'pass' => is_file(APP_ROOT . '/tools/index.php'), 'note' => 'tools/index.php tersedia'],
    ['id' => 'doctor_exists', 'pass' => is_file(APP_ROOT . '/tools/doctor/index.php'), 'note' => 'doctor page tersedia'],
    ['id' => 'triage_exists', 'pass' => is_file(APP_ROOT . '/tools/hardening/erp_hardening_triage_web.php'), 'note' => 'triage page tersedia'],
    ['id' => 'checklist_exists', 'pass' => is_file(APP_ROOT . '/tools/release/release_final_checklist.php'), 'note' => 'release checklist page tersedia'],
    ['id' => 'export_exists', 'pass' => is_file(APP_ROOT . '/tools/release/export_checklist.php'), 'note' => 'export endpoint tersedia'],
];

$baseCfg = tools_validate_base_url();
$baseUrl = (string)($baseCfg['base_url'] ?? '');
if (!$baseCfg['valid']) {
    $checks[] = [
        'id' => 'tools_base_url_valid',
        'pass' => false,
        'note' => (string)($baseCfg['error_code'] ?? 'ERR_TOOLS_BASE_URL_INVALID') . ': ' . (string)($baseCfg['message'] ?? ''),
    ];
} elseif (function_exists('curl_init')) {
    $httpTargets = [
        ['id' => 'http_tools_index', 'url' => $baseUrl . '/tools/index.php'],
        ['id' => 'http_doctor_index', 'url' => $baseUrl . '/tools/doctor/index.php'],
        ['id' => 'http_hardening_triage', 'url' => $baseUrl . '/tools/hardening/erp_hardening_triage_web.php'],
        ['id' => 'http_release_checklist', 'url' => $baseUrl . '/tools/release/release_final_checklist.php'],
        ['id' => 'http_export_json', 'url' => $baseUrl . '/tools/release/export_checklist.php?fmt=json'],
        ['id' => 'http_export_csv', 'url' => $baseUrl . '/tools/release/export_checklist.php?fmt=csv'],
    ];
    foreach ($httpTargets as $target) {
        $ch = curl_init($target['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ok = in_array($code, [200, 302], true);
        $checks[] = [
            'id' => $target['id'],
            'pass' => $ok,
            'note' => 'url=' . $target['url'] . ' code=' . $code,
        ];
    }
} else {
    $checks[] = [
        'id' => 'tools_base_url_valid',
        'pass' => false,
        'note' => 'ERR_TOOLS_BASE_URL_INVALID: curl extension not available for strict smoke',
    ];
}

$pass = 0;
$fail = 0;
foreach ($checks as &$c) {
    if ($c['pass']) $pass++; else $fail++;
    $c['result'] = $c['pass'] ? 'PASS' : 'FAIL';
}
unset($c);

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'tools_base_url' => $baseUrl,
    'tools_base_url_valid' => (bool)$baseCfg['valid'],
    'summary' => ['total' => count($checks), 'pass' => $pass, 'fail' => $fail],
    'checks' => $checks,
    'ok' => $fail === 0,
];

tools_json_write_atomic(APP_ROOT . '/storage/logs/smoke_tools_regression_last.json', $payload);
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
