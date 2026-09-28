<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (!function_exists('mobile_idem_key_required')) {
    function mobile_idem_key_required(): string
    {
        $key = trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
        if ($key === '') {
            mobile_err('ERR_VALIDATION', 'Missing X-Idempotency-Key.', 422);
        }
        if (!mobile_uuid_like($key)) {
            mobile_err('ERR_VALIDATION', 'Invalid X-Idempotency-Key.', 422);
        }
        return $key;
    }
}

if (!function_exists('mobile_request_hash')) {
    function mobile_request_hash(array $body): string
    {
        ksort($body);
        return hash('sha256', (string)json_encode($body, JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('mobile_idem_begin')) {
    function mobile_idem_begin(PDO $pdo, int $userId, string $endpointKey, string $idemKey, array $body): ?array
    {
        $hash = mobile_request_hash($body);
        $st = $pdo->prepare("SELECT id, request_hash, response_json FROM mobile_idempotency WHERE user_id=? AND endpoint_key=? AND idem_key=? LIMIT 1");
        $st->execute([$userId, $endpointKey, $idemKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $ins = $pdo->prepare("INSERT INTO mobile_idempotency (user_id, endpoint_key, idem_key, request_hash, response_json, created_at, updated_at) VALUES (?,?,?,?,NULL,NOW(),NOW())");
            $ins->execute([$userId, $endpointKey, $idemKey, $hash]);
            return null;
        }

        if (!hash_equals((string)$row['request_hash'], $hash)) {
            mobile_err('ERR_IDEMPOTENCY_REPLAY_MISMATCH', 'Idempotency key reused with different payload.', 409);
        }
        if (!empty($row['response_json'])) {
            $payload = json_decode((string)$row['response_json'], true);
            if (is_array($payload)) return $payload;
        }
        return null;
    }
}

if (!function_exists('mobile_idem_commit')) {
    function mobile_idem_commit(PDO $pdo, int $userId, string $endpointKey, string $idemKey, array $responsePayload): void
    {
        $st = $pdo->prepare("UPDATE mobile_idempotency SET response_json=?, updated_at=NOW() WHERE user_id=? AND endpoint_key=? AND idem_key=?");
        $st->execute([json_encode($responsePayload, JSON_UNESCAPED_SLASHES), $userId, $endpointKey, $idemKey]);
    }
}
