<?php
declare(strict_types=1);

require_once __DIR__ . '/executive_summary_lib.php';

if (!function_exists('exss_has_deny_patterns')) {
    function exss_has_deny_patterns(string $raw): array
    {
        $patterns = ['/Users/', 'C:\\', 'BEGIN PRIVATE KEY', 'Authorization:', 'DB_PASS'];
        $hits = [];
        foreach ($patterns as $p) {
            if (str_contains($raw, $p)) $hits[] = $p;
        }
        return $hits;
    }
}

if (!function_exists('exss_enum_ok')) {
    function exss_enum_ok(mixed $value, array $allowed): bool
    {
        return is_string($value) && in_array($value, $allowed, true);
    }
}

if (!function_exists('exss_validate_payload')) {
    function exss_validate_payload(array $payload): array
    {
        $fails = [];
        $warns = [];
        $required = ['state_version', 'generated_at', 'env', 'run_id', 'decision', 'panels', 'links', 'data_missing', 'notes'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $payload)) $fails[] = 'missing_key:' . $k;
        }
        $decision = (array)($payload['decision'] ?? []);
        if (!exss_enum_ok($decision['go_no_go'] ?? null, ['GO', 'NO-GO', 'UNKNOWN'])) {
            $fails[] = 'decision.go_no_go.invalid';
        }
        if (!exss_enum_ok($decision['level'] ?? null, ['HEALTHY', 'ATTENTION', 'CRITICAL'])) {
            $fails[] = 'decision.level.invalid';
        }
        if (!is_array($decision['reasons'] ?? null)) $fails[] = 'decision.reasons.type';
        $panels = (array)($payload['panels'] ?? []);
        foreach (['ops_score', 'fix_backlog', 'release_verify', 'alerts', 'change_control', 'freshness'] as $pk) {
            if (!array_key_exists($pk, $panels)) $fails[] = 'panels.' . $pk . '.missing';
        }
        $arch = (array)($panels['architecture'] ?? []);
        if ($arch !== []) {
            if (!exss_enum_ok($arch['status'] ?? null, ['OK', 'ATTENTION', 'CRITICAL', 'DATA_MISSING'])) {
                $warns[] = 'panels.architecture.status.invalid';
            }
            foreach ((array)($arch['domains'] ?? []) as $i => $dom) {
                if (!is_array($dom)) continue;
                if (!isset($dom['name']) || !is_string($dom['name'])) $warns[] = 'panels.architecture.domains[' . $i . '].name';
                if (!exss_enum_ok($dom['status'] ?? null, ['PRESENT', 'PARTIAL', 'NOT_FOUND', 'UNKNOWN'])) {
                    $warns[] = 'panels.architecture.domains[' . $i . '].status.invalid';
                }
                if (isset($dom['confidence']) && !is_int($dom['confidence'])) $warns[] = 'panels.architecture.domains[' . $i . '].confidence_not_int';
            }
        }

        $jsonRaw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $denyHits = exss_has_deny_patterns((string)$jsonRaw);
        foreach ($denyHits as $hit) {
            $fails[] = 'deny_pattern:' . $hit;
        }

        if (!is_int($payload['state_version'] ?? null)) $warns[] = 'state_version_not_int';
        if (!is_array($payload['data_missing'] ?? null)) $warns[] = 'data_missing_not_array';
        if (!is_array($payload['notes'] ?? null)) $warns[] = 'notes_not_array';

        return [
            'ok' => $fails === [],
            'fails' => $fails,
            'warns' => $warns,
        ];
    }
}

if (!function_exists('exss_render_validate_md')) {
    function exss_render_validate_md(array $report): string
    {
        $lines = [];
        $lines[] = '# Executive Summary Validation';
        $lines[] = '';
        $lines[] = '- generated_at: ' . exs_mask((string)($report['generated_at'] ?? ''));
        $lines[] = '- strict: ' . ((bool)($report['strict'] ?? false) ? 'true' : 'false');
        $lines[] = '- status: ' . exs_mask((string)($report['status'] ?? 'UNKNOWN'));
        $lines[] = '- fail_count: ' . (int)($report['fail_count'] ?? 0);
        $lines[] = '- warn_count: ' . (int)($report['warn_count'] ?? 0);
        $lines[] = '';
        $lines[] = '## Fails';
        foreach ((array)($report['fails'] ?? []) as $f) {
            $lines[] = '- ' . exs_mask((string)$f);
        }
        if ((array)($report['fails'] ?? []) === []) $lines[] = '- none';
        $lines[] = '';
        $lines[] = '## Warns';
        foreach ((array)($report['warns'] ?? []) as $w) {
            $lines[] = '- ' . exs_mask((string)$w);
        }
        if ((array)($report['warns'] ?? []) === []) $lines[] = '- none';
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('exss_write_validate_last')) {
    function exss_write_validate_last(array $report): void
    {
        $dir = exs_pipeline_dir();
        $jsonPath = $dir . '/executive_ops_summary_validate_last.json';
        $mdPath = $dir . '/executive_ops_summary_validate_last.md';
        ops_write_json($jsonPath, $report);
        @file_put_contents($mdPath, exss_render_validate_md($report));
    }
}

