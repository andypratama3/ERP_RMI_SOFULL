<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (!function_exists('mobile_audit')) {
    function mobile_audit(
        PDO $pdo,
        string $eventName,
        array $actor,
        ?string $targetType = null,
        $targetId = null,
        array $payload = []
    ): void {
        try {
            $rid = mobile_request_id();
            $endpoint = (string)($_SERVER['REQUEST_URI'] ?? '');
            $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $ipMasked = mobile_mask_ip((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
            $st = $pdo->prepare("INSERT INTO mobile_audit_events (event_name, actor_user_id, actor_username, request_id, endpoint, method, target_type, target_id, payload_json, ip_masked, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
            $st->execute([
                strtoupper($eventName),
                (int)($actor['user_id'] ?? 0),
                (string)($actor['username'] ?? 'SYSTEM'),
                $rid,
                substr($endpoint, 0, 200),
                strtoupper(substr($method, 0, 10)),
                $targetType,
                $targetId === null ? null : (string)$targetId,
                json_encode($payload, JSON_UNESCAPED_SLASHES),
                $ipMasked,
            ]);
        } catch (Throwable $e) {
            // fail-soft for audit
        }
    }
}
