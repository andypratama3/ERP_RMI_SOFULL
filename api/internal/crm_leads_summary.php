<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';

use App\Api\ApiResponse;

require_login();
require_role(['CRM','MPR','FIN','ACT','MANAGER','SYS', 'ADMIN','SUPERADMIN']);

$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    ApiResponse::fail('Invalid date format. Use YYYY-MM-DD.', 'ERR_VALIDATION', 422);
}
if ($dateFrom > $dateTo) {
    ApiResponse::fail('date_from must be <= date_to.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $stTbl = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_leads'");
    $stTbl->execute();
    if ((int)$stTbl->fetchColumn() <= 0) {
        ApiResponse::fail('CRM Leads module is not migrated yet.', 'ERR_NOT_READY', 503);
    }

    $st = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(estimated_value),0) AS est_total
         FROM crm_leads
         WHERE DATE(created_at) BETWEEN ? AND ?
         GROUP BY status"
    );
    $st->execute([$dateFrom, $dateTo]);
    $map = [
        'DRAFT' => ['count' => 0, 'estimated_value' => 0.0],
        'SUBMITTED' => ['count' => 0, 'estimated_value' => 0.0],
        'APPROVED' => ['count' => 0, 'estimated_value' => 0.0],
        'CLOSED' => ['count' => 0, 'estimated_value' => 0.0],
        'CANCELLED' => ['count' => 0, 'estimated_value' => 0.0],
    ];
    $total = 0;
    $totalEst = 0.0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $s = strtoupper((string)($r['status'] ?? ''));
        if (!isset($map[$s])) {
            continue;
        }
        $c = (int)($r['cnt'] ?? 0);
        $v = (float)($r['est_total'] ?? 0);
        $map[$s]['count'] = $c;
        $map[$s]['estimated_value'] = $v;
        $total += $c;
        $totalEst += $v;
    }

    $submitted = (int)$map['SUBMITTED']['count'];
    $approved = (int)$map['APPROVED']['count'];
    $closed = (int)$map['CLOSED']['count'];
    $approvalRate = $submitted > 0 ? (($approved / $submitted) * 100.0) : 0.0;
    $closeRate = $approved > 0 ? (($closed / $approved) * 100.0) : 0.0;

    ApiResponse::ok([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'total_leads' => $total,
        'total_estimated_value' => $totalEst,
        'status_counts' => $map,
        'funnel' => [
            'submitted_to_approved_pct' => $approvalRate,
            'approved_to_closed_pct' => $closeRate,
        ],
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load CRM leads summary.', 'ERR_CRM_LEADS_SUMMARY', 500);
}

