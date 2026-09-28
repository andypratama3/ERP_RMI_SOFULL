<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/_lib/migration_sql_lint_lib.php';

$args = $_SERVER['argv'] ?? [];
$dir = 'sql/migrations';
$runId = '';
$writeLast = false;
$strict = false;
foreach ($args as $arg) {
    if (!is_string($arg)) continue;
    if (str_starts_with($arg, '--dir=')) $dir = trim((string)substr($arg, 6));
    if (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
    if ($arg === '--write-last') $writeLast = true;
    if ($arg === '--strict') $strict = true;
}

$root = msl_root();
$runId = $runId !== '' ? $runId : ('migration-lint-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8));
$files = msl_collect_sql_files($dir);
$allIssues = [];
foreach ($files as $path) {
    $issues = msl_lint_file($path, $root, $strict);
    foreach ($issues as $iss) {
        $allIssues[] = array_merge($iss, ['file' => str_replace($root . '/', '', $path)]);
    }
}

$failCount = count(array_filter($allIssues, static fn(array $x): bool => ($x['severity'] ?? '') === 'FAIL'));
$warnCount = count(array_filter($allIssues, static fn(array $x): bool => ($x['severity'] ?? '') === 'WARN'));
$overallOk = ($failCount === 0);

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'run_id' => $runId,
    'overall_ok' => $overallOk,
    'file_count' => count($files),
    'fail_count' => $failCount,
    'warn_count' => $warnCount,
    'issues' => array_map(static function (array $i): array {
        return [
            'file' => function_exists('ts_mask') ? ts_mask((string)($i['file'] ?? '')) : ($i['file'] ?? ''),
            'line' => $i['line'] ?? null,
            'rule' => $i['rule'] ?? '',
            'severity' => $i['severity'] ?? '',
            'message' => $i['message'] ?? '',
        ];
    }, $allIssues),
];

$logDir = $root . '/storage/logs/pipeline';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$jsonPath = $logDir . '/migration_sql_lint_last.json';
$mdPath = $logDir . '/migration_sql_lint_last.md';
if ($writeLast) {
    ts_write_json($jsonPath, $payload);
    $md = [];
    $md[] = '# Migration SQL Lint Last';
    $md[] = '';
    $md[] = '- generated_at: ' . $payload['generated_at'];
    $md[] = '- run_id: ' . (function_exists('ts_mask') ? ts_mask($runId) : $runId);
    $md[] = '- overall_ok: ' . ($overallOk ? 'true' : 'false');
    $md[] = '- file_count: ' . count($files);
    $md[] = '- fail_count: ' . $failCount;
    $md[] = '- warn_count: ' . $warnCount;
    $md[] = '';
    $md[] = '## Issues';
    foreach ($payload['issues'] as $i) {
        $md[] = '- [' . ($i['severity'] ?? '') . '] ' . ($i['file'] ?? '') . ':' . ($i['line'] ?? '?') . ' ' . ($i['rule'] ?? '') . ' — ' . ($i['message'] ?? '');
    }
    @file_put_contents($mdPath, implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
