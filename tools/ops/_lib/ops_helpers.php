<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/../../tools_ui_helpers.php';

if (!function_exists('ops_root')) {
    function ops_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('ops_logs_dir')) {
    function ops_logs_dir(): string
    {
        return ts_storage_logs_dir();
    }
}

if (!function_exists('ops_mask')) {
    function ops_mask(string $value): string
    {
        return ts_mask(tools_mask_sensitive($value));
    }
}

if (!function_exists('ops_read_json_safe')) {
    function ops_read_json_safe(string $path, array $requiredKeys = []): array
    {
        $res = [
            'ok' => false,
            'error' => 'missing',
            'path_masked' => ops_mask($path),
            'data' => [],
        ];
        if (!is_file($path)) {
            return $res;
        }
        $raw = (string)@file_get_contents($path);
        if ($raw === '') {
            $res['error'] = 'empty';
            return $res;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $res['error'] = 'invalid_json';
            return $res;
        }
        foreach ($requiredKeys as $k) {
            if (!array_key_exists($k, $decoded)) {
                $res['error'] = 'schema_mismatch';
                return $res;
            }
        }
        if (!array_key_exists('state_version', $decoded)) {
            $decoded['state_version'] = 0;
        }
        $res['ok'] = true;
        $res['error'] = '';
        $res['data'] = $decoded;
        return $res;
    }
}

if (!function_exists('ops_write_json')) {
    function ops_write_json(string $path, array $data): bool
    {
        if (!array_key_exists('state_version', $data)) {
            $data['state_version'] = 1;
        }
        return ts_write_json($path, $data);
    }
}

if (!function_exists('ops_contract_check_types')) {
    function ops_contract_check_types(array $data, array $schema): array
    {
        $errors = [];
        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $data)) {
                $errors[] = $key . ':missing';
                continue;
            }
            $v = $data[$key];
            $ok = match ($type) {
                'int' => is_int($v),
                'float' => is_float($v) || is_int($v),
                'bool' => is_bool($v),
                'string' => is_string($v),
                'array' => is_array($v),
                'nullable_float' => $v === null || is_float($v) || is_int($v),
                'nullable_bool' => $v === null || is_bool($v),
                default => true,
            };
            if (!$ok) {
                $errors[] = $key . ':type';
            }
        }
        return ['ok' => empty($errors), 'errors' => $errors];
    }
}

if (!function_exists('ops_state_path')) {
    function ops_state_path(string $name): string
    {
        return rtrim(ops_logs_dir(), '/') . '/' . ltrim($name, '/');
    }
}

if (!function_exists('ops_ensure_all_checks_last')) {
    function ops_ensure_all_checks_last(): array
    {
        $allChecksPath = ops_state_path('all_checks.last.json');
        $allChecks = ops_read_json_safe($allChecksPath, ['state_version']);
        if ($allChecks['ok']) {
            return $allChecks;
        }

        $cut = ops_read_json_safe(ops_state_path('cutover_checks.last.json'));
        $neg = ops_read_json_safe(ops_state_path('negative_tests.last.json'));
        $steps = [];
        $failCount = 0;
        if ($cut['ok']) {
            $ok = (bool)($cut['data']['overall_ok'] ?? false);
            if (!$ok) $failCount++;
            $steps[] = ['name' => 'cutover_checks', 'ok' => $ok, 'duration_ms' => (int)($cut['data']['duration_ms'] ?? 0), 'artifact' => '[APP_ROOT]/storage/logs/cutover_checks.last.json'];
        }
        if ($neg['ok']) {
            $ok = (bool)($neg['data']['ok'] ?? false);
            if (!$ok) $failCount++;
            $steps[] = ['name' => 'negative_tests', 'ok' => $ok, 'duration_ms' => (int)($neg['data']['duration_ms'] ?? 0), 'artifact' => '[APP_ROOT]/storage/logs/negative_tests.last.json'];
        }
        if (!$steps) {
            return $allChecks;
        }
        $payload = [
            'state_version' => 1,
            'run_at' => date(DateTimeInterface::ATOM),
            'overall_ok' => ($failCount === 0),
            'summary' => [
                'total' => count($steps),
                'fail_count' => $failCount,
                'score' => max(0, 100 - ($failCount * 50)),
            ],
            'steps' => $steps,
            'errors_masked' => [],
            'generated_by' => 'ops_ensure_all_checks_last',
        ];
        ops_write_json($allChecksPath, $payload);
        return ops_read_json_safe($allChecksPath, ['summary', 'overall_ok']);
    }
}

if (!function_exists('ops_get_backup_age_hours')) {
    function ops_get_backup_age_hours(): ?float
    {
        $meta = ts_latest_backup_meta();
        if (!is_array($meta)) return null;
        $age = $meta['age_hours'] ?? null;
        if ($age === null || $age === '') return null;
        return (float)$age;
    }
}

if (!function_exists('ops_collect_snapshots')) {
    function ops_collect_snapshots(int $days): array
    {
        $days = max(1, $days);
        $glob = glob(ops_state_path('ops_daily_snapshot_*.json')) ?: [];
        rsort($glob);
        $rows = [];
        foreach ($glob as $path) {
            $r = ops_read_json_safe($path, ['date', 'readiness_score', 'smoke_fail', 'contract_ok']);
            if (!$r['ok']) continue;
            $d = (string)($r['data']['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
            $rows[] = $r['data'];
            if (count($rows) >= $days) break;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
        return $rows;
    }
}

if (!function_exists('ops_trend_payload')) {
    function ops_trend_payload(int $days): array
    {
        $snapshots = ops_collect_snapshots($days);
        $series = [
            'dates' => [],
            'readiness_score' => [],
            'smoke_fail' => [],
            'backup_age_hours' => [],
        ];
        foreach ($snapshots as $s) {
            $series['dates'][] = (string)($s['date'] ?? '');
            $series['readiness_score'][] = (int)($s['readiness_score'] ?? 0);
            $series['smoke_fail'][] = (int)($s['smoke_fail'] ?? 0);
            $series['backup_age_hours'][] = isset($s['backup_age_hours']) && $s['backup_age_hours'] !== null ? (float)$s['backup_age_hours'] : null;
        }
        return [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'window_days' => $days,
            'series' => $series,
        ];
    }
}

if (!function_exists('ops_svg_sparkline')) {
    function ops_svg_sparkline(array $values, int $width = 180, int $height = 40): string
    {
        $vals = [];
        foreach ($values as $v) {
            if ($v === null || $v === '') continue;
            $vals[] = (float)$v;
        }
        if (!$vals) {
            return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '"><line x1="0" y1="' . ($height - 1) . '" x2="' . $width . '" y2="' . ($height - 1) . '" stroke="currentColor" stroke-opacity="0.25"/></svg>';
        }
        $min = min($vals);
        $max = max($vals);
        $range = max(1e-9, $max - $min);
        $n = count($vals);
        $dx = $n > 1 ? ($width - 2) / ($n - 1) : 0;
        $points = [];
        foreach ($vals as $i => $v) {
            $x = 1 + ($i * $dx);
            $y = ($height - 2) - (($v - $min) / $range) * ($height - 6);
            $points[] = round($x, 2) . ',' . round((float)$y, 2);
        }
        $polyline = implode(' ', $points);
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" aria-hidden="true"><polyline fill="none" stroke="currentColor" stroke-width="2" points="' . htmlspecialchars($polyline, ENT_QUOTES, 'UTF-8') . '"/></svg>';
    }
}

if (!function_exists('ops_status_badge')) {
    function ops_status_badge(string $status): string
    {
        $s = strtoupper(trim($status));
        if (in_array($s, ['HEALTHY', 'OK', 'GO', 'PASS', 'READY', 'RESOLVED'], true)) return tools_badge('HEALTHY', $s);
        if (in_array($s, ['CRITICAL', 'FAIL', 'FAILED'], true)) return tools_badge('CRITICAL', $s);
        return tools_badge('ATTENTION', $s !== '' ? $s : 'UNKNOWN');
    }
}

if (!function_exists('ops_week_key')) {
    function ops_week_key(DateTimeInterface $dt): string
    {
        return $dt->format('o-\WW');
    }
}

if (!function_exists('ops_load_sla_owners')) {
    function ops_load_sla_owners(): array
    {
        $path = ops_root() . '/docs/governance/OPS_SLA_OWNERS.json';
        $r = ops_read_json_safe($path);
        return $r['ok'] && is_array($r['data']) ? (array)$r['data'] : [];
    }
}
