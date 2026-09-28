<?php
/**
 * customer_portal/_auth.php
 * Auth guard untuk Customer Portal.
 */
declare(strict_types=1);

if (!function_exists('portal_base')) {
    function portal_base(): string {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($script === '') return '';
        $script = str_replace('\\', '/', $script);
        if (preg_match('~^(.*?)/customer_portal/~', $script, $m)) {
            return rtrim($m[1], '/');
        }
        $dir = dirname($script);
        return rtrim(str_replace('\\', '/', $dir), '/');
    }
}

if (!function_exists('require_portal_login')) {
    function require_portal_login(): void {
        if (empty($_SESSION['portal_user_id']) || empty($_SESSION['portal_customers_code'])) {
            $next = urlencode($_SERVER['REQUEST_URI'] ?? '/customer_portal/');
            $base = portal_base();
            rmi_redirect($base . '/customer_portal/login.php?next=' . $next);
        }
    }
}

if (!function_exists('portal_user')) {
    function portal_user(): ?array {
        if (empty($_SESSION['portal_user_id'])) return null;
        return [
            'id' => (int)$_SESSION['portal_user_id'],
            'username' => (string)($_SESSION['portal_username'] ?? ''),
            'full_name' => (string)($_SESSION['portal_full_name'] ?? ''),
            'customers_code' => (string)($_SESSION['portal_customers_code'] ?? ''),
            'office_code' => (string)($_SESSION['portal_office_code'] ?? ''),
        ];
    }
}
