<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/_lib/secret_scan_lib.php';

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

$root = sscan_root();
$runId = $runId !== '' ? $runId : ('secret-scan-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8));
$allowlist = sscan_load_allowlist($root);
$files = sscan_collect_files($root);
$findings = [];
foreach ($files as $path) {
    foreach (sscan_scan_file($path, $root, $allowlist, $strict) as $f) {
        $findings[] = $f;
    }
}

$failCount = count(array_filter($findings, static fn(array $x): bool => ($x['severity'] ?? '') === 'FAIL'));
$warnCount = count(array_filter($findings, static fn(array $x): bool => ($x['severity'] ?? '') === 'WARN'));
$overallOk = ($failCount === 0);

$maskedFindings = array_map(static function (array $f): array {
    return [
        'severity' => $f['severity'] ?? 'UNKNOWN',
        'line' => (int)($f['line'] ?? 0),
        'label' => $f['label'] ?? '',
        'context_masked' => sscan_mask((string)($f['context_masked'] ?? '')),
        'file_rel' => sscan_mask((string)($f['file_rel'] ?? '')),
    ];
}, $findings);

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'run_id' => $runId,
    'overall_ok' => $overallOk,
    'fail_count' => $failCount,
    'warn_count' => $warnCount,
    'findings' => $maskedFindings,
    'files_scanned' => count($files),
];

$dir = $root . '/storage/logs/pipeline';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$jsonPath = $dir . '/secret_scan_last.json';
$mdPath = $dir . '/secret_scan_last.md';
if ($writeLast) {
    ts_write_json($jsonPath, $payload);
    $md = [];
    $md[] = '# Secret Scan Last';
    $md[] = '';
    $md[] = '- generated_at: ' . $payload['generated_at'];
    $md[] = '- run_id: ' . sscan_mask($runId);
    $md[] = '- overall_ok: ' . ($overallOk ? 'true' : 'false');
    $md[] = '- fail_count: ' . $failCount;
    $md[] = '- warn_count: ' . $warnCount;
    $md[] = '- files_scanned: ' . count($files);
    $md[] = '';
    $md[] = '## Findings';
    foreach ($maskedFindings as $f) {
        $md[] = '- [' . ($f['severity'] ?? '') . '] ' . ($f['file_rel'] ?? '') . ':' . ($f['line'] ?? 0) . ' ' . ($f['label'] ?? '') . ' — ' . ($f['context_masked'] ?? '');
    }
    @file_put_contents($mdPath, implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
