<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/rfc_index_lib.php';

$writeLast = false;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--write-last') {
        $writeLast = true;
    }
}

$payload = rfc_scan_index();
$statePath = rfc_index_state_path();
if ($writeLast) {
    ts_write_json($statePath, $payload);
}

$out = [
    'state_version' => 1,
    'overall_ok' => true,
    'generated_at' => (string)$payload['generated_at'],
    'count' => (int)$payload['count'],
    'state_path' => rfc_mask($statePath),
    'written' => $writeLast,
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

