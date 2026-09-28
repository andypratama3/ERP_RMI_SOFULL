<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';
require_once __DIR__ . '/_lib/bug_pack_redact.php';
require_once __DIR__ . '/_lib/bug_pack_allowlist.php';
require_once __DIR__ . '/_lib/bug_pack_request_id.php';
require_once __DIR__ . '/_lib/bug_pack_snippets.php';
require_once __DIR__ . '/_lib/bug_pack_lib.php';
require_once __DIR__ . '/_lib/bug_pack_manifest.php';
require_once __DIR__ . '/_lib/bug_pack_zip.php';

$args = $_SERVER['argv'] ?? [];
$runId = 'auto';
$profile = 'tools';
$env = 'auto';
$route = '';
$error = '';
$repro = '';
$requestId = null;
$since = '24h';
$maxBytesPerFile = 262144;
$maxFiles = 200;
$includeState = true;
$includeLogs = true;
$includeConfigSummary = true;
$includeGit = true;
$includeHttpEndpoints = true;
$includeSourceSnippets = false;
$snippetsMaxFiles = 20;
$snippetsContextLines = 12;
$strict = false;
$writeLast = true;

foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg)) continue;
    if (str_starts_with($arg, '--run-id=')) {
        $runId = trim(substr($arg, 9));
    } elseif (str_starts_with($arg, '--profile=')) {
        $profile = trim(substr($arg, 10));
    } elseif (str_starts_with($arg, '--env=')) {
        $env = trim(substr($arg, 6));
    } elseif (str_starts_with($arg, '--route=')) {
        $route = trim(substr($arg, 8));
    } elseif (str_starts_with($arg, '--error=')) {
        $error = trim(substr($arg, 8));
    } elseif (str_starts_with($arg, '--repro=')) {
        $repro = trim(substr($arg, 8));
    } elseif (str_starts_with($arg, '--request-id=')) {
        $requestId = trim(substr($arg, 13));
    } elseif (str_starts_with($arg, '--since=')) {
        $since = trim(substr($arg, 8));
    } elseif (str_starts_with($arg, '--max-bytes-per-file=')) {
        $maxBytesPerFile = (int)trim(substr($arg, 21));
    } elseif (str_starts_with($arg, '--max-files=')) {
        $maxFiles = (int)trim(substr($arg, 12));
    } elseif (str_starts_with($arg, '--include-state=')) {
        $includeState = (int)trim(substr($arg, 17)) !== 0;
    } elseif (str_starts_with($arg, '--include-logs=')) {
        $includeLogs = (int)trim(substr($arg, 15)) !== 0;
    } elseif (str_starts_with($arg, '--include-config-summary=')) {
        $includeConfigSummary = (int)trim(substr($arg, 25)) !== 0;
    } elseif (str_starts_with($arg, '--include-git=')) {
        $includeGit = (int)trim(substr($arg, 15)) !== 0;
    } elseif (str_starts_with($arg, '--include-http-endpoints=')) {
        $includeHttpEndpoints = (int)trim(substr($arg, 26)) !== 0;
    } elseif (str_starts_with($arg, '--include-source-snippets=')) {
        $includeSourceSnippets = (int)trim(substr($arg, 26)) !== 0;
    } elseif (str_starts_with($arg, '--snippets-max-files=')) {
        $snippetsMaxFiles = (int)trim(substr($arg, 21));
    } elseif (str_starts_with($arg, '--snippets-context-lines=')) {
        $snippetsContextLines = (int)trim(substr($arg, 26));
    } elseif ($arg === '--strict') {
        $strict = true;
    } elseif ($arg === '--write-last') {
        $writeLast = true;
    }
}

if (!in_array($profile, ['tools', 'erp_full'], true)) {
    $profile = 'tools';
}
if ($profile === 'erp_full' && $includeSourceSnippets === false) {
    $includeSourceSnippets = true;
}
if ($profile === 'tools') {
    $maxFiles = min($maxFiles, 200);
} else {
    $maxFiles = min($maxFiles, 250);
}

if ($runId === 'auto') {
    $runId = date('Ymd_His') . 'Z_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

$envResolved = $env === 'auto' ? (getenv('APP_ENV') ?: 'unknown') : $env;
$maxBytesPerFile = max(1024, min($maxBytesPerFile, 2097152));
$maxFiles = max(1, min($maxFiles, 500));

$exportsDir = $root . '/storage/exports';
$bugPacksDir = $exportsDir . '/bug_packs';
$packDir = $bugPacksDir . '/bug_pack_' . $runId;
$zipPath = $bugPacksDir . '/bug_pack_' . $runId . '.zip';
$pipelineDir = $root . '/storage/logs/pipeline';
$auditLog = $root . '/storage/logs/audit_bug_pack.jsonl';

if (!is_dir($exportsDir)) {
    if (!@mkdir($exportsDir, 0775, true) || !is_dir($exportsDir)) {
        fwrite(STDERR, "FAIL: cannot create storage/exports\n");
        exit(1);
    }
}
if (!is_dir($bugPacksDir)) {
    if (!@mkdir($bugPacksDir, 0775, true) || !is_dir($bugPacksDir)) {
        fwrite(STDERR, "FAIL: cannot create storage/exports/bug_packs\n");
        exit(1);
    }
}
if (!@mkdir($packDir, 0775, true) || !is_dir($packDir)) {
    fwrite(STDERR, "FAIL: cannot create pack directory\n");
    exit(1);
}

$included = [];
$missing = [];
$fixCommands = [];
$notes = [];
$denyViolations = [];
$redactionFailCount = 0;
$redactionWarnCount = 0;
$logTailsForRequestId = [];

if ($includeState) {
    $stateRes = bpl_collect_state_files($root, $maxBytesPerFile, $strict);
    foreach ($stateRes['included'] as $e) {
        $e['sha256'] = hash('sha256', $e['content']);
        $included[] = $e;
        $dest = $packDir . '/' . $e['rel'];
        $destDir = dirname($dest);
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
        @file_put_contents($dest, $e['content']);
    }
    foreach ($stateRes['missing'] as $m) {
        $missing[] = $m;
        $redactionWarnCount++;
    }
    $fixCommands = array_merge($fixCommands, $stateRes['fix_commands']);
}
$fixCommands = array_unique($fixCommands);

if ($includeLogs) {
    $logRes = bpl_collect_logs($root, $maxBytesPerFile, $maxFiles, $profile);
    foreach ($logRes['included'] as $e) {
        $e['sha256'] = hash('sha256', $e['content']);
        $included[] = $e;
        $dest = $packDir . '/' . $e['rel'];
        $destDir = dirname($dest);
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
        @file_put_contents($dest, $e['content']);
    }
    $logTailsForRequestId = (array)($logRes['log_tails_for_request_id'] ?? []);
}

$requestIdResult = bprid_detect($requestId, $error, $repro, $logTailsForRequestId);
$requestIdDetected = $requestIdResult['request_id'];
$requestIdSources = $requestIdResult['sources'];

if ($requestIdDetected !== null && $requestIdDetected !== '') {
    $reqIdPath = $packDir . '/input/request_id.txt';
    @mkdir(dirname($reqIdPath), 0775, true);
    @file_put_contents($reqIdPath, $requestIdDetected . "\n");
    $included[] = ['rel' => 'input/request_id.txt', 'content' => $requestIdDetected . "\n", 'bytes' => strlen($requestIdDetected) + 1, 'sha256' => hash('sha256', $requestIdDetected . "\n"), 'redacted' => true];
}

if ($includeSourceSnippets && ($error !== '' || $repro !== '')) {
    $snippetRes = bps_collect_snippets($error . "\n" . $repro, $root, $snippetsContextLines, $snippetsMaxFiles, 50);
    foreach ($snippetRes['included'] as $e) {
        $e['sha256'] = hash('sha256', $e['content']);
        $included[] = $e;
        $dest = $packDir . '/' . $e['rel'];
        $destDir = dirname($dest);
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
        @file_put_contents($dest, $e['content']);
    }
    if (($snippetRes['note'] ?? '') !== '') {
        $notes[] = $snippetRes['note'];
    }
}

if ($includeConfigSummary) {
    $envSummary = bpl_env_summary($root);
    $envPath = $packDir . '/meta/env_summary.json';
    @mkdir(dirname($envPath), 0775, true);
    @file_put_contents($envPath, json_encode($envSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $included[] = ['rel' => 'meta/env_summary.json', 'bytes' => strlen(json_encode($envSummary)), 'sha256' => hash('sha256', json_encode($envSummary)), 'redacted' => true];
}

if ($includeGit) {
    $gitSummary = bpl_git_summary($root);
    $gitPath = $packDir . '/meta/git_summary.json';
    @mkdir(dirname($gitPath), 0775, true);
    @file_put_contents($gitPath, json_encode($gitSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $included[] = ['rel' => 'meta/git_summary.json', 'bytes' => strlen(json_encode($gitSummary)), 'sha256' => hash('sha256', json_encode($gitSummary)), 'redacted' => true];
}

$issueContext = [
    'route' => $route,
    'error' => $error,
    'repro' => $repro,
    'env' => $envResolved,
    'run_id' => $runId,
    'profile' => $profile,
    'request_id' => $requestIdDetected,
];
$issuePath = $packDir . '/input/issue_context.json';
@mkdir(dirname($issuePath), 0775, true);
$issueJson = json_encode(bpr_redact_array($issueContext), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
@file_put_contents($issuePath, $issueJson);

if ($profile === 'erp_full') {
    $fixCommands[] = 'php tools/qa/smoke_http.php --strict --endpoints=tools/qa/http_endpoints_stage16.json --run-id=auto --write-last';
}
$fixCommands = array_unique($fixCommands);

if ($includeHttpEndpoints) {
    foreach (bpl_endpoints_allowlist() as $src => $rel) {
        if (is_file($src)) {
            $raw = (string)@file_get_contents($src);
            $content = bpr_redact_json($raw);
            $scan = bpr_deny_pattern_scan($content, $root);
            if (!$scan['ok'] && $strict) {
                $denyViolations = array_merge($denyViolations, $scan['violations']);
                $redactionFailCount++;
            } else {
                $dest = $packDir . '/' . $rel;
                @mkdir(dirname($dest), 0775, true);
                @file_put_contents($dest, $content);
                $included[] = ['rel' => $rel, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'redacted' => true];
            }
        }
    }
}

if (!empty($missing)) {
    $notes[] = count($missing) . ' files DATA_MISSING or INVALID';
}

$denyReport = ['violations' => [], 'files' => []];
if ($strict) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packDir, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($it as $fi) {
        if (!$fi->isFile()) continue;
        $p = $fi->getPathname();
        $content = (string)@file_get_contents($p);
        $scan = bpr_deny_pattern_scan($content, $root);
        if (!$scan['ok']) {
            $denyViolations = array_merge($denyViolations, $scan['violations']);
            $denyReport['files'][] = ts_mask(str_replace($packDir . '/', '', $p));
            $redactionFailCount++;
        }
    }
}

if ($strict && !empty($denyViolations)) {
    fwrite(STDERR, "FAIL: redaction violation – deny patterns found: " . implode(', ', array_unique($denyViolations)) . "\n");
    $reportPath = $packDir . '/checks/deny_pattern_scan.json';
    @mkdir(dirname($reportPath), 0775, true);
    $denyReport['violations'] = array_values(array_unique($denyViolations));
    @file_put_contents($reportPath, json_encode($denyReport, JSON_PRETTY_PRINT));
    $redactReportPath = $packDir . '/checks/redact_report.json';
    @file_put_contents($redactReportPath, json_encode(['fail_count' => $redactionFailCount, 'warn_count' => $redactionWarnCount], JSON_PRETTY_PRINT));
    ts_write_json($pipelineDir . '/bug_pack_' . $runId . '.json', [
        'run_id' => $runId,
        'ok' => false,
        'error' => 'REDACTION_VIOLATION',
        'zip_path' => '',
    ]);
    exit(1);
}

@mkdir($packDir . '/checks', 0775, true);
@file_put_contents($packDir . '/checks/redact_report.json', json_encode([
    'fail_count' => $redactionFailCount,
    'warn_count' => $redactionWarnCount,
], JSON_PRETTY_PRINT));
@file_put_contents($packDir . '/checks/deny_pattern_scan.json', json_encode([
    'violations' => [],
    'files' => [],
], JSON_PRETTY_PRINT));

$manifest = bpm_build([
    'run_id' => $runId,
    'profile' => $profile,
    'env' => $envResolved,
    'request_id_detected' => $requestIdDetected,
    'request_id_sources' => $requestIdSources,
    'issue' => ['route' => $route, 'error' => $error, 'repro' => $repro],
    'included' => $included,
    'missing' => $missing,
    'notes' => $notes,
    'fix_commands' => array_unique($fixCommands),
    'redaction_fail_count' => $redactionFailCount,
    'redaction_warn_count' => $redactionWarnCount,
]);
@file_put_contents($packDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$readme = bpm_readme($manifest);
@file_put_contents($packDir . '/README.md', $readme);

$checksums = bpz_checksums($packDir);
$checksumLines = [];
foreach ($checksums as $rel => $hash) {
    $checksumLines[] = $hash . '  ' . $rel;
}
@mkdir($packDir . '/checksums', 0775, true);
@file_put_contents($packDir . '/checksums/sha256.txt', implode("\n", $checksumLines) . "\n");

$zipResult = bpz_create($packDir, $zipPath);
if ($zipResult === '') {
    fwrite(STDERR, "FAIL: zip creation failed\n");
    ts_write_json($pipelineDir . '/bug_pack_' . $runId . '.json', [
        'run_id' => $runId,
        'ok' => false,
        'error' => 'ZIP_FAILED',
        'zip_path' => '',
    ]);
    exit(1);
}

$zipPathMasked = ts_mask($zipPath);
$resultLevel = $redactionFailCount > 0 ? 'FAIL' : (empty($missing) ? 'OK' : 'WARN');

if ($writeLast) {
    ts_write_json($pipelineDir . '/bug_pack_' . $runId . '.json', [
        'state_version' => 2,
        'run_id' => $runId,
        'profile' => $profile,
        'generated_at' => date(DateTimeInterface::ATOM),
        'env' => $envResolved,
        'zip_path' => $zipPathMasked,
        'zip_path_abs' => $zipPath,
        'included_count' => count($included),
        'missing_count' => count($missing),
        'request_id' => $requestIdDetected,
    ]);
    ts_write_json($pipelineDir . '/bug_pack_last.json', [
        'state_version' => 2,
        'run_id' => $runId,
        'profile' => $profile,
        'generated_at' => date(DateTimeInterface::ATOM),
        'zip_path' => $zipPathMasked,
    ]);
}

$auditDir = dirname($auditLog);
if (!is_dir($auditDir)) @mkdir($auditDir, 0775, true);
$actor = getenv('USER') ?: 'cli';
$auditLine = json_encode([
    'ts' => date(DateTimeInterface::ATOM),
    'actor_username' => $actor,
    'action' => 'BUG_PACK_CREATED',
    'run_id' => $runId,
    'profile' => $profile,
    'env' => $envResolved,
    'result_level' => $resultLevel,
    'zip_path_masked' => $zipPathMasked,
    'request_id' => $requestIdDetected,
], JSON_UNESCAPED_SLASHES) . "\n";
@file_put_contents($auditLog, $auditLine, FILE_APPEND);

echo "OK: bug_pack v2 created run_id=" . $runId . " profile=" . $profile . " zip=" . $zipPathMasked . "\n";
exit(0);
