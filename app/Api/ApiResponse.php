<?php
declare(strict_types=1);

namespace App\Api;

use App\Support\RequestContext;

final class ApiResponse
{
    public static function ok(array $data = [], array $meta = [], int $status = 200): void
    {
        self::send([
            'success' => true,
            'data' => $data,
            'error' => null,
            'meta' => self::meta($meta),
        ], $status);
    }

    public static function fail(string $message, string $code = 'ERR_GENERIC', int $status = 400, array $meta = []): void
    {
        self::send([
            'success' => false,
            'data' => null,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => self::meta($meta),
        ], $status);
    }

    private static function send(array $payload, int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function meta(array $meta): array
    {
        $base = [
            'request_id' => RequestContext::ensureRequestId(),
            'ts' => date('c'),
        ];
        return array_merge($base, $meta);
    }
}
