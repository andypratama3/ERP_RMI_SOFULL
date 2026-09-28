<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/../../../_shared/erp_audit.php';
require_once __DIR__ . '/../../../master/_audit_master.php';
require_once __DIR__ . '/_internal_api_bootstrap.php';

use App\Api\ApiResponse;

require_login();
require_role(['CRM','MPR','FIN','ACT','MANAGER','SYS', 'ADMIN','SUPERADMIN']);
internal_api_require_write_guard();

if (!function_exists('crm_api_uid')) {
    function crm_api_uid(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('crm_api_username')) {
    function crm_api_username(): string
    {
        return trim((string)($_SESSION['username'] ?? 'system'));
    }
}

if (!function_exists('crm_api_is_admin_like')) {
    function crm_api_is_admin_like(): bool
    {
        $role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
        return in_array($role, ['SUPERADMIN', 'SYS', 'ADMIN'], true);
    }
}

if (!function_exists('crm_api_can_approve')) {
    function crm_api_can_approve(): bool
    {
        if (crm_api_is_admin_like()) {
            return true;
        }
        $role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
        $level = strtolower(trim((string)($_SESSION['level'] ?? '')));
        $dept = strtoupper(trim((string)($_SESSION['department'] ?? ($_SESSION['dept'] ?? ''))));
        if ($role === 'MANAGER' || $level === 'manager') {
            return true;
        }
        return in_array($dept, ['MANAGER', ], true);
    }
}

if (!function_exists('crm_api_log_action')) {
    function crm_api_log_action(PDO $pdo, int $leadId, string $action, ?string $fromStatus, ?string $toStatus, string $note = ''): void
    {
        $sql = "INSERT INTO crm_lead_logs
                (lead_id, action, from_status, to_status, note, actor_user_id, actor_username, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $pdo->prepare($sql)->execute([
            $leadId,
            strtoupper(trim($action)),
            $fromStatus !== null ? strtoupper(trim($fromStatus)) : null,
            $toStatus !== null ? strtoupper(trim($toStatus)) : null,
            trim($note) !== '' ? trim($note) : null,
            crm_api_uid() > 0 ? crm_api_uid() : null,
            crm_api_username(),
        ]);
    }
}

$id = (int)($_POST['id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? '')));
$cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
if ($id <= 0) {
    ApiResponse::fail('id is required and must be integer > 0.', 'ERR_VALIDATION', 422);
}
if (!in_array($action, ['submit', 'approve', 'close', 'cancel'], true)) {
    ApiResponse::fail('action must be one of submit|approve|close|cancel.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    erp_audit_ensure($pdo);

    $stTbl = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_leads'");
    $stTbl->execute();
    if ((int)$stTbl->fetchColumn() <= 0) {
        ApiResponse::fail('CRM Leads module is not migrated yet.', 'ERR_NOT_READY', 503);
    }

    $uid = crm_api_uid();
    $pdo->beginTransaction();
    $stLead = $pdo->prepare("SELECT * FROM crm_leads WHERE id=? FOR UPDATE");
    $stLead->execute([$id]);
    $lead = $stLead->fetch(PDO::FETCH_ASSOC);
    if (!$lead) {
        throw new RuntimeException('Lead not found.', 404);
    }

    $fromStatus = strtoupper((string)($lead['status'] ?? ''));
    $toStatus = $fromStatus;
    $note = '';
    $idempotent = false;

    if ($action === 'submit') {
        if ($fromStatus === 'SUBMITTED') {
            $idempotent = true;
            $note = 'Already submitted';
        } elseif ($fromStatus !== 'DRAFT') {
            throw new RuntimeException('Invalid transition. Only DRAFT can be submitted.', 409);
        } else {
            $st = $pdo->prepare("UPDATE crm_leads SET status='SUBMITTED', submitted_by=?, submitted_at=NOW(), updated_at=NOW() WHERE id=? AND status='DRAFT'");
            $st->execute([$uid > 0 ? $uid : null, $id]);
            if ($st->rowCount() === 0) {
                throw new RuntimeException('Submit failed due to concurrent update.', 409);
            }
            $toStatus = 'SUBMITTED';
            $note = 'Lead submitted for approval';
            crm_api_log_action($pdo, $id, 'SUBMIT', $fromStatus, $toStatus, $note);
            erp_audit($pdo, 'CRM_LEADS', 'LEAD#' . $id, 'SUBMIT_API', []);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'crm_leads', 'crm_leads', 'SUBMIT_API', $id, 'LEAD#' . $id, "Lead submitted via API: #{$id}", []);
            }
        }
    } elseif ($action === 'approve') {
        if ($fromStatus === 'APPROVED') {
            $idempotent = true;
            $note = 'Already approved';
        } elseif ($fromStatus !== 'SUBMITTED') {
            throw new RuntimeException('Invalid transition. Only SUBMITTED can be approved.', 409);
        } else {
            if (!crm_api_can_approve()) {
                throw new RuntimeException('No permission to approve.', 403);
            }
            if ((int)($lead['created_by'] ?? 0) > 0 && (int)$lead['created_by'] === $uid) {
                throw new RuntimeException('Maker-checker violation: creator cannot approve own lead.', 409);
            }
            $st = $pdo->prepare("UPDATE crm_leads SET status='APPROVED', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=? AND status='SUBMITTED'");
            $st->execute([$uid > 0 ? $uid : null, $id]);
            if ($st->rowCount() === 0) {
                throw new RuntimeException('Approve failed due to concurrent update.', 409);
            }
            $toStatus = 'APPROVED';
            $note = 'Lead approved';
            crm_api_log_action($pdo, $id, 'APPROVE', $fromStatus, $toStatus, $note);
            erp_audit($pdo, 'CRM_LEADS', 'LEAD#' . $id, 'APPROVE_API', []);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'crm_leads', 'crm_leads', 'APPROVE_API', $id, 'LEAD#' . $id, "Lead approved via API: #{$id}", []);
            }
        }
    } elseif ($action === 'close') {
        if ($fromStatus === 'CLOSED') {
            $idempotent = true;
            $note = 'Already closed';
        } elseif ($fromStatus !== 'APPROVED') {
            throw new RuntimeException('Invalid transition. Only APPROVED can be closed.', 409);
        } else {
            if (!crm_api_can_approve()) {
                throw new RuntimeException('No permission to close.', 403);
            }
            $st = $pdo->prepare("UPDATE crm_leads SET status='CLOSED', closed_by=?, closed_at=NOW(), updated_at=NOW() WHERE id=? AND status='APPROVED'");
            $st->execute([$uid > 0 ? $uid : null, $id]);
            if ($st->rowCount() === 0) {
                throw new RuntimeException('Close failed due to concurrent update.', 409);
            }
            $toStatus = 'CLOSED';
            $note = 'Lead closed';
            crm_api_log_action($pdo, $id, 'CLOSE', $fromStatus, $toStatus, $note);
            erp_audit($pdo, 'CRM_LEADS', 'LEAD#' . $id, 'CLOSE_API', []);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'crm_leads', 'crm_leads', 'CLOSE_API', $id, 'LEAD#' . $id, "Lead closed via API: #{$id}", []);
            }
        }
    } elseif ($action === 'cancel') {
        if ($fromStatus === 'CANCELLED') {
            $idempotent = true;
            $note = 'Already cancelled';
        } elseif (!in_array($fromStatus, ['DRAFT', 'SUBMITTED', 'APPROVED'], true)) {
            throw new RuntimeException('Invalid transition for cancel.', 409);
        } else {
            if (!crm_api_can_approve() && (int)($lead['created_by'] ?? 0) !== $uid) {
                throw new RuntimeException('No permission to cancel.', 403);
            }
            if ($cancelReason === '') {
                throw new RuntimeException('cancel_reason is required for cancel action.', 422);
            }
            $st = $pdo->prepare("UPDATE crm_leads SET status='CANCELLED', cancelled_by=?, cancelled_at=NOW(), cancel_reason=?, updated_at=NOW() WHERE id=? AND status IN ('DRAFT','SUBMITTED','APPROVED')");
            $st->execute([$uid > 0 ? $uid : null, $cancelReason, $id]);
            if ($st->rowCount() === 0) {
                throw new RuntimeException('Cancel failed due to concurrent update.', 409);
            }
            $toStatus = 'CANCELLED';
            $note = $cancelReason;
            crm_api_log_action($pdo, $id, 'CANCEL', $fromStatus, $toStatus, $note);
            erp_audit($pdo, 'CRM_LEADS', 'LEAD#' . $id, 'CANCEL_API', ['reason' => $cancelReason]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'crm_leads', 'crm_leads', 'CANCEL_API', $id, 'LEAD#' . $id, "Lead cancelled via API: #{$id}", ['reason' => $cancelReason]);
            }
        }
    }

    $pdo->commit();
    ApiResponse::ok([
        'lead_id' => $id,
        'action' => $action,
        'from_status' => $fromStatus,
        'to_status' => $toStatus,
        'idempotent' => $idempotent,
        'note' => $note,
    ]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $code = $e->getCode();
    $http = in_array($code, [403, 404, 409, 422], true) ? $code : 400;
    ApiResponse::fail($e->getMessage(), 'ERR_CRM_LEAD_ACTION', $http);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ApiResponse::fail('Failed to process CRM lead action.', 'ERR_CRM_LEAD_ACTION', 500);
}

