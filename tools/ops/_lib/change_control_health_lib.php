<?php
declare(strict_types=1);

require_once __DIR__ . '/executive_summary_lib.php';

if (!function_exists('cch_paths')) {
    function cch_paths(): array
    {
        $pipe = exs_pipeline_dir();
        return [
            'index' => exs_root() . '/storage/state/rfc_index_last.json',
            'lint' => $pipe . '/rfc_quality_lint_last.json',
            'usage' => $pipe . '/rfc_usage_last.json',
            'pipeline_last' => $pipe . '/pipeline_last.json',
            'out_json' => $pipe . '/change_control_health_last.json',
            'out_md' => $pipe . '/change_control_health_last.md',
            'audit' => exs_root() . '/storage/logs/audit_change_control_health.jsonl',
            'assumptions' => exs_root() . '/docs/governance/ASSUMPTIONS.md',
        ];
    }
}

if (!function_exists('cch_age_s')) {
    function cch_age_s(string $path): ?int
    {
        if (!is_file($path)) return null;
        $mt = @filemtime($path);
        if ($mt === false) return null;
        return max(0, time() - (int)$mt);
    }
}

if (!function_exists('cch_read_state')) {
    function cch_read_state(string $path, array $requiredKeys = []): array
    {
        $r = exs_read_json_strict($path);
        if (!$r['ok']) return $r;
        foreach ($requiredKeys as $k) {
            if (!array_key_exists($k, (array)$r['data'])) {
                return [
                    'ok' => false,
                    'error' => 'schema_mismatch',
                    'error_masked' => exs_mask('schema_mismatch:' . $k),
                    'path_masked' => exs_mask($path),
                    'data' => [],
                ];
            }
        }
        return $r;
    }
}

if (!function_exists('cch_count_maker_checker_violations')) {
    function cch_count_maker_checker_violations(array $lintRows): int
    {
        $count = 0;
        foreach ($lintRows as $row) {
            if (!is_array($row)) continue;
            foreach (['fails', 'warns'] as $bucket) {
                foreach ((array)($row[$bucket] ?? []) as $msg) {
                    $code = strtoupper((string)($msg['code'] ?? ''));
                    if (str_starts_with($code, 'MAKER_CHECKER_')) $count++;
                }
            }
        }
        return $count;
    }
}

if (!function_exists('cch_find_lint_row')) {
    function cch_find_lint_row(array $lintRows, string $rfcId): array
    {
        $id = strtoupper(trim($rfcId));
        foreach ($lintRows as $row) {
            if (!is_array($row)) continue;
            if (strtoupper((string)($row['id'] ?? '')) === $id) return $row;
        }
        return [];
    }
}

if (!function_exists('cch_issue')) {
    function cch_issue(string $severity, string $code, string $message, ?string $rfcId = null): array
    {
        return [
            'severity' => strtoupper($severity),
            'code' => strtoupper($code),
            'message' => exs_mask($message),
            'rfc_id' => $rfcId !== null ? strtoupper($rfcId) : null,
        ];
    }
}

if (!function_exists('cch_sort_issues')) {
    function cch_sort_issues(array $issues): array
    {
        usort($issues, static function (array $a, array $b): int {
            $rank = ['CRITICAL' => 0, 'ATTENTION' => 1];
            $ra = $rank[(string)($a['severity'] ?? 'ATTENTION')] ?? 9;
            $rb = $rank[(string)($b['severity'] ?? 'ATTENTION')] ?? 9;
            if ($ra !== $rb) return $ra <=> $rb;
            $ca = (string)($a['code'] ?? '');
            $cb = (string)($b['code'] ?? '');
            if ($ca !== $cb) return strcmp($ca, $cb);
            return strcmp((string)($a['rfc_id'] ?? ''), (string)($b['rfc_id'] ?? ''));
        });
        return $issues;
    }
}

if (!function_exists('cch_build_md')) {
    function cch_build_md(array $health): string
    {
        $s = (array)($health['summary'] ?? []);
        $lint = (array)($s['lint'] ?? []);
        $gov = (array)($s['governance'] ?? []);
        $use = (array)($s['usage'] ?? []);
        $fresh = (array)($health['freshness'] ?? []);
        $lines = [];
        $lines[] = '# Change Control Health';
        $lines[] = '';
        $lines[] = '- generated_at: ' . exs_mask((string)($health['generated_at'] ?? ''));
        $lines[] = '- env: ' . exs_mask((string)($health['env'] ?? ''));
        $lines[] = '- overall_level: ' . exs_mask((string)($health['overall_level'] ?? 'UNKNOWN'));
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '|---|---:|';
        $lines[] = '| total_rfc | ' . (string)($s['total_rfc'] ?? 'null') . ' |';
        $lines[] = '| lint_pass | ' . (string)($lint['pass'] ?? 'null') . ' |';
        $lines[] = '| lint_warn | ' . (string)($lint['warn'] ?? 'null') . ' |';
        $lines[] = '| lint_fail | ' . (string)($lint['fail'] ?? 'null') . ' |';
        $lines[] = '| avg_coverage | ' . (string)($lint['avg_coverage'] ?? 'null') . ' |';
        $lines[] = '| approved_but_lint_fail | ' . (string)($gov['approved_but_lint_fail'] ?? 'null') . ' |';
        $lines[] = '| prod_missing_schedule | ' . (string)($gov['prod_missing_schedule'] ?? 'null') . ' |';
        $lines[] = '| approved_missing_approvals | ' . (string)($gov['approved_missing_approvals'] ?? 'null') . ' |';
        $lines[] = '| maker_checker_violations | ' . (string)($gov['maker_checker_violations'] ?? 'null') . ' |';
        $lines[] = '| primary_rfc_id | ' . exs_mask((string)($use['primary_rfc_id'] ?? 'null')) . ' |';
        $lines[] = '| primary_status | ' . exs_mask((string)($use['primary_status'] ?? 'null')) . ' |';
        $lines[] = '| primary_approvals_missing | ' . (string)($use['primary_approvals_missing'] ?? 'null') . ' |';
        $lines[] = '';
        $lines[] = '## Freshness (seconds)';
        $lines[] = '- rfc_index_age_s: ' . (string)($fresh['rfc_index_age_s'] ?? 'null');
        $lines[] = '- rfc_lint_age_s: ' . (string)($fresh['rfc_lint_age_s'] ?? 'null');
        $lines[] = '- rfc_usage_age_s: ' . (string)($fresh['rfc_usage_age_s'] ?? 'null');
        $lines[] = '';
        $lines[] = '## Top Issues';
        $issues = (array)($health['top_issues'] ?? []);
        if ($issues === []) {
            $lines[] = '- none';
        } else {
            foreach ($issues as $i) {
                $lines[] = '- [' . exs_mask((string)($i['severity'] ?? 'ATTENTION')) . '] '
                    . exs_mask((string)($i['code'] ?? 'UNKNOWN'))
                    . ' | rfc=' . exs_mask((string)($i['rfc_id'] ?? 'null'))
                    . ' | ' . exs_mask((string)($i['message'] ?? ''));
            }
        }
        $lines[] = '';
        $lines[] = '## Commands';
        foreach ((array)($health['commands'] ?? []) as $cmd) {
            $lines[] = '- `' . exs_mask((string)$cmd) . '`';
        }
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('cch_append_assumption')) {
    function cch_append_assumption(string $line): void
    {
        $p = cch_paths()['assumptions'];
        if (!is_file($p)) {
            @file_put_contents($p, "# Assumptions Log\n\n");
        }
        @file_put_contents($p, '- ts: ' . date(DateTimeInterface::ATOM) . ' | tool: change_control_health | ' . exs_mask($line) . "\n", FILE_APPEND);
    }
}

if (!function_exists('cch_policy_strict_gate_enabled')) {
    function cch_policy_strict_gate_enabled(): bool
    {
        $candidates = [
            'release.require_change_control_health_healthy_for_prod',
            'governance.require_change_control_health_healthy_for_prod',
            'gates.require_change_control_health_healthy_for_prod',
            'change_control.require_change_control_health_healthy_for_prod',
        ];
        foreach ($candidates as $k) {
            $v = get_threshold($k, null);
            if ($v === null) continue;
            if (is_bool($v)) return $v;
            $s = strtolower(trim((string)$v));
            if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return true;
            if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
        }
        return false;
    }
}

if (!function_exists('cch_compute')) {
    function cch_compute(string $env, string $runId): array
    {
        $paths = cch_paths();
        $commands = [
            'php tools/rfc/rfc_index_scan.php --write-last',
            'php tools/rfc/rfc_quality_lint.php --env=staging --mode=quick --write-last',
            'php tools/rfc/collect_rfc_usage.php --env=staging --run-id=last --write-last',
        ];

        $index = cch_read_state($paths['index'], ['rfcs']);
        $lint = cch_read_state($paths['lint'], ['summary', 'rfcs']);
        $usage = cch_read_state($paths['usage'], ['rfcs']);
        $pipelineLast = cch_read_state($paths['pipeline_last']);

        $notes = [];
        $issues = [];
        $missingSources = [];
        foreach (['index' => $index, 'lint' => $lint, 'usage' => $usage] as $name => $src) {
            if (!$src['ok']) {
                $missingSources[] = strtoupper($name) . '_MISSING';
                $issues[] = cch_issue('ATTENTION', strtoupper($name) . '_MISSING', strtoupper($name) . ' state missing or invalid');
                $notes[] = exs_mask(strtoupper($name) . ' source unavailable');
            }
        }

        if ($missingSources !== []) {
            cch_append_assumption('fallback_used=' . implode(',', $missingSources) . '; run_id=' . $runId);
        }

        $indexRows = (array)($index['data']['rfcs'] ?? []);
        $lintRows = (array)($lint['data']['rfcs'] ?? []);
        $lintSummary = (array)($lint['data']['summary'] ?? []);
        $usageRows = (array)($usage['data']['rfcs'] ?? []);
        $primaryRfcId = strtoupper(trim((string)($usage['data']['primary_rfc_id'] ?? '')));

        $totalRfc = $index['ok'] ? count($indexRows) : null;
        $byStatus = ['DRAFT' => 0, 'IN_REVIEW' => 0, 'APPROVED' => 0, 'IMPLEMENTED' => 0, 'CLOSED' => 0, 'REJECTED' => 0];
        foreach ($indexRows as $row) {
            if (!is_array($row)) continue;
            $st = strtoupper(trim((string)($row['status'] ?? '')));
            if (isset($byStatus[$st])) $byStatus[$st]++;
        }

        $lintFailCount = $lint['ok'] ? (int)($lintSummary['fail_count'] ?? 0) : null;
        $lintWarnCount = $lint['ok'] ? (int)($lintSummary['warn_count'] ?? 0) : null;
        $lintPassCount = $lint['ok'] ? max(0, count($lintRows) - ((int)($lintSummary['fail_count'] ?? 0)) - ((int)($lintSummary['warn_count'] ?? 0)) ) : null;
        $avgCoverage = $lint['ok'] ? (float)($lintSummary['coverage_avg'] ?? 0.0) : null;
        $makerCheckerCount = $lint['ok'] ? cch_count_maker_checker_violations($lintRows) : null;

        $approvedButLintFail = 0;
        $prodMissingSchedule = 0;
        $approvedMissingApprovals = 0;
        foreach ($indexRows as $row) {
            if (!is_array($row)) continue;
            $id = strtoupper((string)($row['id'] ?? ''));
            $status = strtoupper((string)($row['status'] ?? ''));
            $missingAp = (array)($row['approvals_missing'] ?? []);
            $lintRow = cch_find_lint_row($lintRows, $id);
            $lintResult = strtoupper((string)($lintRow['result'] ?? 'UNKNOWN'));
            if ($status === 'APPROVED' && $lintResult === 'FAIL') {
                $approvedButLintFail++;
                $issues[] = cch_issue('CRITICAL', 'APPROVED_BUT_LINT_FAIL', 'RFC approved while lint is FAIL', $id);
            }
            if ($status === 'APPROVED' && $missingAp !== []) {
                $approvedMissingApprovals++;
                $issues[] = cch_issue('CRITICAL', 'APPROVED_MISSING_APPROVALS', 'Approved RFC still missing approvals', $id);
            }
            $targetEnv = strtolower((string)($lintRow['target_env'] ?? ''));
            if ($targetEnv === 'production' && in_array($status, ['IN_REVIEW', 'APPROVED'], true)) {
                $schedule = trim((string)($lintRow['schedule'] ?? ''));
                $scheduleMissingCode = false;
                foreach ((array)($lintRow['fails'] ?? []) as $f) {
                    if (strtoupper((string)($f['code'] ?? '')) === 'SCHEDULE_REQUIRED') {
                        $scheduleMissingCode = true;
                        break;
                    }
                }
                if ($schedule === '' && $scheduleMissingCode) {
                    $prodMissingSchedule++;
                    $issues[] = cch_issue('CRITICAL', 'PROD_MISSING_SCHEDULE', 'Production RFC missing schedule', $id);
                }
            }
        }

        $primaryStatus = null;
        $primaryMissingApprovals = null;
        $relatedCount = $usage['ok'] ? count($usageRows) : null;
        $hasAlertPolicyChange = null;
        $hasOpsThresholdChange = null;
        if ($usage['ok']) {
            $hasAlertPolicyChange = false;
            $hasOpsThresholdChange = false;
            foreach ($usageRows as $row) {
                if (!is_array($row)) continue;
                $tags = array_map('strtoupper', (array)($row['tags'] ?? []));
                if (in_array('ALERT_POLICY', $tags, true)) $hasAlertPolicyChange = true;
                if (in_array('OPS_THRESHOLDS', $tags, true)) $hasOpsThresholdChange = true;
                if (strtoupper((string)($row['id'] ?? '')) === $primaryRfcId && $primaryRfcId !== '') {
                    $primaryStatus = strtoupper((string)($row['status'] ?? ''));
                    $primaryMissingApprovals = count((array)($row['approvals']['missing'] ?? []));
                }
            }
        }

        $freshness = [
            'rfc_index_age_s' => cch_age_s($paths['index']),
            'rfc_lint_age_s' => cch_age_s($paths['lint']),
            'rfc_usage_age_s' => cch_age_s($paths['usage']),
        ];

        $strictGate = cch_policy_strict_gate_enabled();
        $level = 'HEALTHY';
        $isProd = ($env === 'production');

        $critical = false;
        if ($approvedButLintFail > 0) $critical = true;
        if ($approvedMissingApprovals > 0) $critical = true;
        if ($isProd && $prodMissingSchedule > 0) $critical = true;
        if ($primaryRfcId !== '' && $primaryStatus !== null && $primaryStatus !== 'APPROVED' && $strictGate && $isProd) {
            $critical = true;
            $issues[] = cch_issue('CRITICAL', 'PRIMARY_RFC_NOT_APPROVED', 'Primary RFC is not APPROVED in production strict gate', $primaryRfcId);
        } elseif ($primaryRfcId !== '' && $primaryStatus !== null && $primaryStatus !== 'APPROVED') {
            $issues[] = cch_issue('ATTENTION', 'PRIMARY_RFC_NOT_APPROVED', 'Primary RFC is not APPROVED', $primaryRfcId);
        }
        if ($makerCheckerCount !== null && $makerCheckerCount > 0 && $isProd && $strictGate) {
            $critical = true;
            $issues[] = cch_issue('CRITICAL', 'MAKER_CHECKER_VIOLATION', 'Maker-checker violation exists in strict production', null);
        }

        $attention = false;
        if ($lintFailCount !== null && $lintFailCount > 0) $attention = true;
        if ($lintWarnCount !== null && $lintWarnCount > 0) $attention = true;
        if ($avgCoverage !== null && $avgCoverage < 80.0) $attention = true;
        if ($missingSources !== []) $attention = true;
        foreach ($freshness as $k => $age) {
            if ($age !== null && $age > 86400) {
                $attention = true;
                $issues[] = cch_issue('ATTENTION', strtoupper($k) . '_STALE', 'Source freshness exceeds 24h', null);
            }
        }
        if (!$isProd && $primaryMissingApprovals !== null && $primaryMissingApprovals > 0) $attention = true;

        if ($critical) $level = 'CRITICAL';
        elseif ($attention) $level = 'ATTENTION';

        $issues = cch_sort_issues($issues);
        $topIssues = array_slice($issues, 0, 12);

        $health = [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'env' => $env,
            'run_id' => $runId,
            'overall_level' => $level,
            'freshness' => $freshness,
            'summary' => [
                'total_rfc' => $totalRfc,
                'by_status' => $totalRfc !== null ? $byStatus : null,
                'lint' => [
                    'pass' => $lintPassCount,
                    'warn' => $lintWarnCount,
                    'fail' => $lintFailCount,
                    'avg_coverage' => $avgCoverage,
                ],
                'governance' => [
                    'approved_but_lint_fail' => $index['ok'] && $lint['ok'] ? $approvedButLintFail : null,
                    'prod_missing_schedule' => $index['ok'] && $lint['ok'] ? $prodMissingSchedule : null,
                    'approved_missing_approvals' => $index['ok'] ? $approvedMissingApprovals : null,
                    'maker_checker_violations' => $makerCheckerCount,
                ],
                'usage' => [
                    'primary_rfc_id' => $usage['ok'] ? ($primaryRfcId !== '' ? $primaryRfcId : null) : null,
                    'primary_status' => $usage['ok'] ? $primaryStatus : null,
                    'primary_approvals_missing' => $usage['ok'] ? $primaryMissingApprovals : null,
                    'related_count' => $relatedCount,
                    'has_alert_policy_change' => $hasAlertPolicyChange,
                    'has_ops_threshold_change' => $hasOpsThresholdChange,
                ],
            ],
            'top_issues' => $topIssues,
            'commands' => $commands,
            'notes' => array_values(array_unique($notes)),
            'strict_gate_active' => $strictGate,
            'pipeline_run_id' => (string)($pipelineLast['data']['run_id'] ?? ''),
        ];

        return ['health' => $health, 'paths' => $paths, 'strict_gate_active' => $strictGate];
    }
}

if (!function_exists('cch_write_artifacts')) {
    function cch_write_artifacts(array $health, array $paths): void
    {
        ops_write_json((string)$paths['out_json'], $health);
        @file_put_contents((string)$paths['out_md'], cch_build_md($health));
    }
}

if (!function_exists('cch_append_audit')) {
    function cch_append_audit(array $health, string $requestId): void
    {
        $paths = cch_paths();
        $actor = 'SYSTEM';
        if (function_exists('tools_current_actor_username')) {
            $actor = (string)tools_current_actor_username();
        } else {
            $actor = trim((string)(getenv('CI_ACTOR') ?: getenv('USER') ?: 'SYSTEM'));
        }
        $payload = [
            'ts' => date(DateTimeInterface::ATOM),
            'actor_username' => exs_mask($actor),
            'env' => (string)($health['env'] ?? 'staging'),
            'action' => 'CHANGE_CONTROL_HEALTH_BUILT',
            'level' => (string)($health['overall_level'] ?? 'UNKNOWN'),
            'request_id' => $requestId,
            'summary' => (array)($health['summary'] ?? []),
        ];
        @file_put_contents((string)$paths['audit'], json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }
}

