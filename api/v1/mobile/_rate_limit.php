<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (!function_exists('mobile_jwt_payload_unsafe')) {
    function mobile_jwt_payload_unsafe(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        $pad = strlen($parts[1]) % 4;
        if ($pad > 0) $parts[1] .= str_repeat('=', 4 - $pad);
        $raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($raw === false) return null;
        $dec = json_decode($raw, true);
        return is_array($dec) ? $dec : null;
    }
}

if (!function_exists('mobile_rate_limit_global')) {
    function mobile_rate_limit_global(PDO $pdo): void
    {
        $actorKey = 'ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
            if ($token !== '') {
                $payload = function_exists('mobile_jwt_payload_unsafe') ? mobile_jwt_payload_unsafe($token) : null;
                $uid = $payload ? (int)($payload['uid'] ?? 0) : 0;
                if ($uid > 0) $actorKey = 'u:' . $uid;
            }
        }
        mobile_rl_hit($pdo, 'MOBILE_GLOBAL', $actorKey, 60, 120);
    }
}

if (!function_exists('mobile_rate_limit_login')) {
    function mobile_rate_limit_login(PDO $pdo, string $username): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        mobile_rl_hit($pdo, 'MOBILE_AUTH_LOGIN_IP', 'ip:' . $ip, 600, 10);
        mobile_rl_hit($pdo, 'MOBILE_AUTH_LOGIN_USER', 'u:' . strtolower($username), 600, 10);
    }
}

if (!function_exists('mobile_rate_limit_refresh')) {
    function mobile_rate_limit_refresh(PDO $pdo): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        mobile_rl_hit($pdo, 'MOBILE_AUTH_REFRESH', 'ip:' . $ip, 600, 30);
    }
}

if (!function_exists('mobile_rate_limit_chat_send')) {
    function mobile_rate_limit_chat_send(PDO $pdo, string $username): void
    {
        mobile_rl_hit($pdo, 'MOBILE_CHAT_SEND', 'u:' . strtolower($username), 60, 60);
    }
}
