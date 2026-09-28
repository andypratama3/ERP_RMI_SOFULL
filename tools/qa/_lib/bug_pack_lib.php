<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/bug_pack_redact.php';
require_once __DIR__ . '/bug_pack_allowlist.php';

if (!function_exists('bpl_root')) {
    function bpl_root(): string
    {
        return ts_root();
    }
}

if (!function_exists('bpl_state_allowlist')) {
    function bpl_state_allowlist(): array
    {
        $logs = bpl_root() . '/storage/logs';
        $pipeline = $logs . '/pipeline';
        return [
            $logs . '/health.last.json' => 'state/health.last.json',
            $logs . '/preflight_check.last.json' => 'state/preflight_check.last.json',
            $logs . '/contract_check_last.json' => 'state/contract_check_last.json',
            $logs . '/smoke_http_last.json' => 'state/smoke_http_last.json',
            $logs . '/smoke_core_flows_last.json' => 'state/smoke_core_flows_last.json',
            $logs . '/tools_dashboard_smoke.last.json' => 'state/tools_dashboard_smoke.last.json',
            $logs . '/readiness_report_last.json' => 'state/readiness_report_last.json',
            $pipeline . '/executive_ops_summary_last.json' => 'state/executive_ops_summary_last.json',
            $pipeline . '/plan_exec_summary_last.json' => 'state/plan_exec_summary_last.json',
            $pipeline . '/arch_audit_last.json' => 'state/arch_audit_last.json',
            $pipeline . '/arch_audit_last_one_pager.md' => 'state/arch_audit_last_one_pager.md',
        ];
    }
}

if (!function_exists('bpl_endpoints_allowlist')) {
    function bpl_endpoints_allowlist(): array
    {
        $root = bpl_root();
        return [
            $root . '/tools/qa/http_endpoints_stage16.json' => 'endpoints/http_endpoints_stage16.json',
            $root . '/tools/qa/contract_whitelist_stage16.json' => 'endpoints/contract_whitelist_stage16.json',
        ];
    }
}

if (!function_exists('bpl_collect_state_files')) {
    /**
     * @param int $maxBytes
     * @return array{included:array, missing:array, fix_commands:array}
     */
    function bpl_collect_state_files(string $root, int $maxBytes, bool $strict): array
    {
        $included = [];
        $missing = [];
        $fixCommands = [
            'php tools/health.php',
            'php tools/preflight_check.php',
            'php tools/smoke_http.php',
            'php tools/qa/contract_check.php --strict --write-last',
            'php tools/qa/smoke_core_flows.php',
            'php tools/qa/tools_dashboard_smoke.php',
            'php tools/qa/run_pipeline_final.php --plan=staging_gate_before_merge_strict_http --run-id=auto --write-last',
            'php tools/ops/generate_executive_summary.php --env=staging --run-id=auto --write-last',
            'php tools/qa/run_pipeline_final.php --plan=full_staging_00_16 --run-id=auto --write-last',
            'php tools/qa/arch_audit.php --run-id=auto --scope=repo --write-last',
        ];
        $allowlist = bpl_state_allowlist();
        foreach ($allowlist as $src => $rel) {
            if (!is_file($src)) {
                $missing[] = ['path' => bpr_mask_path($src, $root), 'reason' => 'MISSING'];
                continue;
            }
            $raw = (string)@file_get_contents($src);
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            if ($ext === 'json') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    $content = bpr_redact_text($raw);
                    $content = substr($content, 0, $maxBytes);
                    $scan = bpr_deny_pattern_scan($content, $root);
                    if (!$scan['ok'] && $strict) {
                        $missing[] = ['path' => bpr_mask_path($src, $root), 'reason' => 'REDACTION_VIOLATION'];
                        continue;
                    }
                    $included[] = ['rel' => $rel, 'content' => $content, 'bytes' => strlen($content), 'redacted' => true, 'invalid_json' => true];
                    continue;
                }
                $redacted = bpr_redact_array($decoded);
                $content = json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } else {
                $content = bpr_redact_text($raw);
            }
            $scan = bpr_deny_pattern_scan($content, $root);
            if (!$scan['ok'] && $strict) {
                $missing[] = ['path' => bpr_mask_path($src, $root), 'reason' => 'REDACTION_VIOLATION'];
                continue;
            }
            $content = substr($content, 0, $maxBytes);
            $included[] = ['rel' => $rel, 'content' => $content, 'bytes' => strlen($content), 'redacted' => true];
        }
        return ['included' => $included, 'missing' => $missing, 'fix_commands' => $fixCommands];
    }
}

if (!function_exists('bpl_collect_logs')) {
    /**
     * @param string $profile tools|erp_full
     * @return array{included:array, missing:array, log_tails_for_request_id:array}
     */
    function bpl_collect_logs(string $root, int $maxBytes, int $maxFiles, string $profile = 'tools'): array
    {
        $logsDir = $root . '/storage/logs';
        $pipelineDir = $logsDir . '/pipeline';
        $included = [];
        $candidates = [];
        $moduleKeywords = ['sales', 'purchases', 'stock', 'finance', 'auth', 'api', 'tools'];
        if (is_dir($logsDir)) {
            foreach (glob($logsDir . '/*.log') ?: [] as $p) {
                if (is_file($p)) {
                    $base = basename($p);
                    $hasModule = false;
                    foreach ($moduleKeywords as $kw) {
                        if (stripos($base, $kw) !== false) { $hasModule = true; break; }
                    }
                    $candidates[] = ['path' => $p, 'mtime' => (int)@filemtime($p), 'type' => 'log', 'subdir' => '', 'module_match' => $hasModule];
                }
            }
        }
        if (is_dir($logsDir . '/webserver')) {
            foreach (glob($logsDir . '/webserver/*.log') ?: [] as $p) {
                if (is_file($p)) {
                    $candidates[] = ['path' => $p, 'mtime' => (int)@filemtime($p), 'type' => 'log', 'subdir' => 'webserver', 'module_match' => false];
                }
            }
        }
        if (is_dir($pipelineDir)) {
            foreach (glob($pipelineDir . '/*.json') ?: [] as $p) {
                $base = basename($p);
                if (in_array($base, ['plan_exec_summary_last.json', 'executive_ops_summary_last.json', 'arch_audit_last.json', 'pipeline_last.json'], true)) {
                    $candidates[] = ['path' => $p, 'mtime' => (int)@filemtime($p), 'type' => 'json', 'subdir' => 'pipeline', 'module_match' => false];
                }
            }
        }
        $jsonlFiles = ['tools_run_history.jsonl', 'audit_arch_audit.jsonl', 'audit_change_control_health.jsonl'];
        foreach ($jsonlFiles as $f) {
            $p = $logsDir . '/' . $f;
            if (is_file($p)) {
                $candidates[] = ['path' => $p, 'mtime' => (int)@filemtime($p), 'type' => 'jsonl', 'subdir' => '', 'module_match' => false];
            }
        }
        usort($candidates, static function ($a, $b) {
            $mt = $b['mtime'] <=> $a['mtime'];
            if ($mt !== 0) return $mt;
            return strcmp($a['path'], $b['path']);
        });
        $topN = $profile === 'erp_full' ? 10 : 5;
        $taken = 0;
        $moduleTaken = 0;
        $logTailsForRequestId = [];
        foreach ($candidates as $c) {
            if ($taken >= $maxFiles) break;
            $take = false;
            if ($taken < $topN) {
                $take = true;
            } elseif ($profile === 'erp_full' && ($c['module_match'] ?? false) && $moduleTaken < 5) {
                $take = true;
                $moduleTaken++;
            }
            if (!$take) continue;
            $size = (int)@filesize($c['path']);
            $len = min($size, $maxBytes);
            $offset = max(0, $size - $len);
            $raw = $len > 0 ? (string)@file_get_contents($c['path'], false, null, $offset, $len) : '';
            $redacted = bpr_redact_text($raw);
            $scan = bpr_deny_pattern_scan($redacted, $root);
            if (!$scan['ok']) continue;
            $subdir = (string)($c['subdir'] ?? '');
            $rel = $subdir !== '' ? 'logs/' . $subdir . '/' . basename($c['path']) : 'logs/' . basename($c['path']);
            $included[] = ['rel' => $rel, 'content' => $redacted, 'bytes' => strlen($redacted), 'redacted' => true];
            $logTailsForRequestId[] = ['name' => basename($c['path']), 'content' => $raw];
            $taken++;
        }
        return ['included' => $included, 'missing' => [], 'log_tails_for_request_id' => $logTailsForRequestId];
    }
}

if (!function_exists('bpl_env_summary')) {
    function bpl_env_summary(string $root): array
    {
        return [
            'APP_ENV' => getenv('APP_ENV') ?: 'unknown',
            'php_version' => PHP_VERSION,
            'timezone' => date_default_timezone_get(),
            'writable' => [
                'storage/logs' => is_writable($root . '/storage/logs'),
                'storage/state' => is_writable($root . '/storage/state'),
                'storage/exports' => is_writable($root . '/storage/exports'),
            ],
        ];
    }
}

if (!function_exists('bpl_git_summary')) {
    function bpl_git_summary(string $root): array
    {
        $gitDir = $root . '/.git';
        if (!is_dir($gitDir)) {
            return ['present' => false, 'commit' => null, 'branch' => null, 'dirty' => null];
        }
        $commit = null;
        $branch = null;
        $dirty = null;
        $out = [];
        @exec('cd ' . escapeshellarg($root) . ' && git rev-parse HEAD 2>/dev/null', $out);
        if (!empty($out)) $commit = trim((string)$out[0]);
        $out = [];
        @exec('cd ' . escapeshellarg($root) . ' && git rev-parse --abbrev-ref HEAD 2>/dev/null', $out);
        if (!empty($out)) $branch = trim((string)$out[0]);
        $out = [];
        @exec('cd ' . escapeshellarg($root) . ' && git status --porcelain 2>/dev/null', $out);
        $dirty = !empty($out) ? 'yes' : 'no';
        return ['present' => true, 'commit' => $commit, 'branch' => $branch, 'dirty' => $dirty];
    }
}
