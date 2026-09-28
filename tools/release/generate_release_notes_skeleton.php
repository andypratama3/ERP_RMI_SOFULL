<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/release_notes_skeleton_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$rfcId = '';
$runIdArg = 'last';
$mode = 'draft';
$writeLast = false;
$strictArg = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') {
        continue;
    }
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if ($arg === '--strict') {
        $strictArg = true;
        continue;
    }
    if (str_starts_with($arg, '--env=')) {
        $env = strtolower(trim((string)substr($arg, 6)));
        continue;
    }
    if (str_starts_with($arg, '--rfc=')) {
        $rfcId = strtoupper(trim((string)substr($arg, 6)));
        continue;
    }
    if (str_starts_with($arg, '--run-id=')) {
        $runIdArg = trim((string)substr($arg, 9));
        continue;
    }
    if (str_starts_with($arg, '--mode=')) {
        $mode = strtolower(trim((string)substr($arg, 7)));
        continue;
    }
}
if (!in_array($env, ['staging', 'production'], true)) {
    $env = 'staging';
}
if (!in_array($mode, ['draft', 'final-prep'], true)) {
    $mode = 'draft';
}
$strict = $strictArg || ($env === 'production');
$runId = $runIdArg;
if ($runId === '' || strtolower($runId) === 'last') {
    $runId = rns_pipeline_last_run_id();
}
if ($runId === '') {
    $runId = 'LOCAL_' . date('YmdHis');
}

$errors = [];
$notes = [];
if ($rfcId === '') {
    $errors[] = 'rfc_required';
}
if (preg_match('/^RFC\-\d{4}\-\d{4}$/', $rfcId) !== 1) {
    $errors[] = 'rfc_id_format_invalid';
}

$file = $errors === [] ? find_rfc_file_by_id($rfcId) : '';
if ($file === '') {
    $errors[] = 'rfc_missing_or_invalid';
}
$raw = $file !== '' ? (string)@file_get_contents($file) : '';
if ($file !== '' && trim($raw) === '') {
    $errors[] = 'rfc_read_failed';
}

$fm = $raw !== '' ? parse_rfc_front_matter($raw) : [];
$parsed = $file !== '' ? rfc_parse_file($file) : ['ok' => false, 'frontmatter' => [], 'body' => ''];
if (!$parsed['ok']) {
    $errors[] = 'rfc_parse_failed';
}
if ($parsed['ok']) {
    $val = rfc_validate_frontmatter((array)$parsed['frontmatter'], true);
    if ($val !== []) {
        $errors[] = 'rfc_frontmatter_invalid';
        $notes[] = rns_mask_text(implode(',', array_slice($val, 0, 5)));
    }
}

$body = $raw !== '' ? rns_body_from_rfc_md($raw) : '';
$usedFallback = false;
$missingSections = [];
$parseNotes = [];

$summary = rns_get_section_best_effort($body, 'Summary', $parseNotes, $usedFallback);
$scopeSection = rns_get_section_best_effort($body, 'Scope', $parseNotes, $usedFallback);
$scopeIn = rns_extract_subsection($scopeSection, 'In Scope');
$scopeOut = rns_extract_subsection($scopeSection, 'Out of Scope');
$risk = rns_get_section_best_effort($body, 'Risk Assessment', $parseNotes, $usedFallback);
$rollback = rns_get_section_best_effort($body, 'Rollback Plan', $parseNotes, $usedFallback);
$evidencePlan = rns_get_section_best_effort($body, 'Evidence Plan', $parseNotes, $usedFallback);
$goNoGo = rns_get_section_best_effort($body, 'Go/No-Go Criteria', $parseNotes, $usedFallback);
$steps = rns_get_section_best_effort($body, 'Execution Steps', $parseNotes, $usedFallback);
$stepsPre = rns_extract_subsection($steps, 'Pre-Deploy');
$stepsDeploy = rns_extract_subsection($steps, 'Deploy');
$stepsPost = rns_extract_subsection($steps, 'Post-Deploy');
$hypercare = rns_get_section_best_effort($body, 'Hypercare Plan', $parseNotes, $usedFallback);
$approvalsSection = rns_get_section_best_effort($body, 'Approvals', $parseNotes, $usedFallback);

$summaryText = rns_tbd($summary, 'summary', $missingSections);
$scopeInText = rns_tbd($scopeIn, 'scope_in', $missingSections);
$scopeOutText = rns_tbd($scopeOut, 'scope_out', $missingSections);
$riskText = rns_tbd($risk, 'risk', $missingSections);
$rollbackText = rns_tbd($rollback, 'rollback', $missingSections);
$evidenceText = rns_tbd($evidencePlan, 'evidence_plan', $missingSections);
$goNoGoText = rns_tbd($goNoGo, 'go_no_go', $missingSections);
$stepsPreText = rns_tbd($stepsPre, 'steps_pre', $missingSections);
$stepsDeployText = rns_tbd($stepsDeploy, 'steps_deploy', $missingSections);
$stepsPostText = rns_tbd($stepsPost, 'steps_post', $missingSections);
$hypercareText = rns_tbd($hypercare, 'hypercare', $missingSections);
$approvalsText = rns_tbd($approvalsSection, 'approvals', $missingSections);

$assumptionsPath = rn_root() . '/docs/governance/ASSUMPTIONS.md';
if ($missingSections !== []) {
    $assumeLines = [];
    $assumeLines[] = '- ts: ' . date(DateTimeInterface::ATOM)
        . ' | tool: generate_release_notes_skeleton'
        . ' | rfc_id: ' . rns_mask_text($rfcId)
        . ' | run_id: ' . rns_mask_text($runId)
        . ' | missing_sections: ' . rns_mask_text(implode(',', $missingSections))
        . ' | note: set_to_TBD';
    if (!is_file($assumptionsPath)) {
        @file_put_contents($assumptionsPath, "# Assumptions Log\n\n");
    }
    @file_put_contents($assumptionsPath, implode("\n", $assumeLines) . "\n", FILE_APPEND);
}

$requiredTotal = 11;
$foundNonEmpty = $requiredTotal - count(array_values(array_filter($missingSections, static fn($v): bool => $v !== 'approvals')));
$coverage = (int)floor(($foundNonEmpty / $requiredTotal) * 100);
if ($coverage < 60) {
    $parseNotes[] = 'coverage_below_60';
    if ($env === 'production' && $mode === 'final-prep' && $strict) {
        $errors[] = 'coverage_below_60_strict';
    }
}

$status = strtoupper(trim((string)($fm['STATUS'] ?? ($parsed['frontmatter']['STATUS'] ?? ''))));
if ($mode === 'final-prep' && $env === 'production' && $strict && $status !== 'APPROVED') {
    $errors[] = 'rfc_not_approved_for_final_prep';
}
if ($mode === 'draft' && !in_array($status, ['DRAFT', 'IN_REVIEW', 'APPROVED', 'IMPLEMENTED', 'CLOSED'], true)) {
    $parseNotes[] = 'draft_mode_unusual_status:' . $status;
}

$front = (array)($parsed['frontmatter'] ?? []);
$approvalSummary = rn_approval_summary($front);
$relFile = $file !== '' ? ltrim(str_replace(rn_root(), '', (string)realpath($file)), '/') : '';
$baseData = [
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'run_id' => $runId,
    'mode' => $mode,
    'rfc' => [
        'id' => $rfcId,
        'title' => rns_mask_text((string)($fm['TITLE'] ?? ($front['TITLE'] ?? ''))),
        'type' => rns_mask_text((string)($fm['TYPE'] ?? ($front['TYPE'] ?? ''))),
        'status' => rns_mask_text($status),
        'schedule' => (($fm['SCHEDULE'] ?? ($front['SCHEDULE'] ?? '')) !== '') ? rns_mask_text((string)($fm['SCHEDULE'] ?? $front['SCHEDULE'])) : null,
        'target_env' => (($fm['TARGET_ENV'] ?? ($front['TARGET_ENV'] ?? '')) !== '') ? rns_mask_text((string)($fm['TARGET_ENV'] ?? $front['TARGET_ENV'])) : null,
        'created_by' => (($fm['CREATED_BY'] ?? ($front['CREATED_BY'] ?? '')) !== '') ? rns_mask_text((string)($fm['CREATED_BY'] ?? $front['CREATED_BY'])) : null,
        'approvals' => $approvalSummary,
        'file_rel_path' => rns_mask_text($relFile),
    ],
    'sections' => [
        'summary' => ['source' => 'RFC', 'text' => $summaryText],
        'scope_in' => ['source' => 'RFC', 'text' => $scopeInText],
        'scope_out' => ['source' => 'RFC', 'text' => $scopeOutText],
        'risk' => ['source' => 'RFC', 'text' => $riskText],
        'rollback' => ['source' => 'RFC', 'text' => $rollbackText],
        'evidence_plan' => ['source' => 'RFC', 'text' => $evidenceText],
        'go_no_go' => ['source' => 'RFC', 'text' => $goNoGoText],
        'steps_pre' => ['source' => 'RFC', 'text' => $stepsPreText],
        'steps_deploy' => ['source' => 'RFC', 'text' => $stepsDeployText],
        'steps_post' => ['source' => 'RFC', 'text' => $stepsPostText],
        'hypercare' => ['source' => 'RFC', 'text' => $hypercareText],
        'approvals_text' => ['source' => 'RFC', 'text' => $approvalsText],
    ],
    'parse_report' => [
        'coverage_percent' => $coverage,
        'missing_sections' => array_values(array_unique($missingSections)),
        'used_fallback_mapping' => $usedFallback,
        'notes' => array_values(array_unique(array_map(static fn($v): string => rns_mask_text((string)$v), $parseNotes))),
    ],
];

$safeRfc = str_replace('-', '_', $rfcId);
$safeRun = preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) ?: 'last';
$exportDir = rn_export_dir();
$jsonPath = $exportDir . '/release_notes_skeleton_' . $safeRfc . '_' . $safeRun . '.json';
$mdPath = $exportDir . '/release_notes_skeleton_' . $safeRfc . '_' . $safeRun . '.md';
$jsonLast = $exportDir . '/release_notes_skeleton_last.json';
$mdLast = $exportDir . '/release_notes_skeleton_last.md';
$buildLastPath = rn_root() . '/storage/logs/pipeline/release_notes_skeleton_build_last.json';

$overallOk = ($errors === []);
$payload = build_skeleton_json($baseData);
$payload['overall_ok'] = $overallOk;
$payload['errors_masked'] = array_values(array_unique(array_map(static fn($v): string => rns_mask_text((string)$v), $errors)));
$md = build_skeleton_md($baseData);

if (!ts_write_json($jsonPath, $payload)) {
    $overallOk = false;
    $payload['overall_ok'] = false;
    $payload['errors_masked'][] = 'write_json_failed';
}
if (@file_put_contents($mdPath, $md) === false) {
    $overallOk = false;
    $payload['overall_ok'] = false;
    $payload['errors_masked'][] = 'write_md_failed';
}
ts_write_json($jsonPath, $payload);

if ($writeLast) {
    @copy($jsonPath, $jsonLast);
    @copy($mdPath, $mdLast);
}

$build = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => $overallOk,
    'env' => $env,
    'run_id' => $runId,
    'rfc_id' => $rfcId,
    'mode' => $mode,
    'strict' => $strict,
    'coverage_percent' => $coverage,
    'missing_sections' => array_values(array_unique($missingSections)),
    'used_fallback_mapping' => $usedFallback,
    'notes' => array_values(array_unique(array_map(static fn($v): string => rns_mask_text((string)$v), array_merge($notes, $parseNotes)))),
    'errors_masked' => array_values(array_unique(array_map(static fn($v): string => rns_mask_text((string)$v), $errors))),
    'artifacts' => [
        'json' => rns_mask_text($jsonPath),
        'md' => rns_mask_text($mdPath),
        'json_last' => $writeLast ? rns_mask_text($jsonLast) : null,
        'md_last' => $writeLast ? rns_mask_text($mdLast) : null,
    ],
];
ts_write_json($buildLastPath, $build);

$out = [
    'state_version' => 1,
    'overall_ok' => $overallOk,
    'env' => $env,
    'run_id' => $runId,
    'mode' => $mode,
    'strict' => $strict,
    'rfc_id' => $rfcId,
    'coverage_percent' => $coverage,
    'artifacts' => $build['artifacts'],
    'errors_masked' => $build['errors_masked'],
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);

