<?php
/**
 * manufacturer_portal/_auth.php
 * Auth guard untuk Manufacturer Portal.
 */
declare(strict_types=1);

if (!function_exists('mportal_base')) {
    function mportal_base(): string {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($script === '') return '';
        $script = str_replace('\\', '/', $script);
        if (preg_match('~^(.*?)/manufacturer_portal/~', $script, $m)) {
            return rtrim($m[1], '/');
        }
        $dir = dirname($script);
        return rtrim(str_replace('\\', '/', $dir), '/');
    }
}

if (!function_exists('require_mportal_login')) {
    function require_mportal_login(): void {
        if (empty($_SESSION['mportal_user_id']) || empty($_SESSION['mportal_manufacture_code'])) {
            $next = urlencode($_SERVER['REQUEST_URI'] ?? '/manufacturer_portal/');
            $base = mportal_base();
            rmi_redirect($base . '/manufacturer_portal/login.php?next=' . $next);
        }
    }
}

if (!function_exists('mportal_user')) {
    function mportal_user(): ?array {
        if (empty($_SESSION['mportal_user_id'])) return null;
        return [
            'id' => (int)$_SESSION['mportal_user_id'],
            'username' => (string)($_SESSION['mportal_username'] ?? ''),
            'full_name' => (string)($_SESSION['mportal_full_name'] ?? ''),
            'manufacture_code' => (string)($_SESSION['mportal_manufacture_code'] ?? ''),
            'manufacture_id' => isset($_SESSION['mportal_manufacture_id']) ? (int)$_SESSION['mportal_manufacture_id'] : null,
        ];
    }
}
