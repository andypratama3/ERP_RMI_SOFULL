<?php
/**
 * api/v1/internal/compliance/reg_alkes_summary.php
 * Internal API: Reg Alkes compliance summary (Phase 3)
 * require_login + permission guard
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/../_internal_api_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : (function_exists('db_pdo') ? db_pdo() : null);
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB unavailable', 'data' => [], 'request_id' => 'req-' . date('YmdHis')]);
    exit;
}

$requestId = 'req-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$data = [
    'total_active' => 0,
    'expiring_30d' => 0,
    'expiring_90d' => 0,
    'expired' => 0,
    'last_check_at' => date('c'),
];

try {
    $st = $pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases WHERE status='OPEN'");
    $data['total_active'] = (int)$st->fetchColumn();
    $st = $pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases WHERE status='OPEN' AND revision_deadline IS NOT NULL AND revision_deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $data['expiring_30d'] = (int)$st->fetchColumn();
    $st = $pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases WHERE status='OPEN' AND revision_deadline IS NOT NULL AND revision_deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
    $data['expiring_90d'] = (int)$st->fetchColumn();
    $st = $pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases WHERE status='OPEN' AND revision_deadline IS NOT NULL AND revision_deadline < CURDATE()");
    $data['expired'] = (int)$st->fetchColumn();
} catch (Throwable $e) {
    // tables may not exist
}

echo json_encode([
    'ok' => true,
    'success' => true,
    'message' => 'OK',
    'data' => $data,
    'request_id' => $requestId,
], JSON_UNESCAPED_SLASHES);
