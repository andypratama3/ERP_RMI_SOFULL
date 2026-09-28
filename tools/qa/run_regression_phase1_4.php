<?php
declare(strict_types=1);

/**
 * Regression runner for Phase 1–4.
 * Runs checks in order with STOP-ON-FAIL; writes full report.
 * Output: storage/logs/regression_phase1_4_last.json
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$logsDir = $root . '/storage/logs';
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';

$requestId = 'reg-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
$env = getenv('APP_ENV') ?: 'local';
$baseUrl = rtrim((string)getenv('APP_BASE_URL'), '/');
$appRoot = getenv('APP_ROOT') ?: $root;

$checks = [];
$failCount = 0;
$overallOk = true;
$stopOnFail = true;
$blocked = [];

function run_cmd(string $cmd, string $cwd): array {
    $pipes = [];
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($proc)) {
        return ['exit' => -1, 'out' => '', 'err' => 'proc_open failed'];
    }
    $out = (string)@stream_get_contents($pipes[1] ?? null);
    $err = (string)@stream_get_contents($pipes[2] ?? null);
    @fclose($pipes[1] ?? null);
    @fclose($pipes[2] ?? null);
    $exit = proc_close($proc);
    return ['exit' => $exit, 'out' => $out, 'err' => $err];
}

// A) preflight_check (write-last)
$pfScript = $root . '/tools/preflight_check.php';
if (is_file($pfScript)) {
    $r = run_cmd('php ' . escapeshellarg($pfScript), $root);
    $ok = ($r['exit'] === 0);
    $checks[] = ['name' => 'preflight_check', 'ok' => $ok, 'exit' => $r['exit'], 'detail' => $ok ? 'OK' : ($r['err'] ?: $r['out'])];
    if (!$ok) { $failCount++; $overallOk = false; if ($stopOnFail) goto write_report; }
} else {
    $blocked[] = ['phase' => 'A', 'script' => 'preflight_check', 'reason' => 'script not found'];
}

// B) api/v1/health.php parse JSON
$healthScript = $root . '/api/v1/health.php';
if (is_file($healthScript)) {
    $r = run_cmd('php ' . escapeshellarg($healthScript), $root);
    $json = json_decode($r['out'], true);
    $ok = is_array($json) && (isset($json['success']) || isset($json['data']) || isset($json['ok']));
    $checks[] = ['name' => 'api_health_json', 'ok' => $ok, 'exit' => $r['exit'], 'detail' => $ok ? 'JSON valid' : 'invalid JSON'];
    if (!$ok) { $failCount++; $overallOk = false; if ($stopOnFail) goto write_report; }
} else {
    $blocked[] = ['phase' => 'B', 'script' => 'api/v1/health.php', 'reason' => 'script not found'];
}

// C) contract_check --strict
$ccScript = $root . '/tools/qa/contract_check.php';
if (is_file($ccScript)) {
    $r = run_cmd('php ' . escapeshellarg($ccScript) . ' --strict', $root);
    $ok = ($r['exit'] === 0);
    $checks[] = ['name' => 'contract_check', 'ok' => $ok, 'exit' => $r['exit'], 'detail' => $ok ? 'OK' : substr($r['out'] . $r['err'], 0, 200)];
    if (!$ok) { $failCount++; $overallOk = false; if ($stopOnFail) goto write_report; }
} else {
    $blocked[] = ['phase' => 'C', 'script' => 'contract_check', 'reason' => 'script not found'];
}

// D) smoke_http --strict
$shScript = $root . '/tools/smoke_http.php';
if (is_file($shScript)) {
    $r = run_cmd('php ' . escapeshellarg($shScript) . ' --strict', $root);
    $smokeJson = json_decode($r['out'], true);
    $fail = (int)($smokeJson['fail'] ?? $smokeJson['summary']['fail'] ?? 999);
    $ok = ($r['exit'] === 0 && $fail === 0);
    $checks[] = ['name' => 'smoke_http', 'ok' => $ok, 'exit' => $r['exit'], 'detail' => 'fail=' . $fail];
    if (!$ok) { $failCount++; $overallOk = false; if ($stopOnFail) goto write_report; }
} else {
    $blocked[] = ['phase' => 'D', 'script' => 'smoke_http', 'reason' => 'script not found'];
}

// E) tools_dashboard_smoke
$tdScript = $root . '/tools/qa/tools_dashboard_smoke.php';
if (is_file($tdScript)) {
    $envStr = $baseUrl !== '' ? ' --base-url=' . escapeshellarg($baseUrl) : '';
    $r = run_cmd('php ' . escapeshellarg($tdScript) . $envStr, $root);
    $tdJson = json_decode($r['out'], true);
    $ok = ($r['exit'] === 0 && ($tdJson['ok'] ?? false));
    $checks[] = ['name' => 'tools_dashboard_smoke', 'ok' => $ok, 'exit' => $r['exit'], 'detail' => $ok ? 'OK' : ($tdJson['errors_masked'][0] ?? $r['err'])];
    if (!$ok) { $failCount++; $overallOk = false; if ($stopOnFail) goto write_report; }
} else {
    $blocked[] = ['phase' => 'E', 'script' => 'tools_dashboard_smoke', 'reason' => 'script not found'];
}

// F) uat_smoke (web only / proc_open with APP_ROOT)
$uatScript = $root . '/tools/uat_smoke.php';
if (is_file($uatScript)) {
    $r = run_cmd('APP_ROOT=' . escapeshellarg($appRoot) . ' php ' . escapeshellarg($uatScript), $root);
    $ok = ($r['exit'] === 0);
    $checks[] = ['name' => 'uat_smoke', 'ok' => $ok, 'exit' => $r['exit'], 'detail' => $ok ? 'OK' : 'requires web session or DB'];
    if (!$ok) { $failCount++; $overallOk = false; if ($stopOnFail) goto write_report; }
} else {
    $blocked[] = ['phase' => 'F', 'script' => 'uat_smoke', 'reason' => 'script not found'];
}

// G) FASE 1–4 smoke scripts
$phaseScripts = [
    'smoke_mobile_stock_opname' => $root . '/tools/qa/smoke_mobile_stock_opname.php',
    'smoke_pwa' => $root . '/tools/qa/smoke_pwa.php',
    'smoke_compliance_integrations' => $root . '/tools/qa/smoke_compliance_integrations.php',
];
foreach ($phaseScripts as $label => $path) {
    if (!is_file($path)) {
        $blocked[] = ['phase' => 'G', 'script' => $label, 'reason' => 'script not found'];
        continue;
    }
    $envVars = 'APP_ROOT=' . escapeshellarg($appRoot);
    if ($baseUrl !== '') $envVars .= ' APP_BASE_URL=' . escapeshellarg($baseUrl);
    $r = run_cmd($envVars . ' php ' . escapeshellarg($path), $root);
    $outJson = json_decode($r['out'], true);
    $ok = ($r['exit'] === 0 && ($outJson['overall_ok'] ?? $outJson['skipped'] ?? false));
    $checks[] = ['name' => $label, 'ok' => $ok, 'exit' => $r['exit'], 'detail' => $outJson['skip_reason'] ?? ($ok ? 'OK' : 'fail')];
    if (!$ok && !($outJson['skipped'] ?? false)) { $failCount++; $overallOk = false; if ($stopOnFail) goto write_report; }
}

write_report:
$report = [
    'state_version' => 1,
    'env' => $env,
    'overall_ok' => $overallOk,
    'fail_count' => $failCount,
    'checks' => $checks,
    'blocked' => $blocked,
    'request_id' => $requestId,
    'generated_at' => date('c'),
];
ts_write_json($logsDir . '/regression_phase1_4_last.json', $report);

echo "=== Regression Phase 1–4 ===\n";
echo "Request ID: {$requestId}\n";
echo "Overall: " . ($overallOk ? 'OK' : 'FAIL') . " (fail_count={$failCount})\n";
foreach ($checks as $c) {
    echo '  ' . ($c['ok'] ? '[OK]' : '[FAIL]') . ' ' . $c['name'] . ': ' . ($c['detail'] ?? '') . "\n";
}
if (!empty($blocked)) {
    echo "Blocked: " . count($blocked) . "\n";
}
exit($overallOk ? 0 : 1);
