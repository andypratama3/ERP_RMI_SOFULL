<?php
/**
 * runbook_onboarding_check.php — Gate K: Runbook + Onboarding Docs
 *
 * Checks:
 *   - docs/runbook.md (atau runbook/README.md / RUNBOOK_OPS.md)
 *   - docs/onboarding.md (atau onboarding/ONBOARDING.md / ONBOARDING_USER.md)
 *   - Setiap file punya section minimal
 *
 * Usage: php tools/qa/runbook_onboarding_check.php [--strict] [--write-last]
 * Artifact: storage/logs/runbook_onboarding_check_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
$logsDir = ts_storage_logs_dir();

$errors   = [];
$warnings = [];
$found    = [];

// ── Find runbook file (multiple valid locations)
$runbookCandidates = [
    'docs/RUNBOOK_OPS.md',
    'docs/runbook.md',
    'docs/RUNBOOK.md',
    'docs/runbook/README.md',
    'docs/runbook/RUNBOOK.md',
    'docs/ops/RUNBOOK_INDEX.md',
];
$runbookFile = null;
foreach ($runbookCandidates as $c) {
    if (is_file($root . '/' . $c)) { $runbookFile = $c; break; }
}
if ($runbookFile) {
    $rbContent = (string)file_get_contents($root . '/' . $runbookFile);
    $found['runbook'] = $runbookFile;
    // Check minimal sections
    $rbSections = ['deploy','rollback','backup','incident','error'];
    $rbMissing  = [];
    foreach ($rbSections as $s) {
        if (!str_contains(strtolower($rbContent), $s)) $rbMissing[] = $s;
    }
    if (count($rbMissing) > 3) {
        $warnings[] = "runbook:{$runbookFile} missing sections: " . implode(',', $rbMissing);
    }
} else {
    $errors[] = 'runbook:file_not_found (candidates: ' . implode(', ', $runbookCandidates) . ')';
}

// ── Find onboarding file
$onboardingCandidates = [
    'docs/ONBOARDING_USER.md',
    'docs/onboarding.md',
    'docs/ONBOARDING.md',
    'docs/onboarding/ONBOARDING.md',
    'docs/onboarding/NEW_USER.md',
];
$onboardingFile = null;
foreach ($onboardingCandidates as $c) {
    if (is_file($root . '/' . $c)) { $onboardingFile = $c; break; }
}
if ($onboardingFile) {
    $obContent = (string)file_get_contents($root . '/' . $onboardingFile);
    $found['onboarding'] = $onboardingFile;
    $obSections = ['user','dept','office','role','password'];
    $obMissing  = [];
    foreach ($obSections as $s) {
        if (!str_contains(strtolower($obContent), $s)) $obMissing[] = $s;
    }
    if (count($obMissing) > 3) {
        $warnings[] = "onboarding:{$onboardingFile} missing sections: " . implode(',', $obMissing);
    }
} else {
    $errors[] = 'onboarding:file_not_found';
}

// ── Find governance doc
$govCandidates = [
    'docs/GOVERNANCE_MODULE.md',
    'docs/GOVERNANCE_NEW_MODULE.md',
    'docs/governance_modules.md',
    'docs/governance/MODULE_GOVERNANCE.md',
    'docs/governance/NEW_MODULE.md',
    'docs/ops/CHECKLIST_MODUL_BARU.md',
];
$govFile = null;
foreach ($govCandidates as $c) {
    if (is_file($root . '/' . $c)) { $govFile = $c; break; }
}
if ($govFile) {
    $found['governance'] = $govFile;
} else {
    $warnings[] = 'governance_doc:file_not_found (non-blocking)';
}

// ── Check module_governance_lint artifact
$lintJson = $logsDir . '/module_governance_lint_last.json';
$lintOk = false;
if (is_file($lintJson)) {
    $lintData = json_decode((string)file_get_contents($lintJson), true);
    $lintOk = is_array($lintData) && (bool)($lintData['ok'] ?? false);
    $found['module_governance_lint'] = $lintOk ? 'ok' : 'failed';
} else {
    $found['module_governance_lint'] = 'not_run_yet';
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
        'runbook_found'    => $runbookFile !== null,
        'onboarding_found' => $onboardingFile !== null,
        'governance_found' => $govFile !== null,
        'lint_ok'          => $lintOk,
    ],
];

if ($writeLast) {
    @file_put_contents($logsDir . '/runbook_onboarding_check_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
