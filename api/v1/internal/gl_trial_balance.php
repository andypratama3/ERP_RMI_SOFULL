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
$accountType = strtoupper(trim((string)($_GET['account_type'] ?? '')));
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(500, (int)($_GET['per_page'] ?? 50)));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    ApiResponse::fail('Invalid date format. Use YYYY-MM-DD.', 'ERR_VALIDATION', 422);
}

if ($accountType !== '' && !in_array($accountType, ['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'], true)) {
    ApiResponse::fail('Invalid account_type.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $svc = new GLReportService();
    $payload = $svc->trialBalancePaged($pdo, $dateFrom, $dateTo, $accountType, $search, $page, $perPage);

    $sumDr = 0.0;
    $sumCr = 0.0;
    foreach ($payload['rows'] as $r) {
        $sumDr += (float)($r['total_dr'] ?? 0);
        $sumCr += (float)($r['total_cr'] ?? 0);
    }

    ApiResponse::ok([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'account_type' => $accountType,
        'search' => $search,
        'rows' => $payload['rows'],
        'totals_page' => [
            'dr' => $sumDr,
            'cr' => $sumCr,
            'balanced' => abs($sumDr - $sumCr) < 0.00001,
        ],
        'pagination' => [
            'page' => $payload['page'],
            'per_page' => $payload['per_page'],
            'total' => $payload['total'],
            'total_pages' => $payload['total_pages'],
        ],
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load trial balance.', 'ERR_GL_TRIAL_BALANCE', 500);
}
