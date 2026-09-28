<?php
declare(strict_types=1);

// NOTE:
// Modul Absensi sebelumnya membawa helper CSRF sendiri.
// Setelah security sweep, helper CSRF tersedia global di `_shared/helpers.php`.
// File ini dipertahankan untuk backward-compatibility, tapi *tidak boleh* redeclare fungsi.

require_once __DIR__ . '/../../_shared/helpers.php';

// Backward-compat wrappers.
// Jika fungsi sudah ada (dari `_shared/helpers.php`), jangan didefinisikan lagi.

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        $t = function_exists('csrf_token') ? csrf_token() : (function_exists('rmi_csrf_token') ? rmi_csrf_token() : '');
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token = null): bool {
        if (function_exists('rmi_csrf_verify')) {
            return rmi_csrf_verify($token);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sent = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $stored = $_SESSION['_csrf'] ?? '';
        if (!is_string($sent)) {
            $sent = '';
        }
        if (!is_string($stored)) {
            $stored = '';
        }
        if ($sent === '' || $stored === '') {
            return false;
        }
        return hash_equals($stored, $sent);
    }
}
