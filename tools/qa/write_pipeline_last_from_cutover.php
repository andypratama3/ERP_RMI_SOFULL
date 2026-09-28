<?php
declare(strict_types=1);

/**
 * Write minimal pipeline_last.json from cutover_checks / all_checks output.
 * Used when full run_pipeline.php is not run — allows Executive Summary to have run_id.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';

$cutoverPath = $root . '/storage/logs/cutover_checks.last.json';
$allChecksPath = $root . '/storage/logs/all_checks.last.json';
$pipeDir = $root . '/storage/logs/pipeline';
$outPath = $pipeDir . '/pipeline_last.json';

if (!is_dir($pipeDir)) {
    @mkdir($pipeDir, 0775, true);
}

$overallOk = true;
$runAt = date(DateTimeInterface::ATOM);
$runId = 'cutover-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

if (is_file($cutoverPath)) {
    $cutover = @json_decode((string)@file_get_contents($cutoverPath), true);
    if (is_array($cutover)) {
        $overallOk = (bool)($cutover['overall_ok'] ?? true);
        $runAt = (string)($cutover['run_at'] ?? $runAt);
    }
}

if (is_file($allChecksPath)) {
    $all = @json_decode((string)@file_get_contents($allChecksPath), true);
    if (is_array($all)) {
        $overallOk = (bool)($all['overall_ok'] ?? $overallOk);
        $runAt = (string)($all['run_at'] ?? $runAt);
    }
}

$payload = [
    'state_version' => 1,
    'run_id' => $runId,
    'env' => 'staging',
    'overall_ok' => $overallOk,
    'source' => 'write_pipeline_last_from_cutover',
    'run_at' => $runAt,
    'stages' => [],
];

ts_write_json($outPath, $payload);
echo json_encode(['ok' => true, 'run_id' => $runId, 'overall_ok' => $overallOk], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
