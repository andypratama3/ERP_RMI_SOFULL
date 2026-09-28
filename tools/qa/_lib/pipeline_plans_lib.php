<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_lib.php';

if (!function_exists('ppl_plan_path')) {
    function ppl_plan_path(): string
    {
        return ppl_root() . '/tools/qa/plans/pipeline_plans.yaml';
    }
}

if (!function_exists('ppl_stage_id_valid')) {
    function ppl_stage_id_valid(string $stage): bool
    {
        return (bool)preg_match('/^(0[0-9]|1[0-6])$/', $stage);
    }
}

if (!function_exists('ppl_plan_template_yaml')) {
    function ppl_plan_template_yaml(): string
    {
        $stagesAll = [];
        for ($i = 0; $i <= 16; $i++) {
            $stagesAll[] = sprintf('"%02d"', $i);
        }
        $yaml = [];
        $yaml[] = 'state_version: 1';
        $yaml[] = 'plans:';
        for ($i = 0; $i <= 16; $i++) {
            $s = sprintf('%02d', $i);
            $yaml[] = '  - id: "stage' . $s . '_staging_draft"';
            $yaml[] = '    description: "Stage ' . $s . ' — staging draft checks"';
            $yaml[] = '    env: "staging"';
            $yaml[] = '    type: "single"';
            $yaml[] = '    stage: "' . $s . '"';
            $yaml[] = '    mode: "draft"';
            $yaml[] = '    strict: "auto"';
            $yaml[] = '    allow_autogen_whitelists: false';
            $yaml[] = '    require_tools_base_url: true';
            $yaml[] = '    release_verify_mode: "quick"';
            $yaml[] = '    core_flows_profile: "normal"';
            $yaml[] = '    require_rfc: false';
            $yaml[] = '    require_confirm: false';
            $yaml[] = '    stop_on_fail: true';
            $yaml[] = '    notes: "Safe default; no silent skip."';
        }
        $yaml[] = '  - id: "stage16_staging_strict"';
        $yaml[] = '    description: "Stage 16 — staging strict gate"';
        $yaml[] = '    env: "staging"';
        $yaml[] = '    type: "single"';
        $yaml[] = '    stage: "16"';
        $yaml[] = '    mode: "final"';
        $yaml[] = '    strict: true';
        $yaml[] = '    allow_autogen_whitelists: false';
        $yaml[] = '    require_tools_base_url: true';
        $yaml[] = '    release_verify_mode: "quick"';
        $yaml[] = '    core_flows_profile: "normal"';
        $yaml[] = '    require_rfc: false';
        $yaml[] = '    require_confirm: false';
        $yaml[] = '    stop_on_fail: true';

        $yaml[] = '  - id: "prod_stage16_final"';
        $yaml[] = '    description: "Stage 16 — Production Final Gate"';
        $yaml[] = '    env: "production"';
        $yaml[] = '    type: "single"';
        $yaml[] = '    stage: "16"';
        $yaml[] = '    mode: "final"';
        $yaml[] = '    strict: true';
        $yaml[] = '    allow_autogen_whitelists: false';
        $yaml[] = '    require_tools_base_url: true';
        $yaml[] = '    release_verify_mode: "full"';
        $yaml[] = '    core_flows_profile: "read-only"';
        $yaml[] = '    require_rfc: true';
        $yaml[] = '    require_confirm: true';
        $yaml[] = '    stop_on_fail: true';

        $yaml[] = '  - id: "full_staging_00_16"';
        $yaml[] = '    description: "Run Stage 00→16 sequential in staging (stop-on-fail), evidence per stage + 1 exec summary"';
        $yaml[] = '    env: "staging"';
        $yaml[] = '    type: "multi"';
        $yaml[] = '    stages: [' . implode(', ', $stagesAll) . ']';
        $yaml[] = '    mode: "draft"';
        $yaml[] = '    strict: false';
        $yaml[] = '    allow_autogen_whitelists: false';
        $yaml[] = '    require_tools_base_url: true';
        $yaml[] = '    release_verify_mode: "quick"';
        $yaml[] = '    core_flows_profile: "normal"';
        $yaml[] = '    require_rfc: false';
        $yaml[] = '    require_confirm: false';
        $yaml[] = '    stop_on_fail: true';
        $yaml[] = '    per_stage_run_id_suffix: true';
        $yaml[] = '    generate_plan_exec_summary: true';
        return implode("\n", $yaml) . "\n";
    }
}

if (!function_exists('ppl_parse_scalar')) {
    function ppl_parse_scalar(string $raw): mixed
    {
        $v = trim($raw);
        if ($v === '') return '';
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            return substr($v, 1, -1);
        }
        $l = strtolower($v);
        if (in_array($l, ['true', 'false'], true)) return $l === 'true';
        if ($l === 'null') return null;
        if (preg_match('/^-?\d+$/', $v)) return (int)$v;
        if (str_starts_with($v, '[') && str_ends_with($v, ']')) {
            $body = trim(substr($v, 1, -1));
            if ($body === '') return [];
            $parts = array_map('trim', explode(',', $body));
            $arr = [];
            foreach ($parts as $p) {
                $arr[] = ppl_parse_scalar($p);
            }
            return $arr;
        }
        return $v;
    }
}

if (!function_exists('ppl_parse_yaml_fallback')) {
    function ppl_parse_yaml_fallback(string $yaml): array
    {
        $state = ['state_version' => 1, 'plans' => []];
        $lines = preg_split("/\r\n|\n|\r/", $yaml) ?: [];
        $cur = null;
        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if (trim($line) === '' || str_starts_with(trim($line), '#')) continue;
            if (preg_match('/^\s*state_version\s*:\s*(.+)$/', $line, $m)) {
                $state['state_version'] = (int)ppl_parse_scalar((string)$m[1]);
                continue;
            }
            if (trim($line) === 'plans:') continue;
            if (preg_match('/^\s*-\s+id\s*:\s*(.+)$/', $line, $m)) {
                if (is_array($cur)) $state['plans'][] = $cur;
                $cur = ['id' => (string)ppl_parse_scalar((string)$m[1])];
                continue;
            }
            if (!is_array($cur)) continue;
            if (preg_match('/^\s+([a-zA-Z0-9_]+)\s*:\s*(.*)$/', $line, $m)) {
                $key = (string)$m[1];
                $value = (string)$m[2];
                $cur[$key] = ppl_parse_scalar($value);
            }
        }
        if (is_array($cur)) $state['plans'][] = $cur;
        return $state;
    }
}

if (!function_exists('load_plans_yaml')) {
    function load_plans_yaml(string $path): array
    {
        if (!is_file($path)) {
            $dir = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($path, ppl_plan_template_yaml());
            return [
                'ok' => false,
                'error' => 'plan_file_created',
                'created' => true,
                'path_masked' => ppl_mask($path),
                'data' => [],
            ];
        }
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            return ['ok' => false, 'error' => 'empty', 'created' => false, 'path_masked' => ppl_mask($path), 'data' => []];
        }
        try {
            if (function_exists('yaml_parse')) {
                $parsed = @yaml_parse($raw);
                if (!is_array($parsed)) {
                    return ['ok' => false, 'error' => 'invalid_yaml', 'created' => false, 'path_masked' => ppl_mask($path), 'data' => []];
                }
            } else {
                $parsed = ppl_parse_yaml_fallback($raw);
            }
            return ['ok' => true, 'error' => '', 'created' => false, 'path_masked' => ppl_mask($path), 'data' => $parsed];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'invalid_yaml', 'created' => false, 'path_masked' => ppl_mask($path), 'error_masked' => ppl_mask($e->getMessage()), 'data' => []];
        }
    }
}

if (!function_exists('ppl_arch_audit_config')) {
    /**
     * Normalize arch_audit config from plan. Supports nested arch_audit or flat arch_audit_* keys.
     * run_scope: "per_stage" | "plan_end" — per_stage for single, plan_end for multi (only if enabled).
     */
    function ppl_arch_audit_config(array $plan): array
    {
        $block = (array)($plan['arch_audit'] ?? []);
        $env = strtolower((string)($plan['env'] ?? 'staging'));
        $type = strtolower((string)($plan['type'] ?? 'single'));
        $enabled = (bool)($block['enabled'] ?? false) || (bool)($plan['arch_audit_enabled'] ?? false);
        $strict = (bool)($block['strict'] ?? false) || (bool)($plan['arch_audit_strict'] ?? false);
        $maxFiles = (int)($block['max_files'] ?? $plan['arch_audit_max_files'] ?? 5000);
        if ($maxFiles < 500) $maxFiles = 500;
        if ($maxFiles > 20000) $maxFiles = 20000;
        $required = (bool)($block['required'] ?? false) || (bool)($plan['arch_audit_required'] ?? false);
        $maxFiles = max(500, min(20000, $maxFiles));
        if ($required && !$enabled) $enabled = true;
        if ($env === 'production' && $enabled) {
            $required = false;
        }
        $runScope = strtolower(trim((string)($block['run_scope'] ?? $plan['arch_audit_run_scope'] ?? '')));
        if ($runScope === '') {
            $runScope = $type === 'multi' && $enabled ? 'plan_end' : 'per_stage';
        }
        if ($type === 'multi' && $enabled && $runScope !== 'plan_end') {
            $runScope = 'plan_end';
        }
        if ($type === 'single' && $runScope === 'plan_end') {
            $runScope = 'per_stage';
        }
        return [
            'enabled' => $enabled,
            'strict' => $strict,
            'max_files' => $maxFiles,
            'required' => $required,
            'run_scope' => $runScope,
            'notes' => (string)($block['notes'] ?? $plan['arch_audit_notes'] ?? ''),
        ];
    }
}

if (!function_exists('ppl_resolve_strict')) {
    function ppl_resolve_strict(array $plan): bool
    {
        $raw = $plan['strict'] ?? 'auto';
        if (is_bool($raw)) return $raw;
        $s = strtolower(trim((string)$raw));
        if (in_array($s, ['true', '1', 'yes', 'on'], true)) return true;
        if (in_array($s, ['false', '0', 'no', 'off'], true)) return false;
        return strtolower((string)($plan['env'] ?? 'staging')) === 'production';
    }
}

if (!function_exists('validate_plan_schema')) {
    function validate_plan_schema(array $plan): array
    {
        $errors = [];
        $id = trim((string)($plan['id'] ?? ''));
        if ($id === '') $errors[] = 'id_required';
        $env = strtolower((string)($plan['env'] ?? ''));
        if (!in_array($env, ['staging', 'production'], true)) $errors[] = 'env_invalid';
        $type = strtolower((string)($plan['type'] ?? ''));
        if (!in_array($type, ['single', 'multi'], true)) $errors[] = 'type_invalid';
        $mode = strtolower((string)($plan['mode'] ?? 'draft'));
        if (!in_array($mode, ['draft', 'final'], true)) $errors[] = 'mode_invalid';
        $rvm = strtolower((string)($plan['release_verify_mode'] ?? 'quick'));
        if (!in_array($rvm, ['quick', 'full'], true)) $errors[] = 'release_verify_mode_invalid';
        $cfp = strtolower((string)($plan['core_flows_profile'] ?? 'normal'));
        if (!in_array($cfp, ['normal', 'read-only'], true)) $errors[] = 'core_flows_profile_invalid';

        if ($type === 'single') {
            $stage = (string)($plan['stage'] ?? '');
            if (!ppl_stage_id_valid($stage)) $errors[] = 'single_stage_invalid';
        }
        if ($type === 'multi') {
            $stages = $plan['stages'] ?? null;
            if (!is_array($stages) || $stages === []) {
                $errors[] = 'multi_stages_required';
            } else {
                foreach ($stages as $s) {
                    if (!ppl_stage_id_valid((string)$s)) {
                        $errors[] = 'multi_stage_invalid:' . (string)$s;
                    }
                }
            }
        }

        $strictRaw = $plan['strict'] ?? 'auto';
        if (!(is_bool($strictRaw) || in_array(strtolower((string)$strictRaw), ['auto', 'true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true))) {
            $errors[] = 'strict_invalid';
        }
        $profile = strtolower(trim((string)($plan['profile'] ?? '')));
        if ($profile !== '') {
            $allowedProfiles = ['full', 'pr_gate', 'pr_gate_strict_http', 'pr_gate_security_lite', 'pr_gate_migration_hygiene', 'nightly_staging_full', 'release_candidate_staging', 'ui_regression_optional', 'mobile_contract_gate', 'ops_freshness_gate'];
            if (!in_array($profile, $allowedProfiles, true)) {
                $errors[] = 'profile_invalid:' . $profile;
            }
            if (in_array($profile, ['pr_gate', 'pr_gate_strict_http', 'pr_gate_security_lite', 'pr_gate_migration_hygiene', 'nightly_staging_full', 'release_candidate_staging', 'ui_regression_optional', 'mobile_contract_gate', 'ops_freshness_gate'], true) && $env !== 'staging') {
                $errors[] = 'profile_' . $profile . '_staging_only';
            }
            if (in_array($profile, ['pr_gate', 'pr_gate_strict_http', 'pr_gate_security_lite', 'pr_gate_migration_hygiene', 'nightly_staging_full', 'release_candidate_staging', 'ui_regression_optional', 'mobile_contract_gate'], true) && (($plan['require_tools_base_url'] ?? false) !== true)) {
                $errors[] = 'profile_require_tools_base_url_true';
            }
        }
        if ($env === 'production' && $mode === 'final') {
            if (($plan['require_rfc'] ?? false) !== true) $errors[] = 'production_final_require_rfc_true';
            if (($plan['require_confirm'] ?? false) !== true) $errors[] = 'production_final_require_confirm_true';
        }
        if ($env === 'production' && (($plan['allow_autogen_whitelists'] ?? false) === true)) {
            $errors[] = 'production_autogen_whitelist_forbidden';
        }
        $arch = (array)($plan['arch_audit'] ?? []);
        if ($arch !== [] || isset($plan['arch_audit_enabled'])) {
            $enabled = (bool)($arch['enabled'] ?? $plan['arch_audit_enabled'] ?? false);
            $maxFiles = (int)($arch['max_files'] ?? $plan['arch_audit_max_files'] ?? 5000);
            $required = (bool)($arch['required'] ?? $plan['arch_audit_required'] ?? false);
            if ($maxFiles === 0) $maxFiles = 5000;
            if ($required && !$enabled) $errors[] = 'arch_audit_required_implies_enabled';
            if ($maxFiles < 500 || $maxFiles > 20000) $errors[] = 'arch_audit_max_files_range';
            $runScope = strtolower(trim((string)($arch['run_scope'] ?? $plan['arch_audit_run_scope'] ?? '')));
            if ($type === 'multi' && $enabled && $runScope !== '' && $runScope !== 'plan_end') {
                $errors[] = 'ERR_INVALID_RUN_SCOPE_FOR_MULTI';
            }
        }
        return $errors;
    }
}

if (!function_exists('ppl_validate_all_plans')) {
    function ppl_validate_all_plans(array $data): array
    {
        $errors = [];
        if (!isset($data['plans']) || !is_array($data['plans'])) {
            $errors[] = 'plans_array_required';
            return $errors;
        }
        $seen = [];
        foreach ($data['plans'] as $idx => $plan) {
            if (!is_array($plan)) {
                $errors[] = 'plan_not_object_at_index_' . $idx;
                continue;
            }
            $id = (string)($plan['id'] ?? '');
            if ($id !== '') {
                if (isset($seen[$id])) $errors[] = 'duplicate_plan_id:' . $id;
                $seen[$id] = true;
            }
            foreach (validate_plan_schema($plan) as $e) {
                $errors[] = 'plan[' . $id . ']:' . $e;
            }
        }
        return $errors;
    }
}

if (!function_exists('ppl_write_validate_last')) {
    function ppl_write_validate_last(array $payload): void
    {
        $dir = ppl_pipeline_dir();
        ppl_write_json($dir . '/pipeline_plan_validate_last.json', $payload);
        $lines = [];
        $lines[] = '# Pipeline Plan Validation';
        $lines[] = '';
        $lines[] = '- generated_at: ' . ppl_mask((string)($payload['generated_at'] ?? ''));
        $lines[] = '- ok: ' . (((bool)($payload['ok'] ?? false)) ? 'true' : 'false');
        $lines[] = '- plan_id: ' . ppl_mask((string)($payload['plan_id'] ?? ''));
        $lines[] = '- error: ' . ppl_mask((string)($payload['error'] ?? ''));
        $lines[] = '';
        foreach ((array)($payload['errors'] ?? []) as $e) {
            $lines[] = '- ' . ppl_mask((string)$e);
        }
        @file_put_contents($dir . '/pipeline_plan_validate_last.md', implode("\n", $lines) . "\n");
    }
}

if (!function_exists('get_plan')) {
    function get_plan(string $planId, ?string $path = null): array
    {
        $path = $path ?: ppl_plan_path();
        $loaded = load_plans_yaml($path);
        if (!$loaded['ok']) {
            $report = [
                'state_version' => 1,
                'generated_at' => date(DateTimeInterface::ATOM),
                'ok' => false,
                'plan_id' => $planId,
                'error' => (string)$loaded['error'],
                'errors' => [(string)$loaded['error']],
                'path_masked' => (string)($loaded['path_masked'] ?? ''),
            ];
            ppl_write_validate_last($report);
            return ['ok' => false, 'error' => (string)$loaded['error'], 'created' => (bool)($loaded['created'] ?? false), 'plan' => [], 'path_masked' => (string)($loaded['path_masked'] ?? '')];
        }
        $errors = ppl_validate_all_plans((array)$loaded['data']);
        if ($errors !== []) {
            $report = [
                'state_version' => 1,
                'generated_at' => date(DateTimeInterface::ATOM),
                'ok' => false,
                'plan_id' => $planId,
                'error' => 'schema_invalid',
                'errors' => array_values(array_map(static fn(string $v): string => ppl_mask($v), $errors)),
                'path_masked' => (string)$loaded['path_masked'],
            ];
            ppl_write_validate_last($report);
            return ['ok' => false, 'error' => 'schema_invalid', 'created' => false, 'plan' => [], 'path_masked' => (string)$loaded['path_masked']];
        }
        foreach ((array)$loaded['data']['plans'] as $plan) {
            if (!is_array($plan)) continue;
            if ((string)($plan['id'] ?? '') !== $planId) continue;
            $plan['strict_resolved'] = ppl_resolve_strict($plan);
            return ['ok' => true, 'error' => '', 'created' => false, 'plan' => $plan, 'path_masked' => (string)$loaded['path_masked']];
        }
        $report = [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'ok' => false,
            'plan_id' => $planId,
            'error' => 'plan_not_found',
            'errors' => ['plan_not_found:' . $planId],
            'path_masked' => (string)$loaded['path_masked'],
        ];
        ppl_write_validate_last($report);
        return ['ok' => false, 'error' => 'plan_not_found', 'created' => false, 'plan' => [], 'path_masked' => (string)$loaded['path_masked']];
    }
}

if (!function_exists('list_plans')) {
    function list_plans(?string $path = null): array
    {
        $path = $path ?: ppl_plan_path();
        $loaded = load_plans_yaml($path);
        if (!$loaded['ok']) return [];
        $rows = [];
        foreach ((array)$loaded['data']['plans'] as $p) {
            if (!is_array($p)) continue;
            $rows[] = [
                'id' => (string)($p['id'] ?? ''),
                'description' => (string)($p['description'] ?? ''),
                'env' => (string)($p['env'] ?? ''),
                'type' => (string)($p['type'] ?? ''),
                'mode' => (string)($p['mode'] ?? ''),
                'strict_resolved' => ppl_resolve_strict($p),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));
        return $rows;
    }
}

