<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/executive_summary_schema.php';

$args = $_SERVER['argv'] ?? [];
$path = exs_pipeline_dir() . '/executive_ops_summary_last.json';
$strict = false;
$writeLast = true;
$env = 'staging';

foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if (str_starts_with($arg, '--path=')) $path = trim((string)substr($arg, 7));
    if ($arg === '--strict') $strict = true;
    if ($arg === '--write-last') $writeLast = true;
    if ($arg === '--no-write-last') $writeLast = false;
    if (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
}
if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';
if ($env === 'production' && !$strict) $strict = true;

$r = exs_read_json_strict($path);
$validation = ['ok' => false, 'fails' => ['input_missing_or_invalid'], 'warns' => []];
$runId = '';
if ($r['ok']) {
    $validation = exss_validate_payload((array)$r['data']);
    $runId = (string)($r['data']['run_id'] ?? '');
}

$report = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'strict' => $strict,
    'run_id' => $runId,
    'path_masked' => exs_mask($path),
    'status' => ($validation['fails'] === [] ? ($validation['warns'] === [] ? 'OK' : 'WARN') : 'FAIL'),
    'ok' => $validation['fails'] === [],
    'fail_count' => count((array)$validation['fails']),
    'warn_count' => count((array)$validation['warns']),
    'fails' => array_values(array_map(static fn(string $s): string => exs_mask($s), (array)$validation['fails'])),
    'warns' => array_values(array_map(static fn(string $s): string => exs_mask($s), (array)$validation['warns'])),
];

if ($writeLast) {
    exss_write_validate_last($report);
}

echo json_encode([
    'ok' => $report['ok'],
    'status' => $report['status'],
    'fail_count' => $report['fail_count'],
    'warn_count' => $report['warn_count'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($strict && (int)$report['fail_count'] > 0) {
    exit(1);
}
exit(0);

