<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/rfc_lint_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$scope = 'all';
$rfcId = '';
$range = 'all';
$mode = 'quick';
$strictArg = false;
$runIdArg = 'auto';
$writeLast = true;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--strict') { $strictArg = true; continue; }
    if ($arg === '--write-last') { $writeLast = true; continue; }
    if ($arg === '--no-write-last') { $writeLast = false; continue; }
    if (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--scope=')) $scope = strtolower(trim((string)substr($arg, 8)));
    elseif (str_starts_with($arg, '--rfc=')) $rfcId = strtoupper(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--range=')) $range = strtolower(trim((string)substr($arg, 8)));
    elseif (str_starts_with($arg, '--mode=')) $mode = strtolower(trim((string)substr($arg, 7)));
    elseif (str_starts_with($arg, '--run-id=')) $runIdArg = trim((string)substr($arg, 9));
}

if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';
if (!in_array($scope, ['all', 'rfc-id'], true)) $scope = 'all';
if (!in_array($range, ['all', 'last30d'], true)) $range = 'all';
if (!in_array($mode, ['quick', 'full'], true)) $mode = 'quick';
$strict = $strictArg || ($env === 'production');
$runId = rfclint_resolve_run_id($runIdArg);
$safeRun = preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) ?: 'LOCAL';
$requestId = 'rfc-quality-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

$pipe = rfclint_pipeline_dir();
$jsonRun = $pipe . '/rfc_quality_lint_' . $safeRun . '.json';
$mdRun = $pipe . '/rfc_quality_lint_' . $safeRun . '.md';
$jsonLast = $pipe . '/rfc_quality_lint_last.json';
$mdLast = $pipe . '/rfc_quality_lint_last.md';

// Clean-room guard (mandatory)
$cleanOut = [];
$cleanCode = 1;
$cleanCmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(rfclint_root() . '/tools/dev/verify_clean_room.php');
@exec($cleanCmd . ' 2>&1', $cleanOut, $cleanCode);
if ((int)$cleanCode !== 0) {
    $payload = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'env' => $env,
        'run_id' => $runId,
        'strict' => $strict,
        'mode' => $mode,
        'summary' => ['rfc_count' => 0, 'fail_count' => 1, 'warn_count' => 0, 'info_count' => 0, 'coverage_avg' => 0],
        'rfcs' => [],
        'notes' => [rfclint_mask('CLEAN_ROOM_GUARD_FAIL:' . implode(' | ', array_slice($cleanOut, -2)))],
    ];
    ts_write_json($jsonRun, $payload);
    @file_put_contents($mdRun, "# RFC Quality Lint Report\n\n- CLEAN_ROOM_GUARD_FAIL\n");
    if ($writeLast) {
        ts_write_json($jsonLast, $payload);
        @copy($mdRun, $mdLast);
    }
    @file_put_contents(rfclint_root() . '/storage/logs/audit_rfc_quality.jsonl', json_encode([
        'ts' => date(DateTimeInterface::ATOM),
        'actor_username' => rfclint_actor(),
        'env' => $env,
        'run_id' => $runId,
        'action' => 'RFC_QUALITY_LINT',
        'strict' => $strict,
        'mode' => $mode,
        'result' => 'FAIL',
        'fail_count' => 1,
        'warn_count' => 0,
        'request_id' => $requestId,
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    echo json_encode(['overall_ok' => false, 'error' => 'CLEAN_ROOM_GUARD_FAIL', 'run_id' => $runId], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$notes = [];
if ($scope === 'rfc-id' && $rfcId === '') {
    $notes[] = 'scope_rfc_id_requires_rfc';
}
$files = rfclint_discover_files($scope, $rfcId, $range);
if ($scope === 'rfc-id' && $files === []) {
    $notes[] = 'rfc_not_found';
}
if ($files === []) {
    $notes[] = 'no_rfc_files_discovered';
}

$rows = [];
foreach ($files as $file) {
    $rows[] = rfclint_lint_one($file, $env, $mode, $strict);
}
usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));

$failCount = 0;
$warnCount = 0;
$infoCount = 0;
$coverageTotal = 0.0;
foreach ($rows as $r) {
    $failCount += count((array)($r['fails'] ?? []));
    $warnCount += count((array)($r['warns'] ?? []));
    $infoCount += count((array)($r['infos'] ?? []));
    $coverageTotal += (float)($r['coverage_percent'] ?? 0);
}
$rfcCount = count($rows);
$coverageAvg = $rfcCount > 0 ? round($coverageTotal / $rfcCount, 2) : 0.0;

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'run_id' => $runId,
    'strict' => $strict,
    'mode' => $mode,
    'summary' => [
        'rfc_count' => $rfcCount,
        'fail_count' => $failCount,
        'warn_count' => $warnCount,
        'info_count' => $infoCount,
        'coverage_avg' => $coverageAvg,
    ],
    'rfcs' => $rows,
    'notes' => array_values(array_unique(array_map(static fn($v): string => rfclint_mask((string)$v), $notes))),
];

ts_write_json($jsonRun, $payload);
@file_put_contents($mdRun, rfclint_render_md($payload));
if ($writeLast) {
    ts_write_json($jsonLast, $payload);
    @copy($mdRun, $mdLast);
}

$result = 'OK';
if ($failCount > 0) $result = 'FAIL';
elseif ($warnCount > 0) $result = 'WARN';
@file_put_contents(rfclint_root() . '/storage/logs/audit_rfc_quality.jsonl', json_encode([
    'ts' => date(DateTimeInterface::ATOM),
    'actor_username' => rfclint_actor(),
    'env' => $env,
    'run_id' => $runId,
    'action' => 'RFC_QUALITY_LINT',
    'strict' => $strict,
    'mode' => $mode,
    'result' => $result,
    'fail_count' => $failCount,
    'warn_count' => $warnCount,
    'request_id' => $requestId,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$out = [
    'state_version' => 1,
    'overall_ok' => (!$strict || $failCount === 0),
    'env' => $env,
    'run_id' => $runId,
    'strict' => $strict,
    'mode' => $mode,
    'summary' => $payload['summary'],
    'artifacts' => [
        'json' => rfclint_mask($jsonRun),
        'md' => rfclint_mask($mdRun),
        'json_last' => $writeLast ? rfclint_mask($jsonLast) : null,
        'md_last' => $writeLast ? rfclint_mask($mdLast) : null,
    ],
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($strict && $failCount > 0) exit(1);
exit(0);

