<?php
declare(strict_types=1);

require_once __DIR__ . '/ops_helpers.php';
require_once __DIR__ . '/ops_score_lib.php';
require_once __DIR__ . '/ops_thresholds_lib.php';

if (!function_exists('exs_root')) {
    function exs_root(): string
    {
        return ops_root();
    }
}

if (!function_exists('exs_pipeline_dir')) {
    function exs_pipeline_dir(): string
    {
        $dir = exs_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('exs_mask')) {
    function exs_mask(string $value): string
    {
        return ops_mask($value);
    }
}

if (!function_exists('exs_read_json_strict')) {
    function exs_read_json_strict(string $path): array
    {
        $result = [
            'ok' => false,
            'error' => 'missing',
            'error_masked' => '',
            'path_masked' => exs_mask($path),
            'data' => [],
        ];
        if (!is_file($path)) {
            return $result;
        }
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            $result['error'] = 'empty';
            return $result;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                $result['error'] = 'schema_mismatch';
                return $result;
            }
            $result['ok'] = true;
            $result['error'] = '';
            $result['data'] = $decoded;
            return $result;
        } catch (Throwable $e) {
            $result['error'] = 'invalid_json';
            $result['error_masked'] = exs_mask($e->getMessage());
            return $result;
        }
    }
}

if (!function_exists('make_sparkline')) {
    function make_sparkline(array $values, string $mode = 'ascii'): string
    {
        if ($values === []) {
            return 'DATA_MISSING';
        }
        $vals = array_map(static fn($v): int => (int)$v, $values);
        $min = min($vals);
        $max = max($vals);
        $range = max(1, $max - $min);

        if (strtolower($mode) === 'svg') {
            $points = [];
            $count = count($vals);
            $dx = $count > 1 ? 100 / ($count - 1) : 0;
            foreach ($vals as $i => $v) {
                $x = round($i * $dx, 2);
                $y = round(20 - (($v - $min) * 20 / $range), 2);
                $points[] = $x . ',' . $y;
            }
            return 'M' . implode(' L', $points);
        }

        // ASCII-only deterministic sparkline.
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

if (!function_exists('exs_pick_trend_metric')) {
    function exs_pick_trend_metric(array $trendNode, string $key): array
    {
        $legacy = (array)($trendNode[$key] ?? []);
        if (isset($legacy['delta'])) {
            return [
                'delta' => (int)($legacy['delta'] ?? 0),
                'trend' => (string)($legacy['trend'] ?? 'UNKNOWN'),
            ];
        }
        $deltaKey = $key . '_delta';
        $trendKey = $key . '_trend';
        return [
            'delta' => (int)($trendNode[$deltaKey] ?? 0),
            'trend' => (string)($trendNode[$trendKey] ?? 'UNKNOWN'),
        ];
    }
}

if (!function_exists('load_fix_backlog_trend')) {
    function load_fix_backlog_trend(string $env): array
    {
        $trendPath = exs_pipeline_dir() . '/fix_backlog_trend_last.json';
        $packPath = exs_pipeline_dir() . '/fix_backlog_last.json';
        $links = [
            'fix_backlog_pack' => 'storage/logs/pipeline/fix_backlog_last.md',
            'fix_backlog_trend' => 'storage/logs/pipeline/fix_backlog_trend_last.html',
        ];
        $base = [
            'status' => 'DATA_MISSING',
            'p0_current' => 0,
            'p1_current' => 0,
            'p2_current' => 0,
            'data_missing_current' => 0,
            'trend_7d' => [
                'p0_delta' => 0, 'p1_delta' => 0, 'p2_delta' => 0,
                'p0_trend' => 'UNKNOWN', 'flags' => ['DATA_MISSING'],
                'sparkline_p0' => 'DATA_MISSING', 'top_modules' => [],
            ],
            'trend_30d' => [
                'p0_delta' => 0, 'p1_delta' => 0, 'p2_delta' => 0,
                'p0_trend' => 'UNKNOWN', 'flags' => ['DATA_MISSING'],
                'sparkline_p0' => 'DATA_MISSING', 'top_modules' => [],
            ],
            'links' => $links,
            'notes' => [],
            'commands' => [
                'php tools/ops/snapshot_fix_backlog.php --env=' . $env . ' --write-last',
                'php tools/ops/generate_fix_backlog_trend.php --env=' . $env . ' --window=both --write-last',
            ],
        ];

        $pack = exs_read_json_strict($packPath);
        if ($pack['ok']) {
            $sum = (array)($pack['data']['summary'] ?? []);
            $base['p0_current'] = (int)($sum['p0'] ?? 0);
            $base['p1_current'] = (int)($sum['p1'] ?? 0);
            $base['p2_current'] = (int)($sum['p2'] ?? 0);
            $base['data_missing_current'] = (int)($sum['data_missing'] ?? 0);
        } elseif ($pack['error'] !== 'missing') {
            $base['notes'][] = exs_mask('ATTENTION: fix backlog pack invalid (' . (string)$pack['error'] . ')');
        }

        $trend = exs_read_json_strict($trendPath);
        if (!$trend['ok']) {
            if ($trend['error'] === 'missing') {
                $base['status'] = 'DATA_MISSING';
                $base['notes'][] = exs_mask('DATA_MISSING: fix backlog trend file not found');
            } else {
                $base['status'] = 'ATTENTION';
                $base['notes'][] = exs_mask('ATTENTION: invalid JSON in trend file (' . (string)$trend['error'] . ') ' . (string)$trend['error_masked']);
            }
            return $base;
        }

        $data = (array)$trend['data'];
        $windows = (array)($data['windows'] ?? []);
        $w7 = (array)($windows['7'] ?? []);
        $w30 = (array)($windows['30'] ?? []);
        if ($w7 === [] || $w30 === []) {
            $base['status'] = 'ATTENTION';
            $base['notes'][] = exs_mask('Schema mismatch; please re-run generator');
            return $base;
        }

        $trend7 = (array)($w7['trend'] ?? []);
        $trend30 = (array)($w30['trend'] ?? []);
        $series7 = (array)($w7['series'] ?? []);
        $series30 = (array)($w30['series'] ?? []);
        $tm7p0 = exs_pick_trend_metric($trend7, 'p0');
        $tm7p1 = exs_pick_trend_metric($trend7, 'p1');
        $tm7p2 = exs_pick_trend_metric($trend7, 'p2');
        $tm30p0 = exs_pick_trend_metric($trend30, 'p0');
        $tm30p1 = exs_pick_trend_metric($trend30, 'p1');
        $tm30p2 = exs_pick_trend_metric($trend30, 'p2');

        $base['trend_7d'] = [
            'p0_delta' => (int)$tm7p0['delta'],
            'p1_delta' => (int)$tm7p1['delta'],
            'p2_delta' => (int)$tm7p2['delta'],
            'p0_trend' => (string)($tm7p0['trend'] ?: 'UNKNOWN'),
            'flags' => array_values(array_unique(array_map('strval', (array)($w7['flags'] ?? [])))),
            'sparkline_p0' => make_sparkline((array)($series7['p0'] ?? []), 'ascii'),
            'top_modules' => array_slice((array)($w7['top_modules'] ?? []), 0, 3),
        ];
        $base['trend_30d'] = [
            'p0_delta' => (int)$tm30p0['delta'],
            'p1_delta' => (int)$tm30p1['delta'],
            'p2_delta' => (int)$tm30p2['delta'],
            'p0_trend' => (string)($tm30p0['trend'] ?: 'UNKNOWN'),
            'flags' => array_values(array_unique(array_map('strval', (array)($w30['flags'] ?? [])))),
            'sparkline_p0' => make_sparkline((array)($series30['p0'] ?? []), 'ascii'),
            'top_modules' => array_slice((array)($w30['top_modules'] ?? []), 0, 3),
        ];

        $flagsAll = array_merge((array)$base['trend_7d']['flags'], (array)$base['trend_30d']['flags']);
        $status = 'OK';
        if (in_array('DATA_MISSING', $flagsAll, true)) {
            $status = 'DATA_MISSING';
        } elseif (in_array('DEPLOY_BLOCKER_PRESENT', $flagsAll, true) || in_array('DATA_INTEGRITY_RISK', $flagsAll, true)) {
            $status = 'ATTENTION';
        }
        $base['status'] = $status;
        if (in_array('DEPLOY_BLOCKER_PRESENT', $flagsAll, true)) {
            $base['notes'][] = exs_mask('Deploy blocker present (P0>0)');
        }
        if (in_array('DATA_INTEGRITY_RISK', $flagsAll, true)) {
            $base['notes'][] = exs_mask('Trend data integrity risk');
        }
        return $base;
    }
}

if (!function_exists('load_ops_score_trend')) {
    function load_ops_score_trend(string $env): array
    {
        $trendPath = exs_pipeline_dir() . '/ops_score_trend_last.json';
        $policyStrict = load_ops_thresholds('active-first', true);
        $base = [
            'status' => 'DATA_MISSING',
            'go_no_go' => 'UNKNOWN',
            'ops_score_current' => null,
            'metrics_current' => [
                'readiness_score' => null,
                'contract_ok' => null,
                'smoke_http_fail' => null,
                'smoke_http_warn' => null,
                'core_flows_ok' => null,
                'backlog_p0' => null,
                'backlog_p1' => null,
                'backlog_p2' => null,
                'backup_age_hours' => null,
                'sla_breach' => null,
                'data_missing_count' => 0,
            ],
            'trend_7d' => [
                'ops_score_delta' => 0,
                'readiness_score_delta' => 0,
                'backlog_p0_delta' => 0,
                'smoke_http_fail_delta' => 0,
                'flags' => ['DATA_MISSING'],
                'sparkline_ops_score' => 'DATA_MISSING',
            ],
            'trend_30d' => [
                'ops_score_delta' => 0,
                'readiness_score_delta' => 0,
                'backlog_p0_delta' => 0,
                'smoke_http_fail_delta' => 0,
                'flags' => ['DATA_MISSING'],
                'sparkline_ops_score' => 'DATA_MISSING',
            ],
            'reasons_current' => [],
            'links' => [
                'ops_score_trend' => 'storage/logs/pipeline/ops_score_trend_last.html',
                'fix_backlog_pack' => 'storage/logs/pipeline/fix_backlog_last.md',
                'readiness' => 'storage/logs/readiness_report_last.md',
                'policy_validate' => 'storage/logs/pipeline/ops_thresholds_validate_last.md',
            ],
            'commands' => [
                'php tools/ops/validate_ops_thresholds.php --path=storage/state/ops_thresholds_current.yaml --strict --write-last',
                'php tools/ops/update_ops_thresholds.php --from=docs/governance/OPS_THRESHOLDS.yaml --apply --i-understand --confirm="APPLY_OPS_POLICY" --actor=<username> --write-last',
                'php tools/ops/snapshot_ops_score.php --env=' . $env . ' --write-last',
                'php tools/ops/generate_ops_score_trend.php --env=' . $env . ' --window=both --write-last',
            ],
            'policy' => [
                'policy_id' => '',
                'fingerprint' => '',
                'updated_at' => '',
                'updated_by' => '',
                'source' => 'unknown',
                'valid' => false,
            ],
            'notes' => [],
        ];
        if (!$policyStrict['ok']) {
            $base['status'] = 'CRITICAL';
            $base['go_no_go'] = 'NO-GO';
            $base['notes'][] = exs_mask('CRITICAL: Policy invalid');
            foreach ((array)($policyStrict['errors'] ?? []) as $e) {
                $base['notes'][] = exs_mask((string)$e);
            }
            return $base;
        }
        $base['policy'] = [
            'policy_id' => (string)($policyStrict['policy']['policy_id'] ?? ''),
            'fingerprint' => (string)($policyStrict['fingerprint'] ?? ''),
            'updated_at' => (string)($policyStrict['policy']['updated_at'] ?? ''),
            'updated_by' => (string)($policyStrict['policy']['updated_by'] ?? ''),
            'source' => (string)($policyStrict['source'] ?? 'unknown'),
            'valid' => true,
        ];

        $trend = exs_read_json_strict($trendPath);
        if (!$trend['ok']) {
            if ($trend['error'] === 'missing') {
                $base['notes'][] = exs_mask('DATA_MISSING: ops score trend file not found');
                return $base;
            }
            $base['status'] = 'ATTENTION';
            $base['notes'][] = exs_mask('ATTENTION: invalid JSON in ops score trend (' . (string)$trend['error'] . ') ' . (string)$trend['error_masked']);
            return $base;
        }

        $data = (array)$trend['data'];
        $windows = (array)($data['windows'] ?? []);
        $w7 = (array)($windows['7'] ?? []);
        $w30 = (array)($windows['30'] ?? []);
        $current = (array)($data['current'] ?? []);
        if ($w7 === [] || $w30 === []) {
            $base['status'] = 'ATTENTION';
            $base['notes'][] = exs_mask('Schema mismatch; please re-run generator');
            return $base;
        }

        $base['ops_score_current'] = ops_score_int_or_null($current['ops_score'] ?? null);
        $base['status'] = (string)($current['status'] ?? 'DATA_MISSING');
        $base['go_no_go'] = (string)($current['go_no_go'] ?? 'UNKNOWN');
        $base['metrics_current'] = array_merge($base['metrics_current'], (array)($current['metrics'] ?? []));
        $base['reasons_current'] = array_slice(array_map('strval', (array)($current['reasons'] ?? [])), 0, 3);
        $base['policy'] = array_merge((array)$base['policy'], (array)($data['policy'] ?? []));
        $base['trend_7d'] = [
            'ops_score_delta' => (int)($w7['delta']['ops_score'] ?? 0),
            'readiness_score_delta' => (int)($w7['delta']['readiness_score'] ?? 0),
            'backlog_p0_delta' => (int)($w7['delta']['backlog_p0'] ?? 0),
            'smoke_http_fail_delta' => (int)($w7['delta']['smoke_http_fail'] ?? 0),
            'flags' => array_values(array_unique(array_map('strval', (array)($w7['flags'] ?? [])))),
            'sparkline_ops_score' => (string)($w7['sparkline_ops_score'] ?? 'DATA_MISSING'),
        ];
        $base['trend_30d'] = [
            'ops_score_delta' => (int)($w30['delta']['ops_score'] ?? 0),
            'readiness_score_delta' => (int)($w30['delta']['readiness_score'] ?? 0),
            'backlog_p0_delta' => (int)($w30['delta']['backlog_p0'] ?? 0),
            'smoke_http_fail_delta' => (int)($w30['delta']['smoke_http_fail'] ?? 0),
            'flags' => array_values(array_unique(array_map('strval', (array)($w30['flags'] ?? [])))),
            'sparkline_ops_score' => (string)($w30['sparkline_ops_score'] ?? 'DATA_MISSING'),
        ];
        if ($base['status'] === 'DATA_MISSING') {
            $base['notes'][] = exs_mask('ATTENTION: ops score trend produced DATA_MISSING status');
        }
        return $base;
    }
}

if (!function_exists('exs_age_seconds')) {
    function exs_age_seconds(string $path): ?int
    {
        if (!is_file($path)) return null;
        $mtime = @filemtime($path);
        if ($mtime === false) return null;
        return max(0, time() - (int)$mtime);
    }
}

if (!function_exists('exs_format_ts')) {
    function exs_format_ts(?string $iso): string
    {
        $raw = trim((string)$iso);
        if ($raw === '') return 'N/A';
        try {
            $dt = new DateTimeImmutable($raw);
            return $dt->format('Y-m-d H:i:s T');
        } catch (Throwable) {
            return exs_mask($raw);
        }
    }
}

if (!function_exists('exs_read_source')) {
    function exs_read_source(string $sourceKey, string $path): array
    {
        $r = exs_read_json_strict($path);
        return [
            'source' => $sourceKey,
            'path' => $path,
            'path_masked' => exs_mask($path),
            'ok' => (bool)$r['ok'],
            'error' => (string)$r['error'],
            'error_masked' => (string)($r['error_masked'] ?? ''),
            'age_s' => exs_age_seconds($path),
            'data' => (array)$r['data'],
        ];
    }
}

if (!function_exists('exs_bool')) {
    function exs_bool(mixed $v, ?bool $default = null): ?bool
    {
        if (is_bool($v)) return $v;
        if ($v === null) return $default;
        $s = strtolower(trim((string)$v));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
        return $default;
    }
}

if (!function_exists('exs_actor')) {
    function exs_actor(): string
    {
        if (function_exists('tools_current_actor_username')) {
            return exs_mask((string)tools_current_actor_username());
        }
        return exs_mask(trim((string)(getenv('CI_ACTOR') ?: getenv('USER') ?: 'SYSTEM')));
    }
}

if (!function_exists('exs_append_assumption')) {
    function exs_append_assumption(string $env, string $runId, string $assumption, string $impact, string $removalCommand): void
    {
        $path = exs_root() . '/docs/governance/ASSUMPTIONS.md';
        if (!is_file($path)) {
            @file_put_contents($path, "# Assumptions Log\n\n");
        }
        $line = '- ts: ' . date(DateTimeInterface::ATOM)
            . ' | env: ' . exs_mask($env)
            . ' | run_id: ' . exs_mask($runId)
            . ' | assumption: ' . exs_mask($assumption)
            . ' | impact: ' . exs_mask(strtoupper($impact))
            . ' | remove_by: ' . exs_mask($removalCommand);
        @file_put_contents($path, $line . "\n", FILE_APPEND);
    }
}

