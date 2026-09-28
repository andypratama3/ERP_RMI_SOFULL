<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/_lib/inline_ddl_scan_lib.php';

$args = $_SERVER['argv'] ?? [];
$scope = 'repo';
$runId = '';
$writeLast = false;
$strict = false;
foreach ($args as $arg) {
    if (!is_string($arg)) continue;
    if (str_starts_with($arg, '--scope=')) $scope = trim((string)substr($arg, 8));
    if (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
    if ($arg === '--write-last') $writeLast = true;
    if ($arg === '--strict') $strict = true;
}

$root = iddl_root();
$runId = $runId !== '' ? $runId : ('inline-ddl-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8));
$patterns = iddl_fail_patterns();
$files = iddl_collect_files($root);
$hits = [];
foreach ($files as $path) {
    foreach (iddl_scan_file($path, $root, $patterns) as $h) {
        $hits[] = $h;
    }
}

$hitCount = count($hits);
$overallOk = ($hitCount === 0);

$maskedHits = array_map(static function (array $h): array {
    return [
        'file' => function_exists('ts_mask') ? ts_mask((string)($h['file'] ?? '')) : ($h['file'] ?? ''),
        'line' => (int)($h['line'] ?? 0),
        'snippet_masked' => function_exists('ts_mask') ? ts_mask((string)($h['snippet_masked'] ?? '')) : ($h['snippet_masked'] ?? ''),
        'pattern' => $h['pattern'] ?? '',
    ];
}, $hits);

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'run_id' => $runId,
    'overall_ok' => $overallOk,
    'hit_count' => $hitCount,
    'hits' => $maskedHits,
    'files_scanned' => count($files),
];

$dir = $root . '/storage/logs/pipeline';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$jsonPath = $dir . '/inline_ddl_scan_last.json';
$mdPath = $dir . '/inline_ddl_scan_last.md';
if ($writeLast) {
    ts_write_json($jsonPath, $payload);
    $md = [];
    $md[] = '# Inline DDL Scan Last';
    $md[] = '';
    $md[] = '- generated_at: ' . $payload['generated_at'];
    $md[] = '- run_id: ' . (function_exists('ts_mask') ? ts_mask($runId) : $runId);
    $md[] = '- overall_ok: ' . ($overallOk ? 'true' : 'false');
    $md[] = '- hit_count: ' . $hitCount;
    $md[] = '- files_scanned: ' . count($files);
    $md[] = '';
    $md[] = '## Hits';
    foreach ($maskedHits as $h) {
        $md[] = '- [' . ($h['pattern'] ?? '') . '] ' . ($h['file'] ?? '') . ':' . ($h['line'] ?? 0) . ' — ' . ($h['snippet_masked'] ?? '');
    }
    @file_put_contents($mdPath, implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
