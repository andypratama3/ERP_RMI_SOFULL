<?php
declare(strict_types=1);

require_once __DIR__ . '/_lib/ops_helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("Method Not Allowed\n");
}

$daysOpt = null;
foreach (($argv ?? []) as $arg) {
    if (strpos((string)$arg, '--days=') === 0) {
        $daysOpt = (int)substr((string)$arg, 7);
    }
}

$windows = $daysOpt ? [max(1, $daysOpt)] : [7, 30];
$written = [];

foreach ($windows as $days) {
    $payload = ops_trend_payload($days);
    $contract = ops_contract_check_types($payload, [
        'state_version' => 'int',
        'generated_at' => 'string',
        'window_days' => 'int',
        'series' => 'array',
    ]);
    if (!$contract['ok']) {
        fwrite(STDERR, "trend contract mismatch\n");
        exit(2);
    }
    $name = $days === 7 ? 'ops_trend_7d_last.json' : ($days === 30 ? 'ops_trend_30d_last.json' : ('ops_trend_' . $days . 'd_last.json'));
    $path = ops_state_path($name);
    ops_write_json($path, $payload);
    $written[] = '[APP_ROOT]/storage/logs/' . $name;
}

echo json_encode([
    'ok' => true,
    'written' => $written,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
