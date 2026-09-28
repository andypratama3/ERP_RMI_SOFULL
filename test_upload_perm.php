<?php
// Test upload permissions from web server context
// DELETE THIS FILE AFTER TESTING
declare(strict_types=1);
$results = [];
$dirs = ['uploads/absensi', 'uploads/sales_act', 'uploads/sales_fin', 'uploads/hrl', 'uploads/purchases_forwarding'];
$root = __DIR__;
foreach ($dirs as $d) {
    $path = $root . '/' . $d;
    $f = $path . '/test_webperm_' . time() . '.tmp';
    $ok = @file_put_contents($f, 'webserver_test') !== false;
    if ($ok) @unlink($f);
    $results[$d] = ['writable' => $ok, 'exists' => is_dir($path)];
}
header('Content-Type: application/json');
echo json_encode([
    'php_user' => get_current_user(),
    'php_uid' => getmyuid(),
    'results' => $results,
    'all_ok' => !in_array(false, array_column($results, 'writable'))
], JSON_PRETTY_PRINT);
// Self-destruct
@unlink(__FILE__);
