<?php
/**
 * Run Plan — CLI. Wajib assert app_root lock (NAS).
 * Delegates to run_pipeline_final.php.
 * Base URL dari TOOLS_BASE_URL (NAS-first). Ephemeral php -S disabled.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
require_once $root . '/tools/_lib/tools_paths.php';
tools_assert_app_root_locked_cli();

$args = $_SERVER['argv'] ?? [];
$planId = '';
$env = 'staging';
$writeLast = false;
$strict = false;
foreach ($args as $a) {
    if (!is_string($a)) continue;
    if (str_starts_with($a, '--id=')) $planId = trim(substr($a, 5));
    if (str_starts_with($a, '--env=')) $env = strtolower(trim(substr($a, 6)));
    if ($a === '--write-last') $writeLast = true;
    if ($a === '--strict') $strict = true;
}

if ($planId === '') {
    echo "Usage: php tools/qa/plans/run_plan.php --id=<plan_id> [--env=staging] [--write-last] [--strict]\n";
    exit(1);
}

$php = (string)(defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php');
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/qa/run_pipeline_final.php');
$cmd .= ' --plan=' . escapeshellarg($planId);
$cmd .= ' --env=' . escapeshellarg($env);
if ($writeLast) $cmd .= ' --write-last';
if ($strict) $cmd .= ' --strict';

passthru($cmd, $code);
exit((int)$code);
