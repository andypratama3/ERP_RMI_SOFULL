<?php
declare(strict_types=1);

/**
 * Internal API security guard.
 * All state-changing internal endpoints must pass CSRF verification.
 */
require_once __DIR__ . '/../../../master/auth.php';
require_login();

if (!function_exists('internal_api_require_write_guard')) {
    function internal_api_require_write_guard(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            http_response_code(405);
            echo json_encode([
                'ok' => false,
                'message' => 'Method Not Allowed',
                'data' => [],
                'request_id' => 'req-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 10),
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        verify_csrf();
    }
}

$__internalMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (in_array($__internalMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    verify_csrf();
}

