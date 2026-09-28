<?php
declare(strict_types=1);

if (!function_exists('bpr_root')) {
    function bpr_root(): string
    {
        if (function_exists('ts_root')) {
            return ts_root();
        }
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('bpr_mask_path')) {
    function bpr_mask_path(string $text, ?string $root = null): string
    {
        $root = $root ?? bpr_root();
        $root = str_replace('\\', '/', $root);
        if ($root !== '') {
            $text = str_replace($root, '[APP_ROOT]', str_replace('\\', '/', $text));
        }
        return $text;
    }
}

if (!function_exists('bpr_redact_secrets')) {
    function bpr_redact_secrets(string $text): string
    {
        $out = preg_replace('/Authorization:\s*[^\s\r\n]+/i', 'Authorization: [REDACTED]', $text) ?? $text;
        $out = preg_replace('/(password|passwd|pwd|secret|token|api_key|db_pass)\s*[:=]\s*\S+/i', '$1=[REDACTED]', $out) ?? $out;
        $out = preg_replace('/cookie:\s*[^\s\r\n]+/i', 'cookie: [REDACTED]', $out) ?? $out;
        $out = preg_replace('/BEGIN\s+PRIVATE\s+KEY[\s\S]*?END\s+PRIVATE\s+KEY/i', '[REDACTED]', $out) ?? $out;
        return $out;
    }
}

if (!function_exists('bpr_deny_patterns')) {
    function bpr_deny_patterns(): array
    {
        return ['/Users/', 'C:\\', 'BEGIN PRIVATE KEY', 'Authorization:', 'DB_PASS'];
    }
}

if (!function_exists('bpr_deny_pattern_scan')) {
    /**
     * @return array{ok:bool, violations:array}
     */
    function bpr_deny_pattern_scan(string $text, ?string $root = null): array
    {
        $masked = bpr_mask_path($text, $root);
        $redacted = bpr_redact_secrets($masked);
        $violations = [];
        foreach (bpr_deny_patterns() as $p) {
            if (str_contains($redacted, $p)) {
                $violations[] = $p;
            }
        }
        return ['ok' => empty($violations), 'violations' => $violations];
    }
}

if (!function_exists('bpr_redact_text')) {
    function bpr_redact_text(string $text): string
    {
        $out = bpr_mask_path($text);
        $out = bpr_redact_secrets($out);
        return $out;
    }
}

if (!function_exists('bpr_redact_json')) {
    /**
     * Recursively redact string values in JSON. Returns redacted JSON string.
     */
    function bpr_redact_json(string $json): string
    {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $redacted = bpr_redact_array($decoded);
            $out = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $out !== false ? $out : bpr_redact_text($json);
        }
        return bpr_redact_text($json);
    }
}

if (!function_exists('bpr_redact_array')) {
    function bpr_redact_array(mixed $v): mixed
    {
        if (is_string($v)) {
            return bpr_redact_text($v);
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $out[$k] = bpr_redact_array($val);
            }
            return $out;
        }
        return $v;
    }
}
