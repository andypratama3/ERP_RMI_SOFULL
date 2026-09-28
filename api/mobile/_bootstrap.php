<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/_response.php';
require_once __DIR__ . '/../../../app/Security/RateLimiterService.php';

use App\Security\RateLimiterService;

if (!function_exists('mobile_pdo')) {
    function mobile_pdo(): PDO
    {
        if (function_exists('rmi_db_pdo')) {
            return rmi_db_pdo();
        }
        return db_pdo();
    }
}

if (!function_exists('mobile_require_method')) {
    function mobile_require_method(string $method): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($method)) {
            mobile_err('ERR_VALIDATION', 'Method not allowed.', 405);
        }
    }
}

if (!function_exists('mobile_get_json_body')) {
    function mobile_get_json_body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $dec = json_decode($raw, true);
        return is_array($dec) ? $dec : [];
    }
}

if (!function_exists('mobile_get_input')) {
    function mobile_get_input(): array
    {
        $json = mobile_get_json_body();
        if (!empty($json)) return $json;
        return $_POST ?: [];
    }
}

if (!function_exists('mobile_pagination')) {
    function mobile_pagination(array $src): array
    {
        $page = max(1, (int)($src['page'] ?? 1));
        $limit = (int)($src['limit'] ?? 20);
        if ($limit <= 0) $limit = 20;
        if ($limit > 100) $limit = 100;
        $offset = ($page - 1) * $limit;
        return [$page, $limit, $offset];
    }
}

if (!function_exists('mobile_uuid_like')) {
    function mobile_uuid_like(string $v): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\\-_:.]{7,120}$/', $v) === 1;
    }
}

if (!function_exists('mobile_require_request_id')) {
    function mobile_require_request_id(): string
    {
        $rid = mobile_request_id();
        if (!mobile_uuid_like($rid)) {
            mobile_err('ERR_VALIDATION', 'Invalid X-Request-Id.', 422);
        }
        return $rid;
    }
}

if (!function_exists('mobile_mask_ip')) {
    function mobile_mask_ip(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.x.x';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return ($parts[0] ?? '::') . ':' . ($parts[1] ?? '::') . ':x:x';
        }
        return 'unknown';
    }
}

if (!function_exists('mobile_rl_hit')) {
    function mobile_rl_hit(PDO $pdo, string $scope, string $actorKey, int $windowSec, int $maxHits): void
    {
        $rl = new RateLimiterService($pdo);
        $rate = $rl->hit($scope, $actorKey, $windowSec, $maxHits);
        if (empty($rate['allowed'])) {
            mobile_err('ERR_RATE_LIMIT', 'Rate limit exceeded.', 429, [
                'remaining' => (int)($rate['remaining'] ?? 0),
                'reset_at' => date('c', (int)($rate['reset_at'] ?? time())),
            ]);
        }
    }
}
