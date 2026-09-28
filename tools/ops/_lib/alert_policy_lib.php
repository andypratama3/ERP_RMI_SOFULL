<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/../../tools_ui_helpers.php';

if (!function_exists('ap_root')) {
    function ap_root(): string
    {
        return ts_root();
    }
}

if (!function_exists('ap_mask')) {
    function ap_mask(string $text): string
    {
        return ts_mask(tools_mask_sensitive($text));
    }
}

if (!function_exists('ap_docs_policy_path')) {
    function ap_docs_policy_path(): string
    {
        return ap_root() . '/docs/governance/ALERTING_POLICY.yaml';
    }
}

if (!function_exists('ap_active_policy_path')) {
    function ap_active_policy_path(): string
    {
        return ap_root() . '/storage/state/alerting_policy_current.yaml';
    }
}

if (!function_exists('mask_sensitive_in_errors')) {
    function mask_sensitive_in_errors(array $errors): array
    {
        return array_values(array_unique(array_map(static fn(string $e): string => ap_mask($e), $errors)));
    }
}

if (!function_exists('safe_yaml_parse')) {
    function safe_yaml_parse(string $raw, bool $strict = true): array
    {
        if (!function_exists('yaml_parse')) {
            return ['ok' => false, 'data' => [], 'errors' => ['ERR_YAML_EXTENSION_MISSING']];
        }
        $parsed = @yaml_parse($raw);
        if (!is_array($parsed)) {
            return ['ok' => false, 'data' => [], 'errors' => ['ERR_YAML_PARSE']];
        }
        return ['ok' => true, 'data' => $parsed, 'errors' => []];
    }
}

if (!function_exists('ap_contains_forbidden_keys')) {
    function ap_contains_forbidden_keys(array $obj, string $prefix = ''): array
    {
        $hits = [];
        foreach ($obj as $k => $v) {
            $key = strtolower((string)$k);
            $path = $prefix === '' ? (string)$k : ($prefix . '.' . (string)$k);
            if (str_contains($key, 'password') || str_contains($key, 'token') || str_contains($key, 'secret')) {
                $hits[] = 'ERR_FORBIDDEN_KEY:' . $path;
            }
            if (is_array($v)) {
                $hits = array_merge($hits, ap_contains_forbidden_keys($v, $path));
            }
        }
        return $hits;
    }
}

if (!function_exists('validate_policy_struct')) {
    function validate_policy_struct(array $obj): array
    {
        $errors = [];
        if (!isset($obj['sla_defaults']) || !is_array($obj['sla_defaults'])) $errors[] = 'ERR_SCHEMA_SLA_DEFAULTS';
        foreach (['CRITICAL', 'ATTENTION'] as $sev) {
            $ack = (int)($obj['sla_defaults'][$sev]['ack_minutes'] ?? 0);
            $res = (int)($obj['sla_defaults'][$sev]['resolve_minutes'] ?? 0);
            if ($ack <= 0) $errors[] = 'ERR_SCHEMA_ACK_MINUTES_' . $sev;
            if ($res <= 0) $errors[] = 'ERR_SCHEMA_RESOLVE_MINUTES_' . $sev;
        }
        if (!isset($obj['owners']) || !is_array($obj['owners'])) $errors[] = 'ERR_SCHEMA_OWNERS';
        if (!isset($obj['owners']['default_owner'])) $errors[] = 'ERR_SCHEMA_DEFAULT_OWNER';
        if (!isset($obj['owners']['default_secondary'])) $errors[] = 'ERR_SCHEMA_DEFAULT_SECONDARY';
        if (!isset($obj['owners']['rules']) || !is_array($obj['owners']['rules'])) $errors[] = 'ERR_SCHEMA_OWNER_RULES';
        if (!isset($obj['escalation']) || !is_array($obj['escalation'])) $errors[] = 'ERR_SCHEMA_ESCALATION';
        if (!isset($obj['workflow']) || !is_array($obj['workflow'])) $errors[] = 'ERR_SCHEMA_WORKFLOW';

        // Additional safety constraints.
        $emailEnabledDefault = (bool)($obj['email']['enabled_default'] ?? false);
        if ($emailEnabledDefault !== false) $errors[] = 'ERR_EMAIL_DEFAULT_MUST_BE_OFF';
        $throttleEnabled = (bool)($obj['throttle']['enabled'] ?? false);
        if ($throttleEnabled !== true) $errors[] = 'ERR_THROTTLE_MUST_BE_ENABLED';
        foreach (['CRITICAL', 'ATTENTION'] as $sev) {
            $cd = (int)($obj['throttle']['cooldown_minutes_by_severity'][$sev] ?? 0);
            if ($cd <= 0) $errors[] = 'ERR_COOLDOWN_MISSING_' . $sev;
        }
        $errors = array_merge($errors, ap_contains_forbidden_keys($obj));
        return array_values(array_unique($errors));
    }
}

if (!function_exists('ap_ksort_recursive')) {
    function ap_ksort_recursive(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if (is_array($v)) $arr[$k] = ap_ksort_recursive($v);
        }
        ksort($arr);
        return $arr;
    }
}

if (!function_exists('canonicalize_yaml_for_hash')) {
    function canonicalize_yaml_for_hash(array $obj): string
    {
        $sorted = ap_ksort_recursive($obj);
        return json_encode($sorted, JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}

if (!function_exists('policy_fingerprint')) {
    function policy_fingerprint(string $yamlCanonical): string
    {
        return hash('sha256', $yamlCanonical);
    }
}

if (!function_exists('ap_default_policy_fallback')) {
    function ap_default_policy_fallback(): array
    {
        return [
            'state_version' => 1,
            'policy_id' => 'ALERT-POLICY-BASELINE',
            'email' => ['enabled_default' => false],
            'throttle' => [
                'enabled' => true,
                'cooldown_minutes_by_severity' => ['CRITICAL' => 60, 'ATTENTION' => 240],
            ],
            'sla_defaults' => [
                'CRITICAL' => ['ack_minutes' => 30, 'resolve_minutes' => 240],
                'ATTENTION' => ['ack_minutes' => 240, 'resolve_minutes' => 1440],
            ],
            'owners' => ['default_owner' => 'TBD', 'default_secondary' => 'TBD', 'rules' => []],
            'escalation' => [
                'enabled' => true,
                'banner_add_sla_breach_badge' => true,
                'email_on_sla_breach' => false,
                'create_manual_action_queue' => false,
                'max_escalation_levels' => 3,
                'include_owner_in_non_admin_banner' => true,
            ],
            'workflow' => [
                'allow_ack' => true,
                'allow_status_update' => true,
                'allow_owner_override' => false,
                'statuses' => ['OPEN', 'ACKNOWLEDGED', 'IN_PROGRESS', 'RESOLVED', 'SUPPRESSED'],
                'suppress_rules' => ['enabled' => false, 'note' => 'Do not suppress CRITICAL in production without RFC'],
            ],
        ];
    }
}

if (!function_exists('ap_load_policy_file')) {
    function ap_load_policy_file(string $path): array
    {
        if (!is_file($path)) return ['ok' => false, 'data' => [], 'errors' => ['ERR_POLICY_FILE_MISSING'], 'raw' => ''];
        $raw = (string)@file_get_contents($path);
        $parsed = safe_yaml_parse($raw, true);
        if (!$parsed['ok']) return ['ok' => false, 'data' => [], 'errors' => $parsed['errors'], 'raw' => $raw];
        $obj = (array)$parsed['data'];
        $errors = validate_policy_struct($obj);
        return ['ok' => $errors === [], 'data' => $obj, 'errors' => $errors, 'raw' => $raw];
    }
}

if (!function_exists('load_alert_policy')) {
    function load_alert_policy(string $env): array
    {
        $env = strtolower($env);
        $activePath = ap_active_policy_path();
        $docsPath = ap_docs_policy_path();

        if ($env === 'production') {
            $active = ap_load_policy_file($activePath);
            if (!$active['ok']) {
                return [
                    'ok' => false,
                    'policy' => ap_default_policy_fallback(),
                    'source' => 'ACTIVE',
                    'path_masked' => ap_mask($activePath),
                    'errors' => mask_sensitive_in_errors((array)$active['errors']),
                    'policy_fingerprint' => policy_fingerprint(canonicalize_yaml_for_hash(ap_default_policy_fallback())),
                    'policy_id' => 'ALERT-POLICY-FALLBACK',
                    'policy_updated_at' => '',
                    'policy_updated_by' => '',
                ];
            }
            $policy = (array)$active['data'];
            $canonical = canonicalize_yaml_for_hash($policy);
            return [
                'ok' => true,
                'policy' => $policy,
                'source' => 'ACTIVE',
                'path_masked' => ap_mask($activePath),
                'errors' => [],
                'policy_fingerprint' => policy_fingerprint($canonical),
                'policy_id' => (string)($policy['policy_id'] ?? ''),
                'policy_updated_at' => (string)($policy['updated_at'] ?? ''),
                'policy_updated_by' => (string)($policy['updated_by'] ?? ''),
            ];
        }

        // staging: active-first fallback docs
        $active = ap_load_policy_file($activePath);
        if ($active['ok']) {
            $policy = (array)$active['data'];
            $canonical = canonicalize_yaml_for_hash($policy);
            return [
                'ok' => true,
                'policy' => $policy,
                'source' => 'ACTIVE',
                'path_masked' => ap_mask($activePath),
                'errors' => [],
                'policy_fingerprint' => policy_fingerprint($canonical),
                'policy_id' => (string)($policy['policy_id'] ?? ''),
                'policy_updated_at' => (string)($policy['updated_at'] ?? ''),
                'policy_updated_by' => (string)($policy['updated_by'] ?? ''),
            ];
        }
        $docs = ap_load_policy_file($docsPath);
        if ($docs['ok']) {
            $policy = (array)$docs['data'];
            $canonical = canonicalize_yaml_for_hash($policy);
            return [
                'ok' => true,
                'policy' => $policy,
                'source' => 'DOCS',
                'path_masked' => ap_mask($docsPath),
                'errors' => [],
                'policy_fingerprint' => policy_fingerprint($canonical),
                'policy_id' => (string)($policy['policy_id'] ?? ''),
                'policy_updated_at' => (string)($policy['updated_at'] ?? ''),
                'policy_updated_by' => (string)($policy['updated_by'] ?? ''),
            ];
        }
        $fallback = ap_default_policy_fallback();
        return [
            'ok' => false,
            'policy' => $fallback,
            'source' => 'DOCS',
            'path_masked' => ap_mask($docsPath),
            'errors' => mask_sensitive_in_errors((array)$docs['errors']),
            'policy_fingerprint' => policy_fingerprint(canonicalize_yaml_for_hash($fallback)),
            'policy_id' => 'ALERT-POLICY-FALLBACK',
            'policy_updated_at' => '',
            'policy_updated_by' => '',
        ];
    }
}

