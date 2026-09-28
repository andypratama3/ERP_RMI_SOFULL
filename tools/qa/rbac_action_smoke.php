<?php
/**
 * rbac_action_smoke.php — Back-compat wrapper: dual RBAC matrix HTTP + action matrix extract.
 *
 * Runs:
 *   1) tools/qa/rbac_matrix_http_dual.php
 *   2) tools/qa/rbac_action_matrix.php
 *
 * @deprecated Prefer calling the two scripts explicitly in cutover.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);
require_once $root . '/tools/_shared/tools_bootstrap.php';

$args = $_SERVER['argv'] ?? [];
$phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
$argStr = implode(' ', array_map('escapeshellarg', $args));

$code1 = 1;
passthru(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/rbac_matrix_http_dual.php') . ' ' . $argStr, $code1);
if ((int)$code1 !== 0) {
    exit((int)$code1);
}

$code2 = 1;
passthru(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/rbac_action_matrix.php') . ' ' . $argStr, $code2);
exit((int)$code2);
