<?php
/**
 * Build Executive Ops Summary — CLI. Delegates to generate_executive_summary.php.
 * Ensures executive_ops_summary_last.html in storage/logs (print-ready).
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
$script = $root . '/tools/ops/generate_executive_summary.php';

$args = implode(' ', array_map('escapeshellarg', array_slice($_SERVER['argv'] ?? [], 1)));
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
if (strpos($args, '--write-last') === false) $cmd .= ' --write-last';
if (strpos($args, '--trend') === false) $cmd .= ' --trend=7';
$cmd .= ' ' . $args;

passthru($cmd, $code);
if ($code !== 0) exit($code);

$logsDir = $root . '/storage/logs';
$pipelineDir = $root . '/storage/logs/pipeline';
$src = $pipelineDir . '/executive_ops_summary_last.html';
$dst = $logsDir . '/executive_ops_summary_last.html';
if (is_file($src)) {
    @copy($src, $dst);
}

exit(0);
