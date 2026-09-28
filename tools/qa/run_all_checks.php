<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/_shared/app_root_guard.php';
tools_assert_expected_app_root();
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';

$args = $_SERVER['argv'] ?? [];
$passUnderstand = in_array('--i-understand', $args, true);
$quickMode = in_array('--quick', $args, true);
$env = strtolower((string)(getenv('APP_ENV') ?: 'local'));
$phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');

$baseUrl = trim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: getenv('APP_URL') ?: ''));
if ($baseUrl === '') $baseUrl = tools_default_base_url();
$baseUrl = rtrim(preg_replace('#(https?://)/+#', '$1', $baseUrl), '/');
$envPrefix = '';
if ($baseUrl !== '') {
    $envPrefix = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl) . ' TOOLS_BASE_URL=' . escapeshellarg($baseUrl) . ' APP_URL=' . escapeshellarg($baseUrl) . ' SMOKE_BASE_URL=' . escapeshellarg($baseUrl) . ' ';
}

$cutoverCmd = $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/run_cutover_checks.php') . ' --env=staging --write-last --strict --fix-act-fin-integrity';
if ($passUnderstand && in_array($env, ['prod', 'production'], true)) {
    $cutoverCmd .= ' --i-understand';
}
$steps = [
    [
        'name' => 'ban_non_ascii_paths',
        'cmd' => escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/dev/ban_non_ascii_paths.php'),
        'artifact' => ts_storage_logs_dir() . '/ban_non_ascii_paths_last.json',
    ],
    [
        'name' => 'cutover_checks',
        'cmd' => $cutoverCmd,
        'artifact' => ts_storage_logs_dir() . '/cutover_checks.last.json',
    ],
];
if (!$quickMode) {
    $steps[] = [
        'name' => 'negative_tests',
        // Avoid duplicated heavy steps because cutover already ran in this wrapper.
        'cmd' => $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/run_negative_tests.php') . ' --skip-cutover --skip-rotate',
        'artifact' => ts_storage_logs_dir() . '/negative_tests.last.json',
    ];
}

$results = [];
$errors = [];
$overallOk = true;

foreach ($steps as $step) {
    $t0 = microtime(true);
    $out = [];
    $code = 1;
    @exec((string)$step['cmd'] . ' 2>&1', $out, $code);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $ok = ((int)$code === 0);
    if (!$ok) {
        $overallOk = false;
        $errors[] = $step['name'] . ':' . tools_mask_sensitive(implode(' | ', array_slice($out, -3)));
    }
    $results[] = [
        'name' => $step['name'],
        'ok' => $ok,
        'duration_ms' => $ms,
        'artifact' => ts_mask((string)$step['artifact']),
    ];
}

$failCount = count(array_filter($results, static fn(array $r): bool => empty($r['ok'])));
$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'mode' => $quickMode ? 'quick' : 'full',
    'overall_ok' => $overallOk,
    'summary' => [
        'total' => count($results),
        'fail_count' => $failCount,
        'score' => max(0, 100 - ($failCount * 50)),
    ],
    'steps' => $results,
    'errors_masked' => $errors,
];

ts_write_json(ts_storage_logs_dir() . '/all_checks.last.json', $payload);
ts_append_run_history('all_checks', $overallOk ? 'OK' : 'FAIL', [
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'source' => 'tools/qa/run_all_checks.php',
    'score' => $payload['summary']['score'],
]);

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
