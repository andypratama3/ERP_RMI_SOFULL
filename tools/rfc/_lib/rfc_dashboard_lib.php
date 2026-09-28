<?php
declare(strict_types=1);

require_once __DIR__ . '/rfc_safe_file_lib.php';

if (!function_exists('rfcdash_pipeline_dir')) {
    function rfcdash_pipeline_dir(): string
    {
        $dir = rfcsf_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('rfcdash_state_path')) {
    function rfcdash_state_path(): string
    {
        return rfcsf_root() . '/storage/state/rfc_dashboard_last.json';
    }
}

if (!function_exists('rfcdash_log_json_path')) {
    function rfcdash_log_json_path(): string
    {
        return rfcdash_pipeline_dir() . '/rfc_dashboard_last.json';
    }
}

if (!function_exists('rfcdash_log_md_path')) {
    function rfcdash_log_md_path(): string
    {
        return rfcdash_pipeline_dir() . '/rfc_dashboard_last.md';
    }
}

if (!function_exists('rfcdash_audit_path')) {
    function rfcdash_audit_path(): string
    {
        return rfcsf_root() . '/storage/logs/audit_rfc_dashboard.jsonl';
    }
}

if (!function_exists('rfcdash_mask')) {
    function rfcdash_mask(string $text): string
    {
        return rfcsf_mask($text);
    }
}

if (!function_exists('rfcdash_age_seconds')) {
    function rfcdash_age_seconds(string $path): ?int
    {
        if (!is_file($path)) return null;
        $mt = @filemtime($path);
        if ($mt === false) return null;
        return max(0, time() - (int)$mt);
    }
}

if (!function_exists('rfcdash_safe_read_json')) {
    function rfcdash_safe_read_json(string $path, string $missingCode): array
    {
        $data = ts_read_json($path);
        if (!is_array($data) || $data === []) {
            return ['ok' => false, 'error' => $missingCode, 'data' => [], 'path' => $path];
        }
        return ['ok' => true, 'error' => '', 'data' => $data, 'path' => $path];
    }
}

if (!function_exists('rfcdash_load_sources')) {
    function rfcdash_load_sources(): array
    {
        $index = rfcdash_safe_read_json(rfcsf_index_path(), 'INDEX_MISSING');
        if ($index['ok'] && !isset($index['data']['rfcs'])) {
            $index = ['ok' => false, 'error' => 'INDEX_MISSING', 'data' => [], 'path' => rfcsf_index_path()];
        }
        $lintPath = rfcdash_pipeline_dir() . '/rfc_quality_lint_last.json';
        $lint = rfcdash_safe_read_json($lintPath, 'LINT_MISSING');
        if ($lint['ok'] && !isset($lint['data']['rfcs'])) {
            $lint = ['ok' => false, 'error' => 'LINT_MISSING', 'data' => [], 'path' => $lintPath];
        }
        $usagePath = rfcdash_pipeline_dir() . '/rfc_usage_last.json';
        $usage = rfcdash_safe_read_json($usagePath, 'USAGE_MISSING');
        if ($usage['ok'] && !isset($usage['data']['rfcs'])) {
            $usage = ['ok' => false, 'error' => 'USAGE_MISSING', 'data' => [], 'path' => $usagePath];
        }
        return ['index' => $index, 'lint' => $lint, 'usage' => $usage];
    }
}

if (!function_exists('rfcdash_lint_map')) {
    function rfcdash_lint_map(array $lintData): array
    {
        $map = [];
        foreach ((array)($lintData['rfcs'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $id = strtoupper(trim((string)($row['id'] ?? '')));
            if ($id === '' || $id === 'UNKNOWN') continue;
            $map[$id] = $row;
        }
        return $map;
    }
}

if (!function_exists('rfcdash_usage_map')) {
    function rfcdash_usage_map(array $usageData): array
    {
        $primary = strtoupper(trim((string)($usageData['primary_rfc_id'] ?? '')));
        $related = [];
        foreach ((array)($usageData['rfcs'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $id = strtoupper(trim((string)($row['id'] ?? '')));
            if ($id === '') continue;
            $related[$id] = true;
        }
        return ['primary' => $primary, 'related' => $related];
    }
}

if (!function_exists('rfcdash_merge_rows')) {
    function rfcdash_merge_rows(array $indexData, array $lintData, array $usageData): array
    {
        $lintMap = rfcdash_lint_map($lintData);
        $usage = rfcdash_usage_map($usageData);
        $rows = [];
        foreach ((array)($indexData['rfcs'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $id = strtoupper(trim((string)($r['id'] ?? '')));
            if ($id === '') continue;
            $lint = (array)($lintMap[$id] ?? []);
            $lintResult = strtoupper(trim((string)($lint['result'] ?? 'UNKNOWN')));
            $used = 'NO';
            if ($usage['primary'] !== '' && $id === $usage['primary']) $used = 'PRIMARY';
            elseif (!empty($usage['related'][$id])) $used = 'RELATED';
            $rows[] = [
                'id' => $id,
                'title' => rfcdash_mask((string)($r['title'] ?? '')),
                'type' => rfcdash_mask((string)($r['type'] ?? '')),
                'status' => strtoupper(trim((string)($r['status'] ?? 'UNKNOWN'))),
                'file_rel_path' => rfcdash_mask((string)($r['file'] ?? '')),
                'approvals_completed' => array_values((array)($r['approvals_completed'] ?? [])),
                'approvals_missing' => array_values((array)($r['approvals_missing'] ?? [])),
                'lint_result' => $lintResult !== '' ? $lintResult : 'UNKNOWN',
                'coverage_percent' => (float)($lint['coverage_percent'] ?? 0),
                'lint_fails' => array_values((array)($lint['fails'] ?? [])),
                'lint_warns' => array_values((array)($lint['warns'] ?? [])),
                'used_last_run' => $used,
                'schedule' => rfcdash_mask((string)($lint['schedule'] ?? '')),
                'target_env' => rfcdash_mask((string)($lint['target_env'] ?? '')),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$b['id'], (string)$a['id']));
        return $rows;
    }
}

if (!function_exists('rfcdash_filter_rows')) {
    function rfcdash_filter_rows(array $rows, array $filters): array
    {
        $status = strtoupper(trim((string)($filters['status'] ?? 'ALL')));
        $type = strtoupper(trim((string)($filters['type'] ?? 'ALL')));
        $lint = strtoupper(trim((string)($filters['lint'] ?? 'ALL')));
        $search = strtolower(trim((string)($filters['search'] ?? '')));
        $out = [];
        foreach ($rows as $r) {
            if ($status !== 'ALL' && strtoupper((string)($r['status'] ?? '')) !== $status) continue;
            if ($type !== 'ALL' && strtoupper((string)($r['type'] ?? '')) !== $type) continue;
            if ($lint !== 'ALL' && strtoupper((string)($r['lint_result'] ?? 'UNKNOWN')) !== $lint) continue;
            if ($search !== '') {
                $blob = strtolower((string)($r['id'] . ' ' . $r['title'] . ' ' . $r['type']));
                if (!str_contains($blob, $search)) continue;
            }
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('rfcdash_top_issues')) {
    function rfcdash_top_issues(array $rows): array
    {
        $issues = [];
        foreach ($rows as $r) {
            $id = (string)($r['id'] ?? 'UNKNOWN');
            $status = strtoupper((string)($r['status'] ?? 'UNKNOWN'));
            $lint = strtoupper((string)($r['lint_result'] ?? 'UNKNOWN'));
            if ($status === 'APPROVED' && $lint === 'FAIL') {
                $issues[] = ['severity' => 'CRITICAL', 'code' => 'APPROVED_LINT_FAIL', 'message' => 'RFC approved but lint is FAIL', 'rfc_id' => $id];
            }
            foreach ((array)($r['lint_fails'] ?? []) as $f) {
                $code = strtoupper((string)($f['code'] ?? ''));
                if ($code === 'MAKER_CHECKER_VIOLATION') {
                    $issues[] = ['severity' => 'CRITICAL', 'code' => 'MAKER_CHECKER_VIOLATION', 'message' => 'Maker-checker violation detected', 'rfc_id' => $id];
                }
                if ($code === 'SCHEDULE_REQUIRED') {
                    $issues[] = ['severity' => 'CRITICAL', 'code' => 'PRODUCTION_SCHEDULE_MISSING', 'message' => 'Production RFC missing schedule', 'rfc_id' => $id];
                }
            }
            foreach ((array)($r['lint_warns'] ?? []) as $w) {
                $code = strtoupper((string)($w['code'] ?? ''));
                if ($code === 'SCHEDULE_PARSE_WARN') {
                    $issues[] = ['severity' => 'ATTENTION', 'code' => 'SCHEDULE_PARSE_WARN', 'message' => 'Schedule format warning', 'rfc_id' => $id];
                }
            }
        }
        usort($issues, static function (array $a, array $b): int {
            $rank = ['CRITICAL' => 0, 'ATTENTION' => 1];
            $ra = $rank[(string)($a['severity'] ?? 'ATTENTION')] ?? 9;
            $rb = $rank[(string)($b['severity'] ?? 'ATTENTION')] ?? 9;
            if ($ra !== $rb) return $ra <=> $rb;
            return strcmp((string)($a['rfc_id'] ?? ''), (string)($b['rfc_id'] ?? ''));
        });
        return array_slice($issues, 0, 10);
    }
}

if (!function_exists('rfcdash_build_summary')) {
    function rfcdash_build_summary(array $rows, array $usageData): array
    {
        $byStatus = ['DRAFT' => 0, 'IN_REVIEW' => 0, 'APPROVED' => 0, 'IMPLEMENTED' => 0, 'CLOSED' => 0, 'REJECTED' => 0];
        $lint = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($rows as $r) {
            $st = strtoupper((string)($r['status'] ?? 'UNKNOWN'));
            if (isset($byStatus[$st])) $byStatus[$st]++;
            $lr = strtoupper((string)($r['lint_result'] ?? 'UNKNOWN'));
            if ($lr === 'PASS') $lint['pass']++;
            elseif ($lr === 'WARN') $lint['warn']++;
            elseif ($lr === 'FAIL') $lint['fail']++;
        }
        return [
            'total' => count($rows),
            'by_status' => $byStatus,
            'lint' => $lint,
            'used_last_run' => [
                'primary' => (string)($usageData['primary_rfc_id'] ?? '') !== '' ? (string)$usageData['primary_rfc_id'] : null,
                'related_count' => count((array)($usageData['rfcs'] ?? [])),
            ],
        ];
    }
}

if (!function_exists('rfcdash_overall_level')) {
    function rfcdash_overall_level(array $sources, array $summary, array $issues): string
    {
        if (!$sources['index']['ok']) return 'CRITICAL';
        if (!$sources['lint']['ok']) return 'ATTENTION';
        if (!$sources['usage']['ok']) return 'ATTENTION';
        if ((int)($summary['lint']['fail'] ?? 0) > 0) return 'CRITICAL';
        foreach ($issues as $i) {
            if (strtoupper((string)($i['severity'] ?? '')) === 'CRITICAL') return 'CRITICAL';
        }
        if ((int)($summary['lint']['warn'] ?? 0) > 0) return 'ATTENTION';
        return 'HEALTHY';
    }
}

if (!function_exists('rfcdash_build_state')) {
    function rfcdash_build_state(array $sources, array $rows): array
    {
        $summary = rfcdash_build_summary($rows, (array)$sources['usage']['data']);
        $issues = rfcdash_top_issues($rows);
        $state = [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'overall_level' => rfcdash_overall_level($sources, $summary, $issues),
            'freshness' => [
                'rfc_index_age_s' => rfcdash_age_seconds((string)$sources['index']['path']),
                'rfc_lint_age_s' => rfcdash_age_seconds((string)$sources['lint']['path']),
                'rfc_usage_age_s' => rfcdash_age_seconds((string)$sources['usage']['path']),
            ],
            'summary' => $summary,
            'top_issues' => $issues,
        ];
        return $state;
    }
}

if (!function_exists('rfcdash_render_md')) {
    function rfcdash_render_md(array $state, array $sources): string
    {
        $s = (array)($state['summary'] ?? []);
        $fresh = (array)($state['freshness'] ?? []);
        $lines = [];
        $lines[] = '# RFC Dashboard Snapshot';
        $lines[] = '';
        $lines[] = '- generated_at: ' . rfcdash_mask((string)($state['generated_at'] ?? ''));
        $lines[] = '- overall_level: ' . rfcdash_mask((string)($state['overall_level'] ?? 'UNKNOWN'));
        $lines[] = '';
        $lines[] = '| Total | PASS | WARN | FAIL | Primary RFC | Related Count |';
        $lines[] = '|---:|---:|---:|---:|---|---:|';
        $lines[] = '| ' . (int)($s['total'] ?? 0)
            . ' | ' . (int)($s['lint']['pass'] ?? 0)
            . ' | ' . (int)($s['lint']['warn'] ?? 0)
            . ' | ' . (int)($s['lint']['fail'] ?? 0)
            . ' | ' . rfcdash_mask((string)($s['used_last_run']['primary'] ?? 'null'))
            . ' | ' . (int)($s['used_last_run']['related_count'] ?? 0) . ' |';
        $lines[] = '';
        $lines[] = '## Freshness';
        $lines[] = '- rfc_index_age_s: ' . ((string)($fresh['rfc_index_age_s'] ?? 'null'));
        $lines[] = '- rfc_lint_age_s: ' . ((string)($fresh['rfc_lint_age_s'] ?? 'null'));
        $lines[] = '- rfc_usage_age_s: ' . ((string)($fresh['rfc_usage_age_s'] ?? 'null'));
        $lines[] = '';
        $lines[] = '## Critical / Attention Issues';
        $issues = (array)($state['top_issues'] ?? []);
        if ($issues === []) {
            $lines[] = '- none';
        } else {
            foreach ($issues as $i) {
                $lines[] = '- [' . rfcdash_mask((string)($i['severity'] ?? 'ATTENTION')) . '] '
                    . rfcdash_mask((string)($i['rfc_id'] ?? 'UNKNOWN')) . ' - '
                    . rfcdash_mask((string)($i['code'] ?? 'UNKNOWN'));
            }
        }
        $lines[] = '';
        $lines[] = '## Commands (if source missing)';
        if (!$sources['index']['ok']) $lines[] = '- `php tools/rfc/rfc_index_scan.php --write-last`';
        if (!$sources['lint']['ok']) $lines[] = '- `php tools/rfc/rfc_quality_lint.php --env=staging --mode=quick --write-last`';
        if (!$sources['usage']['ok']) $lines[] = '- `php tools/rfc/collect_rfc_usage.php --env=staging --run-id=last --write-last`';
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('rfcdash_write_state')) {
    function rfcdash_write_state(array $state, array $sources): void
    {
        ts_write_json(rfcdash_state_path(), $state);
        ts_write_json(rfcdash_log_json_path(), $state);
        @file_put_contents(rfcdash_log_md_path(), rfcdash_render_md($state, $sources));
    }
}

if (!function_exists('rfcdash_append_audit')) {
    function rfcdash_append_audit(string $action, string $requestId, array $meta = []): void
    {
        $payload = [
            'ts' => date(DateTimeInterface::ATOM),
            'actor_username' => tools_current_actor_username(),
            'request_id' => $requestId,
            'action' => $action,
            'meta_masked' => rfcdash_mask(json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '{}'),
        ];
        @file_put_contents(rfcdash_audit_path(), json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }
}

