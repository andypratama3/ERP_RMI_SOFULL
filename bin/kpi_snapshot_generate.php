<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../app/Dashboard/DashboardDetailService.php';

use App\Dashboard\DashboardDetailService;

$opts = getopt('', ['date::', 'month::', 'year::']);
$asOf = trim((string)($opts['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    fwrite(STDERR, "Invalid --date format, use YYYY-MM-DD\n");
    exit(1);
}
$monthNo = isset($opts['month']) ? (int)$opts['month'] : (int)date('m', strtotime($asOf));
$yearNo = isset($opts['year']) ? (int)$opts['year'] : (int)date('Y', strtotime($asOf));
if ($monthNo < 1 || $monthNo > 12) {
    $monthNo = (int)date('m', strtotime($asOf));
}
if ($yearNo < 2000 || $yearNo > 2100) {
    $yearNo = (int)date('Y', strtotime($asOf));
}

$pdo = rmi_db_pdo();
$svc = new DashboardDetailService($pdo);
$data = $svc->build(str_pad((string)$monthNo, 2, '0', STR_PAD_LEFT), $yearNo, $asOf);

$rows = [];
foreach ($data['section2']['main']['rows'] as $r) {
    $rows[] = $r;
}
foreach ($data['section2']['accunit']['rows'] as $r) {
    // Keep finance detail snapshot per office once.
    // ACCUNIT rows are retained as segment-specific snapshots.
    $rows[] = $r + ['_segment_override' => 'ACCUNIT'];
}
foreach ($data['section2']['tangerang']['rows'] as $r) {
    $rows[] = $r + ['_segment_override' => 'TANGERANG'];
}

$officeToId = [];
foreach ($data['office_meta'] as $oc => $meta) {
    if (isset($meta['id'])) {
        $officeToId[$oc] = (int)$meta['id'];
    }
}

$sql = "INSERT INTO kpi_daily_snapshots (snapshot_date, office_id, segment, metrics_json, created_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE metrics_json=VALUES(metrics_json), created_at=NOW()";
$st = $pdo->prepare($sql);

$count = 0;
foreach ($rows as $r) {
    $officeCode = strtoupper((string)($r['office_code'] ?? ''));
    if ($officeCode === '' || !isset($officeToId[$officeCode])) {
        continue;
    }
    $segment = (string)($r['_segment_override'] ?? 'FINANCE_DETAIL');
    $payload = [
        'penjualan' => (float)($r['penjualan'] ?? 0),
        'hutang' => (float)($r['hutang'] ?? 0),
        'piutang_baru' => (float)($r['piutang_baru'] ?? 0),
        'piutang_lama' => (float)($r['piutang_lama'] ?? 0),
        'total_piutang' => (float)($r['total_piutang'] ?? 0),
        'stock_value' => (float)($r['stock_by_office'] ?? 0),
        'jumlah' => (float)($r['jumlah'] ?? 0),
    ];
    $st->execute([
        $asOf,
        $officeToId[$officeCode],
        $segment,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $count++;
}

echo "Snapshot generated for {$count} rows at {$asOf}\n";
