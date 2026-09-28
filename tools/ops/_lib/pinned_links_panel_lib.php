<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_ui_helpers.php';
require_once __DIR__ . '/plan_exec_summary_view_lib.php';
require_once __DIR__ . '/safe_viewer_lib.php';

if (!function_exists('plp_load_exec_summary_state')) {
    /**
     * Load executive ops summary ULTIMATE state for pinned links panel.
     * @return array{ok:bool, badge:string, level:string, age_hours:?float, error:string, fix_command:string}
     */
    function plp_load_exec_summary_state(): array
    {
        $dir = svl_pipeline_dir();
        $jsonPath = $dir . '/executive_ops_summary_ultimate_last.json';
        if (!is_file($jsonPath)) {
            $fallback = $dir . '/executive_ops_summary_last.json';
            if (is_file($fallback)) {
                $jsonPath = $fallback;
            } else {
                return [
                    'ok' => false,
                    'badge' => 'DATA_MISSING',
                    'level' => 'UNKNOWN',
                    'age_hours' => null,
                    'error' => 'DATA_MISSING',
                    'fix_command' => 'php tools/ops/generate_executive_summary.php --env=staging --run-id=auto --write-last',
                ];
            }
        }
        $raw = (string)@file_get_contents($jsonPath);
        $err = null;
        $data = tools_safe_json_decode($raw, $err);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'badge' => 'STATE_CORRUPT',
                'level' => 'UNKNOWN',
                'age_hours' => tools_age_hours($jsonPath),
                'error' => 'STATE_CORRUPT',
                'fix_command' => 'php tools/ops/generate_executive_summary.php --env=staging --run-id=auto --write-last',
            ];
        }
        $dec = (array)($data['decision'] ?? []);
        $goNoGo = strtoupper((string)($dec['go_no_go'] ?? 'UNKNOWN'));
        $level = strtoupper((string)($dec['level'] ?? 'UNKNOWN'));
        return [
            'ok' => true,
            'badge' => $goNoGo,
            'level' => $level,
            'age_hours' => tools_age_hours($jsonPath),
            'error' => '',
            'fix_command' => '',
        ];
    }
}

if (!function_exists('plp_load_plan_exec_state')) {
    /**
     * Load plan exec summary state (reuse pesv_load_panel_state).
     * @return array{ok:bool, badge:string, level:string, age_hours:?float, error:string, fix_command:string, state_mismatch:bool, plan_id:string, master_run_id:string}
     */
    function plp_load_plan_exec_state(): array
    {
        $state = pesv_load_panel_state();
        if (!$state['ok']) {
            $err = (string)($state['error'] ?? 'DATA_MISSING');
            return [
                'ok' => false,
                'badge' => $err === 'STATE_CORRUPT' ? 'STATE_CORRUPT' : 'DATA_MISSING',
                'level' => 'UNKNOWN',
                'age_hours' => $state['age_hours'] ?? null,
                'error' => $err,
                'fix_command' => 'php tools/qa/run_pipeline_final.php --plan=full_staging_00_16 --run-id=auto --write-last',
                'state_mismatch' => false,
                'plan_id' => '',
                'master_run_id' => '',
            ];
        }
        $data = (array)($state['data'] ?? []);
        $dec = (array)($data['decision'] ?? []);
        $goNoGo = strtoupper((string)($dec['go_no_go'] ?? 'UNKNOWN'));
        $level = strtoupper((string)($dec['level'] ?? 'UNKNOWN'));
        $mismatch = (bool)($state['state_mismatch'] ?? false);
        $perRunExists = (bool)($state['per_run_exists'] ?? false);
        $planId = (string)($data['plan_id'] ?? '');
        $masterRunId = (string)($data['master_run_id'] ?? '');
        $fixCmd = 'php tools/qa/run_pipeline_final.php --plan=full_staging_00_16 --run-id=auto --write-last';
        if ($mismatch || !$perRunExists) {
            $fixCmd = 'Plan last summary points to ' . $planId . '/' . $masterRunId . ' but per-run file missing. Re-run plan.';
        }
        return [
            'ok' => !$mismatch && $perRunExists,
            'badge' => $mismatch || !$perRunExists ? 'ATTENTION' : $goNoGo,
            'level' => $level,
            'age_hours' => $state['age_hours'] ?? null,
            'error' => $mismatch ? 'STATE_MISMATCH' : ($perRunExists ? '' : 'PER_RUN_MISSING'),
            'fix_command' => $fixCmd,
            'state_mismatch' => $mismatch,
            'plan_id' => $planId,
            'master_run_id' => $masterRunId,
        ];
    }
}

if (!function_exists('plp_load_arch_audit_state')) {
    /**
     * Load arch audit one pager state for pinned links panel.
     * @return array{ok:bool, badge:string, age_hours:?float, error:string, fix_command:string}
     */
    function plp_load_arch_audit_state(): array
    {
        $dir = svl_pipeline_dir();
        $onePagerPath = $dir . '/arch_audit_last_one_pager.md';
        $jsonPath = $dir . '/arch_audit_last.json';
        if (!is_file($onePagerPath)) {
            return [
                'ok' => false,
                'badge' => 'DATA_MISSING',
                'age_hours' => null,
                'error' => 'DATA_MISSING',
                'fix_command' => 'php tools/qa/arch_audit.php --run-id=auto --scope=repo --write-last',
            ];
        }
        $ageHours = tools_age_hours($onePagerPath);
        $badge = 'UNKNOWN';
        if (is_file($jsonPath)) {
            $raw = (string)@file_get_contents($jsonPath);
            $err = null;
            $data = tools_safe_json_decode($raw, $err);
            if (is_array($data)) {
                $domains = (array)($data['domains'] ?? []);
                $backup = (array)($domains['backup_dr'] ?? []);
                $monitoring = (array)($domains['monitoring'] ?? []);
                $backupStatus = strtoupper((string)($backup['status'] ?? ''));
                $monStatus = strtoupper((string)($monitoring['status'] ?? ''));
                if ($backupStatus === 'NOT_FOUND' || $monStatus === 'NOT_FOUND') {
                    $badge = 'CRITICAL';
                } elseif ($backupStatus === 'PARTIAL' || $monStatus === 'PARTIAL') {
                    $badge = 'ATTENTION';
                } else {
                    $badge = 'OK';
                }
            }
        }
        return [
            'ok' => true,
            'badge' => $badge,
            'age_hours' => $ageHours,
            'error' => '',
            'fix_command' => '',
        ];
    }
}
