<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/_lib/release_pack_lib.php';
require_once __DIR__ . '/_lib/release_verify_lib.php';

$args = $_SERVER['argv'] ?? [];
$zip = '';
$manifest = '';
$strict = false;
$mode = 'quick';
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--strict') $strict = true;
    elseif (str_starts_with($arg, '--zip=')) $zip = trim((string)substr($arg, 6));
    elseif (str_starts_with($arg, '--manifest=')) $manifest = trim((string)substr($arg, 11));
    elseif (str_starts_with($arg, '--mode=')) $mode = strtolower(trim((string)substr($arg, 7)));
}
if ($zip !== '' && !str_starts_with($zip, '/')) $zip = rn_root() . '/' . ltrim($zip, '/');
if ($manifest !== '' && !str_starts_with($manifest, '/')) $manifest = rn_root() . '/' . ltrim($manifest, '/');
if (!in_array($mode, ['quick', 'full'], true)) $mode = 'quick';

$policy = ['max_zip_bytes' => rp_max_bytes()];
$verifyStruct = $mode === 'full' ? verify_pack_full($zip, $manifest, $policy) : verify_pack_quick($zip, $manifest, $policy);
$status = (string)($verifyStruct['status'] ?? 'FAIL');
$errors = [];
foreach ((array)($verifyStruct['fails'] ?? []) as $f) {
    $errors[] = (string)($f['code'] ?? 'ERR_UNKNOWN');
}
if ($strict && $status === 'WARN') {
    foreach ((array)($verifyStruct['warnings'] ?? []) as $w) {
        $errors[] = (string)($w['code'] ?? 'WARN_UNKNOWN');
    }
}
$overallOk = $strict ? ($status === 'OK') : ($status !== 'FAIL');
echo json_encode([
    'state_version' => 1,
    'overall_ok' => $overallOk,
    'strict' => $strict,
    'mode' => $mode,
    'status' => $status,
    'zip' => rv_mask($zip),
    'manifest' => rv_mask($manifest),
    'checks' => (array)($verifyStruct['checks'] ?? []),
    'warnings' => (array)($verifyStruct['warnings'] ?? []),
    'fails' => (array)($verifyStruct['fails'] ?? []),
    'errors_masked' => array_values(array_unique(array_map(static fn(string $e): string => rv_mask($e), $errors))),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);

