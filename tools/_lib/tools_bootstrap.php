<?php
/**
 * Tools Bootstrap — NAS-First standard lib.
 * Defines APP_ROOT, safe error handler, tools_require_admin, tools_verify_csrf.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

if (!function_exists('tools_mask_sensitive')) {
    function tools_mask_sensitive(string $text): string
    {
        $root = defined('APP_ROOT') ? (string)APP_ROOT : '';
        if ($root !== '') {
            $text = str_replace($root, '[APP_ROOT]', $text);
        }
        $text = preg_replace('/(--password=)([^\s]+)/i', '$1[REDACTED]', $text ?? '') ?? $text;
        $text = preg_replace('/(password\s*=\s*)([^\s&]+)/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/Authorization:\s*\S+/i', 'Authorization: [REDACTED]', $text ?? '') ?? $text;
        $text = preg_replace('/(Bearer|token|api_key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $text ?? '') ?? $text;
        return $text;
    }
}

if (!function_exists('tools_require_admin')) {
    function tools_require_admin(): void
    {
        if (function_exists('require_login')) {
            require_login();
        }
        if (function_exists('require_role')) {
            require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
        }
    }
}

if (!function_exists('tools_verify_csrf_or_403')) {
    function tools_verify_csrf_or_403(?string $token = null): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            tools_verify_csrf($token);
        }
    }
}

if (!function_exists('tools_verify_csrf')) {
    function tools_verify_csrf(?string $token = null): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        if (function_exists('verify_csrf')) {
            verify_csrf($token ?? (string)($_POST['csrf_token'] ?? ''));
            return;
        }
        if (function_exists('rmi_csrf_validate')) {
            rmi_csrf_validate($token ?? (string)($_POST['csrf_token'] ?? ''));
            return;
        }
        $postToken = $token ?? (string)($_POST['csrf_token'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        if ($postToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postToken)) {
            http_response_code(403);
            exit('CSRF validation failed');
        }
    }
}
