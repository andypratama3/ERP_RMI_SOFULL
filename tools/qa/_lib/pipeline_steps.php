<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_lib.php';
require_once __DIR__ . '/pipeline_plans_lib.php';

if (!function_exists('ppl_inject_arch_audit_before_exec')) {
    /**
     * Insert ARCH_AUDIT_RUN step before EXEC_SUMMARY_ULTIMATE when plan.arch_audit.enabled.
     */
    function ppl_inject_arch_audit_before_exec(array $steps, array $plan, string $runId): array
    {
        $cfg = ppl_arch_audit_config($plan);
        if (!$cfg['enabled']) return $steps;
        $out = [];
        $injected = false;
        foreach ($steps as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name === 'EXEC_SUMMARY_ULTIMATE' && !$injected) {
                $php = (string)(PHP_BINARY ?: 'php');
                $root = ppl_root();
                $strictArg = $cfg['strict'] ? ' --strict' : '';
                $out[] = [
                    'name' => 'ARCH_AUDIT_RUN',
                    'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/arch_audit.php')
                        . ' --run-id=' . escapeshellarg($runId)
                        . ' --scope=repo --max-files=' . (int)$cfg['max_files']
                        . ' --write-last' . $strictArg,
                    'critical' => $cfg['required'],
                    '_arch_audit_config' => $cfg,
                ];
                $injected = true;
            }
            $out[] = $row;
        }
        if (!$injected) {
            $php = (string)(PHP_BINARY ?: 'php');
            $root = ppl_root();
            $strictArg = $cfg['strict'] ? ' --strict' : '';
            $out[] = [
                'name' => 'ARCH_AUDIT_RUN',
                'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/arch_audit.php')
                    . ' --run-id=' . escapeshellarg($runId)
                    . ' --scope=repo --max-files=' . (int)$cfg['max_files']
                    . ' --write-last' . $strictArg,
                'critical' => $cfg['required'],
                '_arch_audit_config' => $cfg,
            ];
        }
        return $out;
    }
}

if (!function_exists('ppl_exec_arch_audit_step')) {
    function ppl_exec_arch_audit_step(string $cmd, string $runId, array $cfg): array
    {
        $root = ppl_root();
        $pipe = ppl_pipeline_dir();
        $toolPath = $root . '/tools/qa/arch_audit.php';
        if (!is_file($toolPath)) {
            return [
                'ok' => false,
                'duration_ms' => 0,
                'output_tail_masked' => 'ERR_TOOL_MISSING',
                'error_code' => 'ERR_TOOL_MISSING',
            ];
        }
        $t0 = microtime(true);
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $tail = ppl_mask(implode(' | ', array_slice($out, -3)));
        if ($code !== 0 && $code !== 2) {
            return [
                'ok' => false,
                'duration_ms' => $ms,
                'output_tail_masked' => $tail,
                'error_code' => 'ARCH_AUDIT_EXIT_' . $code,
            ];
        }
        $pathByRunId = $pipe . '/arch_audit_' . $runId . '.json';
        if (!is_file($pathByRunId)) {
            return [
                'ok' => false,
                'duration_ms' => $ms,
                'output_tail_masked' => $tail,
                'error_code' => 'PER_RUN_ARTIFACT_MISSING',
            ];
        }
        $r = ppl_read_json_strict($pathByRunId);
        if (!$r['ok']) {
            return [
                'ok' => false,
                'duration_ms' => $ms,
                'output_tail_masked' => $tail,
                'error_code' => 'INVALID_JSON',
            ];
        }
        $fileRunId = trim((string)($r['data']['run_id'] ?? ''));
        if ($fileRunId !== $runId) {
            return [
                'ok' => false,
                'duration_ms' => $ms,
                'output_tail_masked' => $tail,
                'error_code' => 'ERR_RUN_ID_MISMATCH',
            ];
        }
        return [
            'ok' => true,
            'duration_ms' => $ms,
            'output_tail_masked' => $tail,
        ];
    }
}

if (!function_exists('ppl_profile_steps')) {
    /**
     * Returns step definitions for a profile. Each step has name, cmd, critical (stop on fail).
     * @return array<array{name:string,cmd:string,critical?:bool}>
     */
    function ppl_profile_steps(string $profile, string $runId, array $planResolved): array
    {
        $root = ppl_root();
        $php = (string)(PHP_BINARY ?: 'php');
        $runIdArg = '--run-id=' . escapeshellarg($runId);

        if ($profile === 'full') {
            return [];
        }
        if ($profile === 'pr_gate') {
            $baseUrl = trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1';
            $contractFile = $root . '/tools/qa/contract_whitelist_stage16.json';
            $endpointsFile = $root . '/tools/qa/http_endpoints_stage16.json';
            $whitelistCheck = $php . ' ' . escapeshellarg($root . '/tools/qa/_lib/whitelist_verify_stage16.php');
            return [
                ['name' => 'PRECHECK_ENV', 'cmd' => $php . ' -r "exit(trim((string)(getenv(\'TOOLS_BASE_URL\')?:\'\'))!==\'\'?0:1);"', 'critical' => true],
                ['name' => 'PHP_LINT', 'cmd' => '(cd ' . escapeshellarg($root) . ' && find app tools web api -name "*.php" 2>/dev/null | head -200 | xargs -n1 ' . escapeshellarg($php) . ' -l 2>&1)', 'critical' => true],
                ['name' => 'WHITELIST_VERIFY_STAGE16', 'cmd' => $whitelistCheck, 'critical' => true],
                ['name' => 'CONTRACT_CHECK_STAGE16_STRICT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last', 'critical' => true],
                ['name' => 'TOOLS_DASHBOARD_SMOKE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/tools_dashboard_smoke.php') . ' --base-url=' . escapeshellarg($baseUrl), 'critical' => true],
                ['name' => 'EXEC_SUMMARY_ULTIMATE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => false],
            ];
        }
        if ($profile === 'pr_gate_strict_http') {
            $endpointsFile = $root . '/tools/qa/http_endpoints_stage16.json';
            $contractFile = $root . '/tools/qa/contract_whitelist_stage16.json';
            $baseUrl = trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1';
            $whitelistCheck = $php . ' ' . escapeshellarg($root . '/tools/qa/_lib/whitelist_verify_stage16.php');
            return [
                [
                    'name' => 'PRECHECK_ENV',
                    'cmd' => $php . ' -r "exit(trim((string)(getenv(\'TOOLS_BASE_URL\')?:\'\'))!==\'\'?0:1);"',
                    'critical' => true,
                ],
                [
                    'name' => 'PHP_LINT',
                    'cmd' => '(cd ' . escapeshellarg($root) . ' && find app tools web api -name "*.php" 2>/dev/null | head -200 | xargs -n1 ' . escapeshellarg($php) . ' -l 2>&1)',
                    'critical' => true,
                ],
                [
                    'name' => 'WHITELIST_VERIFY_STAGE16',
                    'cmd' => $whitelistCheck,
                    'critical' => true,
                ],
                [
                    'name' => 'CONTRACT_CHECK_STAGE16_STRICT',
                    'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/contract_check.php')
                        . ' --strict --write-last',
                    'critical' => true,
                ],
                [
                    'name' => 'SMOKE_HTTP_STAGE16_STRICT',
                    'cmd' => 'SMOKE_BASE_URL=' . escapeshellarg($baseUrl) . ' ' . $php . ' ' . escapeshellarg($root . '/tools/qa/smoke_http.php')
                        . ' --strict --endpoints=' . escapeshellarg($endpointsFile)
                        . ' ' . $runIdArg . ' --write-last',
                    'critical' => true,
                ],
                [
                    'name' => 'TOOLS_DASHBOARD_SMOKE',
                    'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/tools_dashboard_smoke.php')
                        . ' --base-url=' . escapeshellarg($baseUrl !== '' ? $baseUrl : 'http://127.0.0.1'),
                    'critical' => true,
                ],
                [
                    'name' => 'EXEC_SUMMARY_ULTIMATE',
                    'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php')
                        . ' --env=staging ' . $runIdArg . ' --write-last',
                    'critical' => false,
                ],
            ];
        }
        if ($profile === 'pr_gate_security_lite') {
            $baseUrl = trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1';
            $whitelistCheck = $php . ' ' . escapeshellarg($root . '/tools/qa/_lib/whitelist_verify_stage16.php');
            return [
                ['name' => 'PRECHECK_ENV', 'cmd' => $php . ' -r "exit(trim((string)(getenv(\'TOOLS_BASE_URL\')?:\'\'))!==\'\'?0:1);"', 'critical' => true],
                ['name' => 'PHP_LINT', 'cmd' => '(cd ' . escapeshellarg($root) . ' && find app tools web api -name "*.php" 2>/dev/null | head -200 | xargs -n1 ' . escapeshellarg($php) . ' -l 2>&1)', 'critical' => true],
                ['name' => 'SECRET_SCAN_STRICT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/secret_scan.php') . ' --scope=repo ' . $runIdArg . ' --write-last --strict', 'critical' => true],
                ['name' => 'DEPENDENCY_AUDIT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/dependency_audit.php') . ' ' . $runIdArg . ' --write-last', 'critical' => true],
                ['name' => 'WHITELIST_VERIFY_STAGE16', 'cmd' => $whitelistCheck, 'critical' => true],
                ['name' => 'CONTRACT_CHECK_STAGE16_STRICT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last', 'critical' => true],
                ['name' => 'TOOLS_DASHBOARD_SMOKE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/tools_dashboard_smoke.php') . ' --base-url=' . escapeshellarg($baseUrl), 'critical' => true],
                ['name' => 'EXEC_SUMMARY_ULTIMATE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => false],
            ];
        }
        if ($profile === 'pr_gate_migration_hygiene') {
            $baseUrl = trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1';
            $whitelistCheck = $php . ' ' . escapeshellarg($root . '/tools/qa/_lib/whitelist_verify_stage16.php');
            return [
                ['name' => 'PRECHECK_ENV', 'cmd' => $php . ' -r "exit(trim((string)(getenv(\'TOOLS_BASE_URL\')?:\'\'))!==\'\'?0:1);"', 'critical' => true],
                ['name' => 'PHP_LINT', 'cmd' => '(cd ' . escapeshellarg($root) . ' && find app tools web api -name "*.php" 2>/dev/null | head -200 | xargs -n1 ' . escapeshellarg($php) . ' -l 2>&1)', 'critical' => true],
                ['name' => 'INLINE_DDL_SCAN_STRICT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/inline_ddl_scan.php') . ' --scope=repo ' . $runIdArg . ' --write-last --strict', 'critical' => true],
                ['name' => 'MIGRATION_SQL_LINT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/migration_sql_lint.php') . ' --dir=sql/migrations ' . $runIdArg . ' --write-last --strict', 'critical' => true],
                ['name' => 'WHITELIST_VERIFY_STAGE16', 'cmd' => $whitelistCheck, 'critical' => true],
                ['name' => 'CONTRACT_CHECK_STAGE16_STRICT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last', 'critical' => true],
                ['name' => 'TOOLS_DASHBOARD_SMOKE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/tools_dashboard_smoke.php') . ' --base-url=' . escapeshellarg($baseUrl), 'critical' => true],
                ['name' => 'EXEC_SUMMARY_ULTIMATE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => false],
            ];
        }
        if ($profile === 'nightly_staging_full') {
            $baseUrl = trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1';
            $endpointsFile = $root . '/tools/qa/http_endpoints_stage16.json';
            $whitelistCheck = $php . ' ' . escapeshellarg($root . '/tools/qa/_lib/whitelist_verify_stage16.php');
            $policyOps = $php . ' ' . escapeshellarg($root . '/tools/ops/validate_ops_thresholds.php') . ' --path=' . escapeshellarg($root . '/storage/state/ops_thresholds_current.yaml') . ' --strict --write-last';
            $policyAlert = $php . ' ' . escapeshellarg($root . '/tools/ops/validate_alerting_policy.php') . ' --path=' . escapeshellarg($root . '/docs/governance/ALERTING_POLICY.yaml') . ' --strict --write-last';
            $steps = [
                ['name' => 'PRECHECK_ENV', 'cmd' => $php . ' -r "exit(trim((string)(getenv(\'TOOLS_BASE_URL\')?:\'\'))!==\'\'?0:1);"', 'critical' => true],
                ['name' => 'POLICY_VALIDATION_STRICT_STAGING_WARN', 'cmd' => $policyOps . ' 2>/dev/null; ' . $policyAlert . ' 2>/dev/null; exit 0', 'critical' => false],
                ['name' => 'WHITELIST_VERIFY_STAGE16', 'cmd' => $whitelistCheck, 'critical' => true],
                ['name' => 'PREFLIGHT_CHECK', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/preflight_check.php'), 'critical' => true],
                ['name' => 'CONTRACT_CHECK_STAGE16_STRICT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last', 'critical' => true],
                ['name' => 'SMOKE_HTTP_STAGE16_STRICT', 'cmd' => 'SMOKE_BASE_URL=' . escapeshellarg($baseUrl) . ' ' . $php . ' ' . escapeshellarg($root . '/tools/qa/smoke_http.php') . ' --strict --endpoints=' . escapeshellarg($endpointsFile) . ' ' . $runIdArg . ' --write-last', 'critical' => true],
                ['name' => 'SMOKE_CORE_FLOWS', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/smoke_core_flows.php'), 'critical' => true],
                ['name' => 'TOOLS_DASHBOARD_SMOKE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/tools_dashboard_smoke.php') . ' --base-url=' . escapeshellarg($baseUrl), 'critical' => true],
                ['name' => 'FIX_BACKLOG_SNAPSHOT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/snapshot_fix_backlog.php') . ' --env=staging --write-last', 'critical' => true],
                ['name' => 'OPS_SCORE_SNAPSHOT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/snapshot_ops_score.php') . ' --env=staging --write-last', 'critical' => true],
                ['name' => 'RELEASE_EXPORTS_SCAN', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/release/scan_release_exports.php') . ' --write-last', 'critical' => true],
                ['name' => 'RELEASE_VERIFY_ALL_QUICK', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/release/verify_all_release_packs.php') . ' --env=staging --mode=quick --write-last', 'critical' => true],
                ['name' => 'ALERT_ENGINE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/alert_engine.php') . ' --env=staging --write-last', 'critical' => true],
                ['name' => 'RFC_INDEX', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/rfc/rfc_index_scan.php') . ' --write-last', 'critical' => true],
                ['name' => 'RFC_QUALITY_LINT_QUICK', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/rfc/rfc_quality_lint.php') . ' --env=staging --mode=quick --write-last', 'critical' => true],
                ['name' => 'RFC_USAGE_COLLECT_LAST', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/rfc/collect_rfc_usage.php') . ' --env=staging --run-id=last --write-last', 'critical' => true],
                ['name' => 'CHANGE_CONTROL_HEALTH', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/build_change_control_health.php') . ' --env=staging ' . $runIdArg, 'critical' => true],
                ['name' => 'EXEC_SUMMARY_ULTIMATE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => false],
            ];
            return ppl_inject_arch_audit_before_exec($steps, $planResolved, $runId);
        }
        if ($profile === 'release_candidate_staging') {
            $baseUrl = trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1';
            $endpointsFile = $root . '/tools/qa/http_endpoints_stage16.json';
            $whitelistCheck = $php . ' ' . escapeshellarg($root . '/tools/qa/_lib/whitelist_verify_stage16.php');
            $rfcId = strtoupper(trim((string)($planResolved['rfc_id'] ?? '')));
            $rfcArg = $rfcId !== '' ? ' --rfc=' . escapeshellarg($rfcId) : '';
            $policyOps = $php . ' ' . escapeshellarg($root . '/tools/ops/validate_ops_thresholds.php') . ' --path=' . escapeshellarg($root . '/storage/state/ops_thresholds_current.yaml') . ' --strict --write-last';
            $policyAlert = $php . ' ' . escapeshellarg($root . '/tools/ops/validate_alerting_policy.php') . ' --path=' . escapeshellarg($root . '/storage/state/alerting_policy_current.yaml') . ' --strict --write-last';
            $steps = [
                ['name' => 'PRECHECK_ENV', 'cmd' => $php . ' -r "exit(trim((string)(getenv(\'TOOLS_BASE_URL\')?:\'\'))!==\'\'?0:1);"', 'critical' => true],
                ['name' => 'POLICY_VALIDATION_STRICT', 'cmd' => $policyOps . ' && ' . $policyAlert, 'critical' => true],
                ['name' => 'WHITELIST_VERIFY_STAGE16', 'cmd' => $whitelistCheck, 'critical' => true],
                ['name' => 'PREFLIGHT_CHECK', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/preflight_check.php'), 'critical' => true],
                ['name' => 'CONTRACT_CHECK_STAGE16_STRICT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last', 'critical' => true],
                ['name' => 'SMOKE_HTTP_STAGE16_STRICT', 'cmd' => 'SMOKE_BASE_URL=' . escapeshellarg($baseUrl) . ' ' . $php . ' ' . escapeshellarg($root . '/tools/qa/smoke_http.php') . ' --strict --endpoints=' . escapeshellarg($endpointsFile) . ' ' . $runIdArg . ' --write-last', 'critical' => true],
                ['name' => 'SMOKE_CORE_FLOWS', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/smoke_core_flows.php'), 'critical' => true],
                ['name' => 'TOOLS_DASHBOARD_SMOKE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/tools_dashboard_smoke.php') . ' --base-url=' . escapeshellarg($baseUrl), 'critical' => true],
                ['name' => 'RELEASE_EXPORTS_SCAN', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/release/scan_release_exports.php') . ' --write-last', 'critical' => true],
                ['name' => 'RELEASE_VERIFY_ALL_FULL', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/release/verify_all_release_packs.php') . ' --env=staging --mode=full --write-last', 'critical' => true],
                ['name' => 'ALERT_ENGINE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/alert_engine.php') . ' --env=staging --write-last', 'critical' => true],
                ['name' => 'RFC_INDEX', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/rfc/rfc_index_scan.php') . ' --write-last', 'critical' => true],
                ['name' => 'RFC_QUALITY_LINT_FULL_STRICT', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/rfc/rfc_quality_lint.php') . ' --env=staging --mode=full --strict --write-last', 'critical' => true],
                ['name' => 'RFC_USAGE_COLLECT_RUN_ID', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/rfc/collect_rfc_usage.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => true],
                ['name' => 'CHANGE_CONTROL_HEALTH', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/build_change_control_health.php') . ' --env=staging ' . $runIdArg, 'critical' => true],
                ['name' => 'EXEC_SUMMARY_ULTIMATE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => false],
                ['name' => 'RELEASE_NOTES_SKELETON', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/release/generate_release_notes_skeleton.php') . ' --env=staging' . $rfcArg . ' ' . $runIdArg . ' --write-last', 'critical' => true],
                ['name' => 'RELEASE_NOTES_FINAL', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/release/generate_release_notes.php') . ' --env=staging' . $rfcArg . ' --pipeline=run-id ' . $runIdArg . ' --mode=final --write-last', 'critical' => true],
                ['name' => 'RELEASE_PACK_BUILD', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/release/scan_release_exports.php') . ' --write-last', 'critical' => true],
                ['name' => 'RELEASE_PACK_VERIFY', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/release/verify_all_release_packs.php') . ' --env=staging --mode=full --write-last', 'critical' => true],
            ];
            return ppl_inject_arch_audit_before_exec($steps, $planResolved, $runId);
        }
        if ($profile === 'ui_regression_optional') {
            $baseUrl = trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1';
            return [
                ['name' => 'PRECHECK_ENV', 'cmd' => $php . ' -r "exit(trim((string)(getenv(\'TOOLS_BASE_URL\')?:\'\'))!==\'\'?0:1);"', 'critical' => true],
                ['name' => 'PLAYWRIGHT_SMOKE_OPTIONAL', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/qa/playwright_smoke.php') . ' --base-url=' . escapeshellarg($baseUrl) . ' ' . $runIdArg . ' --write-last', 'critical' => false],
                ['name' => 'EXEC_SUMMARY_ULTIMATE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => false],
            ];
        }
        if ($profile === 'ops_freshness_gate') {
            $envPlan = strtolower((string)($planResolved['env'] ?? 'staging'));
            return [
                ['name' => 'PRECHECK_ENV', 'cmd' => $php . ' -r "exit(0);"', 'critical' => true],
                ['name' => 'OPS_FRESHNESS_CHECK', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/ops_freshness_check.php') . ' --env=' . escapeshellarg($envPlan) . ' ' . $runIdArg . ' --write-last', 'critical' => true],
                ['name' => 'EXEC_SUMMARY_ULTIMATE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => false],
            ];
        }
        if ($profile === 'mobile_contract_gate') {
            $baseUrl = trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1';
            $envPrefix = 'APP_BASE_URL=' . escapeshellarg($baseUrl);
            $mobileUser = trim((string)getenv('MOBILE_QA_USER'));
            $mobilePass = trim((string)getenv('MOBILE_QA_PASS'));
            if ($mobileUser !== '') $envPrefix .= ' MOBILE_QA_USER=' . escapeshellarg($mobileUser);
            if ($mobilePass !== '') $envPrefix .= ' MOBILE_QA_PASS=' . escapeshellarg($mobilePass);
            $whitelistVerify = $php . ' ' . escapeshellarg($root . '/tools/qa/_lib/whitelist_verify_mobile.php');
            $contractCheck = $php . ' ' . escapeshellarg($root . '/tools/qa/mobile_api_contract_check.php');
            $smokeMobile = $envPrefix . ' ' . $php . ' ' . escapeshellarg($root . '/tools/qa/mobile_api_smoke.php');
            $smokeMaster = $envPrefix . ' ' . $php . ' ' . escapeshellarg($root . '/tools/qa/mobile_master_policy_smoke.php');
            return [
                ['name' => 'PRECHECK_ENV', 'cmd' => $php . ' -r "exit(trim((string)(getenv(\'TOOLS_BASE_URL\')?:\'\'))!==\'\'?0:1);"', 'critical' => true],
                ['name' => 'MOBILE_WHITELIST_VERIFY_ONLY', 'cmd' => $whitelistVerify, 'critical' => true],
                ['name' => 'MOBILE_CONTRACT_CHECK_STRICT', 'cmd' => $contractCheck, 'critical' => true],
                ['name' => 'MOBILE_HTTP_SMOKE_STRICT', 'cmd' => $smokeMobile . ' && ' . $smokeMaster, 'critical' => true],
                ['name' => 'EXEC_SUMMARY_ULTIMATE', 'cmd' => $php . ' ' . escapeshellarg($root . '/tools/ops/generate_executive_summary.php') . ' --env=staging ' . $runIdArg . ' --write-last', 'critical' => false],
            ];
        }
        return [
            ['name' => 'ERR_PROFILE_NOT_IMPLEMENTED', 'cmd' => $php . ' -r "fwrite(STDERR,\'ERR_PROFILE_NOT_IMPLEMENTED: profile ' . addslashes($profile) . ' not implemented. Fix: add case in tools/qa/_lib/pipeline_steps.php ppl_profile_steps().\'); exit(1);"', 'critical' => true],
        ];
    }
}

if (!function_exists('ppl_run_profile_steps')) {
    /**
     * Execute profile steps. Returns same shape as ppl_run_stage_steps.
     */
    function ppl_run_profile_steps(array $steps, string $subRunId, array $planResolved): array
    {
        $overallOk = true;
        $stopReason = '';
        $resultSteps = [];
        foreach ($steps as $row) {
            $name = (string)($row['name'] ?? 'unnamed_step');
            $cmd = trim((string)($row['cmd'] ?? ''));
            $critical = (bool)($row['critical'] ?? true);
            if ($name === 'ARCH_AUDIT_RUN') {
                $cfg = (array)($row['_arch_audit_config'] ?? []);
                $res = ppl_exec_arch_audit_step($cmd, $subRunId, $cfg);
                $resultSteps[] = [
                    'name' => $name,
                    'ok' => (bool)($res['ok'] ?? false),
                    'duration_ms' => (int)($res['duration_ms'] ?? 0),
                    'output_tail_masked' => (string)($res['output_tail_masked'] ?? '') . (isset($res['error_code']) ? ' [' . (string)$res['error_code'] . ']' : ''),
                ];
                if (!(bool)($res['ok'] ?? false) && $critical) {
                    $overallOk = false;
                    $stopReason = 'profile_step_failed_' . $name . (isset($res['error_code']) ? '_' . (string)$res['error_code'] : '');
                    break;
                }
                continue;
            }
            if ($cmd === '') {
                $resultSteps[] = [
                    'name' => $name,
                    'ok' => false,
                    'duration_ms' => 0,
                    'output_tail_masked' => 'empty_command',
                ];
                $overallOk = false;
                $stopReason = 'profile_step_empty_command_' . $name;
                break;
            }
            $res = ppl_exec($cmd);
            $resultSteps[] = [
                'name' => $name,
                'ok' => (bool)$res['ok'],
                'duration_ms' => (int)$res['duration_ms'],
                'output_tail_masked' => (string)$res['output_tail_masked'],
            ];
            if (!$res['ok'] && $critical) {
                $overallOk = false;
                $stopReason = 'profile_step_failed_' . $name;
                break;
            }
        }
        return [
            'ok' => $overallOk,
            'stop_reason' => $stopReason,
            'steps' => $resultSteps,
        ];
    }
}

if (!function_exists('ppl_stage_run_aware_scripts')) {
    function ppl_stage_run_aware_scripts(): array
    {
        return [
            'tools/ops/update_alerting_policy.php',
            'tools/ops/update_ops_thresholds.php',
            'tools/migrate/run.php',
            'tools/backup/restore.php',
            'tools/release/generate_release_notes.php',
        ];
    }
}

if (!function_exists('ppl_run_stage_steps')) {
    function ppl_run_stage_steps(array $stageConfig, string $stage, string $subRunId, array $planResolved): array
    {
        $steps = [];
        $overallOk = true;
        $stopReason = '';
        $list = (array)($stageConfig['steps'] ?? []);
        foreach ($list as $row) {
            if (!is_array($row)) continue;
            $name = (string)($row['name'] ?? 'unnamed_step');
            $cmd = trim((string)($row['cmd'] ?? ''));
            foreach (ppl_stage_run_aware_scripts() as $script) {
                if ($cmd !== '' && str_contains($cmd, $script) && !str_contains($cmd, '--run-id=')) {
                    $cmd .= ' --run-id=' . escapeshellarg($subRunId);
                }
            }
            if ((string)($planResolved['release_verify_mode'] ?? '') !== '' && str_contains($cmd, 'verify_all_release_packs.php') && !str_contains($cmd, '--mode=')) {
                $cmd .= ' --mode=' . escapeshellarg((string)$planResolved['release_verify_mode']);
            }
            if ((string)($planResolved['core_flows_profile'] ?? '') === 'read-only' && str_contains($cmd, 'smoke_core_flows.php') && !str_contains($cmd, '--profile=')) {
                $cmd .= ' --profile=read-only';
            }
            if ($cmd === '') {
                $steps[] = [
                    'name' => $name,
                    'ok' => false,
                    'duration_ms' => 0,
                    'output_tail_masked' => 'empty_command',
                ];
                $overallOk = false;
                $stopReason = 'empty_command_stage_' . $stage . '_' . $name;
                break;
            }
            $res = ppl_exec($cmd);
            $steps[] = [
                'name' => $name,
                'ok' => (bool)$res['ok'],
                'duration_ms' => (int)$res['duration_ms'],
                'output_tail_masked' => (string)$res['output_tail_masked'],
            ];
            if (!$res['ok']) {
                $overallOk = false;
                $stopReason = 'stage_step_failed_stage_' . $stage . '_' . $name;
                break;
            }
        }
        return [
            'ok' => $overallOk,
            'stop_reason' => $stopReason,
            'steps' => $steps,
        ];
    }
}

