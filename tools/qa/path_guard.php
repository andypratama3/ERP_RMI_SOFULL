<?php
/**
 * tools/qa/path_guard.php — CRITICAL FAIL if /Volumes/ in repo paths, config, or output.
 * Source of truth: BASE = /volume4/web/ERP_RMI_SOFULL.
 * Usage: php tools/qa/path_guard.php [--write-last] [--strict]
 * Output: storage/logs/path_guard_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);
$strict = in_array('--strict', $args, true);

$FORBIDDEN = ['/Volumes/'];
$violations = [];
$scannedCount = 0;

// 1) Current execution context
$cwd = getcwd() ?: '';
$realRoot = realpath($root) ?: $root;
if (strpos($cwd, '/Volumes/') !== false) {
    $violations[] = ['source' => 'cwd', 'path' => '[REDACTED]', 'detail' => 'CWD contains /Volumes/'];
}
if (strpos($realRoot, '/Volumes/') !== false) {
    $violations[] = ['source' => 'realpath_root', 'path' => '[REDACTED]', 'detail' => 'APP_ROOT resolves to /Volumes/'];
}

// 2) Scan PHP files in tools/, tools/qa/
// Exclude: meta-files that legitimately reference /Volumes/ as forbidden token, guard concept,
// or Mac mount path documentation. Harus sama dengan base_path_guard.php exclusion list.
$excludeBasenames = [
    // Guard files yang memang scan untuk /Volumes/ sebagai konsep
    'path_guard.php', 'path_police.php', 'volumes_police.php', 'volumes_guard.php',
    'repo_location_guard.php', 'repo_location_audit.php',
    // CLI runner / orchestrator
    'run_cutover_checks.php', 'run_gate_dual.php', 'run_final_gate_dual.php',
    // Audit & gate files yang mendiskusikan /Volumes/ sebagai topik scan
    'audit_live.php', 'audit_realtime_rmi.php',
    'build_final_gate_summary.php',
    'testsprite_gate.php', 'testsprite_regression.php',
    // Tools yang memerlukan path /Volumes/ untuk operasi Mac-side
    'cek_workspace_path.php',
    // NAS shell scripts
    'erp.sh', 'assert_app_root.sh',
    // Ops tools yang discuss /Volumes/ sebagai guard condition
    'refresh_control_center.php', 'reset_for_golive.php',
    // Gate / reporting tools
    'gate_artifacts_sync.php', 'monitoring_heartbeat.php',
    'menu_dashboard_sync.php',
];
$phpPatterns = [
    $root . '/tools/*.php',
    $root . '/tools/qa/*.php',
    $root . '/tools/ops/*.php',
    $root . '/tools/nas/*.sh',
];
foreach ($phpPatterns as $pattern) {
    foreach (glob($pattern) ?: [] as $file) {
        if (!is_file($file) || in_array(basename($file), $excludeBasenames, true)) continue;
        $scannedCount++;
        $content = (string)@file_get_contents($file);
        foreach ($FORBIDDEN as $token) {
            if (strpos($content, $token) !== false) {
                $violations[] = [
                    'source' => 'file_content',
                    'path' => str_replace($root, '[APP_ROOT]', $file),
                    'token' => $token,
                ];
                break;
            }
        }
    }
}

// 3) Scan storage/logs/*.json (last artifacts)
$jsonFiles = glob($root . '/storage/logs/*.json') ?: [];
$exclude = ['path_guard_last.json', 'path_police_last.json', 'repo_location_guard_last.json', 'volumes_guard_last.json', 'volumes_police_last.json'];
foreach ($jsonFiles as $file) {
    if (in_array(basename($file), $exclude, true)) continue;
    $scannedCount++;
    $decoded = @json_decode((string)@file_get_contents($file), true);
    if (!is_array($decoded)) continue;
    $toCheck = [];
    foreach (['cwd', 'cmd', 'app_root', 'base_url', 'path', 'artifact'] as $k) {
        if (isset($decoded[$k]) && is_string($decoded[$k])) $toCheck[] = $decoded[$k];
    }
    foreach (['results', 'checks'] as $arr) {
        if (isset($decoded[$arr]) && is_array($decoded[$arr])) {
            foreach ($decoded[$arr] as $item) {
                if (is_array($item) && isset($item['path']) && is_string($item['path'])) $toCheck[] = $item['path'];
            }
        }
    }
    foreach ($toCheck as $val) {
        if (strpos($val, '/Volumes/') !== false) {
            $violations[] = ['source' => 'artifact', 'path' => str_replace($root, '[APP_ROOT]', $file), 'value_masked' => '[REDACTED]'];
            break;
        }
    }
}

// 4) Config files
$configFiles = [$root . '/.env', $root . '/.expected_app_root'];
foreach ($configFiles as $cf) {
    if (!is_file($cf)) continue;
    $scannedCount++;
    $content = (string)@file_get_contents($cf);
    if (strpos($content, '/Volumes/') !== false) {
        $violations[] = ['source' => 'config', 'path' => basename($cf)];
    }
}

$ok = count($violations) === 0;
$result = [
    'ok' => $ok,
    'run_at' => date(DateTimeInterface::ATOM),
    'scanned' => $scannedCount,
    'violations' => $violations,
    'forbidden_tokens' => $FORBIDDEN,
    'message' => $ok ? 'No /Volumes/ references found.' : 'CRITICAL: /Volumes/ found in repo paths or output.',
];

if ($writeLast) {
    @mkdir($root . '/storage/logs', 0775, true);
    file_put_contents($root . '/storage/logs/path_guard_last.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
