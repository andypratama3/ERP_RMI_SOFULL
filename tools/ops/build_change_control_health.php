<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/_lib/change_control_health_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$runId = '';
foreach ($args as $arg) {
    if (!is_string($arg)) continue;
    if (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    if (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
}
if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';
if ($runId === '') $runId = 'cch-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

$res = cch_compute($env, $runId);
$health = $res['health'];
$paths = $res['paths'];
cch_write_artifacts($health, $paths);
cch_append_audit($health, $runId);

$payload = [
    'state_version' => 1,
    'ok' => true,
    'env' => $env,
    'run_id' => $runId,
    'overall_level' => (string)($health['overall_level'] ?? 'UNKNOWN'),
];
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
