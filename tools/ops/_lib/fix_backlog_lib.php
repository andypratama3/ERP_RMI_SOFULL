<?php
declare(strict_types=1);

require_once __DIR__ . '/ops_helpers.php';

if (!function_exists('fb_stage_normalize')) {
    function fb_stage_normalize(string $stage): string
    {
        $num = preg_replace('/[^0-9]/', '', trim($stage)) ?? '';
        if ($num === '') {
            return '';
        }
        $n = (int)$num;
        if ($n < 7 || $n > 16) {
            return '';
        }
        return str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('fb_mask')) {
    function fb_mask(string $value): string
    {
        return ops_mask($value);
    }
}

if (!function_exists('fb_root')) {
    function fb_root(): string
    {
        return ops_root();
    }
}

if (!function_exists('fb_pipeline_dir')) {
    function fb_pipeline_dir(): string
    {
        $dir = fb_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('fb_priority_rank')) {
    function fb_priority_rank(string $priority): int
    {
        return match (strtoupper(trim($priority))) {
            'P0' => 3,
            'P1' => 2,
            'P2' => 1,
            default => 0,
        };
    }
}

if (!function_exists('fb_severity_rank')) {
    function fb_severity_rank(string $severity): int
    {
        return match (strtoupper(trim($severity))) {
            'CRITICAL' => 4,
            'HIGH' => 3,
            'MED' => 2,
            'LOW' => 1,
            default => 0,
        };
    }
}

if (!function_exists('fb_priority_from_severity')) {
    function fb_priority_from_severity(string $severity): string
    {
        return match (strtoupper(trim($severity))) {
            'CRITICAL', 'HIGH' => 'P0',
            'MED' => 'P1',
            'LOW' => 'P2',
            default => 'P1',
        };
    }
}

if (!function_exists('fb_sla_by_priority')) {
    function fb_sla_by_priority(string $priority): string
    {
        return match (strtoupper(trim($priority))) {
            'P0' => '24h',
            'P1' => '3d',
            'P2' => '7d',
            default => '7d',
        };
    }
}

if (!function_exists('fb_module_for_stage')) {
    function fb_module_for_stage(string $stage, string $category = '', string $title = '', string $details = ''): string
    {
        $s = fb_stage_normalize($stage);
        if ($s === '07') return 'STOCK';
        if ($s === '08') return 'SALES';
        if ($s === '09') return 'PURCHASES';
        if ($s === '10') return 'FINANCE';
        if ($s === '11') {
            $blob = strtoupper($category . ' ' . $title . ' ' . $details);
            $hasBank = str_contains($blob, 'BANK');
            $hasTax = str_contains($blob, 'TAX');
            if ($hasBank && !$hasTax) return 'BANK';
            if ($hasTax && !$hasBank) return 'TAX';
            return 'BANK_TAX';
        }
        if ($s === '12') return 'REPORTS';
        if ($s === '13') return 'MOBILE';
        if ($s === '14') return 'OPS';
        if ($s === '15') return 'CI';
        if ($s === '16') return 'PROD';
        return 'OTHER';
    }
}

if (!function_exists('fb_deterministic_backlog_id')) {
    function fb_deterministic_backlog_id(string $stage, string $suggestionId): string
    {
        $s = fb_stage_normalize($stage);
        $sugg = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $suggestionId) ?? '');
        if ($sugg === '') {
            $sugg = 'UNKNOWN';
        }
        return 'BLG-S' . ($s !== '' ? $s : '00') . '-SUGG-' . $sugg;
    }
}

if (!function_exists('fb_read_json')) {
    function fb_read_json(string $path): array
    {
        $res = ops_read_json_safe($path);
        return [
            'ok' => (bool)($res['ok'] ?? false),
            'error' => (string)($res['error'] ?? 'missing'),
            'data' => (array)($res['data'] ?? []),
            'path_masked' => fb_mask($path),
        ];
    }
}

if (!function_exists('fb_suggestion_path')) {
    function fb_suggestion_path(string $stage, string $inputMode, string $runId): string
    {
        $base = fb_pipeline_dir() . '/manifest_suggest_stage' . $stage . '_';
        if ($inputMode === 'run-id') {
            return $base . $runId . '.json';
        }
        return $base . 'last.json';
    }
}

if (!function_exists('fb_pipeline_last_meta')) {
    function fb_pipeline_last_meta(): array
    {
        $dir = fb_pipeline_dir();
        $paths = [
            $dir . '/pipeline_last.json',
            $dir . '/pipeline_run_last.json',
        ];
        foreach ($paths as $path) {
            $read = fb_read_json($path);
            if ($read['ok']) {
                return [
                    'ok' => true,
                    'data' => $read['data'],
                    'path_masked' => $read['path_masked'],
                ];
            }
        }
        return ['ok' => false, 'data' => [], 'path_masked' => 'unknown'];
    }
}

if (!function_exists('fb_stage_executed_in_pipeline')) {
    function fb_stage_executed_in_pipeline(array $pipelineLast, string $stage): bool
    {
        if (!isset($pipelineLast['stages']) || !is_array($pipelineLast['stages'])) {
            return false;
        }
        foreach ($pipelineLast['stages'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['stage'] ?? '') === $stage) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('fb_merge_actions')) {
    function fb_merge_actions(array $left, array $right): array
    {
        $merged = [];
        $seen = [];
        foreach ([$left, $right] as $src) {
            foreach ($src as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $key = json_encode($action, JSON_UNESCAPED_SLASHES);
                if (!is_string($key) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $action;
            }
        }
        return $merged;
    }
}

if (!function_exists('fb_csv_escape')) {
    function fb_csv_escape(string $value): string
    {
        $needs = str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n");
        $v = str_replace('"', '""', $value);
        return $needs ? '"' . $v . '"' : $v;
    }
}

if (!function_exists('fb_build_summary')) {
    function fb_build_summary(array $items): array
    {
        $summary = [
            'p0' => 0,
            'p1' => 0,
            'p2' => 0,
            'by_module' => [],
            'data_missing' => 0,
        ];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $p = strtoupper((string)($item['priority'] ?? ''));
            if ($p === 'P0') $summary['p0']++;
            if ($p === 'P1') $summary['p1']++;
            if ($p === 'P2') $summary['p2']++;
            if (strtoupper((string)($item['category'] ?? '')) === 'DATA_MISSING') {
                $summary['data_missing']++;
            }
            $m = (string)($item['module'] ?? 'OTHER');
            if (!isset($summary['by_module'][$m])) {
                $summary['by_module'][$m] = ['total' => 0, 'p0' => 0, 'p1' => 0, 'p2' => 0];
            }
            $summary['by_module'][$m]['total']++;
            if ($p === 'P0') $summary['by_module'][$m]['p0']++;
            if ($p === 'P1') $summary['by_module'][$m]['p1']++;
            if ($p === 'P2') $summary['by_module'][$m]['p2']++;
        }
        ksort($summary['by_module']);
        return $summary;
    }
}

if (!function_exists('fb_sort_items')) {
    function fb_sort_items(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $pa = fb_priority_rank((string)($a['priority'] ?? ''));
            $pb = fb_priority_rank((string)($b['priority'] ?? ''));
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            $sa = fb_severity_rank((string)($a['severity'] ?? ''));
            $sb = fb_severity_rank((string)($b['severity'] ?? ''));
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return strcmp((string)($a['backlog_id'] ?? ''), (string)($b['backlog_id'] ?? ''));
        });
        return $items;
    }
}

if (!function_exists('fb_md_from_payload')) {
    function fb_md_from_payload(array $payload): string
    {
        $summary = (array)($payload['summary'] ?? []);
        $items = (array)($payload['items'] ?? []);
        $lines = [];
        $lines[] = '# Fix Backlog Pack (Stage 07–16)';
        $lines[] = '';
        $lines[] = '- env: ' . fb_mask((string)($payload['env'] ?? 'unknown'));
        $lines[] = '- run_id: ' . fb_mask((string)($payload['run_id'] ?? 'unknown'));
        $lines[] = '- generated_at: ' . fb_mask((string)($payload['generated_at'] ?? 'unknown'));
        $lines[] = '- overall_ok: ' . (!empty($payload['overall_ok']) ? 'true' : 'false');
        $lines[] = '';
        $lines[] = '## Summary';
        $lines[] = '- P0: ' . (int)($summary['p0'] ?? 0);
        $lines[] = '- P1: ' . (int)($summary['p1'] ?? 0);
        $lines[] = '- P2: ' . (int)($summary['p2'] ?? 0);
        $lines[] = '- DATA_MISSING: ' . (int)($summary['data_missing'] ?? 0);
        $lines[] = '';
        $lines[] = '### Module heatmap';
        $lines[] = '| Module | Total | P0 | P1 | P2 |';
        $lines[] = '|---|---:|---:|---:|---:|';
        foreach ((array)($summary['by_module'] ?? []) as $module => $counts) {
            $lines[] = '| ' . $module
                . ' | ' . (int)($counts['total'] ?? 0)
                . ' | ' . (int)($counts['p0'] ?? 0)
                . ' | ' . (int)($counts['p1'] ?? 0)
                . ' | ' . (int)($counts['p2'] ?? 0)
                . ' |';
        }
        $lines[] = '';

        $renderTable = static function (string $title, array $rows) use (&$lines): void {
            $lines[] = '## ' . $title;
            $lines[] = '| ID | Stage | Module | Category | Title | SLA | Owner | Next Action |';
            $lines[] = '|---|---|---|---|---|---|---|---|';
            if ($rows === []) {
                $lines[] = '| - | - | - | - | - | - | - | - |';
                $lines[] = '';
                return;
            }
            foreach ($rows as $row) {
                $next = '-';
                $actions = (array)($row['recommended_actions'] ?? []);
                if ($actions !== []) {
                    $a0 = (array)$actions[0];
                    $next = (string)($a0['value_masked'] ?? ($a0['target'] ?? (string)($a0['action'] ?? '-')));
                    $next = str_replace('|', '/', fb_mask($next));
                }
                $lines[] = '| ' . (string)($row['backlog_id'] ?? '-')
                    . ' | ' . (string)($row['stage'] ?? '-')
                    . ' | ' . (string)($row['module'] ?? '-')
                    . ' | ' . (string)($row['category'] ?? '-')
                    . ' | ' . str_replace('|', '/', (string)($row['title'] ?? '-'))
                    . ' | ' . (string)($row['sla_target'] ?? '-')
                    . ' | ' . (string)($row['owner'] ?? 'TBD')
                    . ' | ' . $next
                    . ' |';
            }
            $lines[] = '';
        };

        $p0 = [];
        $p1 = [];
        $p2 = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $p = strtoupper((string)($it['priority'] ?? ''));
            if ($p === 'P0') $p0[] = $it;
            elseif ($p === 'P1') $p1[] = $it;
            else $p2[] = $it;
        }
        $renderTable('P0 Must Fix (Deploy Blockers)', array_slice($p0, 0, 10));
        $renderTable('P1 Next', $p1);
        $renderTable('P2 Later', $p2);

        $cmds = [];
        foreach ($items as $it) {
            foreach ((array)($it['recommended_actions'] ?? []) as $action) {
                if (($action['action'] ?? '') !== 'RUN_COMMAND') continue;
                $v = trim((string)($action['value_masked'] ?? ''));
                if ($v === '') continue;
                $cmds[$v] = true;
            }
        }
        $lines[] = '## Commands Quick Run';
        if ($cmds === []) {
            $lines[] = '- none';
        } else {
            foreach (array_keys($cmds) as $cmd) {
                $lines[] = '- ' . fb_mask($cmd);
            }
        }
        $lines[] = '';

        $lines[] = '## Patch Snippets Index';
        $hasPatch = false;
        foreach ($items as $it) {
            foreach ((array)($it['recommended_actions'] ?? []) as $action) {
                if (($action['action'] ?? '') !== 'PATCH_SNIPPET') continue;
                $hasPatch = true;
                $target = fb_mask((string)($action['target'] ?? 'unknown'));
                $src = fb_mask((string)($it['source_artifact'] ?? 'unknown'));
                $snippet = (string)($action['snippet'] ?? '{}');
                $small = substr($snippet, 0, 220);
                $lines[] = '- ' . (string)($it['backlog_id'] ?? '-') . ' -> `' . $target . '` (see source suggestion json: `' . $src . '`)';
                $lines[] = '```json';
                $lines[] = $small;
                $lines[] = '```';
            }
        }
        if (!$hasPatch) {
            $lines[] = '- none';
        }
        $lines[] = '';
        $lines[] = 'This pack is read-only generated; no auto-edits performed.';
        $lines[] = '';
        return implode("\n", $lines);
    }
}

