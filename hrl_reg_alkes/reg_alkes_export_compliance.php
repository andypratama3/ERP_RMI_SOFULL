<?php
/**
 * reg_alkes_export_compliance.php
 * CSV export for compliance reporting (Phase 3)
 * Audit: COMPLIANCE_EXPORT_REG_ALKES
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../master/auth.php';
require_login();

$pdo = db_pdo();
if (function_exists('require_any_permission')) {
    require_any_permission(['HRL.COMPLIANCE_EXPORT', 'HRL.REG_ALKES_VIEW', 'HRL.VIEW', 'PQP.VIEW']);
}

$format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
$enableExcel = (int)(getenv('ENABLE_EXCEL_EXPORT') ?: 0) === 1;
if ($format === 'xlsx' && !$enableExcel) $format = 'csv';
if ($format !== 'csv' && $format !== 'xlsx') $format = 'csv';

$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));

$rows = [];
$where = ['1=1'];
$params = [];
if ($dateFrom !== '') { $where[] = 'c.updated_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = 'c.updated_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }

try {
    $sql = "SELECT c.id, c.case_code, c.manufacture_code, c.manufacture_name, c.product_name,
             c.stage_no, c.stage_code, c.status, c.nie_no, c.nie_issue_date, c.revision_deadline,
             c.created_at, c.updated_at
      FROM hrl_reg_alkes_cases c
      WHERE " . implode(' AND ', $where) . "
      ORDER BY c.updated_at DESC, c.id DESC
      LIMIT 5000";
    $st = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $st->execute($params);
    else $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // table may not exist
}

$rowCount = count($rows);
$actor = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'SYSTEM');
if (function_exists('erp_audit_ensure')) { try { erp_audit_ensure($pdo); } catch (Throwable $e) {} }
if (function_exists('audit_event')) {
    try {
        audit_event($pdo, 'COMPLIANCE_EXPORT_REG_ALKES', 'HRL_COMPLIANCE', 'REG_ALKES', 'export', 'Export compliance', [
            'actor_username' => $actor,
            'row_count' => $rowCount,
            'format' => $format,
            'range_from' => $dateFrom ?: null,
            'range_to' => $dateTo ?: null,
        ]);
    } catch (Throwable $e) {}
}

if ($format === 'xlsx' && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $headers = ['case_code', 'manufacture_code', 'manufacture_name', 'product_name', 'stage_no', 'stage_code', 'status', 'nie_no', 'nie_issue_date', 'revision_deadline', 'created_at', 'updated_at'];
    $col = 'A';
    foreach ($headers as $h) { $sheet->setCellValue($col++ . '1', $h); }
    $row = 2;
    foreach ($rows as $r) {
        $col = 'A';
        foreach ($headers as $h) { $sheet->setCellValue($col++ . $row, $r[$h] ?? ''); }
        $row++;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="reg_alkes_compliance_' . date('Y-m-d') . '.xlsx"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reg_alkes_compliance_' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['case_code', 'manufacture_code', 'manufacture_name', 'product_name', 'stage_no', 'stage_code', 'status', 'nie_no', 'nie_issue_date', 'revision_deadline', 'created_at', 'updated_at']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['case_code'] ?? '',
        $r['manufacture_code'] ?? '',
        $r['manufacture_name'] ?? '',
        $r['product_name'] ?? '',
        $r['stage_no'] ?? '',
        $r['stage_code'] ?? '',
        $r['status'] ?? '',
        $r['nie_no'] ?? '',
        $r['nie_issue_date'] ?? '',
        $r['revision_deadline'] ?? '',
        $r['created_at'] ?? '',
        $r['updated_at'] ?? '',
    ]);
}
fclose($out);
exit;
