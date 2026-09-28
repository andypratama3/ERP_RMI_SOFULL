<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/_lib/ops_freshness_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$runId = '';
$writeLast = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg)) continue;
    if (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    if (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
    if ($arg === '--write-last') $writeLast = true;
}

$root = ofr_root();
$runId = $runId !== '' ? $runId : ('ops-freshness-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8));
$thresholds = ofr_thresholds($env);
$sources = ofr_sources();
$results = [];
$overallOk = true;

foreach ($sources as $key => $cfg) {
    $path = (string)($cfg['path'] ?? '');
    $ageHours = 999999.0;
    if (is_file($path)) {
        $mtime = (int)@filemtime($path);
        $ageHours = $mtime > 0 ? (time() - $mtime) / 3600.0 : 999999.0;
    }
    $row = ofr_check_source($key, $cfg, $ageHours, $thresholds);
    $results[] = $row;
    if ($row['status'] === 'FAIL') {
        $overallOk = false;
    }
}

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'run_id' => $runId,
    'env' => $env,
    'overall_ok' => $overallOk,
    'sources' => $results,
];

$logDir = $root . '/storage/logs/pipeline';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$jsonPath = $logDir . '/ops_freshness_last.json';
$mdPath = $logDir . '/ops_freshness_last.md';

if ($writeLast) {
    ops_write_json($jsonPath, $payload);
    $md = [];
    $md[] = '# Ops Freshness Last';
    $md[] = '';
    $md[] = '- generated_at: ' . $payload['generated_at'];
    $md[] = '- run_id: ' . ops_mask($runId);
    $md[] = '- env: ' . $env;
    $md[] = '- overall_ok: ' . ($overallOk ? 'true' : 'false');
    $md[] = '';
    $md[] = '## Sources';
    foreach ($payload['sources'] as $s) {
        $md[] = '- [' . ($s['status'] ?? '') . '] ' . ($s['name'] ?? '') . ' age_hours=' . ($s['age_hours'] ?? '') . ' ' . ($s['path_masked'] ?? '');
    }
    @file_put_contents($mdPath, implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
