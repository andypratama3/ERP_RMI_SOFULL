<?php
/**
 * rbac_module_guard.php — Module-level RBAC guards.
 *
 * - rmi_require_module($permCode): require_login + require_permission; SYS selalu bypass.
 * - rmi_require_admin(): require_login + validasi SYS (canonical role).
 *
 * Canonical roles: sys/SYS = superuser. ADMIN dan SUPERADMIN adalah backward-compat alias
 * yang dipetakan ke SYS oleh auth_is_admin() dan rbac_is_privileged_session().
 *
 * Load after master/auth.php (yang menyediakan require_login, require_permission, auth_is_admin).
 */
declare(strict_types=1);

if (!function_exists('rmi_require_module')) {
    /**
     * Require login dan permission modul. SYS selalu bypass (via auth_is_admin).
     */
    function rmi_require_module(string $permCode): void
    {
        if (function_exists('require_login')) {
            require_login();
        }
        // SYS bypass — auth_is_admin() cek role + level + dept secara konsisten
        if (function_exists('auth_is_admin') && auth_is_admin()) {
            return;
        }
        if (function_exists('require_permission')) {
            require_permission($permCode);
        }
    }
}

if (!function_exists('rmi_require_admin')) {
    /**
     * Require login dan akses SYS (privileged).
     * Menggunakan auth_is_admin() sebagai single source of truth untuk SYS check.
     * ADMIN/SUPERADMIN diterima sebagai backward-compat alias.
     */
    function rmi_require_admin(): void
    {
        if (function_exists('require_login')) {
            require_login();
        }
        // Gunakan auth_is_admin() — konsisten dengan canonical SYS check
        if (function_exists('auth_is_admin') && auth_is_admin()) {
            return;
        }
        // Fallback jika auth_is_admin tidak tersedia: cek role + level + dept
        $role  = strtoupper(trim((string)($_SESSION['role']       ?? '')));
        $level = strtoupper(trim((string)($_SESSION['level']      ?? '')));
        $dept  = strtoupper(trim((string)($_SESSION['department'] ?? '')));
        $privileged = in_array($role,  ['SYS','ADMIN','SUPERADMIN'], true)
                   || in_array($level, ['SYS','ADMIN','SUPERADMIN'], true)
                   || $dept === 'SYS';
        if (!$privileged) {
            http_response_code(403);
            echo '<h3>Forbidden</h3><p>Hanya SYS yang dapat mengakses halaman ini.</p>';
            exit;
        }
    }
}
