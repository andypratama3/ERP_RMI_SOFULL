<?php
declare(strict_types=1);

/**
 * Security Scanner - CLI runner
 * Scan SQLi, XSS, path traversal, hardcoded credentials.
 * Jalankan: php tools/audit/security_scanner.php [--scope=core|full] [--write-last]
 */

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/audit/_lib/security_scanner_lib.php';

$args = $_SERVER['argv'] ?? [];
$scope = 'core';
foreach ($args as $a) {
    if (str_starts_with($a, '--scope=')) {
        $scope = trim(substr($a, 8));
        break;
    }
}
if (!in_array($scope, ['core', 'full'], true)) $scope = 'core';

$result = secscan_run($root, $scope);

$writeLast = in_array('--write-last', $args, true);
if ($writeLast) {
    require_once $root . '/tools/tools_state_lib.php';
    ts_write_json(ts_storage_logs_dir() . '/audit_security_scanner_last.json', $result);
}

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
