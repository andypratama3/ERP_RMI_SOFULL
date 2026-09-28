<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = trim((string)($_GET['t'] ?? ''));
$isDemo = (string)($_GET['demo'] ?? '') === '1';
$isDemoToken = $isDemo && $token === 'demo-live-tracking';
if (!$isDemoToken && ($token === '' || !preg_match('/^[a-zA-Z0-9\-_]{32,120}$/', $token))) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

if ($isDemoToken) {
    $baseLat = -6.2088;
    $baseLng = 106.8456;
    $phase = (int)floor(time() / 3);
    $lat = $baseLat + (sin($phase / 3) * 0.0045);
    $lng = $baseLng + (cos($phase / 3) * 0.0045);
    echo json_encode([
        'success' => true,
        'data' => [
            'do_code' => 'DO-DEMO-001',
            'status' => 'on_delivery',
            'tracking_last_status' => 'in_transit',
            'tracking_last_sync_at' => date('Y-m-d H:i:s'),
            'fallback_live_location_url' => 'https://maps.google.com/?q=' . $lat . ',' . $lng,
            'scm_live_lat' => number_format($lat, 6, '.', ''),
            'scm_live_lng' => number_format($lng, 6, '.', ''),
            'scm_live_accuracy_m' => (string)rand(8, 20),
            'scm_live_at' => date('Y-m-d H:i:s'),
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = db_pdo();
$st = $pdo->prepare("
    SELECT id, do_code, status, tracking_last_status, tracking_last_sync_at,
           fallback_live_location_url, scm_live_lat, scm_live_lng, scm_live_accuracy_m, scm_live_at
    FROM sales_do
    WHERE tracking_public_token = ?
    LIMIT 1
");
$st->execute([$token]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => [
        'do_code' => (string)($row['do_code'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'tracking_last_status' => (string)($row['tracking_last_status'] ?? ''),
        'tracking_last_sync_at' => (string)($row['tracking_last_sync_at'] ?? ''),
        'fallback_live_location_url' => (string)($row['fallback_live_location_url'] ?? ''),
        'scm_live_lat' => $row['scm_live_lat'] !== null ? (string)$row['scm_live_lat'] : '',
        'scm_live_lng' => $row['scm_live_lng'] !== null ? (string)$row['scm_live_lng'] : '',
        'scm_live_accuracy_m' => $row['scm_live_accuracy_m'] !== null ? (string)$row['scm_live_accuracy_m'] : '',
        'scm_live_at' => (string)($row['scm_live_at'] ?? ''),
    ],
], JSON_UNESCAPED_SLASHES);
