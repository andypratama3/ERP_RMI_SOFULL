<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/_lib/dependency_audit_lib.php';

$args = $_SERVER['argv'] ?? [];
$runId = '';
$writeLast = false;
foreach ($args as $arg) {
    if (!is_string($arg)) continue;
    if (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
    if ($arg === '--write-last') $writeLast = true;
}

$root = daud_root();
$runId = $runId !== '' ? $runId : ('dep-audit-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8));
$lockPath = $root . '/composer.lock';
$hasComposerLock = is_file($lockPath);
$auditRan = false;
$vulnCount = 0;
$vulnerabilitiesSummary = [];
$overallOk = true;

if ($hasComposerLock) {
    if (!daud_composer_available()) {
        $overallOk = false;
        $vulnerabilitiesSummary[] = [
            'package' => 'N/A',
            'advisory' => 'COMPOSER_MISSING',
            'severity' => 'CRITICAL',
            'message' => 'composer binary not found. Fix: install composer or add to PATH.',
        ];
    } else {
        $res = daud_run_composer_audit($root);
        $auditRan = true;
        $vulnerabilitiesSummary = $res['vulns'];
        $vulnCount = count($vulnerabilitiesSummary);
        $overallOk = $res['ok'];
    }
} else {
    $vulnerabilitiesSummary[] = [
        'package' => 'N/A',
        'advisory' => 'NO_COMPOSER_LOCK',
        'severity' => 'INFO',
        'message' => 'composer.lock not found. Skipping dependency audit.',
    ];
}

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'run_id' => $runId,
    'overall_ok' => $overallOk,
    'has_composer_lock' => $hasComposerLock,
    'audit_ran' => $auditRan,
    'vuln_count' => $vulnCount,
    'vulnerabilities_summary' => $vulnerabilitiesSummary,
];

$dir = $root . '/storage/logs/pipeline';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$jsonPath = $dir . '/dependency_audit_last.json';
$mdPath = $dir . '/dependency_audit_last.md';
if ($writeLast) {
    ts_write_json($jsonPath, $payload);
    $md = [];
    $md[] = '# Dependency Audit Last';
    $md[] = '';
    $md[] = '- generated_at: ' . $payload['generated_at'];
    $md[] = '- run_id: ' . daud_mask($runId);
    $md[] = '- overall_ok: ' . ($overallOk ? 'true' : 'false');
    $md[] = '- has_composer_lock: ' . ($hasComposerLock ? 'true' : 'false');
    $md[] = '- audit_ran: ' . ($auditRan ? 'true' : 'false');
    $md[] = '- vuln_count: ' . $vulnCount;
    $md[] = '';
    $md[] = '## Vulnerabilities Summary';
    foreach ($vulnerabilitiesSummary as $v) {
        $md[] = '- ' . ($v['package'] ?? '') . ' | ' . ($v['advisory'] ?? '') . ' | ' . ($v['severity'] ?? '');
    }
    @file_put_contents($mdPath, implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
