<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../rfc/_lib/rfc_lib.php';
require_once __DIR__ . '/../ops/_lib/alert_policy_lib.php';
require_once __DIR__ . '/../../_shared/bootstrap.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$manifestPath = $root . '/tools/qa/stage_pipeline_manifest_v1.json';
$phpBin = (string)(PHP_BINARY ?: 'php');

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$writeLast = false;
$runId = '';
$suggestOnFail = true;
$rfcId = '';
foreach (array_slice($args, 1) as $arg) {
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if ($arg === '--no-suggest-on-fail') {
        $suggestOnFail = false;
        continue;
    }
    if (str_starts_with((string)$arg, '--env=')) {
        $env = strtolower(trim((string)substr((string)$arg, 6)));
        continue;
    }
    if (str_starts_with((string)$arg, '--suggest-on-fail=')) {
        $raw = strtolower(trim((string)substr((string)$arg, 18)));
        $suggestOnFail = !in_array($raw, ['0', 'false', 'no', 'off'], true);
        continue;
    }
    if (str_starts_with((string)$arg, '--run-id=')) {
        $runId = trim((string)substr((string)$arg, 9));
    }
    if (str_starts_with((string)$arg, '--rfc=')) {
        $rfcId = strtoupper(trim((string)substr((string)$arg, 6)));
    }
}
if (!in_array($env, ['staging', 'production'], true)) {
    fwrite(STDERR, "Invalid env. Allowed: staging|production\n");
    exit(2);
}
if ($runId === '') {
    $runId = 'pipeline-' . $env . '-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 10);
}

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Manifest missing: " . ts_mask($manifestPath) . PHP_EOL);
    exit(1);
}
$raw = (string)@file_get_contents($manifestPath);
try {
    $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, "Manifest invalid JSON: " . tools_mask_sensitive($e->getMessage()) . PHP_EOL);
    exit(1);
}
if (!is_array($manifest) || !isset($manifest['stages']) || !is_array($manifest['stages'])) {
    fwrite(STDERR, "Manifest schema invalid\n");
    exit(1);
}

$stages = array_keys($manifest['stages']);
sort($stages);
$overallOk = true;
$stagesResult = [];
$stopReason = '';
$rfcPrecheck = [
    'attempted' => false,
    'ok' => true,
];
$policyPrecheck = [
    'attempted' => false,
    'ok' => false,
];
$alertPolicyPrecheck = [
    'attempted' => false,
    'ok' => false,
];
if ($env === 'production') {
    $rfcGateCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/rfc/rfc_check_required.php')
        . ' --type=PRODUCTION_DEPLOY --env=production --rfc=' . escapeshellarg($rfcId) . ' --strict';
    $rfcGateOut = [];
    $rfcGateCode = 1;
    $rfcGateT0 = microtime(true);
    @exec($rfcGateCmd . ' 2>&1', $rfcGateOut, $rfcGateCode);
    $rfcGateMs = (int)round((microtime(true) - $rfcGateT0) * 1000);
    $rfcValidateCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/rfc/rfc_validate.php')
        . ' --rfc=' . escapeshellarg($rfcId) . ' --strict --write-last';
    $rfcValidateOut = [];
    $rfcValidateCode = 1;
    $rfcValidateT0 = microtime(true);
    @exec($rfcValidateCmd . ' 2>&1', $rfcValidateOut, $rfcValidateCode);
    $rfcValidateMs = (int)round((microtime(true) - $rfcValidateT0) * 1000);
    $rfcPrecheck = [
        'attempted' => true,
        'ok' => ((int)$rfcGateCode === 0) && ((int)$rfcValidateCode === 0),
        'rfc_id' => $rfcId,
        'gate_duration_ms' => $rfcGateMs,
        'gate_output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($rfcGateOut, -2)))),
        'validate_duration_ms' => $rfcValidateMs,
        'validate_output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($rfcValidateOut, -2)))),
    ];
    if (!$rfcPrecheck['ok']) {
        $overallOk = false;
        $stopReason = 'rfc_gate_failed_production_deploy';
    } elseif (function_exists('auth_pdo') && function_exists('audit_event')) {
        rfc_append_audit([
            'ts' => date(DateTimeInterface::ATOM),
            'actor_username' => 'system',
            'action' => 'RFC_USED_FOR_CHANGE',
            'rfc_id' => $rfcId,
            'request_id' => $runId,
            'meta_masked' => ts_mask(tools_mask_sensitive('change_type=PRODUCTION_DEPLOY;script=tools/qa/run_pipeline.php')),
        ]);
        $pdo = auth_pdo();
        if ($pdo instanceof PDO) {
            audit_event($pdo, 'RFC_USED_FOR_CHANGE', 'OPS', 'production_deploy', $rfcId, 'RFC used for production deploy pipeline', [
                'rfc_id' => $rfcId,
                'change_type' => 'PRODUCTION_DEPLOY',
                'script_name' => 'tools/qa/run_pipeline.php',
                'request_id' => $runId,
            ]);
        }
    }
}

$policyCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/validate_ops_thresholds.php')
    . ' --path=' . escapeshellarg($root . '/storage/state/ops_thresholds_current.yaml')
    . ' --strict --write-last';
$policyOut = [];
$policyCode = 1;
$policyT0 = microtime(true);
@exec($policyCmd . ' 2>&1', $policyOut, $policyCode);
$policyMs = (int)round((microtime(true) - $policyT0) * 1000);
$policyPrecheck = [
    'attempted' => true,
    'ok' => ((int)$policyCode === 0),
    'duration_ms' => $policyMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($policyOut, -2)))),
];
if ((int)$policyCode !== 0) {
    $baselineCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/validate_ops_thresholds.php')
        . ' --path=' . escapeshellarg($root . '/docs/governance/OPS_THRESHOLDS.yaml')
        . ' --strict --write-last';
    $baselineOut = [];
    $baselineCode = 1;
    $baselineT0 = microtime(true);
    @exec($baselineCmd . ' 2>&1', $baselineOut, $baselineCode);
    $baselineMs = (int)round((microtime(true) - $baselineT0) * 1000);
    $policyPrecheck['baseline_validation'] = [
        'attempted' => true,
        'ok' => ((int)$baselineCode === 0),
        'duration_ms' => $baselineMs,
        'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($baselineOut, -2)))),
    ];
    $overallOk = false;
    $stopReason = 'ops_thresholds_policy_invalid_or_missing';
}

if ($overallOk) {
    if ($env === 'production') {
        $apCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/validate_alerting_policy.php')
            . ' --path=' . escapeshellarg($root . '/storage/state/alerting_policy_current.yaml')
            . ' --strict --write-last';
        $apOut = [];
        $apCode = 1;
        $apT0 = microtime(true);
        @exec($apCmd . ' 2>&1', $apOut, $apCode);
        $apMs = (int)round((microtime(true) - $apT0) * 1000);
        $alertPolicyPrecheck = [
            'attempted' => true,
            'ok' => ((int)$apCode === 0),
            'mode' => 'active_strict',
            'duration_ms' => $apMs,
            'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($apOut, -2)))),
        ];
        if ((int)$apCode !== 0) {
            $overallOk = false;
            $stopReason = 'alert_policy_invalid_or_missing';
        }
    } else {
        $docsCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/validate_alerting_policy.php')
            . ' --path=' . escapeshellarg($root . '/docs/governance/ALERTING_POLICY.yaml')
            . ' --strict --write-last';
        $docsOut = [];
        $docsCode = 1;
        $docsT0 = microtime(true);
        @exec($docsCmd . ' 2>&1', $docsOut, $docsCode);
        $docsMs = (int)round((microtime(true) - $docsT0) * 1000);
        $alertPolicyPrecheck = [
            'attempted' => true,
            'ok' => ((int)$docsCode === 0),
            'mode' => 'docs_strict',
            'duration_ms' => $docsMs,
            'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($docsOut, -2)))),
        ];
        if ((int)$docsCode !== 0) {
            $overallOk = false;
            $stopReason = 'alert_policy_docs_invalid';
        } else {
            $activeCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/validate_alerting_policy.php')
                . ' --path=' . escapeshellarg($root . '/storage/state/alerting_policy_current.yaml')
                . ' --strict --write-last';
            $activeOut = [];
            $activeCode = 1;
            @exec($activeCmd . ' 2>&1', $activeOut, $activeCode);
            $alertPolicyPrecheck['active_optional'] = [
                'attempted' => true,
                'ok' => ((int)$activeCode === 0),
                'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($activeOut, -2)))),
            ];
        }
    }
}

foreach ($stages as $stage) {
    if (!$overallOk && in_array($stopReason, ['ops_thresholds_policy_invalid_or_missing', 'rfc_gate_failed_production_deploy', 'alert_policy_invalid_or_missing', 'alert_policy_docs_invalid'], true)) {
        break;
    }
    $stageConfig = $manifest['stages'][$stage] ?? [];
    if (!is_array($stageConfig)) {
        continue;
    }

    $stageOk = true;
    $stageSteps = [];
    $stageArtifacts = [];

    // Mandatory first step: manifest lock.
    $lockRunId = $runId . '-s' . $stage;
    $lockCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/validate_stage_assets.php')
        . ' --stage=' . escapeshellarg((string)$stage)
        . ' --env=' . escapeshellarg($env)
        . ' --run-id=' . escapeshellarg($lockRunId)
        . ($writeLast ? ' --write-last' : '');

    $lockOut = [];
    $lockCode = 1;
    $t0 = microtime(true);
    @exec($lockCmd . ' 2>&1', $lockOut, $lockCode);
    $lockMs = (int)round((microtime(true) - $t0) * 1000);
    $lockOk = ((int)$lockCode === 0);
    $stageSteps[] = [
        'name' => 'manifest_lock',
        'ok' => $lockOk,
        'duration_ms' => $lockMs,
        'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($lockOut, -2)))),
    ];
    if (!$lockOk) {
        $stageOk = false;
        $overallOk = false;
        $stopReason = 'manifest_lock_failed_stage_' . $stage;
        if ($suggestOnFail) {
            $suggestCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/manifest_lock_suggest.php')
                . ' --stage=' . escapeshellarg((string)$stage)
                . ' --env=' . escapeshellarg($env)
                . ' --run-id=' . escapeshellarg($lockRunId)
                . ($writeLast ? ' --write-last' : '');
            $suggestOut = [];
            $suggestCode = 1;
            $suggestT0 = microtime(true);
            @exec($suggestCmd . ' 2>&1', $suggestOut, $suggestCode);
            $suggestMs = (int)round((microtime(true) - $suggestT0) * 1000);
            $suggestArtifacts = [
                'json' => ts_mask($root . '/storage/logs/pipeline/manifest_suggest_stage' . $stage . '_' . $lockRunId . '.json'),
                'md' => ts_mask($root . '/storage/logs/pipeline/manifest_suggest_stage' . $stage . '_' . $lockRunId . '.md'),
            ];
            if ($writeLast) {
                $suggestArtifacts['json_last'] = ts_mask($root . '/storage/logs/pipeline/manifest_suggest_stage' . $stage . '_last.json');
                $suggestArtifacts['md_last'] = ts_mask($root . '/storage/logs/pipeline/manifest_suggest_stage' . $stage . '_last.md');
            }
            $stageSteps[] = [
                'name' => 'manifest_lock_suggest',
                'ok' => ((int)$suggestCode === 0),
                'duration_ms' => $suggestMs,
                'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($suggestOut, -2)))),
                'artifacts' => $suggestArtifacts,
            ];
            $stageArtifacts['manifest_lock_suggest'] = $suggestArtifacts;
        }
    }

    // Execute stage steps only when manifest lock passes.
    if ($stageOk) {
        $steps = $stageConfig['steps'] ?? [];
        if (is_array($steps)) {
            foreach ($steps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $name = (string)($step['name'] ?? 'unnamed_step');
                $cmd = (string)($step['cmd'] ?? '');
                $runAwareScripts = [
                    'tools/ops/update_alerting_policy.php',
                    'tools/ops/update_ops_thresholds.php',
                    'tools/migrate/run.php',
                    'tools/backup/restore.php',
                    'tools/release/generate_release_notes.php',
                ];
                foreach ($runAwareScripts as $script) {
                    if (str_contains($cmd, $script) && !str_contains($cmd, '--run-id=')) {
                        $cmd .= ' --run-id=' . escapeshellarg($runId);
                    }
                }
                if (trim($cmd) === '') {
                    $stageSteps[] = [
                        'name' => $name,
                        'ok' => false,
                        'duration_ms' => 0,
                        'output_tail_masked' => 'empty_command',
                    ];
                    $stageOk = false;
                    $overallOk = false;
                    $stopReason = 'empty_command_stage_' . $stage . '_' . $name;
                    break;
                }
                $out = [];
                $code = 1;
                $stepT0 = microtime(true);
                @exec($cmd . ' 2>&1', $out, $code);
                $ms = (int)round((microtime(true) - $stepT0) * 1000);
                $ok = ((int)$code === 0);
                $stageSteps[] = [
                    'name' => $name,
                    'ok' => $ok,
                    'duration_ms' => $ms,
                    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($out, -2)))),
                ];
                if (!$ok) {
                    $stageOk = false;
                    $overallOk = false;
                    $stopReason = 'stage_step_failed_stage_' . $stage . '_' . $name;
                    break;
                }
            }
        }
    }

    $stagesResult[] = [
        'stage' => (string)$stage,
        'ok' => $stageOk,
        'steps' => $stageSteps,
        'artifacts' => $stageArtifacts,
    ];

    if (!$stageOk) {
        break;
    }
}

$payload = [
    'state_version' => 1,
    'run_id' => $runId,
    'rfc_id' => $rfcId,
    'env' => $env,
    'generated_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => $overallOk,
    'stop_reason' => $stopReason,
    'rfc_precheck' => $rfcPrecheck,
    'ops_thresholds_precheck' => $policyPrecheck,
    'alert_policy_precheck' => $alertPolicyPrecheck,
    'stages' => $stagesResult,
];
if (!$overallOk && $stopReason === 'ops_thresholds_policy_invalid_or_missing') {
    $payload['warnings'][] = 'apply_ops_policy_required';
}
if (!$overallOk && in_array($stopReason, ['alert_policy_invalid_or_missing', 'alert_policy_docs_invalid'], true)) {
    $payload['warnings'][] = 'apply_alert_policy_required';
}
if (!$overallOk && $stopReason === 'rfc_gate_failed_production_deploy') {
    $payload['warnings'][] = 'approved_rfc_required_for_production_deploy';
}

$backlogCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/generate_fix_backlog.php')
    . ' --env=' . escapeshellarg($env)
    . ' --from-stage=07 --to-stage=16 --input-mode=last'
    . ' --run-id=' . escapeshellarg($runId)
    . ($writeLast ? ' --write-last' : '');
$backlogOut = [];
$backlogCode = 1;
$backlogT0 = microtime(true);
@exec($backlogCmd . ' 2>&1', $backlogOut, $backlogCode);
$backlogMs = (int)round((microtime(true) - $backlogT0) * 1000);
$payload['fix_backlog_generation'] = [
    'attempted' => true,
    'ok' => ((int)$backlogCode === 0),
    'duration_ms' => $backlogMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($backlogOut, -2)))),
];
if ((int)$backlogCode !== 0) {
    $payload['warnings'][] = 'fix_backlog_generation_failed';
}

$snapshotCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/snapshot_fix_backlog.php')
    . ' --env=' . escapeshellarg($env)
    . ' --write-last';
$snapshotOut = [];
$snapshotCode = 1;
$snapshotT0 = microtime(true);
@exec($snapshotCmd . ' 2>&1', $snapshotOut, $snapshotCode);
$snapshotMs = (int)round((microtime(true) - $snapshotT0) * 1000);
$payload['fix_backlog_snapshot'] = [
    'attempted' => true,
    'ok' => ((int)$snapshotCode === 0),
    'duration_ms' => $snapshotMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($snapshotOut, -2)))),
];
if ((int)$snapshotCode !== 0) {
    $payload['warnings'][] = 'fix_backlog_snapshot_failed';
}

$trendCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/generate_fix_backlog_trend.php')
    . ' --env=' . escapeshellarg($env)
    . ' --window=both --write-last';
$trendOut = [];
$trendCode = 1;
$trendT0 = microtime(true);
@exec($trendCmd . ' 2>&1', $trendOut, $trendCode);
$trendMs = (int)round((microtime(true) - $trendT0) * 1000);
$payload['fix_backlog_trend'] = [
    'attempted' => true,
    'ok' => ((int)$trendCode === 0),
    'duration_ms' => $trendMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($trendOut, -2)))),
];
if ((int)$trendCode !== 0) {
    $payload['warnings'][] = 'fix_backlog_trend_failed';
}

$opsScoreSnapshotCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/snapshot_ops_score.php')
    . ' --env=' . escapeshellarg($env)
    . ' --write-last';
$opsScoreSnapshotOut = [];
$opsScoreSnapshotCode = 1;
$opsScoreSnapshotT0 = microtime(true);
@exec($opsScoreSnapshotCmd . ' 2>&1', $opsScoreSnapshotOut, $opsScoreSnapshotCode);
$opsScoreSnapshotMs = (int)round((microtime(true) - $opsScoreSnapshotT0) * 1000);
$payload['ops_score_snapshot'] = [
    'attempted' => true,
    'ok' => ((int)$opsScoreSnapshotCode === 0),
    'duration_ms' => $opsScoreSnapshotMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($opsScoreSnapshotOut, -2)))),
];
if ((int)$opsScoreSnapshotCode !== 0) {
    $payload['warnings'][] = 'ops_score_snapshot_failed';
}

$opsScoreTrendCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/generate_ops_score_trend.php')
    . ' --env=' . escapeshellarg($env)
    . ' --window=both --write-last';
$opsScoreTrendOut = [];
$opsScoreTrendCode = 1;
$opsScoreTrendT0 = microtime(true);
@exec($opsScoreTrendCmd . ' 2>&1', $opsScoreTrendOut, $opsScoreTrendCode);
$opsScoreTrendMs = (int)round((microtime(true) - $opsScoreTrendT0) * 1000);
$payload['ops_score_trend'] = [
    'attempted' => true,
    'ok' => ((int)$opsScoreTrendCode === 0),
    'duration_ms' => $opsScoreTrendMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($opsScoreTrendOut, -2)))),
];
if ((int)$opsScoreTrendCode !== 0) {
    $payload['warnings'][] = 'ops_score_trend_failed';
}

$rfcIndexCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/rfc/rfc_index_scan.php') . ' --write-last';
$rfcIndexOut = [];
$rfcIndexCode = 1;
$rfcIndexT0 = microtime(true);
@exec($rfcIndexCmd . ' 2>&1', $rfcIndexOut, $rfcIndexCode);
$rfcIndexMs = (int)round((microtime(true) - $rfcIndexT0) * 1000);
$payload['rfc_index_scan'] = [
    'attempted' => true,
    'ok' => ((int)$rfcIndexCode === 0),
    'duration_ms' => $rfcIndexMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($rfcIndexOut, -2)))),
];
if ((int)$rfcIndexCode !== 0) {
    $payload['warnings'][] = 'rfc_index_scan_failed';
}

$collectCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/rfc/collect_rfc_usage.php')
    . ' --env=' . escapeshellarg($env)
    . ' --run-id=' . escapeshellarg($runId)
    . ' --write-last'
    . ($env === 'production' ? ' --strict' : '');
$collectOut = [];
$collectCode = 1;
$collectT0 = microtime(true);
@exec($collectCmd . ' 2>&1', $collectOut, $collectCode);
$collectMs = (int)round((microtime(true) - $collectT0) * 1000);
$payload['rfc_usage_collect'] = [
    'attempted' => true,
    'ok' => ((int)$collectCode === 0),
    'duration_ms' => $collectMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($collectOut, -2)))),
];
if ((int)$collectCode !== 0) {
    $payload['warnings'][] = 'rfc_usage_collect_failed';
    if ($env === 'production') {
        $overallOk = false;
        if ($stopReason === '') {
            $stopReason = 'rfc_usage_missing_or_not_approved';
        }
    }
}

$execSummaryCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php')
    . ' --env=' . escapeshellarg($env)
    . ' --run-id=' . escapeshellarg($runId)
    . ' --write-last';
$execSummaryOut = [];
$execSummaryCode = 1;
$execSummaryT0 = microtime(true);
@exec($execSummaryCmd . ' 2>&1', $execSummaryOut, $execSummaryCode);
$execSummaryMs = (int)round((microtime(true) - $execSummaryT0) * 1000);
$payload['executive_summary_generation'] = [
    'attempted' => true,
    'ok' => ((int)$execSummaryCode === 0),
    'duration_ms' => $execSummaryMs,
    'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($execSummaryOut, -2)))),
];
if ((int)$execSummaryCode !== 0) {
    $payload['warnings'][] = 'executive_summary_generation_failed';
}
if ((int)$execSummaryCode === 0) {
    $alertPolicyRuntime = load_alert_policy($env);
    $fp = (string)($alertPolicyRuntime['policy_fingerprint'] ?? '');
    $src = (string)($alertPolicyRuntime['source'] ?? '');
    $summaryJsonPath = $root . '/storage/logs/pipeline/executive_ops_summary_last.json';
    $summaryHtmlPath = $root . '/storage/logs/pipeline/executive_ops_summary_last.html';
    $summaryJson = ts_read_json($summaryJsonPath);
    if ($summaryJson !== []) {
        $summaryJson['alert_policy'] = [
            'fingerprint' => $fp,
            'source' => $src,
            'policy_id' => (string)($alertPolicyRuntime['policy_id'] ?? ''),
        ];
        ts_write_json($summaryJsonPath, $summaryJson);
    }
    if (is_file($summaryHtmlPath)) {
        $htmlRaw = (string)@file_get_contents($summaryHtmlPath);
        $inject = '<p><b>Alert Policy Fingerprint:</b> ' . htmlspecialchars(ts_mask($fp), ENT_QUOTES, 'UTF-8')
            . ' <span style="opacity:.75;">(' . htmlspecialchars(ts_mask($src), ENT_QUOTES, 'UTF-8') . ')</span></p>';
        if (str_contains($htmlRaw, '</body>')) {
            $htmlRaw = str_replace('</body>', $inject . '</body>', $htmlRaw);
            @file_put_contents($summaryHtmlPath, $htmlRaw);
        }
    }
}

$pipelineLogDir = $root . '/storage/logs/pipeline';
if (!is_dir($pipelineLogDir)) {
    @mkdir($pipelineLogDir, 0775, true);
}
$jsonPath = $pipelineLogDir . '/pipeline_run_' . $runId . '.json';
ts_write_json($jsonPath, $payload);
if ($writeLast) {
    ts_write_json($pipelineLogDir . '/pipeline_run_last.json', $payload);
    ts_write_json($pipelineLogDir . '/pipeline_last.json', $payload);
}

$payload['release_notes_generation'] = ['attempted' => false, 'ok' => false];
if ($env === 'production' || ($env === 'staging' && $rfcId !== '')) {
    $releaseMode = $env === 'production' ? 'final' : 'draft';
    $releaseCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/release/generate_release_notes.php')
        . ' --env=' . escapeshellarg($env)
        . ' --rfc=' . escapeshellarg($rfcId)
        . ' --pipeline=run-id --run-id=' . escapeshellarg($runId)
        . ' --mode=' . escapeshellarg($releaseMode)
        . ' --write-last';
    $releaseOut = [];
    $releaseCode = 1;
    $releaseT0 = microtime(true);
    @exec($releaseCmd . ' 2>&1', $releaseOut, $releaseCode);
    $releaseMs = (int)round((microtime(true) - $releaseT0) * 1000);
    $payload['release_notes_generation'] = [
        'attempted' => true,
        'ok' => ((int)$releaseCode === 0),
        'duration_ms' => $releaseMs,
        'output_tail_masked' => ts_mask(tools_mask_sensitive(implode(' | ', array_slice($releaseOut, -2)))),
        'mode' => $releaseMode,
    ];
    if ((int)$releaseCode !== 0) {
        $payload['warnings'][] = 'release_notes_generation_failed';
        if ($env === 'production') {
            $overallOk = false;
            if ($stopReason === '') $stopReason = 'release_notes_generation_failed';
            $payload['overall_ok'] = false;
            $payload['stop_reason'] = $stopReason;
        }
    }
} elseif ($env === 'staging' && $rfcId === '') {
    $payload['warnings'][] = 'release_notes_skipped_missing_rfc';
}
$payload['overall_ok'] = $overallOk;
$payload['stop_reason'] = $stopReason;
ts_write_json($jsonPath, $payload);
if ($writeLast) {
    ts_write_json($pipelineLogDir . '/pipeline_run_last.json', $payload);
    ts_write_json($pipelineLogDir . '/pipeline_last.json', $payload);
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
