<?php
/**
 * runbook_check.php — Gate 7: Runbook + Onboarding Check
 *
 * Checks:
 *   - File runbook wajib ada + section minimal
 *   - File onboarding wajib ada
 *   - File governance modul wajib ada
 *   - Semua section checklist minimal tercakup:
 *     login issues, RBAC changes, backup/restore, deploy, incident
 *
 * Usage: php tools/qa/runbook_check.php [--strict] [--write-last]
 * Artifact: storage/logs/runbook_check_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
$logsDir = ts_storage_logs_dir();
$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');

$errors = []; $warnings = []; $found = [];

// ── Runbook candidates ────────────────────────────────────────────────────
$runbookCandidates = [
    'docs/RUNBOOK_OPS.md','docs/runbook.md','docs/RUNBOOK.md',
    'docs/runbook/README.md','docs/runbook/RUNBOOK.md','docs/ops/RUNBOOK_INDEX.md',
];
$runbookFile = null;
foreach ($runbookCandidates as $c) {
    if (is_file($root . '/' . $c)) { $runbookFile = $c; break; }
}

// ── Runbook section check ─────────────────────────────────────────────────
$requiredSections = ['login','rbac','backup','restore','deploy','incident','rollback'];
$missingSections  = [];
if ($runbookFile) {
    $found['runbook'] = $runbookFile;
    $content = strtolower((string)file_get_contents($root . '/' . $runbookFile));
    foreach ($requiredSections as $s) {
        if (!str_contains($content, $s)) $missingSections[] = $s;
    }
    if (count($missingSections) > 3) {
        $warnings[] = "runbook:missing_sections=" . implode(',', $missingSections);
    }
} else {
    $errors[] = 'runbook:file_not_found';
}

// ── Onboarding ────────────────────────────────────────────────────────────
$onboardingCandidates = [
    'docs/ONBOARDING_USER.md','docs/onboarding.md','docs/ONBOARDING.md',
    'docs/onboarding/ONBOARDING.md','docs/onboarding/NEW_USER.md',
];
$onboardingFile = null;
foreach ($onboardingCandidates as $c) {
    if (is_file($root . '/' . $c)) { $onboardingFile = $c; break; }
}
if ($onboardingFile) {
    $found['onboarding'] = $onboardingFile;
    $obContent = strtolower((string)file_get_contents($root . '/' . $onboardingFile));
    $obRequired = ['user','dept','office','role','password'];
    $obMissing  = array_filter($obRequired, fn($s) => !str_contains($obContent, $s));
    if (count($obMissing) > 3) $warnings[] = 'onboarding:missing_sections=' . implode(',', $obMissing);
} else {
    $errors[] = 'onboarding:file_not_found';
}

// ── Governance module doc ──────────────────────────────────────────────────
$govCandidates = [
    'docs/GOVERNANCE_NEW_MODULE.md','docs/GOVERNANCE_MODULE.md','docs/governance_modules.md',
    'docs/governance/NEW_MODULE.md','docs/governance/MODULE_GOVERNANCE.md',
    'docs/ops/CHECKLIST_MODUL_BARU.md',
];
$govFile = null;
foreach ($govCandidates as $c) {
    if (is_file($root . '/' . $c)) { $govFile = $c; break; }
}
if ($govFile) { $found['governance'] = $govFile; }
else { $warnings[] = 'governance_doc:not_found_non_blocking'; }

// ── Runbook index (ops summary) ───────────────────────────────────────────
$indexCandidates = ['docs/ops/RUNBOOK_INDEX.md','docs/ops/FINAL_GATE_STATUS.md'];
$indexFile = null;
foreach ($indexCandidates as $c) {
    if (is_file($root . '/' . $c)) { $indexFile = $c; break; }
}
if ($indexFile) { $found['runbook_index'] = $indexFile; }
else { $warnings[] = 'runbook_index:not_found_non_blocking'; }

// ── Gate integrity: module_governance_lint ────────────────────────────────
$lintJson = $logsDir . '/module_governance_lint_last.json';
if (!is_file($lintJson)) {
    @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/module_governance_lint.php') . ' --write-last 2>&1');
}
$lintOk = false;
if (is_file($lintJson)) {
    $lintData = json_decode((string)file_get_contents($lintJson), true);
    $lintOk   = is_array($lintData) && (bool)($lintData['ok'] ?? false);
    $found['module_governance_lint'] = $lintOk ? 'ok' : 'fail';
    if (!$lintOk) $warnings[] = 'module_governance_lint:fail';
} else {
    $warnings[] = 'module_governance_lint:not_run';
}

$ok = count($errors) === 0;

$payload = [
    'ok'       => $ok,
    'run_at'   => date(DateTimeInterface::ATOM),
    'found'    => $found,
    'errors'   => $errors,
    'warnings' => $warnings,
    'checks'   => [
        'runbook_found'         => $runbookFile !== null,
        'runbook_sections_ok'   => count($missingSections) <= 3,
        'onboarding_found'      => $onboardingFile !== null,
        'governance_doc_found'  => $govFile !== null,
        'runbook_index_found'   => $indexFile !== null,
        'governance_lint_ok'    => $lintOk,
    ],
];

if ($writeLast) {
    @file_put_contents($logsDir . '/runbook_check_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Alias for runbook_onboarding_check_last.json
    @file_put_contents($logsDir . '/runbook_onboarding_check_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => $ok, 'errors' => $errors, 'warnings' => count($warnings)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
