<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../_lib/tools_paths.php';
tools_assert_app_root_locked_cli();

require_once __DIR__ . '/_lib/executive_summary_ultimate_lib.php';
require_once __DIR__ . '/_lib/executive_summary_render.php';
require_once __DIR__ . '/_lib/executive_summary_schema.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$writeLast = true;
$runIdArg = '';
$strictArg = null;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') {
        continue;
    }
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if ($arg === '--no-write-last') {
        $writeLast = false;
        continue;
    }
    if (str_starts_with($arg, '--env=')) {
        $env = strtolower(trim((string)substr($arg, 6)));
    }
    if (str_starts_with($arg, '--run-id=')) {
        $runIdArg = trim((string)substr($arg, 9));
    }
    if ($arg === '--strict') {
        $strictArg = true;
    }
    if ($arg === '--no-strict') {
        $strictArg = false;
    }
}
if (!in_array($env, ['staging', 'production'], true)) {
    $env = 'staging';
}

$built = exsu_build($env, $runIdArg);
$payload = (array)$built['payload'];
$runId = (string)($payload['run_id'] ?? '');
$strict = $strictArg ?? ($env === 'production');

$md = exsr_render_md($payload);
$html = exsr_render_html($payload);

$pipelineDir = exs_pipeline_dir();
$jsonLast = $pipelineDir . '/executive_ops_summary_last.json';
$mdLast = $pipelineDir . '/executive_ops_summary_last.md';
$htmlLast = $pipelineDir . '/executive_ops_summary_last.html';
$jsonUltLast = $pipelineDir . '/executive_ops_summary_ultimate_last.json';
$htmlUltLast = $pipelineDir . '/executive_ops_summary_ultimate_last.html';
ops_write_json($jsonLast, $payload);
@file_put_contents($mdLast, $md);
@file_put_contents($htmlLast, $html);
ops_write_json($jsonUltLast, $payload);
@file_put_contents($htmlUltLast, $html);

$v = exss_validate_payload($payload);
$validateReport = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'run_id' => $runId,
    'strict' => (bool)$strict,
    'status' => ($v['fails'] === [] ? ($v['warns'] === [] ? 'OK' : 'WARN') : 'FAIL'),
    'ok' => $v['fails'] === [],
    'fail_count' => count((array)$v['fails']),
    'warn_count' => count((array)$v['warns']),
    'fails' => array_values(array_map(static fn(string $s): string => exs_mask($s), (array)$v['fails'])),
    'warns' => array_values(array_map(static fn(string $s): string => exs_mask($s), (array)$v['warns'])),
];
exss_write_validate_last($validateReport);

$artifacts = [
    'json' => exs_mask($jsonLast),
    'md' => exs_mask($mdLast),
    'html' => exs_mask($htmlLast),
    'ultimate_json' => exs_mask($jsonUltLast),
    'ultimate_html' => exs_mask($htmlUltLast),
    'validate_json' => exs_mask($pipelineDir . '/executive_ops_summary_validate_last.json'),
    'validate_md' => exs_mask($pipelineDir . '/executive_ops_summary_validate_last.md'),
];
if (!$writeLast) {
    $ts = date('YmdHis');
    $jsonTs = $pipelineDir . '/executive_ops_summary_' . $ts . '.json';
    $mdTs = $pipelineDir . '/executive_ops_summary_' . $ts . '.md';
    $htmlTs = $pipelineDir . '/executive_ops_summary_' . $ts . '.html';
    ops_write_json($jsonTs, $payload);
    @file_put_contents($mdTs, $md);
    @file_put_contents($htmlTs, $html);
    $artifacts['json_ts'] = exs_mask($jsonTs);
    $artifacts['md_ts'] = exs_mask($mdTs);
    $artifacts['html_ts'] = exs_mask($htmlTs);
}

$exitCode = (int)($built['exit_code'] ?? 0);
if ($strict && (int)$validateReport['fail_count'] > 0) {
    $exitCode = max($exitCode, 1);
}
$out = [
    'state_version' => 1,
    'run_id' => $runId,
    'overall_ok' => (string)($payload['decision']['go_no_go'] ?? 'UNKNOWN') === 'GO' && (string)($payload['decision']['level'] ?? 'ATTENTION') !== 'CRITICAL',
    'decision' => [
        'go_no_go' => (string)($payload['decision']['go_no_go'] ?? 'UNKNOWN'),
        'level' => (string)($payload['decision']['level'] ?? 'ATTENTION'),
        'top_reason' => (string)($payload['decision']['top_reason'] ?? ''),
    ],
    'strict' => (bool)$strict,
    'validate_status' => (string)$validateReport['status'],
    'exit_code' => $exitCode,
    'artifacts' => $artifacts,
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($exitCode);

