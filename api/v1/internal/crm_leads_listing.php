<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';

use App\Api\ApiResponse;

require_login();
require_role(['CRM','MPR','FIN','ACT','MANAGER','SYS', 'ADMIN','SUPERADMIN']);

$q = trim((string)($_GET['q'] ?? ''));
$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$priority = strtoupper(trim((string)($_GET['priority'] ?? '')));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(200, (int)($_GET['per_page'] ?? 20)));

if ($status !== '' && !in_array($status, ['DRAFT','SUBMITTED','APPROVED','CLOSED','CANCELLED'], true)) {
    ApiResponse::fail('Invalid status filter.', 'ERR_VALIDATION', 422);
}
if ($priority !== '' && !in_array($priority, ['LOW','MEDIUM','HIGH'], true)) {
    ApiResponse::fail('Invalid priority filter.', 'ERR_VALIDATION', 422);
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    ApiResponse::fail('Invalid date_from format. Use YYYY-MM-DD.', 'ERR_VALIDATION', 422);
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    ApiResponse::fail('Invalid date_to format. Use YYYY-MM-DD.', 'ERR_VALIDATION', 422);
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    ApiResponse::fail('date_from must be <= date_to.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $stTbl = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_leads'");
    $stTbl->execute();
    if ((int)$stTbl->fetchColumn() <= 0) {
        ApiResponse::fail('CRM Leads module is not migrated yet.', 'ERR_NOT_READY', 503);
    }

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = "(lead_no LIKE ? OR lead_name LIKE ? OR company_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $kw = '%' . $q . '%';
        $params[] = $kw;
        $params[] = $kw;
        $params[] = $kw;
        $params[] = $kw;
        $params[] = $kw;
    }
    if ($status !== '') {
        $where[] = "status = ?";
        $params[] = $status;
    }
    if ($priority !== '') {
        $where[] = "priority = ?";
        $params[] = $priority;
    }
    if ($dateFrom !== '') {
        $where[] = "DATE(created_at) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "DATE(created_at) <= ?";
        $params[] = $dateTo;
    }
    $whereSql = count($where) > 0 ? (' WHERE ' . implode(' AND ', $where)) : '';

    $stCount = $pdo->prepare("SELECT COUNT(*) FROM crm_leads {$whereSql}");
    $stCount->execute($params);
    $total = (int)$stCount->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT id, lead_no, lead_name, company_name, phone, email, source_channel, city,
                   estimated_value, priority, status, next_followup_date, created_at, updated_at
            FROM crm_leads
            {$whereSql}
            ORDER BY id DESC
            LIMIT {$perPage} OFFSET {$offset}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    ApiResponse::ok([
        'filters' => [
            'q' => $q,
            'status' => $status,
            'priority' => $priority,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ],
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load CRM leads listing.', 'ERR_CRM_LEADS_LIST', 500);
}

