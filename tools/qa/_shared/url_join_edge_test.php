<?php
declare(strict_types=1);

/**
 * CLI mini-tests for URL join / normalization (gate: no double-slash, no 301 false mismatch).
 *
 * Usage: php tools/qa/_shared/url_join_edge_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
require_once $root . '/tools/_shared/url_utils.php';

$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$fail): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        $fail++;
    }
};

$base = 'https://10.10.60.20/ERP_RMI_SOFULL';
$assert(
    rmi_url_join($base, '/purchases/po.php') === 'https://10.10.60.20/ERP_RMI_SOFULL/purchases/po.php',
    'simple join'
);
$assert(
    rmi_url_join(rtrim($base, '/') . '/', '//sales/sales_dashboard.php') === 'https://10.10.60.20/ERP_RMI_SOFULL/sales/sales_dashboard.php',
    'double-leading path segments'
);
$assert(
    rmi_canonical_url_join($base, '/ERP_RMI_SOFULL/master/login.php')
        === 'https://10.10.60.20/ERP_RMI_SOFULL/master/login.php',
    'canonical strips duplicate app prefix in path'
);
$assert(
    !rmi_url_has_double_slash_path(rmi_url_join($base, '/a/b.php')),
    'result has no double slash in path'
);
$assert(
    rmi_normalize_url_slashes('https://host//x//y.php') === 'https://host/x/y.php',
    'normalize_url_slashes'
);

exit($fail > 0 ? 2 : 0);
