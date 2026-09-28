<?php
/**
 * tools/qa/base_path_guard.php — CLI runner for base path guard.
 *
 * Usage: php tools/qa/base_path_guard.php [--write-last] [--strict]
 * Artifact: storage/logs/base_path_guard_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/_shared/base_path_guard.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);
$strict = in_array('--strict', $args, true);

$result = base_path_guard_run($root);

if ($writeLast) {
    $logsDir = $root . '/storage/logs';
    if (!function_exists('ts_storage_logs_dir')) {
        require_once $root . '/tools/tools_state_lib.php';
    }
    $logsDir = function_exists('ts_storage_logs_dir') ? ts_storage_logs_dir() : $root . '/storage/logs';
    @mkdir($logsDir, 0775, true);
    @file_put_contents($logsDir . '/base_path_guard_last.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : ($strict ? 2 : 1));
