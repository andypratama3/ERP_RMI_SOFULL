<?php
declare(strict_types=1);

require_once __DIR__ . '/executive_summary_lib.php';
require_once __DIR__ . '/architecture_audit_panel_lib.php';

if (!function_exists('exsu_strict_flag')) {
    function exsu_strict_flag(): bool
    {
        $keys = [
            'release.require_executive_summary_strict_for_prod',
            'governance.require_executive_summary_strict_for_prod',
            'gates.require_executive_summary_strict_for_prod',
        ];
        foreach ($keys as $k) {
            $v = get_threshold($k, null);
            $b = exs_bool($v, null);
            if ($b !== null) return $b;
        }
        return false;
    }
}

if (!function_exists('exsu_inputs')) {
    function exsu_inputs(): array
    {
        $root = exs_root();
        $pipe = exs_pipeline_dir();
        return [
            'pipeline_last' => ['path' => $pipe . '/pipeline_last.json', 'critical' => true],
            'ops_score_trend' => ['path' => $pipe . '/ops_score_trend_last.json', 'critical' => true],
            'fix_backlog_last' => ['path' => $pipe . '/fix_backlog_last.json', 'critical' => true],
            'fix_backlog_trend' => ['path' => $pipe . '/fix_backlog_trend_last.json', 'critical' => false],
            'release_verify_all' => ['path' => $root . '/storage/state/release_verify_all_last.json', 'critical' => true],
            'ops_banner' => ['path' => $root . '/storage/state/ops_banner.json', 'critical' => true],
            'alerts_last' => ['path' => $root . '/storage/state/alerts_last.json', 'critical' => false],
            'alerts_workflow' => ['path' => $root . '/storage/state/alerts_workflow_state.json', 'critical' => false],
            'change_control_health' => ['path' => $pipe . '/change_control_health_last.json', 'critical' => true],
            'rfc_usage_last' => ['path' => $pipe . '/rfc_usage_last.json', 'critical' => false],
            'rfc_quality_lint' => ['path' => $pipe . '/rfc_quality_lint_last.json', 'critical' => false],
            'rfc_index_last' => ['path' => $root . '/storage/state/rfc_index_last.json', 'critical' => false],
            'readiness' => ['path' => $root . '/storage/logs/readiness_report_last.json', 'critical' => false],
            'smoke_http' => ['path' => $root . '/storage/logs/smoke_http_last.json', 'critical' => false],
            'contract_check' => ['path' => $root . '/storage/logs/contract_check_last.json', 'critical' => false],
            'smoke_core_flows' => ['path' => $root . '/storage/logs/smoke_core_flows_last.json', 'critical' => false],
            'backup_last' => ['path' => $root . '/storage/state/backup_last.json', 'critical' => false],
        ];
    }
}

if (!function_exists('exsu_reason_ordered')) {
    function exsu_reason_ordered(array $flags): array
    {
        $ordered = [
            'CONTRACT_FAIL',
            'HTTP_SMOKE_FAIL',
            'CORE_FLOWS_FAIL',
            'READINESS_NOT_100',
            'BACKLOG_P0_GT_0',
            'RELEASE_VERIFY_FAIL',
            'CHANGE_CONTROL_HEALTH_CRITICAL',
            'ALERTS_CRITICAL',
        ];
        $out = [];
        foreach ($ordered as $r) {
            if (!empty($flags[$r])) $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('exsu_collect_data_missing')) {
    function exsu_collect_data_missing(array $sources): array
    {
        $rows = [];
        foreach ($sources as $key => $src) {
            if (!(bool)($src['ok'] ?? false)) {
                $err = strtoupper((string)($src['error'] ?? 'missing'));
                $reason = match ($err) {
                    'INVALID_JSON' => 'INVALID_JSON',
                    'EMPTY' => 'INVALID_JSON',
                    default => 'MISSING',
                };
                $rows[] = ['source' => $key, 'reason' => $reason];
            }
        }
        return $rows;
    }
}

if (!function_exists('exsu_build')) {
    function exsu_build(string $env, string $runIdArg = ''): array
    {
        $inputs = exsu_inputs();
        $sources = [];
        foreach ($inputs as $key => $cfg) {
            $sources[$key] = exs_read_source($key, (string)$cfg['path']);
        }

        $pipeline = (array)($sources['pipeline_last']['data'] ?? []);
        $runId = trim($runIdArg) !== '' ? trim($runIdArg) : trim((string)($pipeline['run_id'] ?? ''));
        if ($runId === '') {
            $runId = 'LOCAL_' . date('YmdHis');
            exs_append_assumption($env, $runId, 'run_id generated locally due to missing pipeline_last.run_id', 'LOW', 'php tools/qa/run_pipeline.php --env=' . $env);
        }

        $dataMissing = exsu_collect_data_missing($sources);
        $notes = [];
        foreach ($dataMissing as $dm) {
            $notes[] = exs_mask('DATA_MISSING:' . (string)$dm['source'] . ':' . (string)$dm['reason']);
        }
        foreach ($sources as $k => $src) {
            if ((bool)$src['ok']) continue;
            exs_append_assumption($env, $runId, 'source fallback for ' . $k . ' (' . (string)$src['error'] . ')', 'MED', 'regenerate state for ' . $k);
        }

        $ops = (array)($sources['ops_score_trend']['data'] ?? []);
        $opsCurrent = (array)($ops['current'] ?? []);
        if ($opsCurrent === []) {
            $opsCurrent = (array)($ops['windows']['7']['current'] ?? []);
            if ($opsCurrent !== []) {
                exs_append_assumption($env, $runId, 'ops_score fallback to windows.7.current', 'LOW', 'php tools/ops/generate_ops_score_trend.php --env=' . $env . ' --window=both --write-last');
            }
        }
        $ops7 = (array)($ops['windows']['7'] ?? []);
        $ops30 = (array)($ops['windows']['30'] ?? []);
        $opsScorePanel = [
            'current_score' => isset($opsCurrent['ops_score']) ? (int)$opsCurrent['ops_score'] : null,
            'status' => (string)($opsCurrent['status'] ?? 'DATA_MISSING'),
            'delta_7d' => isset($ops7['delta']['ops_score']) ? (int)$ops7['delta']['ops_score'] : null,
            'delta_30d' => isset($ops30['delta']['ops_score']) ? (int)$ops30['delta']['ops_score'] : null,
            'sparkline_7d_svg' => (string)($ops7['sparkline_svg_path'] ?? ''),
            'sparkline_30d_svg' => (string)($ops30['sparkline_svg_path'] ?? ''),
        ];
        if ($opsScorePanel['sparkline_7d_svg'] === '') {
            $opsScorePanel['sparkline_7d_svg'] = make_sparkline((array)($ops7['series']['ops_score'] ?? []), 'svg');
        }
        if ($opsScorePanel['sparkline_30d_svg'] === '') {
            $opsScorePanel['sparkline_30d_svg'] = make_sparkline((array)($ops30['series']['ops_score'] ?? []), 'svg');
        }

        $fixLast = (array)($sources['fix_backlog_last']['data'] ?? []);
        $fixTrend = (array)($sources['fix_backlog_trend']['data'] ?? []);
        $fixSummary = (array)($fixLast['summary'] ?? []);
        $fix7 = (array)($fixTrend['windows']['7']['trend'] ?? []);
        $fixPanel = [
            'p0' => isset($fixSummary['p0']) ? (int)$fixSummary['p0'] : null,
            'p1' => isset($fixSummary['p1']) ? (int)$fixSummary['p1'] : null,
            'p2' => isset($fixSummary['p2']) ? (int)$fixSummary['p2'] : null,
            'p0_delta_7d' => isset($fix7['p0_delta']) ? (int)$fix7['p0_delta'] : (isset($fix7['p0']['delta']) ? (int)$fix7['p0']['delta'] : null),
            'flags' => [],
            'sparkline_p0_7d' => make_sparkline((array)($fixTrend['windows']['7']['series']['p0'] ?? []), 'svg'),
        ];
        if (($fixPanel['p0'] ?? 0) > 0) $fixPanel['flags'][] = 'DEPLOY_BLOCKER_PRESENT';

        $rv = (array)($sources['release_verify_all']['data'] ?? []);
        $rvSummary = (array)($rv['summary'] ?? []);
        $rvFail = isset($rvSummary['bundle_fail']) ? (int)$rvSummary['bundle_fail'] : null;
        $rvWarn = isset($rvSummary['bundle_warn']) ? (int)$rvSummary['bundle_warn'] : null;
        $rvStatus = 'DATA_MISSING';
        if ((bool)($sources['release_verify_all']['ok'] ?? false)) {
            if ((bool)($rv['overall_ok'] ?? false) && (($rvFail ?? 0) === 0) && (($rvWarn ?? 0) === 0)) $rvStatus = 'OK';
            elseif (($rvFail ?? 0) > 0 || (bool)($rv['overall_ok'] ?? true) === false) $rvStatus = 'FAIL';
            else $rvStatus = 'WARN';
        }
        $releasePanel = [
            'status' => $rvStatus,
            'bundle_fail' => $rvFail,
            'bundle_warn' => $rvWarn,
            'last_run_at' => (string)($rv['generated_at'] ?? null),
            'reason' => $rvStatus === 'FAIL' ? 'Evidence integrity risk' : '',
        ];

        $banner = (array)($sources['ops_banner']['data'] ?? []);
        $alertsLast = (array)($sources['alerts_last']['data'] ?? []);
        $topRule = (string)($banner['top_rule'] ?? '');
        $wf = (array)($alertsLast['rules'][$topRule] ?? []);
        $alertsPanel = [
            'level' => (string)($banner['level'] ?? 'DATA_MISSING'),
            'owner_primary' => (string)($banner['primary_owner'] ?? ($wf['owners']['primary'] ?? null)),
            'sla_due_at' => (string)($banner['sla_due_at'] ?? ($wf['sla']['resolve_due_at'] ?? null)),
            'workflow_status' => (string)($banner['workflow_status'] ?? ($wf['status'] ?? null)),
            'sla_breached' => isset($banner['badge_sla_breach']) ? (bool)$banner['badge_sla_breach'] : (isset($wf['sla']['breached']) ? (bool)$wf['sla']['breached'] : null),
        ];

        $cch = (array)($sources['change_control_health']['data'] ?? []);
        $cchSum = (array)($cch['summary'] ?? []);
        $cchLint = (array)($cchSum['lint'] ?? []);
        $cchGov = (array)($cchSum['governance'] ?? []);
        $cchUse = (array)($cchSum['usage'] ?? []);
        $ccFlags = [];
        if ((bool)($cchUse['has_alert_policy_change'] ?? false)) $ccFlags[] = 'ALERT_POLICY_CHANGE';
        if ((bool)($cchUse['has_ops_threshold_change'] ?? false)) $ccFlags[] = 'OPS_THRESHOLD_CHANGE';
        $changeControlPanel = [
            'level' => (string)($cch['overall_level'] ?? 'DATA_MISSING'),
            'lint_fail' => isset($cchLint['fail']) ? (int)$cchLint['fail'] : null,
            'lint_warn' => isset($cchLint['warn']) ? (int)$cchLint['warn'] : null,
            'approved_but_fail' => isset($cchGov['approved_but_lint_fail']) ? (int)$cchGov['approved_but_lint_fail'] : null,
            'prod_missing_schedule' => isset($cchGov['prod_missing_schedule']) ? (int)$cchGov['prod_missing_schedule'] : null,
            'primary_rfc_id' => (string)($cchUse['primary_rfc_id'] ?? null),
            'primary_status' => (string)($cchUse['primary_status'] ?? null),
            'approvals_missing' => isset($cchUse['primary_approvals_missing']) ? (int)$cchUse['primary_approvals_missing'] : null,
            'flags' => $ccFlags,
        ];

        $metrics = [
            'contract_ok' => exs_bool(($sources['contract_check']['data']['ok'] ?? $sources['contract_check']['data']['overall_ok'] ?? $opsCurrent['metrics']['contract_ok'] ?? null), null),
            'smoke_http_fail' => isset($sources['smoke_http']['data']['fail']) ? (int)$sources['smoke_http']['data']['fail'] : (isset($sources['smoke_http']['data']['fail_count']) ? (int)$sources['smoke_http']['data']['fail_count'] : (isset($opsCurrent['metrics']['smoke_http_fail']) ? (int)$opsCurrent['metrics']['smoke_http_fail'] : null)),
            'core_flows_ok' => exs_bool(($sources['smoke_core_flows']['data']['ok'] ?? $sources['smoke_core_flows']['data']['overall_ok'] ?? $opsCurrent['metrics']['core_flows_ok'] ?? null), null),
            'readiness_score' => isset($sources['readiness']['data']['score']) ? (int)$sources['readiness']['data']['score'] : (isset($opsCurrent['metrics']['readiness_score']) ? (int)$opsCurrent['metrics']['readiness_score'] : null),
            'backlog_p0' => $fixPanel['p0'],
        ];

        $flags = [
            'CONTRACT_FAIL' => ($metrics['contract_ok'] === false),
            'HTTP_SMOKE_FAIL' => (($metrics['smoke_http_fail'] ?? 0) > 0),
            'CORE_FLOWS_FAIL' => ($metrics['core_flows_ok'] === false),
            'READINESS_NOT_100' => ($metrics['readiness_score'] !== null && $metrics['readiness_score'] !== 100),
            'BACKLOG_P0_GT_0' => (($metrics['backlog_p0'] ?? 0) > 0),
            'RELEASE_VERIFY_FAIL' => ($releasePanel['status'] === 'FAIL'),
            'CHANGE_CONTROL_HEALTH_CRITICAL' => (strtoupper((string)$changeControlPanel['level']) === 'CRITICAL'),
            'ALERTS_CRITICAL' => (strtoupper((string)$alertsPanel['level']) === 'CRITICAL'),
        ];
        $orderedReasons = exsu_reason_ordered($flags);

        $opsDecision = strtoupper((string)($opsCurrent['go_no_go'] ?? 'UNKNOWN'));
        if ($opsDecision === 'NO-GO' || $opsDecision === 'NOGO') $opsDecision = 'NO-GO';
        if (!in_array($opsDecision, ['GO', 'NO-GO', 'UNKNOWN'], true)) $opsDecision = 'UNKNOWN';

        $goNoGo = $opsDecision;
        if ($orderedReasons !== []) $goNoGo = 'NO-GO';
        elseif ($opsDecision === 'UNKNOWN' && $metrics['contract_ok'] !== null) {
            $goNoGo = (
                $metrics['contract_ok'] === true
                && ($metrics['smoke_http_fail'] ?? 0) === 0
                && $metrics['core_flows_ok'] === true
                && $metrics['readiness_score'] === 100
                && ($metrics['backlog_p0'] ?? 0) === 0
            ) ? 'GO' : 'NO-GO';
            if ($goNoGo === 'NO-GO' && $orderedReasons === []) $orderedReasons = ['DATA_MISSING'];
            exs_append_assumption($env, $runId, 'go/no-go inferred from partial inputs because ops score decision missing', 'MED', 'php tools/ops/generate_ops_score_trend.php --env=' . $env . ' --window=both --write-last');
        }

        $criticalReasons = ['CONTRACT_FAIL', 'HTTP_SMOKE_FAIL', 'CORE_FLOWS_FAIL', 'BACKLOG_P0_GT_0', 'RELEASE_VERIFY_FAIL'];
        $hasCriticalReason = false;
        foreach ($criticalReasons as $r) {
            if (in_array($r, $orderedReasons, true)) {
                $hasCriticalReason = true;
                break;
            }
        }

        $attentionIndicators = false;
        if ($goNoGo === 'GO' && (($metrics['readiness_score'] ?? 100) < 100)) $attentionIndicators = true;
        if (($releasePanel['status'] ?? 'OK') === 'WARN') $attentionIndicators = true;
        if (($changeControlPanel['lint_warn'] ?? 0) > 0) $attentionIndicators = true;

        $ages = [
            'ops_score_trend_age_s' => $sources['ops_score_trend']['age_s'],
            'fix_backlog_last_age_s' => $sources['fix_backlog_last']['age_s'],
            'release_verify_all_age_s' => $sources['release_verify_all']['age_s'],
            'alerts_last_age_s' => $sources['alerts_last']['age_s'],
            'change_control_health_age_s' => $sources['change_control_health']['age_s'],
            'readiness_age_s' => $sources['readiness']['age_s'],
        ];
        $stale = false;
        foreach (['ops_score_trend_age_s', 'fix_backlog_last_age_s', 'release_verify_all_age_s', 'change_control_health_age_s'] as $k) {
            $age = $ages[$k] ?? null;
            if ($age !== null && $age > 86400) {
                $stale = true;
                $dataMissing[] = ['source' => $k, 'reason' => 'STALE'];
            }
        }
        if ($stale) $attentionIndicators = true;

        $level = 'HEALTHY';
        if ($goNoGo === 'NO-GO' && $hasCriticalReason) $level = 'CRITICAL';
        elseif ($goNoGo === 'NO-GO' || $attentionIndicators || $dataMissing !== []) $level = 'ATTENTION';

        $commands = [];
        $cmdPool = [
            'php tools/rfc/rfc_index_scan.php --write-last',
            'php tools/rfc/rfc_quality_lint.php --env=staging --mode=quick --write-last',
            'php tools/rfc/collect_rfc_usage.php --env=staging --run-id=last --write-last',
            'php tools/release/scan_release_exports.php --write-last',
            'php tools/release/verify_all_release_packs.php --env=staging --mode=quick --write-last',
            'php tools/ops/alert_engine.php --env=staging --write-last',
        ];
        if ($dataMissing !== [] || in_array('CHANGE_CONTROL_HEALTH_CRITICAL', $orderedReasons, true)) $commands[] = $cmdPool[0];
        if (($changeControlPanel['lint_fail'] ?? 0) > 0 || ($changeControlPanel['lint_warn'] ?? 0) > 0) $commands[] = $cmdPool[1];
        if (($changeControlPanel['primary_rfc_id'] ?? '') === '' || in_array('CHANGE_CONTROL_HEALTH_CRITICAL', $orderedReasons, true)) $commands[] = $cmdPool[2];
        if (($releasePanel['status'] ?? 'OK') !== 'OK') {
            $commands[] = $cmdPool[3];
            $commands[] = $cmdPool[4];
        }
        if (($alertsPanel['level'] ?? 'HEALTHY') !== 'HEALTHY') $commands[] = $cmdPool[5];
        if ($commands === []) $commands = [$cmdPool[4]];
        $commands = array_slice(array_values(array_unique($commands)), 0, 6);

        $archPanel = aap_build($runId, $env);
        $ages['arch_audit_age_s'] = $archPanel['arch_audit_age_hours'] !== null
            ? (int)round((float)$archPanel['arch_audit_age_hours'] * 3600) : null;

        $payload = [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'env' => $env,
            'run_id' => $runId,
            'decision' => [
                'go_no_go' => $goNoGo,
                'level' => $level,
                'reasons' => $orderedReasons,
                'top_reason' => $orderedReasons[0] ?? '',
            ],
            'panels' => [
                'ops_score' => $opsScorePanel,
                'fix_backlog' => $fixPanel,
                'release_verify' => $releasePanel,
                'alerts' => $alertsPanel,
                'change_control' => $changeControlPanel,
                'architecture' => $archPanel,
                'freshness' => [
                    'per_source_age_s' => $ages,
                    'stale' => $stale,
                ],
            ],
            'links' => [
                'ops_score_trend_html' => exs_mask('storage/logs/pipeline/ops_score_trend_last.html'),
                'fix_backlog_trend_html' => exs_mask('storage/logs/pipeline/fix_backlog_trend_last.html'),
                'release_artifacts_ui' => '/tools/release/release_artifacts.php',
                'alerts_ui' => '/tools/ops/alerts.php',
                'rfc_dashboard_ui' => '/tools/rfc/rfc_dashboard.php',
            ],
            'data_missing' => $dataMissing,
            'notes' => array_values(array_unique(array_map(static fn(string $v): string => exs_mask($v), $notes))),
            'next_actions' => $commands,
            'meta' => [
                'policy_fingerprint' => exs_mask((string)($ops['policy']['fingerprint'] ?? '')),
            ],
        ];

        $strictProd = ($env === 'production' && exsu_strict_flag());
        $criticalMissing = [];
        foreach ($inputs as $key => $cfg) {
            if (!(bool)$cfg['critical']) continue;
            if (!(bool)($sources[$key]['ok'] ?? false)) $criticalMissing[] = $key;
        }
        $exitCode = 0;
        if ($strictProd && $criticalMissing !== []) {
            $payload['decision']['go_no_go'] = 'NO-GO';
            $payload['decision']['level'] = 'CRITICAL';
            $payload['decision']['reasons'][] = 'CRITICAL_INPUT_MISSING';
            $payload['notes'][] = exs_mask('STRICT_FAIL: missing critical inputs: ' . implode(',', $criticalMissing));
            $exitCode = 2;
        }

        return [
            'payload' => $payload,
            'sources' => $sources,
            'strict_prod' => $strictProd,
            'exit_code' => $exitCode,
        ];
    }
}

