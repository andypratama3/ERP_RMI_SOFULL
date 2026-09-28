<?php
/**
 * api/v1/webhooks/payment_callback.php
 * Webhook: receive payment gateway callbacks (Phase 3)
 * Auth: X-API-Key. Store to payments_webhook_inbox (or payment_callbacks_inbox fallback).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/db.php';

header('Content-Type: application/json; charset=utf-8');

$secret = (string)(function_exists('rmi_env') ? rmi_env('PAYMENT_WEBHOOK_SECRET', '') : (getenv('PAYMENT_WEBHOOK_SECRET') ?: ''));
$apiKey = (string)(function_exists('rmi_env') ? rmi_env('WEBHOOK_API_KEY', '') : (getenv('WEBHOOK_API_KEY') ?: ''));
$provided = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
$keyToCheck = $apiKey !== '' ? $apiKey : $secret;
if ($keyToCheck === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Webhook not configured']);
    exit;
}
if (!hash_equals($keyToCheck, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($payload)) $payload = [];

$source = trim((string)($_SERVER['HTTP_X_PAYMENT_SOURCE'] ?? 'unknown'));
$requestId = 'wh-pay-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : (function_exists('db_pdo') ? db_pdo() : null);
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Service unavailable']);
    exit;
}

if (function_exists('erp_audit_ensure')) { try { erp_audit_ensure($pdo); } catch (Throwable $e) {} }
if (function_exists('audit_event')) {
    try {
        require_once __DIR__ . '/../../../_shared/erp_audit.php';
        audit_event($pdo, 'WEBHOOK_PAYMENT_RECEIVED', 'WEBHOOK', 'PAYMENT', $source, 'Payment callback received', [
            'actor_username' => 'system',
            'request_id' => $requestId,
        ]);
    } catch (Throwable $e) {}
}

$table = 'payments_webhook_inbox';
try {
    $pdo->query("SELECT 1 FROM payments_webhook_inbox LIMIT 1");
} catch (Throwable $e) {
    $table = 'payment_callbacks_inbox';
}

try {
    $pdo->prepare("INSERT INTO {$table} (source, payload_json, created_at) VALUES (?, ?, NOW())")
        ->execute([$source, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['ok' => true, 'message' => 'Received', 'id' => $id, 'request_id' => $requestId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Storage failed']);
}
