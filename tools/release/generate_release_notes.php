<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/_lib/release_notes_lib.php';
require_once __DIR__ . '/_lib/release_pack_lib.php';
require_once __DIR__ . '/_lib/release_notes_skeleton_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$rfcId = '';
$pipelineMode = 'last';
$runId = '';
$mode = 'draft';
$useSkeleton = 'auto';
$writeLast = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--write-last') { $writeLast = true; continue; }
    if (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--rfc=')) $rfcId = strtoupper(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--pipeline=')) $pipelineMode = strtolower(trim((string)substr($arg, 11)));
    elseif (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
    elseif (str_starts_with($arg, '--mode=')) $mode = strtolower(trim((string)substr($arg, 7)));
    elseif (str_starts_with($arg, '--use-skeleton=')) $useSkeleton = trim((string)substr($arg, 15));
}

$errors = [];
$notes = [];
if (!in_array($env, ['staging', 'production'], true)) $errors[] = 'invalid_env';
if ($rfcId === '') $errors[] = 'rfc_required';
if (!in_array($pipelineMode, ['last', 'run-id'], true)) $errors[] = 'invalid_pipeline_mode';
if ($pipelineMode === 'run-id' && $runId === '') $errors[] = 'run_id_required_for_pipeline_run_id';
if (!in_array($mode, ['draft', 'final'], true)) $errors[] = 'invalid_mode';
if (!in_array($useSkeleton, ['auto', 'off'], true) && !str_starts_with($useSkeleton, 'path=')) $errors[] = 'invalid_use_skeleton';

$rfcPath = rfc_find_file_by_id($rfcId);
$rfcParsed = $rfcPath !== '' ? rfc_parse_file($rfcPath) : ['ok' => false, 'error' => 'missing', 'frontmatter' => [], 'body' => ''];
if (!$rfcParsed['ok']) $errors[] = 'rfc_missing_or_invalid';
$rfcFm = (array)($rfcParsed['frontmatter'] ?? []);
$rfcValidationErrors = $rfcParsed['ok'] ? rfc_validate_frontmatter($rfcFm, true) : [];
if ($rfcValidationErrors !== []) $errors = array_merge($errors, $rfcValidationErrors);

$pipeline = rn_find_pipeline_artifact($pipelineMode, $runId);
if (!$pipeline['ok']) $errors[] = 'pipeline_artifact_missing_or_invalid';
$pipelineData = (array)$pipeline['data'];
$resolvedRunId = $runId !== '' ? $runId : (string)($pipelineData['run_id'] ?? 'last');
$safeRun = preg_replace('/[^A-Za-z0-9_\-]/', '_', $resolvedRunId) ?: 'last';

$skeleton = ['ok' => false, 'path' => '', 'data' => []];
$skeletonSafeRfc = str_replace('-', '_', $rfcId);
$skeletonDefaultPath = rn_root() . '/storage/exports/release/release_notes_skeleton_' . $skeletonSafeRfc . '_' . $safeRun . '.json';
if ($useSkeleton !== 'off') {
    $skeletonPath = $skeletonDefaultPath;
    if (str_starts_with($useSkeleton, 'path=')) {
        $rawPath = trim((string)substr($useSkeleton, 5));
        if ($rawPath !== '') {
            $skeletonPath = str_starts_with($rawPath, '/') ? $rawPath : (rn_root() . '/' . ltrim($rawPath, '/'));
        }
    }
    $skeletonRead = rn_read_json($skeletonPath);
    if (!$skeletonRead['ok'] && $useSkeleton === 'auto' && $env === 'staging') {
        $phpBin = (string)(PHP_BINARY ?: 'php');
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg(rn_root() . '/tools/release/generate_release_notes_skeleton.php')
            . ' --env=' . escapeshellarg($env)
            . ' --rfc=' . escapeshellarg($rfcId)
            . ' --run-id=' . escapeshellarg($resolvedRunId)
            . ' --mode=draft --write-last';
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $skeletonRead = rn_read_json($skeletonPath);
        if ((int)$code !== 0) {
            $notes[] = 'DATA_MISSING:skeleton_autogen_failed';
        }
    }
    if ($skeletonRead['ok']) {
        $sd = (array)$skeletonRead['data'];
        if ((string)($sd['rfc']['id'] ?? '') !== $rfcId || (string)($sd['run_id'] ?? '') !== $resolvedRunId) {
            $errors[] = 'skeleton_mismatch';
        } else {
            $skeleton = ['ok' => true, 'path' => $skeletonPath, 'data' => $sd];
        }
    } elseif ($env === 'production' && $mode === 'final') {
        $errors[] = 'skeleton_missing_or_invalid_final_production';
    } else {
        $notes[] = 'DATA_MISSING:skeleton_unavailable';
    }
}

$rfcUsageByRunPath = rn_root() . '/storage/logs/pipeline/rfc_usage_' . $safeRun . '.json';
$rfcUsageLastPath = rn_root() . '/storage/logs/pipeline/rfc_usage_last.json';
$rfcUsageByRun = rn_read_json($rfcUsageByRunPath);
$rfcUsageLast = rn_read_json($rfcUsageLastPath);
$rfcUsage = $rfcUsageByRun['ok'] ? $rfcUsageByRun : $rfcUsageLast;
$rfcUsageData = (array)$rfcUsage['data'];
if (!$rfcUsage['ok']) {
    $notes[] = 'DATA_MISSING:rfc_usage';
    $notes[] = 'CMD: php tools/rfc/collect_rfc_usage.php --env=' . $env . ' --run-id=' . $resolvedRunId . ' --write-last' . (($env === 'production' && $mode === 'final') ? ' --strict' : '');
} elseif ((string)($rfcUsageData['run_id'] ?? '') !== $resolvedRunId) {
    $notes[] = 'DATA_MISSING:rfc_usage_run_id_mismatch';
    $errors[] = 'rfc_usage_run_id_mismatch';
}
if ($mode === 'final' && $env === 'production') {
    if (!$rfcUsage['ok']) {
        $errors[] = 'rfc_usage_missing_final_production';
    } elseif (!(bool)($rfcUsageData['overall_ok'] ?? false)) {
        $errors[] = 'rfc_usage_not_ok_final_production';
    }
}

if ($mode === 'final' && $env === 'production') {
    $phpBin = (string)(PHP_BINARY ?: 'php');
    $gateCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg(rn_root() . '/tools/rfc/rfc_check_required.php')
        . ' --type=PRODUCTION_DEPLOY --env=production --rfc=' . escapeshellarg($rfcId) . ' --strict';
    $gateOut = [];
    $gateCode = 1;
    @exec($gateCmd . ' 2>&1', $gateOut, $gateCode);
    if ($gateCode !== 0) $errors[] = 'rfc_gate_failed_final_production';
    $notes[] = rn_mask(implode(' | ', array_slice($gateOut, -2)));
}

$approvalSummary = rn_approval_summary($rfcFm);
if ($mode === 'draft' && $env === 'staging' && ((string)($rfcFm['STATUS'] ?? '') !== 'APPROVED')) {
    $notes[] = 'staging_draft_rfc_not_approved';
}

$artifacts = rn_collect_artifacts($pipelineData, $resolvedRunId);
$gates = rn_gate_from_artifacts($artifacts);
if ($gates['go_no_go'] === 'NO-GO') $notes[] = 'pipeline_evidence_indicates_no_go';

$requiredCore = ['contract_check_json', 'smoke_http_json', 'core_flows_json'];
if ($mode === 'final' && $env === 'production') {
    foreach ($requiredCore as $k) {
        if (($artifacts[$k] ?? '') === '') $errors[] = 'missing_core_artifact:' . $k;
    }
}

$rfcBody = (string)($rfcParsed['body'] ?? '');
$summaryLines = rn_list_lines(rn_extract_section($rfcBody, 'Summary'));
$scopeLines = rn_list_lines(rn_extract_section($rfcBody, 'Scope'));
$riskLines = rn_list_lines(rn_extract_section($rfcBody, 'Risk Assessment'));
$rollbackLines = rn_list_lines(rn_extract_section($rfcBody, 'Rollback Plan'));
$hypercareLines = rn_list_lines(rn_extract_section($rfcBody, 'Hypercare Plan (24-72h)'));
if ((bool)$skeleton['ok']) {
    $sec = (array)($skeleton['data']['sections'] ?? []);
    $sum = trim((string)($sec['summary']['text'] ?? ''));
    $in = trim((string)($sec['scope_in']['text'] ?? ''));
    $out = trim((string)($sec['scope_out']['text'] ?? ''));
    $risk = trim((string)($sec['risk']['text'] ?? ''));
    $rollback = trim((string)($sec['rollback']['text'] ?? ''));
    $hyper = trim((string)($sec['hypercare']['text'] ?? ''));
    $summaryLines = rn_list_lines($sum);
    $scopeLines = rn_list_lines(implode("\n", array_filter([$in, $out], static fn($v): bool => trim((string)$v) !== '')));
    $riskLines = rn_list_lines($risk);
    $rollbackLines = rn_list_lines($rollback);
    $hypercareLines = rn_list_lines($hyper);
    $notes[] = 'skeleton_used';
}

$moduleRows = [];
foreach ((array)($rfcFm['AFFECTED_AREAS'] ?? ['OPS']) as $m) {
    $module = strtoupper(trim((string)$m));
    if ($module === '') continue;
    $moduleRows[] = ['module' => $module, 'summary' => 'change delivered under RFC scope'];
}
if ($moduleRows === []) $moduleRows[] = ['module' => 'OPS', 'summary' => 'change delivered under RFC scope'];

$migrations = [];
foreach (glob(rn_root() . '/sql/migrations/*.sql') ?: [] as $mig) {
    $migrations[] = basename($mig);
}
$migrations = array_slice($migrations, -10);
if ($migrations === []) $notes[] = 'migration_registry_unknown';

$opsPolicy = rn_root() . '/storage/state/ops_thresholds_current.yaml';
$policyFingerprint = is_file($opsPolicy) ? rn_file_sha256($opsPolicy) : '';
if ($policyFingerprint === '') $notes[] = 'ops_thresholds_fingerprint_unknown';

$releaseJson = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'mode' => $mode,
    'run_id' => $resolvedRunId,
    'rfc' => [
        'id' => $rfcId,
        'title' => (string)($rfcFm['TITLE'] ?? ''),
        'type' => (string)($rfcFm['TYPE'] ?? ''),
        'status' => (string)($rfcFm['STATUS'] ?? ''),
        'schedule' => (string)($rfcFm['SCHEDULE'] ?? ''),
        'created_by' => (string)($rfcFm['CREATED_BY'] ?? ''),
        'approvals_summary' => $approvalSummary,
    ],
    'build' => [
        'run_id' => $resolvedRunId,
        'pipeline_artifact' => (string)$pipeline['masked'],
        'skeleton' => [
            'used' => (bool)$skeleton['ok'],
            'path' => (bool)$skeleton['ok'] ? rn_mask((string)$skeleton['path']) : '',
        ],
        'git' => rn_git_meta(),
        'policy_fingerprints' => [
            'ops_thresholds' => $policyFingerprint !== '' ? $policyFingerprint : null,
        ],
    ],
    'gates' => $gates,
    'changes' => [
        'high_level' => $summaryLines !== [] ? $summaryLines : ($scopeLines !== [] ? $scopeLines : ['Release scope from RFC']),
        'by_module' => $moduleRows,
        'migrations' => $migrations,
    ],
    'operational' => [
        'hypercare_plan' => implode('; ', $hypercareLines),
        'known_risks' => $riskLines,
        'rollback_plan' => $rollbackLines,
    ],
    'related_rfcs' => (array)($rfcUsageData['rfcs'] ?? []),
    'primary_rfc_id' => $rfcUsageData['primary_rfc_id'] ?? null,
    'evidence_links' => [
        'executive_summary_html' => $artifacts['executive_summary_html'] !== '' ? rn_mask($artifacts['executive_summary_html']) : '',
        'readiness_report_md' => $artifacts['readiness_report_md'] !== '' ? rn_mask($artifacts['readiness_report_md']) : '',
        'smoke_http_json' => $artifacts['smoke_http_json'] !== '' ? rn_mask($artifacts['smoke_http_json']) : '',
        'contract_check_json' => $artifacts['contract_check_json'] !== '' ? rn_mask($artifacts['contract_check_json']) : '',
        'core_flows_json' => $artifacts['core_flows_json'] !== '' ? rn_mask($artifacts['core_flows_json']) : '',
        'fix_backlog_md' => $artifacts['fix_backlog_md'] !== '' ? rn_mask($artifacts['fix_backlog_md']) : '',
        'rfc_usage_json' => $rfcUsage['ok'] ? rn_mask((string)($rfcUsageByRun['ok'] ? $rfcUsageByRunPath : $rfcUsageLastPath)) : '',
    ],
    'notes' => array_values(array_unique(array_map(static fn(string $v): string => rn_mask($v), $notes))),
    'errors_masked' => array_values(array_unique(array_map(static fn(string $v): string => rn_mask($v), $errors))),
];

$safeRfc = str_replace('-', '_', $rfcId);
$baseName = 'release_notes_' . $safeRfc . '_' . $safeRun;
$dir = rn_export_dir();
$jsonPath = $dir . '/' . $baseName . '.json';
$mdPath = $dir . '/' . $baseName . '.md';
$htmlPath = $dir . '/' . $baseName . '.html';

$zipName = 'release_pack_' . $safeRfc . '_' . $safeRun . '.zip';
$zipPath = $dir . '/' . $zipName;
$manifestPath = $dir . '/release_pack_' . $safeRfc . '_' . $safeRun . '_manifest.json';

$packInputs = [$jsonPath, $mdPath, $htmlPath];
foreach ($artifacts as $p) if (is_string($p) && $p !== '') $packInputs[] = $p;
if ((bool)$skeleton['ok']) {
    $packInputs[] = (string)$skeleton['path'];
    $skeletonMd = rn_root() . '/storage/exports/release/release_notes_skeleton_' . $skeletonSafeRfc . '_' . $safeRun . '.md';
    if (is_file($skeletonMd)) $packInputs[] = $skeletonMd;
}
if ($rfcUsageByRun['ok']) {
    $packInputs[] = $rfcUsageByRunPath;
    $mdRun = rn_root() . '/storage/logs/pipeline/rfc_usage_' . $safeRun . '.md';
    if (is_file($mdRun)) $packInputs[] = $mdRun;
} elseif ($rfcUsageLast['ok']) {
    $packInputs[] = $rfcUsageLastPath;
    $mdLast = rn_root() . '/storage/logs/pipeline/rfc_usage_last.md';
    if (is_file($mdLast)) $packInputs[] = $mdLast;
}
$requiredRfcPackFiles = [];
foreach ((array)($rfcUsageData['rfcs'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $rel = (string)($row['file_rel_path'] ?? '');
    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) continue;
    if (!str_starts_with($rel, 'docs/governance/RFC/')) continue;
    $abs = rn_root() . '/' . $rel;
    if (!is_file($abs)) continue;
    $sz = (int)@filesize($abs);
    if ($sz <= 0 || $sz > 2 * 1024 * 1024) {
        $notes[] = 'DATA_MISSING:rfc_file_size_or_missing:' . $rel;
        continue;
    }
    $packInputs[] = $abs;
    $requiredRfcPackFiles[] = $rel;
}
foreach ([
    rn_root() . '/docs/governance/CHANGE_CONTROL_POLICY.md',
    rn_root() . '/docs/governance/RELEASE_NOTES_SPEC.md',
    rn_root() . '/docs/governance/RELEASE_PACK_SPEC.md',
] as $docPath) {
    if (is_file($docPath)) $packInputs[] = $docPath;
}
$packFiles = rp_collect_whitelisted_files($packInputs);
$maxPackBytes = rp_max_bytes();

$dummySha = '';
$mdData = rn_render_md($releaseJson, $zipName, $dummySha);
$mdData .= "\n## Related RFCs in this Release\n";
$mdData .= '- Primary RFC: ' . rn_mask((string)($rfcUsageData['primary_rfc_id'] ?? 'DATA_MISSING')) . "\n";
$opsAlertCount = 0;
$opsPolicyCount = 0;
$otherCount = 0;
foreach ((array)($rfcUsageData['rfcs'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $id = rn_mask((string)($row['id'] ?? ''));
    $type = strtoupper((string)($row['type'] ?? ''));
    $status = rn_mask((string)($row['status'] ?? ''));
    if ($type === 'OPS_ALERT_POLICY_CHANGE' && $opsAlertCount < 1) {
        $mdData .= '- Alert Policy RFC: ' . $id . ' (' . $status . ')' . "\n";
        $opsAlertCount++;
        continue;
    }
    if ($type === 'OPS_POLICY_CHANGE' && $opsPolicyCount < 1) {
        $mdData .= '- Ops Policy RFC: ' . $id . ' (' . $status . ')' . "\n";
        $opsPolicyCount++;
        continue;
    }
    if ($otherCount < 5) {
        $mdData .= '- RFC: ' . $id . ' (' . $status . ')' . "\n";
        $otherCount++;
    }
}
$htmlData = rn_render_html($releaseJson, $zipName, $dummySha);
$htmlRelated = '<section style="margin-top:10px;border:1px solid #ddd;padding:8px;border-radius:6px;">'
    . '<h2 style="font-size:12px;margin:0 0 6px;">Related RFCs in this Release</h2>'
    . '<p><b>Primary RFC:</b> ' . htmlspecialchars(rn_mask((string)($rfcUsageData['primary_rfc_id'] ?? 'DATA_MISSING')), ENT_QUOTES, 'UTF-8') . '</p><ul>';
$rowCount = 0;
foreach ((array)($rfcUsageData['rfcs'] ?? []) as $row) {
    if (!is_array($row)) continue;
    if ($rowCount >= 7) break;
    $htmlRelated .= '<li>'
        . htmlspecialchars(rn_mask((string)($row['id'] ?? '')), ENT_QUOTES, 'UTF-8')
        . ' - ' . htmlspecialchars(rn_mask((string)($row['type'] ?? '')), ENT_QUOTES, 'UTF-8')
        . ' - ' . htmlspecialchars(rn_mask((string)($row['status'] ?? '')), ENT_QUOTES, 'UTF-8')
        . '</li>';
    $rowCount++;
}
$htmlRelated .= '</ul></section>';
if (str_contains($htmlData, '</body>')) {
    $htmlData = str_replace('</body>', $htmlRelated . '</body>', $htmlData);
} else {
    $htmlData .= $htmlRelated;
}
$denyHits = array_merge(rn_deny_scan($mdData), rn_deny_scan($htmlData));
if ($denyHits !== []) $errors[] = 'deny_pattern_detected_output';
$releaseJson['errors_masked'] = array_values(array_unique(array_map(static fn(string $v): string => rn_mask($v), $errors)));
ts_write_json($jsonPath, $releaseJson);
@file_put_contents($mdPath, rn_mask($mdData));
@file_put_contents($htmlPath, rn_mask($htmlData));

$pack = rp_create_pack($zipPath, $packFiles, $maxPackBytes);
$manifestPayload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'rfc_id' => $rfcId,
    'run_id' => $resolvedRunId,
    'zip_file' => basename($zipPath),
    'sha256_zip' => (string)($pack['manifest']['sha256_zip'] ?? ''),
    'files' => (array)($pack['manifest']['files'] ?? []),
    'size_bytes_total' => (int)($pack['manifest']['size_bytes_total'] ?? 0),
];
ts_write_json($manifestPath, $manifestPayload);
$verify = rp_verify_pack($zipPath, $manifestPath, true, $maxPackBytes);

$criticalFail = false;
if (in_array('rfc_missing_or_invalid', $errors, true) || in_array('pipeline_artifact_missing_or_invalid', $errors, true)) $criticalFail = true;
if ($mode === 'final' && $env === 'production') {
    if (in_array('rfc_gate_failed_final_production', $errors, true)) $criticalFail = true;
    foreach ($errors as $e) if (str_starts_with($e, 'missing_core_artifact:')) $criticalFail = true;
    if (!$verify['ok']) $criticalFail = true;
    $primaryRfcId = (string)($rfcUsageData['primary_rfc_id'] ?? '');
    if ($primaryRfcId !== '') {
        $primaryPresent = false;
        foreach ($requiredRfcPackFiles as $rel) {
            if (str_starts_with(basename($rel), $primaryRfcId . '-')) {
                $primaryPresent = true;
                break;
            }
        }
        if (!$primaryPresent) {
            $errors[] = 'primary_rfc_file_missing_in_pack';
            $criticalFail = true;
        }
    }
    foreach ((array)($rfcUsageData['rfcs'] ?? []) as $row) {
        if (!is_array($row)) continue;
        if (strtoupper((string)($row['type'] ?? '')) !== 'OPS_ALERT_POLICY_CHANGE') continue;
        $rid = (string)($row['id'] ?? '');
        $present = false;
        foreach ($requiredRfcPackFiles as $rel) {
            if (str_starts_with(basename($rel), $rid . '-')) {
                $present = true;
                break;
            }
        }
        if (!$present) {
            $errors[] = 'alert_policy_rfc_file_missing_in_pack';
            $criticalFail = true;
        }
    }
}
if ($mode === 'draft' && !$verify['ok']) $notes[] = 'pack_verify_warn_in_draft';

$zipSha = rn_file_sha256($zipPath);
@file_put_contents($mdPath, rn_mask(rn_render_md($releaseJson, basename($zipPath), $zipSha)));
@file_put_contents($htmlPath, rn_mask(rn_render_html($releaseJson, basename($zipPath), $zipSha)));

if ($writeLast) {
    @copy($jsonPath, $dir . '/release_notes_last.json');
    @copy($mdPath, $dir . '/release_notes_last.md');
    @copy($htmlPath, $dir . '/release_notes_last.html');
    @copy($zipPath, $dir . '/release_pack_last.zip');
    @copy($manifestPath, $dir . '/release_pack_last_manifest.json');
}

$out = [
    'state_version' => 1,
    'overall_ok' => !$criticalFail,
    'env' => $env,
    'mode' => $mode,
    'rfc_id' => $rfcId,
    'run_id' => $resolvedRunId,
    'artifacts' => [
        'json' => rn_mask($jsonPath),
        'md' => rn_mask($mdPath),
        'html' => rn_mask($htmlPath),
        'zip' => rn_mask($zipPath),
        'manifest' => rn_mask($manifestPath),
    ],
    'pack_verify' => $verify,
    'errors_masked' => array_values(array_unique(array_map(static fn(string $v): string => rn_mask($v), $errors))),
    'notes' => array_values(array_unique(array_map(static fn(string $v): string => rn_mask($v), $notes))),
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($criticalFail ? 1 : 0);

