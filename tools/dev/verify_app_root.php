<?php
/**
 * verify_app_root.php — Verifikasi APP_ROOT (CLI only).
 * Output JSON aman (masked). Exit 0 = OK, 2 = mismatch.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$EXPECTED = '/volume4/web/ERP_RMI_SOFULL';
$actual = realpath(__DIR__ . '/../..') ?: '';
$host = php_uname('n');

$ok = ($actual !== '' && $actual === $EXPECTED);

$mask = '[APP_ROOT]';
$out = [
    'ok' => $ok,
    'expected' => $mask,
    'actual' => $ok ? $mask : '[MISMATCH]',
    'host' => $host,
];

echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
