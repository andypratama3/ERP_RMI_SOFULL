<?php
/**
 * base_path_guard.php — Final gate: lock /volume4, forbid /Volumes in tools + logs.
 *
 * CRITICAL: realpath(APP_ROOT) must be under /volume4/web/ERP_RMI_SOFULL.
 * CWD, __FILE__ of caller, and scanned content must not reference Mac mount token.
 *
 * Forbidden token is built without literal in source (avoid self-scan false positive).
 *
 * Usage:
 *   $r = base_path_guard_run($root);
 *
 * Artifact: storage/logs/base_path_guard_last.json
 */
declare(strict_types=1);

if (!function_exists('base_path_guard_forbidden_mount_token')) {
    function base_path_guard_forbidden_mount_token(): string {
        return '/' . 'Volumes' . '/';
    }
}

if (!function_exists('base_path_guard_run')) {
    /**
     * @return array{ok: bool, severity: string|null, violations: array<int, array<string, mixed>>, run_at: string, expected_prefix: string}
     */
    function base_path_guard_run(?string $root = null): array {
        $runStartedAt = microtime(true);
        $legacyMtimeBefore = $runStartedAt - 300.0;

        $guardDir = __DIR__ . '/..';
        $root = $root ?? (realpath($guardDir . '/..') ?: dirname($guardDir, 2));
        $root = rtrim(str_replace('\\', '/', $root), '/');

        $expectedPrefix = '/volume4/web/ERP_RMI_SOFULL';
        $forbidden = base_path_guard_forbidden_mount_token();
        $violations = [];
        $severity = null;
        $sanitizedLegacyLogsCount = 0;
        $sanitizedLegacyFiles = [];

        // __FILE__ of this library must not live on Mac mount (when running on Mac = fail)
        $thisFile = str_replace('\\', '/', (string)realpath(__FILE__) ?: __FILE__);
        if (strpos($thisFile, $forbidden) !== false) {
            $violations[] = ['source' => 'guard_self', 'detail' => 'base_path_guard.php loaded from Mac mount path'];
            $severity = 'CRITICAL';
        }

        // 1) realpath(APP_ROOT) must equal expected exactly (strict gate, no subdir)
        $realRoot = realpath($root) ?: $root;
        $realRootNorm = rtrim(str_replace('\\', '/', $realRoot), '/');
        if ($realRootNorm !== $expectedPrefix) {
            $violations[] = [
                'source' => 'app_root',
                'detail' => 'APP_ROOT must equal exactly ' . $expectedPrefix . ' (strict)',
            ];
            $severity = 'CRITICAL';
        }
        if (strpos($realRootNorm, $forbidden) !== false) {
            $violations[] = ['source' => 'app_root', 'detail' => 'APP_ROOT resolves to forbidden mount'];
            $severity = 'CRITICAL';
        }

        // 2) CWD — no Mac mount token; must be under APP_ROOT
        $cwd = getcwd() ?: '';
        $cwdNorm = rtrim(str_replace('\\', '/', $cwd), '/');
        if (strpos($cwdNorm, $forbidden) !== false) {
            $violations[] = ['source' => 'cwd', 'detail' => 'CWD contains forbidden mount token'];
            $severity = 'CRITICAL';
        }
        if ($cwdNorm !== '' && $cwdNorm !== $realRootNorm && !str_starts_with($cwdNorm, $realRootNorm . '/')) {
            $violations[] = ['source' => 'cwd', 'detail' => 'CWD must be under APP_ROOT'];
            $severity = 'CRITICAL';
        }

        // 3) Recursive scan tools/ (PHP, sh, json, md, yml — same extensions as path police family)
        $excludeBasenames = [
            'base_path_guard.php', 'path_guard.php', 'path_police.php',
            'volumes_police.php', 'volumes_guard.php', 'repo_location_guard.php',
            'repo_location_audit.php', 'erp.sh', 'assert_app_root.sh',
            'run_cutover_checks.php',
            'audit_live.php', 'audit_realtime_rmi.php',
            'testsprite_gate.php', 'testsprite_regression.php',
            'cek_workspace_path.php', 'refresh_control_center.php',
            'menu_dashboard_sync.php',
            'build_final_gate_summary.php',
            'run_final_gate_dual.php', 'run_gate_dual.php',
            'gate_artifacts_sync.php', 'monitoring_heartbeat.php',
            'reset_for_golive.php',
            'rbac_completeness_check.php',
        ];
        $extOk = ['php', 'sh', 'json', 'md', 'yml', 'yaml', 'env', 'ini', 'mdc', 'jsonl'];
        $toolsDir = $root . '/tools';
        if (is_dir($toolsDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($toolsDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $bn = $f->getFilename();
                if (in_array($bn, $excludeBasenames, true)) {
                    continue;
                }
                $ext = strtolower((string)pathinfo($bn, PATHINFO_EXTENSION));
                if (!in_array($ext, $extOk, true)) {
                    continue;
                }
                $content = (string)@file_get_contents($f->getPathname());
                if ($content !== '' && strpos($content, $forbidden) !== false) {
                    $rel = str_replace($root . '/', '', str_replace('\\', '/', $f->getPathname()));
                    $violations[] = ['source' => 'tools_file', 'path' => $rel, 'token' => 'FORBIDDEN_MOUNT'];
                    $severity = 'CRITICAL';
                }
            }
        }

        // 4) storage/logs — jejak path SMB Mac (/Volumes/…): legacy boleh di-sanitize; file BARU = CRITICAL.
        $logsDir = $root . '/storage/logs';
        $artExclude = ['base_path_guard_last.json', 'path_guard_last.json'];
        $forbiddenInLogs = $forbidden; // "/Volumes/"
        $legacyReplace = '[FORBIDDEN_MOUNT]/';
        if (is_dir($logsDir)) {
            foreach (glob($logsDir . '/*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $bn = basename($file);
                if (in_array($bn, $artExclude, true)) {
                    continue;
                }
                $ext = strtolower((string)pathinfo($bn, PATHINFO_EXTENSION));
                if (!in_array($ext, ['json', 'jsonl', 'txt', 'log', 'md'], true)) {
                    continue;
                }
                $raw = (string)@file_get_contents($file);
                if ($raw === '' || strpos($raw, $forbiddenInLogs) === false) {
                    continue;
                }
                // Known tool artifacts: always scrub (no CRITICAL) — writers must use tools_safe_json_file_put_contents.
                $alwaysSanitizeBasenames = ['assumptions_last.json', 'assumptions_log_last.json'];
                if (in_array($bn, $alwaysSanitizeBasenames, true)) {
                    $clean = str_replace($forbiddenInLogs, $legacyReplace, $raw);
                    if ($clean !== $raw && @file_put_contents($file, $clean) !== false) {
                        $sanitizedLegacyLogsCount++;
                        $sanitizedLegacyFiles[] = 'storage/logs/' . $bn;
                    }
                    continue;
                }
                $mtime = (float)@filemtime($file);
                if ($mtime < $legacyMtimeBefore) {
                    $clean = str_replace($forbiddenInLogs, $legacyReplace, $raw);
                    if ($clean !== $raw && @file_put_contents($file, $clean) !== false) {
                        $sanitizedLegacyLogsCount++;
                        $sanitizedLegacyFiles[] = 'storage/logs/' . $bn;
                    }
                    continue;
                }
                $violations[] = [
                    'source' => 'storage_logs',
                    'path' => 'storage/logs/' . $bn,
                    'token' => 'FORBIDDEN_MAC_SMB_PATH',
                    'detail' => 'new_or_recent_log_contains_forbidden_mount',
                ];
                $severity = 'CRITICAL';
                break;
            }
        }

        // 5) Config files at project root
        foreach ([$root . '/.env', $root . '/.expected_app_root'] as $cf) {
            if (!is_file($cf)) {
                continue;
            }
            $content = (string)@file_get_contents($cf);
            if (strpos($content, $forbidden) !== false) {
                $violations[] = ['source' => 'config', 'path' => basename($cf)];
                $severity = 'CRITICAL';
            }
        }

        // 6) Repo PHP/MD under app (no /Volumes/ in source) — exclude heavy dirs
        // Code only — skip docs/ (may document Mac mount path for developers) and .cursor
        $scanDirs = ['_shared', 'master', 'sales', 'purchases', 'stock', 'dashboards', 'rbac', 'api', 'chat', 'absensi', 'hrl_process', 'payroll', 'mpr', 'Fixed_Asset', 'hrl', 'manufacturer_portal'];
        $repoExcludeFragments = ['/vendor/', '/node_modules/', '/.git/', '/storage/logs/', '/storage/backups/'];
        foreach ($scanDirs as $sub) {
            $dir = $root . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
            } catch (Throwable $e) {
                continue;
            }
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $full = str_replace('\\', '/', $f->getPathname());
                $skipPath = false;
                foreach ($repoExcludeFragments as $frag) {
                    if (str_contains($full, $frag)) {
                        $skipPath = true;
                        break;
                    }
                }
                if ($skipPath) {
                    continue;
                }
                $ext = strtolower((string)pathinfo($f->getFilename(), PATHINFO_EXTENSION));
                if (!in_array($ext, ['php', 'md', 'mdc'], true)) {
                    continue;
                }
                $content = (string)@file_get_contents($full);
                if ($content !== '' && strpos($content, $forbidden) !== false) {
                    $rel = str_replace($root . '/', '', $full);
                    $violations[] = ['source' => 'repo_file', 'path' => $rel, 'token' => 'FORBIDDEN_MOUNT'];
                    $severity = 'CRITICAL';
                    break 2;
                }
            }
        }

        $ok = count($violations) === 0;
        return [
            'ok' => $ok,
            'severity' => $severity,
            'violations' => $violations,
            'run_at' => date(DateTimeInterface::ATOM),
            'expected_prefix' => $expectedPrefix,
            'sanitized_legacy_logs_count' => $sanitizedLegacyLogsCount,
            'sanitized_legacy_files' => $sanitizedLegacyFiles,
            'run_started_at' => date(DateTimeInterface::ATOM, (int)$runStartedAt),
        ];
    }
}
