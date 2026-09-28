<?php
/**
 * audit_trail_probe.php — Verifikasi request_id + minimal event tercatat.
 * Wrapper untuk audit_e2e_probe; output artifact: audit_trail_probe_last.json
 *
 * Usage: php tools/qa/audit_trail_probe.php [--write-last] [--strict] [--lookback-days=N]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args = $_SERVER['argv'] ?? [];
$phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');

$lookback = 14;
foreach ($args as $a) {
    if (is_string($a) && str_starts_with($a, '--lookback-days=')) {
        $lookback = max(1, min(90, (int)trim(substr($a, 15))));
        break;
    }
}
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/audit_e2e_probe.php') . ' --write-last --strict --lookback-days=' . $lookback;
@exec($cmd . ' 2>&1', $_, $code);

$logsDir = $root . '/storage/logs';
if (function_exists('ts_storage_logs_dir')) {
    require_once $root . '/tools/tools_state_lib.php';
    $logsDir = ts_storage_logs_dir();
}
$src = $logsDir . '/audit_e2e_probe_last.json';
$dst = $logsDir . '/audit_trail_probe_last.json';
if (is_file($src)) {
    $data = json_decode((string)file_get_contents($src), true);
    if (is_array($data)) {
        @file_put_contents($dst, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

exit((int)$code);
