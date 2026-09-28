<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';

use App\Api\ApiResponse;

require_login();
require_role(['CRM','MPR','FIN','ACT','MANAGER','SYS', 'ADMIN','SUPERADMIN']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    ApiResponse::fail('id is required and must be integer > 0.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $stTbl = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_leads'");
    $stTbl->execute();
    if ((int)$stTbl->fetchColumn() <= 0) {
        ApiResponse::fail('CRM Leads module is not migrated yet.', 'ERR_NOT_READY', 503);
    }

    $st = $pdo->prepare(
        "SELECT id, lead_no, lead_name, company_name, phone, email, source_channel, city,
                estimated_value, priority, status, next_followup_date, notes,
                created_by, submitted_by, approved_by, closed_by, cancelled_by,
                created_at, updated_at, submitted_at, approved_at, closed_at, cancelled_at, cancel_reason
         FROM crm_leads
         WHERE id=?
         LIMIT 1"
    );
    $st->execute([$id]);
    $lead = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lead) {
        ApiResponse::fail('Lead not found.', 'ERR_NOT_FOUND', 404);
    }

    $logs = [];
    $stLogsTbl = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_lead_logs'");
    $stLogsTbl->execute();
    if ((int)$stLogsTbl->fetchColumn() > 0) {
        $stLog = $pdo->prepare(
            "SELECT action, from_status, to_status, note, actor_user_id, actor_username, created_at
             FROM crm_lead_logs
             WHERE lead_id=?
             ORDER BY id DESC"
        );
        $stLog->execute([$id]);
        $logs = $stLog->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    ApiResponse::ok([
        'lead' => $lead,
        'logs' => $logs,
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load CRM lead detail.', 'ERR_CRM_LEAD_DETAIL', 500);
}

