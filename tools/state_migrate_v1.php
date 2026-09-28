<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/tools_state_lib.php';

$logs = ts_storage_logs_dir();
$targets = [
    $logs . '/diag_db_test.last.json',
    $logs . '/diag_boot.last.json',
    $logs . '/diag_db.last.json',
    $logs . '/health.last.json',
    $logs . '/preflight_check.last.json',
    $logs . '/readiness_report_last.json',
    $logs . '/smoke_http_last.json',
];

$updated = 0;
$skipped = 0;
foreach ($targets as $path) {
    if (!is_file($path)) {
        $skipped++;
        continue;
    }
    $raw = (string)@file_get_contents($path);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $skipped++;
        continue;
    }
    if (!isset($json['state_version'])) {
        $json['state_version'] = 1;
    }
    if (str_ends_with($path, 'readiness_report_last.json') && isset($json['breakdown']) && is_array($json['breakdown'])) {
        $json['health_ok'] = (bool)($json['health_ok'] ?? ($json['breakdown']['health_ok'] ?? false));
        $json['smoke_ok'] = (bool)($json['smoke_ok'] ?? ($json['breakdown']['smoke_ok'] ?? false));
        $json['preflight_ok'] = (bool)($json['preflight_ok'] ?? ($json['breakdown']['preflight_ok'] ?? false));
    }
    if (@file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false) {
        $updated++;
        echo "[OK] " . ts_mask($path) . PHP_EOL;
    } else {
        $skipped++;
    }
}

echo "updated={$updated} skipped={$skipped}" . PHP_EOL;
