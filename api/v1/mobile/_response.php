<?php
declare(strict_types=1);

if (!function_exists('mobile_request_id')) {
    function mobile_request_id(): string
    {
        $rid = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($rid === '') {
            try {
                $rid = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $rid = 'req-' . time();
            }
        }
        return substr($rid, 0, 80);
    }
}

if (!function_exists('mobile_send_json')) {
    function mobile_send_json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Request-Id: ' . mobile_request_id());
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('mobile_ok')) {
    function mobile_ok(array $data = [], string $message = 'OK', int $status = 200): void
    {
        mobile_send_json([
            'ok' => true,
            'message' => $message,
            'data' => $data,
            'request_id' => mobile_request_id(),
        ], $status);
    }
}

if (!function_exists('mobile_mask_string')) {
    function mobile_mask_string(string $s): string
    {
        $s = str_replace('\\', '/', $s);
        $s = preg_replace('/\/Users\/[^\/]+/i', '[REDACTED]', $s ?? '');
        $s = str_replace((string)(defined('RMI_ROOT') ? RMI_ROOT : ''), '[APP_ROOT]', $s ?? '');
        return (string)$s;
    }
}

if (!function_exists('mobile_err')) {
    function mobile_err(string $code, string $message, int $status = 400, array $details = []): void
    {
        $safeDetails = [];
        foreach ($details as $k => $v) {
            if (is_string($v)) {
                $safeDetails[$k] = mobile_mask_string($v);
            } else {
                $safeDetails[$k] = $v;
            }
        }

        $payload = [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'request_id' => mobile_request_id(),
        ];
        if (!empty($safeDetails)) {
            $payload['details'] = $safeDetails;
        }
        mobile_send_json($payload, $status);
    }
}
