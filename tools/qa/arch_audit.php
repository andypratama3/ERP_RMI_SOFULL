<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';
require_once __DIR__ . '/_lib/arch_audit_mask.php';
require_once __DIR__ . '/_lib/arch_audit_lib.php';
require_once __DIR__ . '/_lib/arch_audit_render.php';

$args = $_SERVER['argv'] ?? [];
$runId = 'auto';
$scope = 'repo';
$maxFiles = 5000;
$writeLast = true;
$format = 'md,json,onepager';
$strict = false;

foreach (array_slice($args, 1) as $arg) {
    if (str_starts_with((string)$arg, '--run-id=')) {
        $runId = trim((string)substr((string)$arg, 9));
        continue;
    }
    if (str_starts_with((string)$arg, '--scope=')) {
        $scope = trim((string)substr((string)$arg, 8));
        continue;
    }
    if (str_starts_with((string)$arg, '--max-files=')) {
        $maxFiles = (int)trim((string)substr((string)$arg, 12));
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
    if (str_starts_with((string)$arg, '--format=')) {
        $format = trim((string)substr((string)$arg, 9));
        continue;
    }
    if ($arg === '--strict') {
        $strict = true;
        continue;
    }
}

// Keep runId "auto" literal so Plan Exec Summary (master_run_id=auto) finds arch_audit_auto.json

$precheck = aa_precheck($root);
if (!$precheck['valid']) {
    fwrite(STDERR, "DATA_MISSING: " . $precheck['reason'] . "\n");
    fwrite(STDERR, "Fix: ensure APP_ROOT has tools/ and at least one of app/, modules/, src/, composer.json, package.json\n");
    exit(1);
}

$pipelineDir = $root . '/storage/logs/pipeline';
if (!is_dir($pipelineDir)) {
    @mkdir($pipelineDir, 0775, true);
}
if (!is_dir($pipelineDir)) {
    fwrite(STDERR, "DATA_MISSING: cannot create storage/logs/pipeline\n");
    exit(1);
}

$patterns = require __DIR__ . '/_lib/arch_audit_patterns.php';
$fileListResult = aa_build_file_list($root, $maxFiles);
$files = $fileListResult['files'];
if ($fileListResult['truncated']) {
    fwrite(STDERR, "WARN: max-files exceeded, truncated to " . count($files) . " (deterministic subset)\n");
}

$domainsRaw = aa_run_scan($root, $files, $patterns);
$report = aa_build_report($root, $runId, $fileListResult, $domainsRaw, $strict);

$strictFail = false;
if ($strict) {
    $mon = $report['domains']['monitoring'] ?? [];
    $monOk = ($mon['status'] ?? '') === 'PRESENT' || ($mon['status'] ?? '') === 'PARTIAL';
    $health = $mon['evidence'] ?? [];
    $hasHealth = false;
    foreach ($health as $e) {
        if (stripos($e['file'] ?? '', 'health') !== false || stripos($e['pattern'] ?? '', 'health') !== false) {
            $hasHealth = true;
            break;
        }
    }
    $smoke = false;
    foreach ($health as $e) {
        if (stripos($e['file'] ?? '', 'smoke') !== false || stripos($e['pattern'] ?? '', 'smoke') !== false) {
            $smoke = true;
            break;
        }
    }
    $monBaseline = $monOk || $hasHealth || $smoke;
    $backup = $report['domains']['backup_dr'] ?? [];
    $backupOk = ($backup['backup_present'] ?? 'no') !== 'no';
    $docsBackup = false;
    foreach (($backup['evidence'] ?? []) as $e) {
        if (stripos($e['file'] ?? '', 'docs') !== false) {
            $docsBackup = true;
            break;
        }
    }
    $backupBaseline = $backupOk || $docsBackup;
    if (!$monBaseline) {
        fwrite(STDERR, "STRICT: missing health check OR smoke_http OR monitoring docs\n");
        $strictFail = true;
    }
    if (!$backupBaseline) {
        fwrite(STDERR, "STRICT: missing backup tool OR explicit 'no backup' disclaimer doc\n");
        $strictFail = true;
    }
}

$formats = array_map('trim', explode(',', $format));
$jsonPath = $pipelineDir . '/arch_audit_' . $runId . '.json';
$mdPath = $pipelineDir . '/arch_audit_' . $runId . '.md';
$opPath = $pipelineDir . '/arch_audit_' . $runId . '_one_pager.md';

if (in_array('json', $formats, true)) {
    $jsonContent = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $violations = aa_validate_output($jsonContent);
    if (!empty($violations)) {
        fwrite(STDERR, "MASK_VIOLATION: " . implode(', ', $violations) . "\n");
        exit(1);
    }
    $jsonContent = str_replace($root, '[APP_ROOT]', $jsonContent);
    file_put_contents($jsonPath, $jsonContent);
}

$mdContent = aa_render_md($report);
$mdContent = str_replace($root, '[APP_ROOT]', $mdContent);
$violations = aa_validate_output($mdContent);
if (!empty($violations)) {
    fwrite(STDERR, "MASK_VIOLATION: " . implode(', ', $violations) . "\n");
    exit(1);
}

if (in_array('md', $formats, true)) {
    file_put_contents($mdPath, $mdContent);
}

$opContent = aa_render_one_pager($report);
$opContent = str_replace($root, '[APP_ROOT]', $opContent);
$violations = aa_validate_output($opContent);
if (!empty($violations)) {
    fwrite(STDERR, "MASK_VIOLATION: " . implode(', ', $violations) . "\n");
    exit(1);
}

if (in_array('onepager', $formats, true)) {
    file_put_contents($opPath, $opContent);
}

if ($writeLast) {
    $lastJson = $pipelineDir . '/arch_audit_last.json';
    $lastMd = $pipelineDir . '/arch_audit_last.md';
    $lastOp = $pipelineDir . '/arch_audit_last_one_pager.md';
    if (in_array('json', $formats, true) && is_file($jsonPath)) {
        copy($jsonPath, $lastJson);
    }
    if (in_array('md', $formats, true) && is_file($mdPath)) {
        copy($mdPath, $lastMd);
    }
    if (in_array('onepager', $formats, true) && is_file($opPath)) {
        copy($opPath, $lastOp);
    }
    $govDir = $root . '/docs/governance';
    if (is_dir($govDir)) {
        if (is_file($lastMd)) {
            $trimmed = substr(file_get_contents($lastMd), 0, 50000);
            file_put_contents($govDir . '/ARCHITECTURE_AUDIT_REPORT_LAST.md', $trimmed);
        }
        if (is_file($lastOp)) {
            copy($lastOp, $govDir . '/ARCHITECTURE_AUDIT_ONE_PAGER_LAST.md');
        }
    }
}

$auditLog = $root . '/storage/logs/audit_arch_audit.jsonl';
$auditLine = json_encode([
    'ts' => date(DateTimeInterface::ATOM),
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'run_id' => $runId,
    'action' => 'ARCH_AUDIT_RUN',
    'result_level' => $strictFail ? 'STRICT_FAIL' : 'OK',
    'request_id' => bin2hex(random_bytes(8)),
]) . "\n";
@file_put_contents($auditLog, $auditLine, FILE_APPEND);

$assumptionsPath = $root . '/docs/governance/ASSUMPTIONS.md';
if ($report['scan_coverage']['truncated'] ?? false) {
    $line = "- ts: " . date(DateTimeInterface::ATOM) . " | run_id: " . $runId . " | assumption: scan truncated due to max-files | impact: MEDIUM | how_to_remove: increase --max-files\n";
    if (is_file($assumptionsPath) || is_dir(dirname($assumptionsPath))) {
        if (!is_file($assumptionsPath)) @file_put_contents($assumptionsPath, "# Assumptions Log\n\n");
        @file_put_contents($assumptionsPath, $line, FILE_APPEND);
    } else {
        $fallback = $pipelineDir . '/assumptions_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) . '.md';
        if (!is_file($fallback)) @file_put_contents($fallback, "# Assumptions Log (Fallback)\n\n");
        @file_put_contents($fallback, $line, FILE_APPEND);
    }
}

if ($strictFail) {
    exit(2);
}

echo "OK: arch_audit run_id=" . $runId . " outputs=" . implode(',', $formats) . "\n";
exit(0);
