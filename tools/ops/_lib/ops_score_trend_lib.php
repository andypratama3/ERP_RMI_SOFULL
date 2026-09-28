<?php
declare(strict_types=1);

require_once __DIR__ . '/ops_score_lib.php';

if (!function_exists('ops_score_trend_pipeline_dir')) {
    function ops_score_trend_pipeline_dir(): string
    {
        $dir = ops_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('ops_score_history_path')) {
    function ops_score_history_path(): string
    {
        return ops_score_trend_pipeline_dir() . '/ops_score_history.jsonl';
    }
}

if (!function_exists('ops_score_history_archives')) {
    function ops_score_history_archives(): array
    {
        $paths = glob(ops_score_trend_pipeline_dir() . '/ops_score_history_*.jsonl') ?: [];
        sort($paths);
        return $paths;
    }
}

if (!function_exists('ops_score_maintenance_log')) {
    function ops_score_maintenance_log(): string
    {
        return ops_score_trend_pipeline_dir() . '/ops_score_history_maintenance.log';
    }
}

if (!function_exists('ops_score_log_maintenance')) {
    function ops_score_log_maintenance(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . ops_score_mask($message) . "\n";
        @file_put_contents(ops_score_maintenance_log(), $line, FILE_APPEND);
    }
}

if (!function_exists('ops_score_read_jsonl')) {
    function ops_score_read_jsonl(string $path): array
    {
        $records = [];
        $invalid = 0;
        if (!is_file($path)) {
            return ['records' => [], 'invalid_lines' => 0];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $raw = trim((string)$line);
            if ($raw === '') continue;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $invalid++;
                continue;
            }
            $records[] = $decoded;
        }
        return ['records' => $records, 'invalid_lines' => $invalid];
    }
}

if (!function_exists('ops_score_write_jsonl')) {
    function ops_score_write_jsonl(string $path, array $records): bool
    {
        $rows = [];
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $rows[] = json_encode($record, JSON_UNESCAPED_SLASHES);
        }
        $content = $rows === [] ? '' : implode("\n", $rows) . "\n";
        return @file_put_contents($path, $content) !== false;
    }
}

if (!function_exists('ops_score_record_ts')) {
    function ops_score_record_ts(array $record): int
    {
        $ts = strtotime((string)($record['ts'] ?? ''));
        return $ts === false ? 0 : $ts;
    }
}

if (!function_exists('ops_score_record_date')) {
    function ops_score_record_date(array $record): string
    {
        $d = trim((string)($record['date'] ?? ''));
        if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1) return $d;
        $ts = ops_score_record_ts($record);
        if ($ts <= 0) return '';
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('ops_score_keep_last_days')) {
    function ops_score_keep_last_days(array $records, int $days): array
    {
        $cutoff = strtotime('-' . max(1, $days) . ' days');
        $out = [];
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $ts = ops_score_record_ts($record);
            if ($ts > 0 && $ts >= $cutoff) {
                $out[] = $record;
            }
        }
        return $out;
    }
}

if (!function_exists('ops_score_latest_per_day')) {
    function ops_score_latest_per_day(array $records, string $env): array
    {
        $map = [];
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            if (strtolower((string)($record['env'] ?? '')) !== strtolower($env)) continue;
            $date = ops_score_record_date($record);
            $ts = ops_score_record_ts($record);
            if ($date === '' || $ts <= 0) continue;
            if (!isset($map[$date]) || $ts > ops_score_record_ts((array)$map[$date])) {
                $map[$date] = $record;
            }
        }
        ksort($map);
        return $map;
    }
}

if (!function_exists('ops_score_build_window')) {
    function ops_score_build_window(array $byDay, int $days): array
    {
        $dates = array_keys($byDay);
        $dates = array_slice($dates, -$days);
        $series = [
            'ops_score' => [],
            'readiness_score' => [],
            'backlog_p0' => [],
            'smoke_http_fail' => [],
            'go_no_go' => [],
        ];
        $records = [];
        foreach ($dates as $date) {
            $rec = (array)$byDay[$date];
            $metrics = (array)($rec['metrics'] ?? []);
            $records[] = $rec;
            $series['ops_score'][] = (int)($rec['ops_score'] ?? 0);
            $series['readiness_score'][] = (int)($metrics['readiness_score'] ?? 0);
            $series['backlog_p0'][] = (int)($metrics['backlog_p0'] ?? 0);
            $series['smoke_http_fail'][] = (int)($metrics['smoke_http_fail'] ?? 0);
            $series['go_no_go'][] = (string)($rec['go_no_go'] ?? 'UNKNOWN');
        }
        return ['days' => $dates, 'records' => $records, 'series' => $series];
    }
}

if (!function_exists('ops_score_window_delta')) {
    function ops_score_window_delta(array $vals): int
    {
        if ($vals === []) return 0;
        return (int)end($vals) - (int)reset($vals);
    }
}

if (!function_exists('ops_score_window_flags')) {
    function ops_score_window_flags(array $window): array
    {
        $series = (array)($window['series'] ?? []);
        $records = (array)($window['records'] ?? []);
        $flags = [];
        $goSeries = (array)($series['go_no_go'] ?? []);
        if ($goSeries !== [] && (string)end($goSeries) === 'NO-GO') {
            $flags[] = 'DEPLOY_BLOCKED';
        }
        $unknownCount = count(array_filter($goSeries, static fn(string $v): bool => $v === 'UNKNOWN'));
        if ($unknownCount > 0) {
            $flags[] = 'DATA_INTEGRITY_RISK';
        }
        foreach ($records as $r) {
            if ((string)($r['status'] ?? '') === 'DATA_MISSING') {
                $flags[] = 'DATA_INTEGRITY_RISK';
                break;
            }
        }
        $deltaScore = ops_score_window_delta((array)($series['ops_score'] ?? []));
        $deltaP0 = ops_score_window_delta((array)($series['backlog_p0'] ?? []));
        $deltaSmoke = ops_score_window_delta((array)($series['smoke_http_fail'] ?? []));
        if ($deltaScore > 0 && $deltaP0 <= 0 && $deltaSmoke <= 0) $flags[] = 'IMPROVING';
        if ($deltaScore < 0 || $deltaP0 > 0 || $deltaSmoke > 0) $flags[] = 'WORSENING';
        if ($records === []) $flags[] = 'DATA_MISSING';
        return array_values(array_unique($flags));
    }
}

if (!function_exists('ops_score_sparkline_ascii')) {
    function ops_score_sparkline_ascii(array $values): string
    {
        if ($values === []) return 'DATA_MISSING';
        $vals = array_map(static fn($v): int => (int)$v, $values);
        $min = min($vals);
        $max = max($vals);
        $range = max(1, $max - $min);
        $chars = ['.', ':', '-', '=', '+', '*', '#', '@'];
        $out = '';
        foreach ($vals as $v) {
            $idx = (int)floor((($v - $min) * (count($chars) - 1)) / $range);
            $idx = max(0, min(count($chars) - 1, $idx));
            $out .= $chars[$idx];
        }
        return $out;
    }
}

if (!function_exists('ops_score_sparkline_svg_path')) {
    function ops_score_sparkline_svg_path(array $values): string
    {
        if ($values === []) return 'DATA_MISSING';
        $vals = array_map(static fn($v): int => (int)$v, $values);
        $min = min($vals);
        $max = max($vals);
        $range = max(1, $max - $min);
        $count = count($vals);
        $dx = $count > 1 ? 100 / ($count - 1) : 0;
        $pts = [];
        foreach ($vals as $i => $v) {
            $x = round($i * $dx, 2);
            $y = round(20 - (($v - $min) * 20 / $range), 2);
            $pts[] = $x . ',' . $y;
        }
        return 'M' . implode(' L', $pts);
    }
}

