<?php
/**
 * Weekly Ops Report — CLI wrapper. Delegates to generate_weekly_ops_report.php.
 * Creates weekly_ops_report_latest.md + .json symlinks.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("CLI only\n");
}

require_once __DIR__ . '/../_lib/tools_paths.php';
tools_assert_app_root_locked_cli();

$php = (string)(defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php');
$root = tools_app_root();
$script = $root . '/tools/ops/generate_weekly_ops_report.php';

passthru(escapeshellarg($php) . ' ' . escapeshellarg($script), $code);
if ($code !== 0) exit($code);

$logsDir = $root . '/storage/logs';
require_once $root . '/tools/_lib/tools_state.php';
$pointer = tools_read_json_safe($logsDir . '/weekly_ops_report_last.json');
if ($pointer['ok'] && isset($pointer['data']['latest_md'])) {
    $weekKey = (string)($pointer['data']['week'] ?? '');
    $mdSrc = $logsDir . '/weekly_ops_report_' . $weekKey . '.md';
    $jsonSrc = $logsDir . '/weekly_ops_report_' . $weekKey . '.json';
    if (is_file($mdSrc)) {
        @copy($mdSrc, $logsDir . '/weekly_ops_report_latest.md');
    }
    if (is_file($jsonSrc)) {
        @copy($jsonSrc, $logsDir . '/weekly_ops_report_latest.json');
    }
}

exit(0);
