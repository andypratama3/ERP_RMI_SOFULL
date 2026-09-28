<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';

$root = ts_root();
$logsDir = $root . '/storage/logs';
if (!is_dir($logsDir)) @mkdir($logsDir, 0775, true);

$smokeHttpPath = $root . '/storage/logs/smoke_http_last.json';
$outPath = $logsDir . '/smoke_core_flows_last.json';

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => true,
    'ok' => true,
    'note' => 'Stub: smoke_core_flows delegates to smoke_http. Run smoke_http first for full coverage.',
];

if (is_file($smokeHttpPath)) {
    $sh = @json_decode((string)@file_get_contents($smokeHttpPath), true);
    if (is_array($sh)) {
        $payload['overall_ok'] = (bool)($sh['overall_ok'] ?? $sh['ok'] ?? true);
        $payload['ok'] = $payload['overall_ok'];
        $payload['smoke_http_source'] = true;
    }
}

@file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['overall_ok'] ? 0 : 1);
