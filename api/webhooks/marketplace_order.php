<?php
/**
 * api/v1/webhooks/marketplace_order.php
 * Webhook: receive marketplace order (Phase 3)
 * Auth: X-API-Key (hash_equals env). Idempotent: unique(external_order_id, source).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/db.php';

header('Content-Type: application/json; charset=utf-8');

$secret = (string)(function_exists('rmi_env') ? rmi_env('MARKETPLACE_WEBHOOK_SECRET', '') : (getenv('MARKETPLACE_WEBHOOK_SECRET') ?: ''));
$apiKey = (string)(function_exists('rmi_env') ? rmi_env('WEBHOOK_API_KEY', '') : (getenv('WEBHOOK_API_KEY') ?: ''));
$provided = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ''));
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

$orderId = trim((string)($payload['order_id'] ?? $payload['external_order_id'] ?? ''));
$items = $payload['items'] ?? [];
if (!is_array($items)) $items = [];
if ($orderId === '') $orderId = 'unknown-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$requestId = 'wh-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
$source = trim((string)($_SERVER['HTTP_X_MARKETPLACE_SOURCE'] ?? 'marketplace'));

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
        audit_event($pdo, 'WEBHOOK_MARKETPLACE_RECEIVED', 'WEBHOOK', 'MARKETPLACE', $orderId, 'Marketplace order received', [
            'actor_username' => 'system',
            'request_id' => $requestId,
            'external_order_id' => $orderId,
        ]);
    } catch (Throwable $e) {}
}

try {
    $cols = [];
    try {
        $st = $pdo->query("SHOW COLUMNS FROM marketplace_orders_inbox");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) $cols[] = $r['Field'];
    } catch (Throwable $e) {}
    $hasExtId = in_array('external_order_id', $cols, true);
    $hasReqId = in_array('request_id', $cols, true);

    if ($hasExtId && $hasReqId) {
        $st = $pdo->prepare("SELECT id FROM marketplace_orders_inbox WHERE external_order_id=? AND source=? LIMIT 1");
        $st->execute([$orderId, $source]);
        if ($st->fetch()) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Duplicate', 'request_id' => $requestId]);
            exit;
        }
        $pdo->prepare("INSERT INTO marketplace_orders_inbox (source, external_order_id, request_id, payload_json, created_at) VALUES (?, ?, ?, ?, NOW())")
            ->execute([$source, $orderId, $requestId, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
    } else {
        $pdo->prepare("INSERT INTO marketplace_orders_inbox (source, payload_json, created_at) VALUES (?, ?, NOW())")
            ->execute([$source, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
    }
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['ok' => true, 'message' => 'Received', 'id' => $id, 'request_id' => $requestId]);
} catch (Throwable $e) {
    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate') !== false) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Duplicate', 'request_id' => $requestId]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Storage failed']);
    }
}
