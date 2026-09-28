<?php
declare(strict_types=1);

/**
 * PHP Static Analysis - CLI runner
 * Jalankan: php tools/audit/php_static_analysis.php [--scope=core|full] [--write-last]
 */

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/audit/_lib/php_static_analysis_lib.php';

$args = $_SERVER['argv'] ?? [];
$scope = 'core';
foreach ($args as $a) {
    if (str_starts_with($a, '--scope=')) {
        $scope = trim(substr($a, 8));
        break;
    }
}
if (!in_array($scope, ['core', 'full'], true)) $scope = 'core';

$phpBin = (string)(defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php');
$result = psal_run($root, $scope, $phpBin);

$writeLast = in_array('--write-last', $args, true);
if ($writeLast) {
    require_once $root . '/tools/tools_state_lib.php';
    ts_write_json(ts_storage_logs_dir() . '/audit_php_static_analysis_last.json', $result);
}

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
