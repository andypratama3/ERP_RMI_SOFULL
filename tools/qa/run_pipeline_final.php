<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/_lib/tools_paths.php';
tools_assert_app_root_locked_cli();

require_once __DIR__ . '/_lib/pipeline_plans_lib.php';
require_once __DIR__ . '/_lib/pipeline_plan_runner.php';

$args = $_SERVER['argv'] ?? [];
$planId = '';
$runId = '';
$writeLast = false;
$rfcId = '';
$confirm = '';
$iUnderstand = false;
$manual = [
    'env' => null,
    'stage' => null,
    'mode' => null,
    'strict' => null,
    'release_verify_mode' => null,
    'core_flows_profile' => null,
];
$passThrough = [];
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if (str_starts_with($arg, '--plan=')) {
        $planId = trim((string)substr($arg, 7));
        continue;
    }
    if (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
    if (str_starts_with($arg, '--rfc=')) $rfcId = strtoupper(trim((string)substr($arg, 6)));
    if (str_starts_with($arg, '--confirm=')) $confirm = trim((string)substr($arg, 10));
    if ($arg === '--i-understand') $iUnderstand = true;
    if ($arg === '--write-last') $writeLast = true;
    if (str_starts_with($arg, '--env=')) $manual['env'] = strtolower(trim((string)substr($arg, 6)));
    if (str_starts_with($arg, '--stage=')) $manual['stage'] = trim((string)substr($arg, 8));
    if (str_starts_with($arg, '--mode=')) $manual['mode'] = strtolower(trim((string)substr($arg, 7)));
    if ($arg === '--strict') $manual['strict'] = true;
    if ($arg === '--no-strict') $manual['strict'] = false;
    if (str_starts_with($arg, '--release-verify-mode=')) $manual['release_verify_mode'] = strtolower(trim((string)substr($arg, 22)));
    if (str_starts_with($arg, '--core-flows-profile=')) $manual['core_flows_profile'] = strtolower(trim((string)substr($arg, 21)));
    $passThrough[] = $arg;
}

if ($planId === '') {
    // Backward-compatible mode: delegate to existing runner.
    $php = (string)(PHP_BINARY ?: 'php');
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/run_pipeline.php');
    foreach ($passThrough as $arg) {
        $cmd .= ' ' . $arg;
    }
    $res = ppl_exec($cmd);
    if ($res['output_lines'] !== []) {
        $last = (string)end($res['output_lines']);
        if ($last !== '') echo $last . PHP_EOL;
    }
    exit((int)$res['code']);
}

$planRes = get_plan($planId, ppl_plan_path());
if (!$planRes['ok']) {
    $err = (string)$planRes['error'];
    $msg = $err === 'plan_file_created'
        ? 'Plan file created, review then rerun.'
        : 'Plan load failed: ' . $err;
    $out = [
        'state_version' => 1,
        'ok' => false,
        'error' => $err,
        'message' => ppl_mask($msg),
        'plan_id' => $planId,
        'plan_path' => (string)($planRes['path_masked'] ?? ''),
        'validate_json' => ppl_mask(ppl_pipeline_dir() . '/pipeline_plan_validate_last.json'),
        'validate_md' => ppl_mask(ppl_pipeline_dir() . '/pipeline_plan_validate_last.md'),
    ];
    echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($err === 'plan_file_created' ? 3 : 2);
}

$plan = (array)$planRes['plan'];
$env = strtolower((string)($plan['env'] ?? 'staging'));
$mode = strtolower((string)($plan['mode'] ?? 'draft'));
$strictResolved = (bool)($plan['strict_resolved'] ?? false);
if ($env === 'production' && strtolower((string)($plan['core_flows_profile'] ?? 'normal')) !== 'read-only') {
    $plan['core_flows_profile'] = 'read-only';
    ppl_append_assumption(
        $planId,
        $env,
        $runId !== '' ? $runId : ('plan-' . date('YmdHis')),
        'core_flows_profile forced to read-only in production',
        'LOW',
        'set core_flows_profile: read-only in plan'
    );
}

$masterRunId = trim($runId) !== '' ? trim($runId) : ('plan-' . $planId . '-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8));

foreach (['env', 'stage', 'mode', 'strict', 'release_verify_mode', 'core_flows_profile'] as $k) {
    if ($manual[$k] === null) continue;
    $planVal = match ($k) {
        'strict' => $strictResolved ? 'true' : 'false',
        'stage' => (string)($plan['stage'] ?? implode(',', (array)($plan['stages'] ?? []))),
        default => (string)($plan[$k] ?? ''),
    };
    if (strtolower((string)$manual[$k]) !== strtolower($planVal)) {
        ppl_append_assumption(
            $planId,
            $env,
            $masterRunId,
            'conflicting CLI arg ignored (' . $k . '=' . (is_bool($manual[$k]) ? ((bool)$manual[$k] ? 'true' : 'false') : (string)$manual[$k]) . '), plan value used (' . $planVal . ')',
            'LOW',
            'remove conflicting CLI arg or update plan preset'
        );
    }
}

if ((bool)($plan['require_tools_base_url'] ?? false) && trim((string)getenv('TOOLS_BASE_URL')) === '') {
    $payload = [
        'state_version' => 1,
        'ok' => false,
        'plan_id' => $planId,
        'error' => 'tools_base_url_required',
        'message' => 'TOOLS_BASE_URL is required by plan',
    ];
    ppl_write_validate_last([
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'ok' => false,
        'plan_id' => $planId,
        'error' => 'tools_base_url_required',
        'errors' => ['TOOLS_BASE_URL required by plan'],
    ]);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

if ((bool)($plan['require_rfc'] ?? false) && $rfcId === '') {
    echo json_encode([
        'state_version' => 1,
        'ok' => false,
        'plan_id' => $planId,
        'error' => 'rfc_required',
        'message' => 'Plan requires --rfc for execution',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
if ((bool)($plan['require_confirm'] ?? false)) {
    if (!$iUnderstand || $confirm !== 'RUN_PIPELINE_FINAL') {
        echo json_encode([
            'state_version' => 1,
            'ok' => false,
            'plan_id' => $planId,
            'error' => 'confirm_required',
            'message' => 'Plan requires --i-understand and --confirm="RUN_PIPELINE_FINAL"',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(2);
    }
}

$plan['strict_resolved'] = $strictResolved;
if ($rfcId !== '' && (bool)($plan['require_rfc'] ?? false)) {
    $plan['rfc_id'] = $rfcId;
}
$result = ppl_run_plan($plan, $masterRunId, $writeLast);
$summary = (array)($result['summary'] ?? []);

$out = [
    'state_version' => 1,
    'ok' => (bool)($result['ok'] ?? false),
    'plan_id' => $planId,
    'env' => $env,
    'mode' => $mode,
    'master_run_id' => $masterRunId,
    'failed_stage' => $result['failed_stage'] ?? null,
    'artifacts' => (array)($result['artifacts'] ?? []),
    'overall_level' => ((bool)($result['ok'] ?? false)) ? 'HEALTHY' : 'CRITICAL',
    'stop_on_fail' => (bool)($plan['stop_on_fail'] ?? true),
    'strict' => $strictResolved,
    'notes' => (array)($summary['notes'] ?? []),
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(((bool)($result['ok'] ?? false)) ? 0 : 1);

