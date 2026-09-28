<?php
/**
 * wqs_dashboard_export.php
 * Export WQS dashboard data (POST + CSRF + rate limit)
 */
require_once __DIR__ . '/../../_shared/bootstrap.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/helpers.php';
if (function_exists('require_login')) { require_login(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rmi_redirect(($GLOBALS['BASE_PROJECT'] ?? '') . '/dashboards/warehouse/wqs_dashboard.php');
}

if (function_exists('verify_csrf')) {
    verify_csrf();
}

// Rate limit: 1 export per 10 seconds per user
$user = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'anon');
$lockKey = 'export_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $user);
$lockDir = (defined('RMI_ROOT') ? RMI_ROOT : dirname(__DIR__, 2)) . '/storage/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lockFile = $lockDir . '/' . $lockKey . '.lock';
$last = @file_get_contents($lockFile);
if ($last && (time() - (int)$last) < 10) {
    rmi_redirect(($GLOBALS['BASE_PROJECT'] ?? '') . '/dashboards/warehouse/wqs_dashboard.php?export=rate_limited');
}
@file_put_contents($lockFile, (string)time());

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($_POST['from'] ?? ''))) ? trim($_POST['from']) : date('Y-m-d', strtotime('-30 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($_POST['to'] ?? ''))) ? trim($_POST['to']) : date('Y-m-d');
if (strtotime($from) > strtotime($to)) { $t = $from; $from = $to; $to = $t; }

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    rmi_redirect(($GLOBALS['BASE_PROJECT'] ?? '') . '/dashboards/warehouse/wqs_dashboard.php?export=error');
}

$header = ['Periode', 'Metric', 'Value'];
$rows = [];
$rows[] = [$from . ' s/d ' . $to, 'Export Date', date('Y-m-d H:i:s')];

function t_exists($pdo, $t) {
    if (!$pdo) return false;
    if (function_exists('safe_table_exists')) return safe_table_exists($pdo, $t);
    if (function_exists('kpi_policy_table_exists')) return kpi_policy_table_exists($pdo, $t);
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}
function scalar($pdo, $sql, $params = []) {
    if (!$pdo) return 0;
    try { $st = $pdo->prepare($sql); $st->execute($params); $v = $st->fetchColumn(); return ($v === false || $v === null) ? 0 : $v; } catch (Throwable $e) { return 0; }
}

if (t_exists($pdo, 'wqs_stock')) {
    $rows[] = [$from . ' s/d ' . $to, 'SKU On-hand', scalar($pdo, "SELECT COUNT(*) FROM wqs_stock WHERE stock_qty > 0")];
    $rows[] = [$from . ' s/d ' . $to, 'Qty On-hand', scalar($pdo, "SELECT COALESCE(SUM(stock_qty),0) FROM wqs_stock")];
}
if (t_exists($pdo, 'wqs_incoming')) {
    $rows[] = [$from . ' s/d ' . $to, 'Incoming Count', scalar($pdo, "SELECT COUNT(*) FROM wqs_incoming WHERE received_date BETWEEN ? AND ?", [$from, $to])];
}
if (t_exists($pdo, 'wqs_allocations')) {
    $rows[] = [$from . ' s/d ' . $to, 'Allocation Count', scalar($pdo, "SELECT COUNT(*) FROM wqs_allocations WHERE allocated_at BETWEEN ? AND ?", [$from . ' 00:00:00', $to . ' 23:59:59'])];
}
if (t_exists($pdo, 'wqs_picking')) {
    $rows[] = [$from . ' s/d ' . $to, 'Picking Count', scalar($pdo, "SELECT COUNT(*) FROM wqs_picking WHERE picked_at BETWEEN ? AND ?", [$from . ' 00:00:00', $to . ' 23:59:59'])];
}

require_once __DIR__ . '/../../_shared/export_excel.php';
$reportId = (string)($_POST['report_id'] ?? 'wqs_dashboard');
$format = (string)($_POST['format'] ?? 'csv');
export_report_with_audit($pdo, $reportId, 'wqs_dashboard_' . $from . '_' . $to, $header, $rows, $format);
