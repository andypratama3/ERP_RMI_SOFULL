<?php
/**
 * Tools Doctor — scan + safe auto-fix for tools (NAS-First).
 * CLI only. --check (default) | --apply --i-understand
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/_lib/tools_paths.php';
tools_assert_app_root_locked_cli();

require_once $root . '/tools/_lib/tools_bootstrap.php';
require_once $root . '/tools/_lib/tools_state.php';
require_once $root . '/tools/_lib/tools_http.php';

$args = $_SERVER['argv'] ?? [];
$mode = (in_array('--apply', $args, true) || in_array('--apply-safe-fixes', $args, true)) ? 'apply' : 'check';
$iUnderstand = in_array('--i-understand', $args, true);
$writeLast = !in_array('--no-write-last', $args, true);
$backupDir = '';
foreach ($args as $a) {
    if (is_string($a) && str_starts_with($a, '--backup-dir=')) {
        $backupDir = rtrim(substr($a, 13), '/');
        break;
    }
}
if ($backupDir === '') {
    $backupDir = $root . '/storage/backups/tools_doctor_' . date('YmdHis');
}

$appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'unknown')));
$isProd = $appEnv === 'production' || $appEnv === 'prod';
if ($isProd && $mode === 'apply' && !$iUnderstand) {
    echo "FAIL: Production requires --i-understand for apply.\n";
    exit(1);
}

$baseUrlPresent = tools_get_base_url() !== null;
$disableFunctions = ini_get('disable_functions') ?: '';
$curlAvailable = function_exists('curl_init');
$logsDir = $root . '/storage/logs';
$logsWritable = is_dir($logsDir) && is_writable($logsDir);
$backupsDir = $root . '/storage/backups';
$backupsWritable = is_dir($backupsDir) && is_writable($backupsDir);

$assumptions = ['state_version' => 'assumptions_v1', 'generated_at' => date('c'), 'items' => []];
if (!$baseUrlPresent) {
    $assumptions['items'][] = ['code' => 'TOOLS_BASE_URL_MISSING', 'hint' => 'Set TOOLS_BASE_URL in NAS env'];
}
@mkdir($logsDir, 0775, true);
$assumptionsPath = $logsDir . '/assumptions_last.json';
tools_write_json_atomic($assumptionsPath, $assumptions);

$findings = [];
$manualQueue = [];
$requestId = 'td-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

function td_add(array &$findings, string $severity, string $file, string $code, string $message, bool $autoFixed = false): void
{
    $findings[] = ['severity' => $severity, 'file' => $file, 'code' => $code, 'message' => $message, 'auto_fixed' => $autoFixed];
}

function td_manual(array &$queue, string $file, string $code, string $reason, string $suggestedFix): void
{
    $queue[] = ['file' => $file, 'finding_code' => $code, 'reason' => $reason, 'suggested_fix' => $suggestedFix];
}

$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tools', RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (str_contains($rel, 'vendor') || str_contains($rel, 'node_modules')) continue;
        $phpFiles[] = $rel;
    }
}
sort($phpFiles);
@file_put_contents($logsDir . '/tools_inventory.txt', implode("\n", $phpFiles) . "\n");

$allowlistPublic = ['tools/ping.php'];
$bootstrapPaths = ['tools/_lib/tools_bootstrap.php', 'tools/_lib/bootstrap.php'];

foreach ($phpFiles as $rel) {
    $abs = $root . '/' . $rel;
    $content = @file_get_contents($abs);
    if ($content === false) continue;

    if (str_contains($rel, '_lib/') || str_contains($rel, 'tools_doctor') || str_contains($rel, 'tools_state_lib') || str_contains($rel, 'tools_ui_helpers')) {
        continue;
    }

    if (str_contains($rel, 'tools/qa/') || str_contains($rel, 'tools/ops/')) {
        if (str_ends_with($rel, '_web.php') || preg_match('/\.php$/', $rel)) {
        }
    }

    $relDir = dirname($rel);
    $depth = substr_count($rel, '/');
    $bootstrapRel = str_repeat('../', $depth) . '_lib/tools_bootstrap.php';
    if (str_starts_with($rel, 'tools/')) {
        $fromTools = substr($rel, 6);
        $d = substr_count($fromTools, '/');
        $bootstrapRel = ($d > 0 ? str_repeat('../', $d) : '') . '_lib/tools_bootstrap.php';
        if ($d > 0) $bootstrapRel = str_repeat('../', $d) . '_lib/tools_bootstrap.php';
        else $bootstrapRel = '_lib/tools_bootstrap.php';
    }

    $hasBootstrap = str_contains($content, 'tools_bootstrap') || str_contains($content, 'bootstrap.php') || str_contains($content, 'tools_ui_helpers') || str_contains($content, 'tools_state_lib');
    if (!$hasBootstrap && !str_contains($rel, '_lib/') && !in_array($rel, $allowlistPublic, true)) {
        if (preg_match('/require|include/', $content) && (str_contains($content, 'auth.php') || str_contains($content, 'rmi_layout'))) {
        } else {
            td_add($findings, 'WARN', $rel, 'MISSING_BOOTSTRAP', 'File may need tools bootstrap include', false);
        }
    }

    $isWebPage = str_contains($content, 'rmi_header') || str_contains($content, 'require_login') || str_contains($content, 'require_role') || (str_contains($content, '$_POST') && str_contains($content, 'REQUEST_METHOD'));
    if ($isWebPage && !in_array($rel, $allowlistPublic, true)) {
        $hasAdminGuard = str_contains($content, 'require_login') || str_contains($content, 'require_role') || str_contains($content, 'tools_require_admin') || str_contains($content, 'tools_require_access');
        if (!$hasAdminGuard) {
            td_add($findings, 'WARN', $rel, 'MISSING_ADMIN_GUARD', 'Web page may need admin guard', false);
        }
    }

    if (str_contains($content, '$_POST') && str_contains($content, 'REQUEST_METHOD') && str_contains($content, 'POST')) {
        $hasCsrf = str_contains($content, 'verify_csrf') || str_contains($content, 'tools_verify_csrf') || str_contains($content, 'rmi_csrf_validate');
        if (!$hasCsrf) {
            td_add($findings, 'WARN', $rel, 'MISSING_CSRF', 'POST handler may need CSRF verification', false);
        }
    }

    if (preg_match('/echo\s+\$?(__DIR__|APP_ROOT|_SERVER\[[\'"]DOCUMENT_ROOT)/', $content) || preg_match('/echo\s+.*(\/volume1\/|\/var\/services\/)/', $content)) {
        td_add($findings, 'CRITICAL', $rel, 'PATH_LEAK', 'Potential path/secret leak in output', false);
    }

    if (preg_match('/\b(shell_exec|exec|system|passthru)\s*\(/', $content) && (str_contains($rel, '_web.php') || str_contains($rel, 'tools/') && !str_contains($rel, 'qa/') && !str_contains($rel, '_lib/'))) {
        if (str_contains($disableFunctions, 'shell_exec') || str_contains($disableFunctions, 'exec')) {
            td_add($findings, 'CRITICAL', $rel, 'SHELL_DISABLED', 'Web page uses shell_exec/exec but disabled on server', false);
        } else {
            td_add($findings, 'WARN', $rel, 'SHELL_IN_WEB', 'Web page uses shell_exec/exec; consider HTTP/state read', false);
        }
    }
}

$lintFailures = [];
foreach ($phpFiles as $rel) {
    $abs = $root . '/' . $rel;
    $out = [];
    $code = 0;
    @exec('php -l ' . escapeshellarg($abs) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $lintFailures[] = $rel;
        td_add($findings, 'CRITICAL', $rel, 'LINT_FAIL', implode(' ', $out) ?: 'Parse error', false);
    }
}

$criticalCount = count(array_filter($findings, static fn($f) => ($f['severity'] ?? '') === 'CRITICAL'));
$overallOk = $criticalCount === 0;

$manualQueuePayload = [
    'state_version' => 'manual_action_queue_v1',
    'generated_at' => date('c'),
    'items' => $manualQueue,
];
tools_write_json_atomic($logsDir . '/manual_action_queue_last.json', $manualQueuePayload);

if ($mode === 'apply' && $iUnderstand && !empty($findings)) {
    @mkdir($backupDir, 0775, true);
    foreach (array_unique(array_column($findings, 'file')) as $f) {
        $abs = $root . '/' . $f;
        if (is_file($abs)) {
            $dest = $backupDir . '/' . $f;
            @mkdir(dirname($dest), 0775, true);
            @copy($abs, $dest);
        }
    }
}

$report = [
    'state_version' => 'tools_doctor_v1',
    'generated_at' => date('c'),
    'mode' => $mode,
    'env' => $appEnv,
    'base_url_present' => $baseUrlPresent,
    'overall_ok' => $overallOk,
    'critical_fail_count' => $criticalCount,
    'findings' => $findings,
    'backups_dir' => $backupDir,
    'request_id' => $requestId,
    'lint_failures' => $lintFailures,
];

if ($writeLast) {
    tools_write_json_atomic($logsDir . '/tools_doctor_last.json', $report);
}

$govDir = $root . '/docs/governance';
@mkdir($govDir, 0775, true);
$mdReport = "# Tools Doctor Report\n\n";
$mdReport .= "- Generated: " . date('c') . "\n";
$mdReport .= "- Mode: {$mode}\n";
$mdReport .= "- Overall: " . ($overallOk ? 'OK' : 'FAIL') . "\n";
$mdReport .= "- Critical: {$criticalCount}\n\n";
$mdReport .= "## Findings\n\n";
foreach ($findings as $f) {
    $mdReport .= "- [{$f['severity']}] {$f['file']}: {$f['code']} — {$f['message']}\n";
}
if (!$overallOk) {
    $mdReport .= "\n## Next Actions\n\n";
    $mdReport .= "1. Fix CRITICAL findings (lint, path leak, shell disabled).\n";
    $mdReport .= "2. Run `php tools/qa/tools_doctor.php --check` to verify.\n";
}
@file_put_contents($govDir . '/TOOLS_DOCTOR_REPORT.md', $mdReport);

$patchDiff = "# Patch Diff — Tools Doctor\n\n";
$patchDiff .= "Generated: " . date('c') . "\n\n";
$patchDiff .= "## Files Touched\n\n";
$patchDiff .= "Mode: {$mode}. No automatic patches applied in check mode.\n";
@file_put_contents($govDir . '/PATCH_DIFF_TOOLS_DOCTOR.md', $patchDiff);

if (is_file($govDir . '/ASSUMPTIONS_LOG.md')) {
    $line = "| " . date('Y-m-d') . " | TOOLS_DOCTOR | base_url=" . ($baseUrlPresent ? 'OK' : 'MISSING') . " | " . ($baseUrlPresent ? '' : 'Set TOOLS_BASE_URL') . " | " . ($overallOk ? 'OK' : 'PENDING') . " |\n";
    @file_put_contents($govDir . '/ASSUMPTIONS_LOG.md', $line, FILE_APPEND);
}

echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit($overallOk ? 0 : 1);
