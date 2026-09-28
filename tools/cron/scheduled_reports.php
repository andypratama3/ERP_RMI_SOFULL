<?php
/**
 * tools/cron/scheduled_reports.php
 * Scheduled report generation (Phase 4)
 * CLI only. Generates daily/weekly KPI, GR audit, SLA PO→GR reports.
 * Run: php tools/cron/scheduled_reports.php [--period=Y-m] [--daily=Y-m-d] [--weekly=YYYY-WW]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/_shared/bootstrap.php';

$pdo = null;
if (function_exists('rmi_db_config')) {
    try {
        $cfg = rmi_db_config();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1',
            (int)($cfg['port'] ?? 3306),
            $cfg['name'] ?? 'erp_rmi_sofull'
        );
        $pdo = new PDO($dsn, $cfg['user'] ?? 'root', $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }
}

// Lock anti-overlap
$lockDir = $root . '/storage/locks';
$lockFile = $lockDir . '/scheduled_reports.lock';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lockFp = @fopen($lockFile, 'c');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    echo "SKIP: another instance running\n";
    exit(0);
}

function mask_path(string $path, string $root): string {
    if ($root !== '' && strpos($path, $root) === 0) {
        return '[ROOT]' . substr($path, strlen($root));
    }
    return '[MASKED]';
}

function table_exists(?PDO $pdo, string $t): bool {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$t]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function scalar_val(?PDO $pdo, string $sql, array $params = []): float {
    if (!$pdo) return 0;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? 0 : (float)$v;
    } catch (Throwable $e) {
        return 0;
    }
}

$period = date('Y-m');
$dailyDate = date('Y-m-d');
$weeklyYear = (int)date('o');
$weeklyWeek = (int)date('W');
$weeklyKey = sprintf('%04d-W%02d', $weeklyYear, $weeklyWeek);

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (strpos($arg, '--period=') === 0) {
        $v = trim(substr($arg, 9));
        if (preg_match('/^\d{4}-\d{2}$/', $v)) $period = $v;
    } elseif (strpos($arg, '--daily=') === 0) {
        $v = trim(substr($arg, 8));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $dailyDate = $v;
    } elseif (strpos($arg, '--weekly=') === 0) {
        $v = trim(substr($arg, 9));
        if (preg_match('/^(\d{4})-W?(\d{1,2})$/', $v, $m)) {
            $weeklyYear = (int)$m[1];
            $weeklyWeek = (int)$m[2];
            $weeklyKey = sprintf('%04d-W%02d', $weeklyYear, $weeklyWeek);
        }
    }
}

$reportDir = $root . '/storage/exports/reports';
if (!is_dir($reportDir)) @mkdir($reportDir, 0775, true);

$kpi = [
    'sku_onhand' => 0,
    'qty_onhand' => 0,
    'incoming_daily' => 0,
    'incoming_weekly' => 0,
    'alloc_daily' => 0,
    'alloc_weekly' => 0,
    'picking_daily' => 0,
    'picking_weekly' => 0,
];

$grAudit = ['daily' => 0, 'weekly' => 0];
$slaPoGr = ['po_count' => 0, 'gr_count' => 0, 'po_with_gr' => 0];

if ($pdo) {
    if (table_exists($pdo, 'wqs_stock')) {
        $kpi['sku_onhand'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_stock WHERE stock_qty > 0");
        $kpi['qty_onhand'] = scalar_val($pdo, "SELECT COALESCE(SUM(stock_qty),0) FROM wqs_stock");
    }
    if (table_exists($pdo, 'wqs_incoming')) {
        $kpi['incoming_daily'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_incoming WHERE received_date = ?", [$dailyDate]);
        $weeklyYw = $weeklyYear * 100 + $weeklyWeek;
        $kpi['incoming_weekly'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_incoming WHERE YEARWEEK(received_date,3) = ?", [$weeklyYw]);
    }
    $wStart = '';
    $wEnd = '';
    try {
        $d = new DateTime();
        $d->setISODate($weeklyYear, $weeklyWeek, 1);
        $wStart = $d->format('Y-m-d');
        $d->setISODate($weeklyYear, $weeklyWeek, 7);
        $wEnd = $d->format('Y-m-d');
    } catch (Throwable $e) {
        $wStart = date('Y-m-d');
        $wEnd = date('Y-m-d');
    }
    if (table_exists($pdo, 'wqs_allocations')) {
        $dtStart = $dailyDate . ' 00:00:00';
        $dtEnd = $dailyDate . ' 23:59:59';
        $kpi['alloc_daily'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_allocations WHERE allocated_at BETWEEN ? AND ?", [$dtStart, $dtEnd]);
        $kpi['alloc_weekly'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_allocations WHERE DATE(allocated_at) BETWEEN ? AND ?", [$wStart, $wEnd]);
    }
    if (table_exists($pdo, 'wqs_picking')) {
        $dtStart = $dailyDate . ' 00:00:00';
        $dtEnd = $dailyDate . ' 23:59:59';
        $kpi['picking_daily'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_picking WHERE picked_at BETWEEN ? AND ?", [$dtStart, $dtEnd]);
        $kpi['picking_weekly'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_picking WHERE DATE(picked_at) BETWEEN ? AND ?", [$wStart, $wEnd]);
    }
    if (table_exists($pdo, 'wqs_incoming')) {
        $grAudit['daily'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_incoming WHERE received_date = ?", [$dailyDate]);
        $grAudit['weekly'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM wqs_incoming WHERE received_date BETWEEN ? AND ?", [$wStart, $wEnd]);
    }
    if (table_exists($pdo, 'purchases_po') && table_exists($pdo, 'wqs_incoming')) {
        $slaPoGr['po_count'] = (int)scalar_val($pdo, "SELECT COUNT(*) FROM purchases_po WHERE po_date BETWEEN ? AND ?", [$wStart, $wEnd]);
        $slaPoGr['gr_count'] = (int)scalar_val($pdo, "SELECT COUNT(DISTINCT po_id) FROM wqs_incoming WHERE received_date BETWEEN ? AND ? AND po_id IS NOT NULL", [$wStart, $wEnd]);
        $slaPoGr['po_with_gr'] = $slaPoGr['gr_count'];
    }
}

$dailyYmd = str_replace('-', '', $dailyDate);
$dailyFile = $reportDir . '/report_daily_' . $dailyYmd . '.md';
$weeklyFile = $reportDir . '/report_weekly_' . str_replace('-W', '', $weeklyKey) . '.md';

$dailyMd = "# KPI Daily Report — {$dailyDate}\n\n";
$dailyMd .= "## KPI Summary\n";
$dailyMd .= "| Metric | Value |\n|--------|-------|\n";
$dailyMd .= "| SKU On-hand | {$kpi['sku_onhand']} |\n";
$dailyMd .= "| Qty On-hand | {$kpi['qty_onhand']} |\n";
$dailyMd .= "| Incoming (day) | {$kpi['incoming_daily']} |\n";
$dailyMd .= "| Allocations (day) | {$kpi['alloc_daily']} |\n";
$dailyMd .= "| Picking (day) | {$kpi['picking_daily']} |\n\n";
$dailyMd .= "## GR Audit Completeness\n";
$dailyMd .= "| Period | GR Received |\n|--------|-------------|\n";
$dailyMd .= "| Daily ({$dailyDate}) | {$grAudit['daily']} |\n\n";
$dailyMd .= "## SLA PO→GR\n";
if ($slaPoGr['po_count'] > 0) {
    $dailyMd .= "| Metric | Value |\n|--------|-------|\n";
    $dailyMd .= "| PO in range | {$slaPoGr['po_count']} |\n";
    $dailyMd .= "| PO with GR | {$slaPoGr['po_with_gr']} |\n\n";
} else {
    $dailyMd .= "No PO data in range.\n\n";
}
$dailyMd .= "---\n*Generated at " . date('Y-m-d H:i:s') . "*\n";
@file_put_contents($dailyFile, $dailyMd);

$weeklyMd = "# KPI Weekly Report — {$weeklyKey}\n\n";
$weeklyMd .= "## KPI Summary\n";
$weeklyMd .= "| Metric | Value |\n|--------|-------|\n";
$weeklyMd .= "| SKU On-hand | {$kpi['sku_onhand']} |\n";
$weeklyMd .= "| Qty On-hand | {$kpi['qty_onhand']} |\n";
$weeklyMd .= "| Incoming (week) | {$kpi['incoming_weekly']} |\n";
$weeklyMd .= "| Allocations (week) | {$kpi['alloc_weekly']} |\n";
$weeklyMd .= "| Picking (week) | {$kpi['picking_weekly']} |\n\n";
$weeklyMd .= "## GR Audit Completeness\n";
$weeklyMd .= "| Period | GR Received |\n|--------|-------------|\n";
$weeklyMd .= "| Weekly ({$weeklyKey}) | {$grAudit['weekly']} |\n\n";
$weeklyMd .= "## SLA PO→GR\n";
if ($slaPoGr['po_count'] > 0) {
    $weeklyMd .= "| Metric | Value |\n|--------|-------|\n";
    $weeklyMd .= "| PO in range | {$slaPoGr['po_count']} |\n";
    $weeklyMd .= "| PO with GR | {$slaPoGr['po_with_gr']} |\n\n";
} else {
    $weeklyMd .= "No PO data in range.\n\n";
}
$weeklyMd .= "---\n*Generated at " . date('Y-m-d H:i:s') . "*\n";
@file_put_contents($weeklyFile, $weeklyMd);

$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir . '/scheduled_reports_last.json';
$payload = [
    'run_at' => date('c'),
    'daily_file' => mask_path($dailyFile, $root),
    'weekly_file' => mask_path($weeklyFile, $root),
    'kpi' => $kpi,
    'gr_audit' => $grAudit,
    'sla_po_gr' => $slaPoGr,
];
@file_put_contents($logFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

if ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

echo "OK: scheduled_reports (daily={$dailyDate}, weekly={$weeklyKey})\n";
exit(0);
