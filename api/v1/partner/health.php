<?php
/**
 * api/v1/partner/health.php
 * Sample endpoint untuk partner (API Key auth).
 * Header: X-API-Key atau Authorization: Bearer <key>
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_lib/partner_auth.php';
require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$key = partner_api_key_from_request();

if ($key === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'ERR_MISSING_API_KEY', 'message' => 'X-API-Key or Authorization: Bearer required']);
    exit;
}

$partner = partner_api_key_validate($pdo, $key);
if ($partner === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'ERR_INVALID_API_KEY', 'message' => 'Invalid or inactive API key']);
    exit;
}

partner_update_last_used($pdo, $partner['id']);

echo json_encode([
    'ok' => true,
    'message' => 'OK',
    'partner' => $partner['partner_name'],
    'timestamp' => date('c'),
]);
