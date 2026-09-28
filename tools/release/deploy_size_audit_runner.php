<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

if (!function_exists('dsa_runner_snapshot')) {
    function dsa_runner_snapshot(string $root): array
    {
        $targets = [
            'node_modules',
            'storage/logs',
            'storage/backups',
            'storage/exports',
            'exports',
            'playwright-report',
            'tests-output',
            'android_app',
            'android_app/output',
        ];
        $rows = [];
        foreach ($targets as $rel) {
            $abs = $root . '/' . $rel;
            if (!is_dir($abs)) {
                continue;
            }
            $out = [];
            $code = 1;
            @exec('du -sm ' . escapeshellarg($abs) . ' 2>/dev/null', $out, $code);
            if ($code !== 0 || empty($out[0])) {
                continue;
            }
            $parts = preg_split('/\s+/', trim((string)$out[0]));
            $mb = (int)($parts[0] ?? 0);
            $rows[] = ['path' => $rel, 'size_mb' => $mb];
        }
        usort($rows, static fn(array $a, array $b): int => ($b['size_mb'] <=> $a['size_mb']));
        return $rows;
    }
}

$root = ts_root();
$logs = ts_storage_logs_dir();
$startedAt = date(DateTimeInterface::ATOM);
$runnerOut = [];
$runnerCode = 1;
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();
$phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');

$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/release/create_clean_deploy_zip.php');
@exec($cmd . ' 2>&1', $runnerOut, $runnerCode);
$runnerOut = array_map('tools_mask_sensitive', $runnerOut);

$deploy = ts_read_json($logs . '/deploy_clean_zip_last.json');
$includedBytes = (int)($deploy['included_bytes'] ?? 0);
$zipBytes = (int)($deploy['zip_size_bytes'] ?? 0);
$dirRows = dsa_runner_snapshot($root);

$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'started_at' => $startedAt,
    'exit_code' => (int)$runnerCode,
    'overall_ok' => ((int)$runnerCode === 0),
    'deploy_zip' => $deploy,
    'summary' => [
        'zip_size_bytes' => $zipBytes,
        'zip_size_mb' => round($zipBytes / 1000 / 1000, 2),
        'zip_size_mib' => round($zipBytes / 1024 / 1024, 2),
        'included_bytes' => $includedBytes,
        'included_mb' => round($includedBytes / 1000 / 1000, 2),
        'included_mib' => round($includedBytes / 1024 / 1024, 2),
    ],
    'excluded_hotspots_mb' => $dirRows,
    'output' => $runnerOut,
];

ts_write_json($logs . '/deploy_size_audit_last.json', $payload);
ts_append_run_history('deploy_size_audit_runner', ((int)$runnerCode === 0 ? 'OK' : 'FAIL'), [
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'source' => 'tools/release/deploy_size_audit_runner.php',
    'zip_size_bytes' => $zipBytes,
    'included_bytes' => $includedBytes,
    'exit_code' => (int)$runnerCode,
]);

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(((int)$runnerCode === 0) ? 0 : 1);

