<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';

use App\Api\ApiResponse;
use App\Accounting\GLReportService;

require_login();
require_role(['FIN','SYS', 'ADMIN','SUPERADMIN','ACT','MANAGER']);

$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    ApiResponse::fail('Invalid date format. Use YYYY-MM-DD.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $svc = new GLReportService();
    $tb = $svc->trialBalance($pdo, $dateFrom, $dateTo);
    $journal = $svc->journalListing($pdo, $dateFrom, $dateTo, '', '');
    $totDr = 0.0;
    $totCr = 0.0;
    foreach ($tb as $r) {
        $totDr += (float)($r['total_dr'] ?? 0);
        $totCr += (float)($r['total_cr'] ?? 0);
    }
    ApiResponse::ok([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'journal_count' => count($journal),
        'account_count' => count($tb),
        'total_dr' => $totDr,
        'total_cr' => $totCr,
        'is_balanced' => abs($totDr - $totCr) < 0.00001,
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load GL summary.', 'ERR_GL_SUMMARY', 500);
}
