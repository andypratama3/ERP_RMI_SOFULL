<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/db.php';

$pdo = rmi_db_pdo();
$checks = [];
$ok = true;

$requiredTables = ['sales_do', 'sales_do_tracking_events'];
$requiredColumns = [
    'sales_do' => [
        'carrier_provider',
        'carrier_tracking_no',
        'carrier_courier_code',
        'tracking_public_token',
        'tracking_last_sync_at',
        'tracking_last_status',
        'tracking_last_payload_json',
        'fallback_live_location_url',
        'scm_live_lat',
        'scm_live_lng',
        'scm_live_accuracy_m',
        'scm_live_at',
    ],
];

foreach ($requiredTables as $table) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $st->execute([$table]);
    $exists = ((int)$st->fetchColumn() > 0);
    $checks[] = ['type' => 'table', 'name' => $table, 'ok' => $exists];
    if (!$exists) $ok = false;
}

foreach ($requiredColumns as $table => $cols) {
    foreach ($cols as $col) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute([$table, $col]);
        $exists = ((int)$st->fetchColumn() > 0);
        $checks[] = ['type' => 'column', 'name' => $table . '.' . $col, 'ok' => $exists];
        if (!$exists) $ok = false;
    }
}

$requiredFiles = [
    __DIR__ . '/../../app/Services/SalesTrackingService.php',
    __DIR__ . '/../../api/v1/internal/sales_tracking_sync.php',
    __DIR__ . '/../../api/v1/internal/sales_scm_geo_ping.php',
    __DIR__ . '/../../sales/tracking_public.php',
    __DIR__ . '/../../sales/scm_tracker_mobile.php',
    __DIR__ . '/../../sales/scm-tracker-manifest.json',
    __DIR__ . '/../../sales/scm-tracker-sw.js',
    __DIR__ . '/../../tools/ops/sales_tracking_sync.php',
];
foreach ($requiredFiles as $file) {
    $exists = is_file($file);
    $checks[] = ['type' => 'file', 'name' => str_replace(__DIR__ . '/../../', '', $file), 'ok' => $exists];
    if (!$exists) $ok = false;
}

$out = [
    'state_version' => 1,
    'checked_at' => date('c'),
    'ok' => $ok,
    'checks' => $checks,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
