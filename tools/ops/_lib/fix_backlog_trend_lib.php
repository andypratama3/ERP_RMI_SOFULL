<?php
declare(strict_types=1);

require_once __DIR__ . '/ops_helpers.php';

if (!function_exists('fbt_root')) {
    function fbt_root(): string
    {
        return ops_root();
    }
}

if (!function_exists('fbt_mask')) {
    function fbt_mask(string $value): string
    {
        return ops_mask($value);
    }
}

if (!function_exists('fbt_pipeline_dir')) {
    function fbt_pipeline_dir(): string
    {
        $dir = fbt_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('fbt_now_iso')) {
    function fbt_now_iso(): string
    {
        return date(DateTimeInterface::ATOM);
    }
}

if (!function_exists('fbt_history_file')) {
    function fbt_history_file(): string
    {
        return fbt_pipeline_dir() . '/fix_backlog_history.jsonl';
    }
}

if (!function_exists('fbt_history_archives')) {
    function fbt_history_archives(): array
    {
        $items = glob(fbt_pipeline_dir() . '/fix_backlog_history_*.jsonl') ?: [];
        sort($items);
        return $items;
    }
}

if (!function_exists('fbt_maintenance_log')) {
    function fbt_maintenance_log(): string
    {
        return fbt_pipeline_dir() . '/fix_backlog_history_maintenance.log';
    }
}

if (!function_exists('fbt_append_maintenance')) {
    function fbt_append_maintenance(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . fbt_mask($message) . "\n";
        @file_put_contents(fbt_maintenance_log(), $line, FILE_APPEND);
    }
}

if (!function_exists('fbt_read_json_file')) {
    function fbt_read_json_file(string $path): array
    {
        $out = ['ok' => false, 'error' => 'missing', 'data' => [], 'path_masked' => fbt_mask($path)];
        if (!is_file($path)) {
            return $out;
        }
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            $out['error'] = 'empty';
            return $out;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $out['error'] = 'invalid_json';
            return $out;
        }
        $out['ok'] = true;
        $out['error'] = '';
        $out['data'] = $decoded;
        return $out;
    }
}

if (!function_exists('fbt_jsonl_read_records')) {
    function fbt_jsonl_read_records(string $path): array
    {
        $result = ['records' => [], 'invalid_lines' => 0, 'path_masked' => fbt_mask($path)];
        if (!is_file($path)) {
            return $result;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $trim = trim((string)$line);
            if ($trim === '') {
                continue;
            }
            $decoded = json_decode($trim, true);
            if (!is_array($decoded)) {
                $result['invalid_lines']++;
                continue;
            }
            $result['records'][] = $decoded;
        }
        return $result;
    }
}

if (!function_exists('fbt_jsonl_write_records')) {
    function fbt_jsonl_write_records(string $path, array $records): bool
    {
        $rows = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $rows[] = json_encode($record, JSON_UNESCAPED_SLASHES);
        }
        $payload = $rows === [] ? '' : implode("\n", $rows) . "\n";
        return @file_put_contents($path, $payload) !== false;
    }
}

if (!function_exists('fbt_days_ago_cutoff_ts')) {
    function fbt_days_ago_cutoff_ts(int $days): int
    {
        return strtotime('-' . max(1, $days) . ' days');
    }
}

if (!function_exists('fbt_record_ts')) {
    function fbt_record_ts(array $record): int
    {
        $ts = (string)($record['ts'] ?? '');
        $parsed = strtotime($ts);
        return $parsed === false ? 0 : $parsed;
    }
}

if (!function_exists('fbt_record_date')) {
    function fbt_record_date(array $record): string
    {
        $date = trim((string)($record['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }
        $ts = fbt_record_ts($record);
        if ($ts <= 0) {
            return '';
        }
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('fbt_filter_records_last_days')) {
    function fbt_filter_records_last_days(array $records, int $days): array
    {
        $cutoff = fbt_days_ago_cutoff_ts($days);
        $out = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $ts = fbt_record_ts($record);
            if ($ts <= 0) {
                continue;
            }
            if ($ts >= $cutoff) {
                $out[] = $record;
            }
        }
        return $out;
    }
}

if (!function_exists('fbt_group_latest_per_day')) {
    function fbt_group_latest_per_day(array $records, string $env): array
    {
        $byDay = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $recEnv = strtolower(trim((string)($record['env'] ?? '')));
            if ($recEnv !== strtolower($env)) {
                continue;
            }
            $date = fbt_record_date($record);
            $ts = fbt_record_ts($record);
            if ($date === '' || $ts <= 0) {
                continue;
            }
            if (!isset($byDay[$date]) || $ts > fbt_record_ts((array)$byDay[$date])) {
                $byDay[$date] = $record;
            }
        }
        ksort($byDay);
        return $byDay;
    }
}

if (!function_exists('fbt_build_window_from_latest')) {
    function fbt_build_window_from_latest(array $byDay, int $days): array
    {
        $allDates = array_keys($byDay);
        $sliceDates = array_slice($allDates, -$days);
        $series = [
            'p0' => [],
            'p1' => [],
            'p2' => [],
            'data_missing' => [],
            'by_module_last' => [],
        ];
        $selected = [];
        foreach ($sliceDates as $date) {
            $rec = (array)$byDay[$date];
            $selected[] = $rec;
            $sum = (array)($rec['summary'] ?? []);
            $series['p0'][] = (int)($sum['p0'] ?? 0);
            $series['p1'][] = (int)($sum['p1'] ?? 0);
            $series['p2'][] = (int)($sum['p2'] ?? 0);
            $series['data_missing'][] = (int)($sum['data_missing'] ?? 0);
        }
        if ($selected !== []) {
            $last = (array)end($selected);
            $series['by_module_last'] = (array)($last['by_module'] ?? []);
        }
        return [
            'days' => $sliceDates,
            'records' => $selected,
            'series' => $series,
        ];
    }
}

if (!function_exists('fbt_trend_label')) {
    function fbt_trend_label(int $delta): string
    {
        if ($delta < 0) return 'IMPROVING';
        if ($delta > 0) return 'WORSENING';
        return 'STABLE';
    }
}

if (!function_exists('fbt_compute_trend_metrics')) {
    function fbt_compute_trend_metrics(array $window): array
    {
        $series = (array)($window['series'] ?? []);
        $compute = static function (array $vals): array {
            if ($vals === []) {
                return ['first' => 0, 'last' => 0, 'delta' => 0, 'trend' => 'DATA_MISSING'];
            }
            $first = (int)reset($vals);
            $last = (int)end($vals);
            $delta = $last - $first;
            return ['first' => $first, 'last' => $last, 'delta' => $delta, 'trend' => fbt_trend_label($delta)];
        };
        return [
            'p0' => $compute((array)($series['p0'] ?? [])),
            'p1' => $compute((array)($series['p1'] ?? [])),
            'p2' => $compute((array)($series['p2'] ?? [])),
            'coverage_days' => count((array)($window['days'] ?? [])),
        ];
    }
}

if (!function_exists('fbt_compute_flags')) {
    function fbt_compute_flags(array $window): array
    {
        $series = (array)($window['series'] ?? []);
        $p0 = (array)($series['p0'] ?? []);
        $dm = (array)($series['data_missing'] ?? []);
        $flags = [];
        foreach ($p0 as $v) {
            if ((int)$v > 0) {
                $flags[] = 'DEPLOY_BLOCKER_PRESENT';
                break;
            }
        }
        if ($dm !== []) {
            $first = (int)reset($dm);
            $last = (int)end($dm);
            $max = max($dm);
            if ($max > 0 && ($last > $first || ($last - $first) >= 2)) {
                $flags[] = 'DATA_INTEGRITY_RISK';
            }
        }
        if (((array)($window['days'] ?? [])) === []) {
            $flags[] = 'DATA_MISSING';
        }
        return array_values(array_unique($flags));
    }
}

if (!function_exists('fbt_top_modules')) {
    function fbt_top_modules(array $window30, array $window7): array
    {
        $mods30 = (array)($window30['series']['by_module_last'] ?? []);
        $mods7ByDay = (array)($window7['records'] ?? []);
        $first7 = $mods7ByDay !== [] ? (array)($mods7ByDay[0]['by_module'] ?? []) : [];
        $rows = [];
        foreach ($mods30 as $module => $counts) {
            $p0 = (int)(is_array($counts) ? ($counts['p0'] ?? 0) : 0);
            $firstP0 = (int)(is_array($first7[$module] ?? null) ? (($first7[$module]['p0'] ?? 0)) : 0);
            $rows[] = [
                'module' => (string)$module,
                'p0_last' => $p0,
                'p0_delta_7d' => $p0 - $firstP0,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $b['p0_last'] <=> $a['p0_last']);
        return array_slice($rows, 0, 3);
    }
}

if (!function_exists('fbt_svg_polyline')) {
    function fbt_svg_polyline(array $values, int $width = 380, int $height = 120): string
    {
        if ($values === []) {
            return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '"><text x="8" y="24" font-size="12">DATA_MISSING</text></svg>';
        }
        $vals = array_map(static fn($v): float => (float)$v, $values);
        $min = min($vals);
        $max = max($vals);
        $range = max(1.0, $max - $min);
        $count = count($vals);
        $dx = $count > 1 ? ($width - 20) / ($count - 1) : 0;
        $points = [];
        foreach ($vals as $idx => $val) {
            $x = 10 + ($idx * $dx);
            $y = ($height - 10) - (($val - $min) / $range) * ($height - 20);
            $points[] = round($x, 2) . ',' . round($y, 2);
        }
        $poly = implode(' ', $points);
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '">'
            . '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#fff" stroke="#ddd"/>'
            . '<polyline fill="none" stroke="#0a66c2" stroke-width="2" points="' . htmlspecialchars($poly, ENT_QUOTES, 'UTF-8') . '"/>'
            . '</svg>';
    }
}

if (!function_exists('fbt_write_outputs')) {
    function fbt_write_outputs(string $prefixPath, array $jsonPayload, string $md, string $html, bool $writeLast): array
    {
        $jsonPath = $prefixPath . '.json';
        $mdPath = $prefixPath . '.md';
        $htmlPath = $prefixPath . '.html';
        ops_write_json($jsonPath, $jsonPayload);
        @file_put_contents($mdPath, $md);
        @file_put_contents($htmlPath, $html);
        $written = [
            'json' => fbt_mask($jsonPath),
            'md' => fbt_mask($mdPath),
            'html' => fbt_mask($htmlPath),
        ];
        if ($writeLast) {
            $dir = fbt_pipeline_dir();
            $jsonLast = $dir . '/fix_backlog_trend_last.json';
            $mdLast = $dir . '/fix_backlog_trend_last.md';
            $htmlLast = $dir . '/fix_backlog_trend_last.html';
            ops_write_json($jsonLast, $jsonPayload);
            @file_put_contents($mdLast, $md);
            @file_put_contents($htmlLast, $html);
            $written['json_last'] = fbt_mask($jsonLast);
            $written['md_last'] = fbt_mask($mdLast);
            $written['html_last'] = fbt_mask($htmlLast);
        }
        return $written;
    }
}

