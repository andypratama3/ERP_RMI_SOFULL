<?php
/**
 * _shared/export_excel.php
 * Export helpers: CSV default, XLSX if PhpSpreadsheet + ENABLE_EXCEL_EXPORT=1
 * Audit: REPORT_EXPORTED
 */
declare(strict_types=1);

if (!function_exists('export_csv')) {
    function export_csv(string $filename, array $header, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename) . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, is_array($r) ? $r : []);
        }
        fclose($out);
        exit;
    }
}

if (!function_exists('export_xlsx_if_available')) {
    function export_xlsx_if_available(string $filename, array $header, array $rows, string $sheetName = 'Sheet1'): bool
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) return false;
        if ((int)(getenv('ENABLE_EXCEL_EXPORT') ?: '0') !== 1) return false;
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(substr($sheetName, 0, 31));
            $col = 'A';
            foreach ($header as $h) {
                $sheet->setCellValue($col++ . '1', $h);
            }
            $row = 2;
            foreach ($rows as $r) {
                $col = 'A';
                $arr = is_array($r) ? $r : [];
                foreach ($header as $i => $h) {
                    $sheet->setCellValue($col++ . $row, $arr[$i] ?? '');
                }
                $row++;
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename) . '.xlsx"');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('export_report_with_audit')) {
    function export_report_with_audit(\PDO $pdo, string $reportId, string $filename, array $header, array $rows, string $format = 'csv'): void
    {
        $actor = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'SYSTEM');
        if (function_exists('erp_audit_ensure')) { try { erp_audit_ensure($pdo); } catch (Throwable $e) {} }
        if (function_exists('audit_event')) {
            try {
                require_once __DIR__ . '/erp_audit.php';
                audit_event($pdo, 'REPORT_EXPORTED', 'REPORT', $reportId, 'export', 'Report exported', [
                    'actor_username' => $actor,
                    'report_id' => $reportId,
                    'rows' => count($rows),
                    'format' => $format,
                ]);
            } catch (Throwable $e) {}
        }
        if ($format === 'xlsx' && export_xlsx_if_available($filename, $header, $rows)) return;
        export_csv($filename, $header, $rows);
    }
}
