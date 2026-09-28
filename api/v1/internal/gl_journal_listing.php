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
$sourceModule = trim((string)($_GET['source_module'] ?? ''));
$sourceRef = trim((string)($_GET['source_ref'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(500, (int)($_GET['per_page'] ?? 50)));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    ApiResponse::fail('Invalid date format. Use YYYY-MM-DD.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $svc = new GLReportService();
    $payload = $svc->journalListingPaged($pdo, $dateFrom, $dateTo, $sourceModule, $sourceRef, $page, $perPage);
    ApiResponse::ok([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'source_module' => $sourceModule,
        'source_ref' => $sourceRef,
        'rows' => $payload['rows'],
        'pagination' => [
            'page' => $payload['page'],
            'per_page' => $payload['per_page'],
            'total' => $payload['total'],
            'total_pages' => $payload['total_pages'],
        ],
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load GL journals.', 'ERR_GL_JOURNAL', 500);
}
