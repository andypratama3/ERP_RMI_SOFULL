<?php
declare(strict_types=1);

require_once __DIR__ . '/ops_helpers.php';

if (!function_exists('ops_thresholds_baseline_path')) {
    function ops_thresholds_baseline_path(): string
    {
        return ops_root() . '/docs/governance/OPS_THRESHOLDS.yaml';
    }
}

if (!function_exists('ops_thresholds_active_path')) {
    function ops_thresholds_active_path(): string
    {
        return ops_root() . '/storage/state/ops_thresholds_current.yaml';
    }
}

if (!function_exists('ops_thresholds_audit_log_path')) {
    function ops_thresholds_audit_log_path(): string
    {
        return ops_root() . '/storage/logs/audit_ops_thresholds.log';
    }
}

if (!function_exists('ops_thresholds_validate_last_json_path')) {
    function ops_thresholds_validate_last_json_path(): string
    {
        return ops_root() . '/storage/logs/pipeline/ops_thresholds_validate_last.json';
    }
}

if (!function_exists('ops_thresholds_validate_last_md_path')) {
    function ops_thresholds_validate_last_md_path(): string
    {
        return ops_root() . '/storage/logs/pipeline/ops_thresholds_validate_last.md';
    }
}

if (!function_exists('ops_thresholds_apply_last_json_path')) {
    function ops_thresholds_apply_last_json_path(): string
    {
        return ops_root() . '/storage/logs/pipeline/ops_thresholds_apply_last.json';
    }
}

if (!function_exists('ops_thresholds_apply_last_md_path')) {
    function ops_thresholds_apply_last_md_path(): string
    {
        return ops_root() . '/storage/logs/pipeline/ops_thresholds_apply_last.md';
    }
}

if (!function_exists('ops_thresholds_defaults')) {
    function ops_thresholds_defaults(): array
    {
        return [
            'state_version' => 1,
            'policy_id' => 'OPS_THRESHOLDS_V1',
            'updated_at' => 'AUTO_ON_APPLY',
            'updated_by' => 'AUTO_ON_APPLY',
            'notes' => 'Ops thresholds baseline',
            'go_no_go' => [
                'require_readiness_score_100' => true,
                'require_backlog_p0_zero' => true,
                'require_contract_ok' => true,
                'require_smoke_http_fail_zero' => true,
                'require_core_flows_ok' => true,
                'production_requires_backup_age_hours_max' => 24,
            ],
            'ops_score' => [
                'base_score_source' => 'readiness',
                'fixed_base_score' => 100,
                'critical_blockers' => [
                    'contract_fail_score' => 0,
                    'smoke_http_fail_score' => 0,
                    'core_flows_fail_score' => 0,
                ],
                'penalties' => [
                    'backlog_p0' => ['per_item' => 10, 'cap' => 30],
                    'backlog_p1' => ['per_item' => 1, 'cap' => 10],
                    'smoke_http_warn' => ['per_item' => 1, 'cap' => 10],
                    'backup_age' => [
                        'staging' => ['warn_hours' => 24, 'warn_penalty' => 5, 'critical_hours' => 48, 'critical_penalty' => 10],
                        'production' => ['warn_hours' => 24, 'warn_penalty' => 10, 'critical_hours' => 48, 'critical_penalty' => 20],
                    ],
                    'sla_breach_penalty' => 10,
                ],
            ],
            'status_thresholds' => [
                'healthy_min' => 90,
                'attention_min' => 70,
            ],
            'retention' => [
                'history_days' => 90,
                'rotate_max_bytes' => 5242880,
            ],
            'rendering' => [
                'show_policy_id_in_exec_summary' => true,
                'show_policy_updated_at' => true,
            ],
        ];
    }
}

if (!function_exists('ops_thresholds_parse_scalar')) {
    function ops_thresholds_parse_scalar(string $value): mixed
    {
        $v = trim($value);
        if ($v === '') return '';
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            return substr($v, 1, -1);
        }
        $lv = strtolower($v);
        if ($lv === 'true') return true;
        if ($lv === 'false') return false;
        if (preg_match('/^-?\d+$/', $v) === 1) return (int)$v;
        return $v;
    }
}

if (!function_exists('ops_thresholds_parse_yaml_fallback')) {
    function ops_thresholds_parse_yaml_fallback(string $yaml): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $yaml) ?: [];
        $root = [];
        $stack = [0 => &$root];
        foreach ($lines as $lineNo => $lineRaw) {
            $line = rtrim((string)$lineRaw);
            if (trim($line) === '') continue;
            if (preg_match('/^\s*#/', $line) === 1) continue;
            if (preg_match('/^(\s*)- /', $line) === 1) {
                throw new RuntimeException('Unsupported YAML list at line ' . ($lineNo + 1));
            }
            if (preg_match('/^(\s*)([A-Za-z0-9_]+)\s*:\s*(.*)$/', $line, $m) !== 1) {
                throw new RuntimeException('Invalid YAML line ' . ($lineNo + 1));
            }
            $indent = strlen($m[1]);
            if (($indent % 2) !== 0) {
                throw new RuntimeException('Invalid indentation at line ' . ($lineNo + 1));
            }
            $level = (int)($indent / 2);
            $key = (string)$m[2];
            $rawVal = (string)$m[3];
            while (count($stack) - 1 > $level) {
                array_pop($stack);
            }
            if (!isset($stack[$level])) {
                throw new RuntimeException('Invalid nesting at line ' . ($lineNo + 1));
            }
            if ($rawVal === '') {
                $stack[$level][$key] = [];
                $stack[$level + 1] = &$stack[$level][$key];
            } else {
                $hashPos = strpos($rawVal, '#');
                if ($hashPos !== false && !str_contains($rawVal, '"') && !str_contains($rawVal, "'")) {
                    $rawVal = rtrim(substr($rawVal, 0, $hashPos));
                }
                $stack[$level][$key] = ops_thresholds_parse_scalar($rawVal);
            }
        }
        return $root;
    }
}

if (!function_exists('ops_thresholds_parse_yaml')) {
    function ops_thresholds_parse_yaml(string $yaml): array
    {
        if (function_exists('yaml_parse')) {
            $parsed = @yaml_parse($yaml);
            if (!is_array($parsed)) {
                throw new RuntimeException('yaml_parse failed');
            }
            return $parsed;
        }
        return ops_thresholds_parse_yaml_fallback($yaml);
    }
}

if (!function_exists('ops_thresholds_dump_yaml_node')) {
    function ops_thresholds_dump_yaml_node(array $node, int $level = 0): string
    {
        $out = '';
        ksort($node);
        foreach ($node as $k => $v) {
            $indent = str_repeat('  ', $level);
            if (is_array($v)) {
                $out .= $indent . $k . ":\n" . ops_thresholds_dump_yaml_node($v, $level + 1);
            } else {
                if (is_bool($v)) $sv = $v ? 'true' : 'false';
                elseif (is_int($v) || is_float($v)) $sv = (string)$v;
                else {
                    $sv = (string)$v;
                    $sv = '"' . str_replace('"', '\"', $sv) . '"';
                }
                $out .= $indent . $k . ': ' . $sv . "\n";
            }
        }
        return $out;
    }
}

if (!function_exists('ops_thresholds_dump_yaml')) {
    function ops_thresholds_dump_yaml(array $policy): string
    {
        return ops_thresholds_dump_yaml_node($policy, 0);
    }
}

if (!function_exists('ops_thresholds_fingerprint')) {
    function ops_thresholds_fingerprint(array $policy): string
    {
        return hash('sha256', ops_thresholds_dump_yaml($policy));
    }
}

if (!function_exists('policy_fingerprint')) {
    function policy_fingerprint(array $policy): string
    {
        return ops_thresholds_fingerprint($policy);
    }
}

if (!function_exists('ops_thresholds_disallow_secret_keys')) {
    function ops_thresholds_disallow_secret_keys(array $node, string $prefix = ''): array
    {
        $errors = [];
        foreach ($node as $k => $v) {
            $path = $prefix === '' ? (string)$k : ($prefix . '.' . (string)$k);
            $lk = strtolower((string)$k);
            if (str_contains($lk, 'password') || str_contains($lk, 'token') || str_contains($lk, 'secret')) {
                $errors[] = 'forbidden_key:' . $path;
            }
            if (is_array($v)) {
                $errors = array_merge($errors, ops_thresholds_disallow_secret_keys($v, $path));
            }
        }
        return $errors;
    }
}

if (!function_exists('ops_thresholds_type_check')) {
    function ops_thresholds_type_check(array $obj, string $path, string $type): array
    {
        $parts = explode('.', $path);
        $cur = $obj;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return ['missing:' . $path];
            }
            $cur = $cur[$p];
        }
        $ok = match ($type) {
            'int' => is_int($cur),
            'bool' => is_bool($cur),
            'string' => is_string($cur),
            default => false,
        };
        return $ok ? [] : ['type:' . $path . ':' . $type];
    }
}

if (!function_exists('validate_ops_thresholds_struct')) {
    function validate_ops_thresholds_struct(array $obj): array
    {
        $errors = [];
        $checks = [
            ['state_version', 'int'],
            ['policy_id', 'string'],
            ['updated_at', 'string'],
            ['updated_by', 'string'],
            ['notes', 'string'],
            ['go_no_go.require_readiness_score_100', 'bool'],
            ['go_no_go.require_backlog_p0_zero', 'bool'],
            ['go_no_go.require_contract_ok', 'bool'],
            ['go_no_go.require_smoke_http_fail_zero', 'bool'],
            ['go_no_go.require_core_flows_ok', 'bool'],
            ['go_no_go.production_requires_backup_age_hours_max', 'int'],
            ['ops_score.base_score_source', 'string'],
            ['ops_score.fixed_base_score', 'int'],
            ['ops_score.critical_blockers.contract_fail_score', 'int'],
            ['ops_score.critical_blockers.smoke_http_fail_score', 'int'],
            ['ops_score.critical_blockers.core_flows_fail_score', 'int'],
            ['ops_score.penalties.backlog_p0.per_item', 'int'],
            ['ops_score.penalties.backlog_p0.cap', 'int'],
            ['ops_score.penalties.backlog_p1.per_item', 'int'],
            ['ops_score.penalties.backlog_p1.cap', 'int'],
            ['ops_score.penalties.smoke_http_warn.per_item', 'int'],
            ['ops_score.penalties.smoke_http_warn.cap', 'int'],
            ['ops_score.penalties.backup_age.staging.warn_hours', 'int'],
            ['ops_score.penalties.backup_age.staging.warn_penalty', 'int'],
            ['ops_score.penalties.backup_age.staging.critical_hours', 'int'],
            ['ops_score.penalties.backup_age.staging.critical_penalty', 'int'],
            ['ops_score.penalties.backup_age.production.warn_hours', 'int'],
            ['ops_score.penalties.backup_age.production.warn_penalty', 'int'],
            ['ops_score.penalties.backup_age.production.critical_hours', 'int'],
            ['ops_score.penalties.backup_age.production.critical_penalty', 'int'],
            ['ops_score.penalties.sla_breach_penalty', 'int'],
            ['status_thresholds.healthy_min', 'int'],
            ['status_thresholds.attention_min', 'int'],
            ['retention.history_days', 'int'],
            ['retention.rotate_max_bytes', 'int'],
            ['rendering.show_policy_id_in_exec_summary', 'bool'],
            ['rendering.show_policy_updated_at', 'bool'],
        ];
        foreach ($checks as [$path, $type]) {
            $errors = array_merge($errors, ops_thresholds_type_check($obj, (string)$path, (string)$type));
        }
        if (isset($obj['ops_score']['base_score_source']) && !in_array((string)$obj['ops_score']['base_score_source'], ['readiness', 'fixed_100'], true)) {
            $errors[] = 'invalid:ops_score.base_score_source';
        }
        $errors = array_merge($errors, ops_thresholds_disallow_secret_keys($obj));
        return array_values(array_unique($errors));
    }
}

if (!function_exists('ops_thresholds_read_file')) {
    function ops_thresholds_read_file(string $path): array
    {
        $res = [
            'ok' => false,
            'path' => $path,
            'path_masked' => ops_mask($path),
            'error' => 'missing',
            'errors' => [],
            'policy' => [],
            'fingerprint' => '',
            'raw' => '',
        ];
        if (!is_file($path)) return $res;
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            $res['error'] = 'empty';
            return $res;
        }
        $res['raw'] = $raw;
        try {
            $obj = ops_thresholds_parse_yaml($raw);
        } catch (Throwable $e) {
            $res['error'] = 'parse_error';
            $res['errors'] = [ops_mask($e->getMessage())];
            return $res;
        }
        if (!is_array($obj)) {
            $res['error'] = 'schema_not_map';
            return $res;
        }
        $valErrors = validate_ops_thresholds_struct($obj);
        if ($valErrors !== []) {
            $res['error'] = 'invalid_schema';
            $res['errors'] = array_map(static fn(string $v): string => ops_mask($v), $valErrors);
            $res['policy'] = $obj;
            return $res;
        }
        $res['ok'] = true;
        $res['error'] = '';
        $res['policy'] = $obj;
        $res['fingerprint'] = ops_thresholds_fingerprint($obj);
        return $res;
    }
}

if (!function_exists('load_ops_thresholds')) {
    function load_ops_thresholds(string $mode = 'active-first', bool $strict = false): array
    {
        static $cached = null;
        if ($cached !== null && $mode === 'active-first' && !$strict) {
            return $cached;
        }
        $activePath = ops_thresholds_active_path();
        $baselinePath = ops_thresholds_baseline_path();
        $first = ($mode === 'baseline-first') ? ops_thresholds_read_file($baselinePath) : ops_thresholds_read_file($activePath);
        $second = ($mode === 'baseline-first') ? ops_thresholds_read_file($activePath) : ops_thresholds_read_file($baselinePath);
        $selected = $first['ok'] ? $first : ($second['ok'] ? $second : null);

        if ($selected !== null) {
            $out = [
                'ok' => true,
                'policy' => (array)$selected['policy'],
                'source' => ($selected['path'] === $activePath) ? 'active' : 'baseline',
                'path_masked' => (string)$selected['path_masked'],
                'fingerprint' => (string)$selected['fingerprint'],
                'errors' => [],
                'notes' => [],
            ];
            if ($selected['path'] !== $activePath) {
                $out['notes'][] = ops_mask('ATTENTION: active policy unavailable; using baseline');
            }
            if ($mode === 'active-first' && !$strict) $cached = $out;
            return $out;
        }

        $errs = [
            'active:' . (string)$first['error'] . ':' . implode(',', (array)$first['errors']),
            'baseline:' . (string)$second['error'] . ':' . implode(',', (array)$second['errors']),
        ];
        if ($strict) {
            return [
                'ok' => false,
                'policy' => [],
                'source' => 'none',
                'path_masked' => '',
                'fingerprint' => '',
                'errors' => array_map(static fn(string $v): string => ops_mask($v), $errs),
                'notes' => [ops_mask('CRITICAL: policy missing/invalid in strict mode')],
            ];
        }
        $def = ops_thresholds_defaults();
        return [
            'ok' => false,
            'policy' => $def,
            'source' => 'defaults',
            'path_masked' => '',
            'fingerprint' => ops_thresholds_fingerprint($def),
            'errors' => array_map(static fn(string $v): string => ops_mask($v), $errs),
            'notes' => [ops_mask('ATTENTION: fallback defaults used (non-strict)')],
        ];
    }
}

if (!function_exists('get_threshold')) {
    function get_threshold(string $path, mixed $default = null): mixed
    {
        $loaded = load_ops_thresholds('active-first', false);
        $cur = (array)($loaded['policy'] ?? []);
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) return $default;
            $cur = $cur[$part];
        }
        return $cur;
    }
}

if (!function_exists('mask_sensitive_in_policy_output')) {
    function mask_sensitive_in_policy_output(array $policy): array
    {
        $walk = static function (array $node, string $prefix = '') use (&$walk): array {
            $out = [];
            foreach ($node as $k => $v) {
                $key = (string)$k;
                $path = $prefix === '' ? $key : ($prefix . '.' . $key);
                if (is_array($v)) {
                    $out[$key] = $walk($v, $path);
                    continue;
                }
                $lk = strtolower($key);
                if (str_contains($lk, 'password') || str_contains($lk, 'token') || str_contains($lk, 'secret')) {
                    $out[$key] = '[REDACTED]';
                } else {
                    $out[$key] = is_string($v) ? ops_mask($v) : $v;
                }
            }
            return $out;
        };
        return $walk($policy);
    }
}

if (!function_exists('ops_thresholds_append_audit_log')) {
    function ops_thresholds_append_audit_log(array $event): void
    {
        $path = ops_thresholds_audit_log_path();
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }
}

