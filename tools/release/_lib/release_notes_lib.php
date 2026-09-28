<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/../../tools_ui_helpers.php';
require_once __DIR__ . '/../../rfc/_lib/rfc_lib.php';

if (!function_exists('rn_root')) {
    function rn_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('rn_export_dir')) {
    function rn_export_dir(): string
    {
        $dir = rn_root() . '/storage/exports/release';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('rn_mask')) {
    function rn_mask(string $text): string
    {
        $masked = ts_mask(tools_mask_sensitive($text));
        $deny = ['/Users/', 'C:\\', 'BEGIN PRIVATE KEY', 'Authorization:', 'DB_PASS', 'token', 'password', 'cookie', 'secret', 'key'];
        foreach ($deny as $d) {
            $masked = str_ireplace($d, '[REDACTED]', $masked);
        }
        return $masked;
    }
}

if (!function_exists('rn_deny_scan')) {
    function rn_deny_scan(string $text): array
    {
        $patterns = ['/Users/', 'C:\\', 'BEGIN PRIVATE KEY', 'Authorization:', 'DB_PASS'];
        $hits = [];
        foreach ($patterns as $p) {
            if (stripos($text, $p) !== false) $hits[] = $p;
        }
        return array_values(array_unique($hits));
    }
}

if (!function_exists('rn_read_json')) {
    function rn_read_json(string $path): array
    {
        if (!is_file($path)) return ['ok' => false, 'data' => [], 'error' => 'missing'];
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') return ['ok' => false, 'data' => [], 'error' => 'empty'];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return ['ok' => false, 'data' => [], 'error' => 'invalid_json'];
        return ['ok' => true, 'data' => $decoded, 'error' => ''];
    }
}

if (!function_exists('rn_find_pipeline_artifact')) {
    function rn_find_pipeline_artifact(string $pipelineMode, string $runId): array
    {
        $base = rn_root() . '/storage/logs/pipeline';
        if ($pipelineMode === 'run-id') {
            $path = $base . '/pipeline_run_' . $runId . '.json';
            $read = rn_read_json($path);
            return ['path' => $path, 'masked' => rn_mask($path), 'ok' => $read['ok'], 'data' => $read['data'], 'error' => $read['error']];
        }
        $path = $base . '/pipeline_last.json';
        $read = rn_read_json($path);
        return ['path' => $path, 'masked' => rn_mask($path), 'ok' => $read['ok'], 'data' => $read['data'], 'error' => $read['error']];
    }
}

if (!function_exists('rn_extract_section')) {
    function rn_extract_section(string $body, string $heading): string
    {
        $pattern = '/^##\s+' . preg_quote($heading, '/') . '\s*$(.*?)(?=^##\s+|\z)/ms';
        if (preg_match($pattern, $body, $m) === 1) {
            return trim((string)$m[1]);
        }
        return '';
    }
}

if (!function_exists('rn_list_lines')) {
    function rn_list_lines(string $section): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $section) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            if (str_starts_with($line, '- ')) $line = trim(substr($line, 2));
            if (preg_match('/^\d+\.\s+/', $line) === 1) $line = trim((string)preg_replace('/^\d+\.\s+/', '', $line));
            if ($line !== '') $out[] = rn_mask($line);
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('rn_file_sha256')) {
    function rn_file_sha256(string $path): string
    {
        return is_file($path) ? (string)hash_file('sha256', $path) : '';
    }
}

if (!function_exists('rn_git_meta')) {
    function rn_git_meta(): array
    {
        $root = rn_root();
        $res = ['commit' => 'UNKNOWN', 'branch' => 'UNKNOWN', 'tag' => 'UNKNOWN', 'dirty' => 'UNKNOWN', 'notes' => []];
        $gitDir = $root . '/.git';
        if (!is_dir($gitDir)) {
            $res['notes'][] = 'git_unavailable';
            return $res;
        }
        $cmds = [
            'commit' => 'git rev-parse HEAD',
            'branch' => 'git rev-parse --abbrev-ref HEAD',
            'tag' => 'git describe --tags --always',
            'dirty' => 'git status --porcelain',
        ];
        foreach ($cmds as $k => $cmd) {
            $out = [];
            $code = 1;
            @exec($cmd . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $res['notes'][] = 'git_' . $k . '_unknown';
                continue;
            }
            $line = trim((string)($out[0] ?? ''));
            if ($k === 'dirty') {
                $res['dirty'] = ($line === '') ? 'false' : 'true';
            } else {
                $res[$k] = $line !== '' ? rn_mask($line) : 'UNKNOWN';
            }
        }
        return $res;
    }
}

if (!function_exists('rn_collect_artifacts')) {
    function rn_collect_artifacts(array $pipelineData, string $runId): array
    {
        $root = rn_root();
        $items = [];
        $pipelinePath = $root . '/storage/logs/pipeline/' . ($runId !== '' ? ('pipeline_run_' . $runId . '.json') : 'pipeline_last.json');
        if (!is_file($pipelinePath)) $pipelinePath = $root . '/storage/logs/pipeline/pipeline_last.json';
        $items['pipeline_json'] = is_file($pipelinePath) ? $pipelinePath : '';
        $fixed = [
            'executive_summary_json' => $root . '/storage/logs/pipeline/executive_ops_summary_last.json',
            'executive_summary_html' => $root . '/storage/logs/pipeline/executive_ops_summary_last.html',
            'readiness_report_json' => $root . '/storage/logs/readiness_report_last.json',
            'readiness_report_md' => $root . '/storage/logs/readiness_report_last.md',
            'smoke_http_json' => $root . '/storage/logs/smoke_http_last.json',
            'contract_check_json' => $root . '/storage/logs/contract_check_last.json',
            'core_flows_json' => $root . '/storage/logs/smoke_core_flows_last.json',
            'fix_backlog_json' => $root . '/storage/logs/pipeline/fix_backlog_last.json',
            'fix_backlog_md' => $root . '/storage/logs/pipeline/fix_backlog_last.md',
            'fix_backlog_trend_json' => $root . '/storage/logs/pipeline/fix_backlog_trend_last.json',
            'fix_backlog_trend_html' => $root . '/storage/logs/pipeline/fix_backlog_trend_last.html',
            'ops_score_trend_json' => $root . '/storage/logs/pipeline/ops_score_trend_last.json',
            'ops_score_trend_html' => $root . '/storage/logs/pipeline/ops_score_trend_last.html',
            'rfc_validate_json' => $root . '/storage/logs/pipeline/rfc_validate_last.json',
            'rfc_gate_json' => $root . '/storage/logs/pipeline/rfc_gate_last.json',
        ];
        foreach ($fixed as $k => $p) {
            $items[$k] = is_file($p) ? $p : '';
        }
        return $items;
    }
}

if (!function_exists('rn_gate_from_artifacts')) {
    function rn_gate_from_artifacts(array $artifacts): array
    {
        $reasons = [];
        $contract = null;
        $smokeFail = null;
        $coreFlows = null;
        $readiness = null;
        $goNoGo = 'UNKNOWN';

        $contractData = $artifacts['contract_check_json'] !== '' ? rn_read_json($artifacts['contract_check_json']) : ['ok' => false];
        if ($contractData['ok']) {
            $d = (array)$contractData['data'];
            $contract = isset($d['overall_ok']) ? (bool)$d['overall_ok'] : (isset($d['ok']) ? (bool)$d['ok'] : null);
        }
        $smokeData = $artifacts['smoke_http_json'] !== '' ? rn_read_json($artifacts['smoke_http_json']) : ['ok' => false];
        if ($smokeData['ok']) {
            $d = (array)$smokeData['data'];
            if (isset($d['fail'])) $smokeFail = (int)$d['fail'];
            elseif (isset($d['summary']['fail'])) $smokeFail = (int)$d['summary']['fail'];
        }
        $coreData = $artifacts['core_flows_json'] !== '' ? rn_read_json($artifacts['core_flows_json']) : ['ok' => false];
        if ($coreData['ok']) {
            $d = (array)$coreData['data'];
            $coreFlows = isset($d['overall_ok']) ? (bool)$d['overall_ok'] : (isset($d['ok']) ? (bool)$d['ok'] : null);
        }
        $readyData = $artifacts['readiness_report_json'] !== '' ? rn_read_json($artifacts['readiness_report_json']) : ['ok' => false];
        if ($readyData['ok']) {
            $d = (array)$readyData['data'];
            if (isset($d['score'])) $readiness = (int)$d['score'];
        }
        if ($contract === false) $reasons[] = 'CONTRACT_FAIL';
        if ($smokeFail !== null && $smokeFail > 0) $reasons[] = 'SMOKE_HTTP_FAIL_GT_0';
        if ($coreFlows === false) $reasons[] = 'CORE_FLOWS_FAIL';
        if ($readiness !== null && $readiness < 100) $reasons[] = 'READINESS_LT_100';
        if ($contract === null) $reasons[] = 'CONTRACT_UNKNOWN';
        if ($smokeFail === null) $reasons[] = 'SMOKE_HTTP_UNKNOWN';
        if ($coreFlows === null) $reasons[] = 'CORE_FLOWS_UNKNOWN';
        if ($readiness === null) $reasons[] = 'READINESS_UNKNOWN';

        if ($reasons === [] && $contract === true && $coreFlows === true && $smokeFail === 0 && $readiness === 100) {
            $goNoGo = 'GO';
        } elseif ($contract === false || $coreFlows === false || ($smokeFail !== null && $smokeFail > 0)) {
            $goNoGo = 'NO-GO';
        }
        return [
            'contract_ok' => $contract,
            'smoke_http_fail' => $smokeFail,
            'core_flows_ok' => $coreFlows,
            'readiness_score' => $readiness,
            'go_no_go' => $goNoGo,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }
}

if (!function_exists('rn_approval_summary')) {
    function rn_approval_summary(array $fm): array
    {
        $required = array_values(array_unique(array_map(static fn($v): string => strtoupper(trim((string)$v)), (array)($fm['REQUIRES_APPROVALS'] ?? []))));
        $completed = [];
        foreach ((array)($fm['APPROVALS'] ?? []) as $a) {
            $role = strtoupper(trim((string)($a['ROLE'] ?? '')));
            if ($role !== '') $completed[] = $role;
        }
        $completed = array_values(array_unique($completed));
        $missing = [];
        foreach ($required as $r) if (!in_array($r, $completed, true)) $missing[] = $r;
        return ['required' => $required, 'completed' => $completed, 'missing' => $missing];
    }
}

if (!function_exists('rn_stage_reports')) {
    function rn_stage_reports(): array
    {
        $out = [];
        $root = rn_root();
        for ($i = 7; $i <= 16; $i++) {
            $name = 'S' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '_STAGE_REPORT.md';
            $path = $root . '/docs/stages/' . $name;
            if (is_file($path)) $out[] = $path;
        }
        return $out;
    }
}

if (!function_exists('rn_render_md')) {
    function rn_render_md(array $payload, string $zipName, string $zipSha): string
    {
        $rfc = (array)$payload['rfc'];
        $g = (array)$payload['gates'];
        $c = (array)$payload['changes'];
        $o = (array)$payload['operational'];
        $a = (array)$rfc['approvals_summary'];
        $lines = [];
        $lines[] = '# Release Notes - ' . rn_mask((string)$rfc['id']) . ' - ' . rn_mask((string)$payload['env']) . ' - ' . rn_mask((string)$payload['generated_at']);
        $lines[] = '';
        $lines[] = 'Executive Summary';
        $lines[] = 'Release terkait RFC ' . rn_mask((string)$rfc['id']) . ' untuk kebutuhan ' . rn_mask((string)$rfc['type']) . '. Keputusan kualitas mengikuti evidence pipeline terbaru, dampak operasional mengikuti status gate dan rollback plan RFC.';
        $lines[] = '';
        $lines[] = '## Scope';
        foreach ((array)($c['high_level'] ?? []) as $h) $lines[] = '- ' . rn_mask((string)$h);
        $lines[] = '';
        $lines[] = '## Gates & Quality';
        $lines[] = '| Gate | Value |';
        $lines[] = '|---|---|';
        $lines[] = '| Contract ok | ' . rn_mask(json_encode($g['contract_ok'])) . ' |';
        $lines[] = '| Smoke HTTP fails | ' . rn_mask((string)($g['smoke_http_fail'] ?? 'null')) . ' |';
        $lines[] = '| Core flows ok | ' . rn_mask(json_encode($g['core_flows_ok'])) . ' |';
        $lines[] = '| Readiness score | ' . rn_mask((string)($g['readiness_score'] ?? 'null')) . ' |';
        $lines[] = '| GO/NO-GO | **' . rn_mask((string)$g['go_no_go']) . '** |';
        $lines[] = '';
        $lines[] = '## Changes by Module';
        foreach ((array)($c['by_module'] ?? []) as $m) {
            $lines[] = '- ' . rn_mask((string)($m['module'] ?? 'UNKNOWN')) . ': ' . rn_mask((string)($m['summary'] ?? ''));
        }
        $lines[] = '';
        $lines[] = '## Database Changes';
        foreach ((array)($c['migrations'] ?? []) as $m) $lines[] = '- ' . rn_mask((string)$m);
        $lines[] = '';
        $lines[] = '## Operational Notes';
        $lines[] = '- Hypercare: ' . rn_mask((string)($o['hypercare_plan'] ?? ''));
        foreach ((array)($o['known_risks'] ?? []) as $r) $lines[] = '- Risk: ' . rn_mask((string)$r);
        $lines[] = '';
        $lines[] = '## Rollback Plan';
        foreach ((array)($o['rollback_plan'] ?? []) as $r) $lines[] = '- ' . rn_mask((string)$r);
        $lines[] = '';
        $lines[] = '## Evidence Pack';
        $lines[] = '- pack: `' . rn_mask($zipName) . '`';
        $lines[] = '- sha256: `' . rn_mask($zipSha) . '`';
        $lines[] = '';
        $lines[] = '## Sign-off';
        $lines[] = '- Release: ____';
        $lines[] = '- Eng Lead: ____';
        $lines[] = '- QA: ____';
        $lines[] = '- Security: ____';
        $lines[] = '- Approval completion: ' . count((array)$a['completed']) . '/' . count((array)$a['required']);
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('rn_render_html')) {
    function rn_render_html(array $payload, string $zipName, string $zipSha): string
    {
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $g = (array)$payload['gates'];
        $r = (array)$payload['rfc'];
        $c = (array)$payload['changes'];
        $a = (array)($r['approvals_summary'] ?? ['required' => [], 'completed' => []]);
        $badge = ($g['go_no_go'] ?? 'UNKNOWN') === 'GO' ? '#2e7d32' : ((($g['go_no_go'] ?? '') === 'NO-GO') ? '#c62828' : '#ef6c00');
        $changes = '';
        $limit = 0;
        foreach ((array)($c['by_module'] ?? []) as $m) {
            if ($limit >= 6) break;
            $changes .= '<li>' . $esc(rn_mask((string)($m['module'] ?? 'UNKNOWN'))) . ': ' . $esc(rn_mask((string)($m['summary'] ?? ''))) . '</li>';
            $limit++;
        }
        if ($changes === '') $changes = '<li>UNKNOWN</li>';
        return '<!doctype html><html><head><meta charset="utf-8"><title>Release Notes</title><style>body{font-family:Arial,sans-serif;margin:8mm;font-size:10.5px}h1{margin:0 0 4px}h2{font-size:12px;margin:8px 0 4px}.badge{display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-weight:700}ul{margin:4px 0;padding-left:16px}p{margin:3px 0}@media print{@page{size:A4;margin:8mm}}</style></head><body><h1>Release Notes ' . $esc(rn_mask((string)$r['id'])) . '</h1><p><span class="badge" style="background:' . $esc($badge) . ';">' . $esc(rn_mask((string)($g['go_no_go'] ?? 'UNKNOWN'))) . '</span> | env=' . $esc(rn_mask((string)$payload['env'])) . ' | mode=' . $esc(rn_mask((string)$payload['mode'])) . '</p><p>Contract=' . $esc(rn_mask(json_encode($g['contract_ok']))) . ' | SmokeFail=' . $esc(rn_mask((string)($g['smoke_http_fail'] ?? 'null'))) . ' | CoreFlows=' . $esc(rn_mask(json_encode($g['core_flows_ok']))) . ' | Readiness=' . $esc(rn_mask((string)($g['readiness_score'] ?? 'null'))) . '</p><h2>Top Changes</h2><ul>' . $changes . '</ul><p>Rollback trigger: follow RFC rollback plan by owner.</p><p>Evidence pack: ' . $esc(rn_mask($zipName)) . '</p><p>Pack sha256: ' . $esc(rn_mask($zipSha)) . '</p><p>Approvals: ' . count((array)$a['completed']) . '/' . count((array)$a['required']) . '</p></body></html>';
    }
}

