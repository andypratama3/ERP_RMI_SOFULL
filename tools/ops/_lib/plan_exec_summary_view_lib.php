<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../tools/tools_ui_helpers.php';

if (!function_exists('pesv_pipeline_dir')) {
    function pesv_pipeline_dir(): string
    {
        $root = tools_app_root();
        $dir = $root . '/storage/logs/pipeline';
        return $dir;
    }
}

if (!function_exists('pesv_resolve_path')) {
    /**
     * Resolve safe path for plan exec summary file.
     * Returns ['ok'=>bool, 'path'=>string, 'error'=>string]
     */
    function pesv_resolve_path(string $mode, string $file, ?string $planId = null, ?string $masterRunId = null): array
    {
        $dir = pesv_pipeline_dir();
        $base = realpath($dir);
        if ($base === false || !is_dir($base)) {
            return ['ok' => false, 'path' => '', 'error' => 'pipeline_dir_missing'];
        }
        $allowedExt = ['json', 'md', 'html'];
        if (!in_array($mode, $allowedExt, true)) {
            return ['ok' => false, 'path' => '', 'error' => 'invalid_mode'];
        }
        if ($file === 'last') {
            $rel = 'plan_exec_summary_last.' . $mode;
        } elseif ($file === 'run') {
            if ($planId === null || $planId === '' || $masterRunId === null || $masterRunId === '') {
                return ['ok' => false, 'path' => '', 'error' => 'plan_id_run_id_required'];
            }
            if (!preg_match('/^[A-Za-z0-9_\-]+$/', $planId) || !preg_match('/^[A-Za-z0-9_\-]+$/', $masterRunId)) {
                return ['ok' => false, 'path' => '', 'error' => 'invalid_plan_id_or_run_id'];
            }
            $rel = 'plan_exec_summary_' . $planId . '_' . $masterRunId . '.' . $mode;
        } else {
            return ['ok' => false, 'path' => '', 'error' => 'invalid_file_param'];
        }
        $path = $base . '/' . $rel;
        $resolved = realpath($path);
        if ($resolved === false) {
            // Fallback: mode=md + file=last → render from plan_exec_summary_last.json
            if ($mode === 'md' && $file === 'last') {
                $jsonPath = $base . '/plan_exec_summary_last.json';
                $jsonResolved = realpath($jsonPath);
                if ($jsonResolved !== false && str_starts_with($jsonResolved, $base)) {
                    return ['ok' => true, 'path' => $jsonResolved, 'error' => '', 'render_md_from_json' => true];
                }
            }
            return ['ok' => false, 'path' => $path, 'error' => 'file_not_found'];
        }
        if (!str_starts_with($resolved, $base)) {
            return ['ok' => false, 'path' => '', 'error' => 'path_traversal'];
        }
        return ['ok' => true, 'path' => $resolved, 'error' => '', 'render_md_from_json' => false];
    }
}

if (!function_exists('pesv_read_safe')) {
    function pesv_read_safe(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'content' => '', 'error' => 'unreadable'];
        }
        $content = (string)@file_get_contents($path);
        return ['ok' => true, 'content' => $content, 'error' => ''];
    }
}

if (!function_exists('pesv_deny_patterns')) {
    function pesv_deny_patterns(): array
    {
        return ['/Users/', 'C:\\', 'BEGIN PRIVATE KEY', 'Authorization:', 'DB_PASS'];
    }
}

if (!function_exists('pesv_has_deny_pattern')) {
    function pesv_has_deny_pattern(string $content): bool
    {
        foreach (pesv_deny_patterns() as $p) {
            if (str_contains($content, $p)) return true;
        }
        return false;
    }
}

if (!function_exists('pesv_mask_recursive')) {
    function pesv_mask_recursive(mixed $v): mixed
    {
        if (is_string($v)) {
            return tools_mask_sensitive($v);
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $out[$k] = pesv_mask_recursive($val);
            }
            return $out;
        }
        return $v;
    }
}

if (!function_exists('pesv_load_panel_state')) {
    /**
     * Load plan exec summary state for control center panel.
     * Returns ['ok'=>bool, 'data'=>array, 'error'=>string, 'per_run_exists'=>bool, 'state_mismatch'=>bool, 'age_hours'=>...]
     */
    function pesv_load_panel_state(): array
    {
        $dir = pesv_pipeline_dir();
        $lastPath = $dir . '/plan_exec_summary_last.json';
        if (!is_file($lastPath)) {
            return [
                'ok' => false,
                'data' => [],
                'error' => 'MISSING',
                'per_run_exists' => false,
                'state_mismatch' => false,
                'age_hours' => null,
            ];
        }
        $raw = (string)@file_get_contents($lastPath);
        $err = null;
        $data = tools_safe_json_decode($raw, $err);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'data' => [],
                'error' => 'STATE_CORRUPT',
                'per_run_exists' => false,
                'state_mismatch' => false,
                'age_hours' => tools_age_hours($lastPath),
            ];
        }
        $planId = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($data['plan_id'] ?? ''));
        $masterRunId = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($data['master_run_id'] ?? ''));
        $perRunPath = $dir . '/plan_exec_summary_' . $planId . '_' . $masterRunId . '.json';
        $perRunExists = is_file($perRunPath);
        $stateMismatch = false;
        if ($perRunExists) {
            $perRunRaw = (string)@file_get_contents($perRunPath);
            $perRunData = tools_safe_json_decode($perRunRaw, $perRunErr);
            if (is_array($perRunData)) {
                $lastPlanId = (string)($data['plan_id'] ?? '');
                $lastRunId = (string)($data['master_run_id'] ?? '');
                $perPlanId = (string)($perRunData['plan_id'] ?? '');
                $perRunId = (string)($perRunData['master_run_id'] ?? '');
                if ($lastPlanId !== $perPlanId || $lastRunId !== $perRunId) {
                    $stateMismatch = true;
                }
            }
        }
        return [
            'ok' => true,
            'data' => $data,
            'error' => '',
            'per_run_exists' => $perRunExists,
            'state_mismatch' => $stateMismatch,
            'age_hours' => tools_age_hours($lastPath),
        ];
    }
}
