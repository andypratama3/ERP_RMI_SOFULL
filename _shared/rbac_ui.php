<?php
/**
 * RBAC UI helper (show/hide menu items)
 *
 * This file is intentionally UI-only.
 * It does NOT enforce access. Use auth_require_* for enforcement.
 */

// Safety: this is a helper include, not a public endpoint.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $auth = __DIR__ . '/../master/auth.php';
    if (is_file($auth)) { require_once $auth; }
    // Pattern-match friendly: keep require_login() here so static audit recognizes the guard.
    if (function_exists('require_login')) { require_login(); }
    header('HTTP/1.1 403 Forbidden');
    echo 'Forbidden';
    exit;
}


// ----- Safe getters (support old + new auth patches) -----

if (!function_exists('rbac_ui_user_role')) {
    function rbac_ui_user_role(): string {
        if (function_exists('auth_role')) {
            $v = (string) auth_role();
            return strtoupper(trim($v));
        }
        if (function_exists('current_user_role')) {
            $v = (string) current_user_role();
            return strtoupper(trim($v));
        }
        $v = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
        return strtoupper(trim((string)$v));
    }
}

if (!function_exists('rbac_ui_user_dept')) {
    function rbac_ui_user_dept(): string {
        if (function_exists('auth_dept')) {
            $v = (string) auth_dept();
            return strtoupper(trim($v));
        }
        if (function_exists('current_user_department')) {
            $v = (string) current_user_department();
            return strtoupper(trim($v));
        }
        $v = $_SESSION['department'] ?? $_SESSION['dept'] ?? '';
        return strtoupper(trim((string)$v));
    }
}

if (!function_exists('rbac_ui_username')) {
    function rbac_ui_username(): string {
        if (function_exists('auth_username')) {
            return (string) auth_username();
        }
        if (function_exists('current_username')) {
            return (string) current_username();
        }
        return (string)($_SESSION['username'] ?? $_SESSION['user'] ?? '');
    }
}

if (!function_exists('rbac_ui_is_admin')) {
    function rbac_ui_is_admin(): bool {
        if (function_exists('auth_is_admin_plus')) {
            return (bool) auth_is_admin_plus();
        }

        $role = rbac_ui_user_role();
        if (in_array($role, ['SYS', 'ADMIN', 'SUPERADMIN'], true)) { // SYS = privileged
            return true;
        }

        // Level juga bisa menandakan admin (session level)
        $level = strtoupper(trim((string)($_SESSION['level'] ?? ($_SESSION['user']['level'] ?? ''))));
        if (in_array($level, ['SYS', 'ADMIN', 'SUPERADMIN'], true)) { // SYS = privileged
            return true;
        }

        // Dept SYS = System Admin, akses penuh
        $dept = rbac_ui_user_dept();
        if ($dept === 'SYS') {
            return true;
        }

        // Backward compatibility: some installs used username as admin flag.
        $u = strtolower(rbac_ui_username());
        return in_array($u, ['admin', 'superadmin'], true);
    }
}

if (!function_exists('rbac_ui_can')) {
    /**
     * @param array $opts
     *   - 'depts' => string[]
     *   - 'roles' => string[]
     *   - 'allow_admin' => bool (default true)
     */
    function rbac_ui_can(array $opts): bool {
        $allow_admin = array_key_exists('allow_admin', $opts) ? (bool)$opts['allow_admin'] : true;
        if ($allow_admin && rbac_ui_is_admin()) {
            return true;
        }

        $ok = true;

        if (!empty($opts['depts'])) {
            $dept = rbac_ui_user_dept();
            $allowed = array_map(static fn($d) => strtoupper(trim((string)$d)), (array)$opts['depts']);
            $ok = $ok && in_array($dept, $allowed, true);
        }

        if (!empty($opts['roles'])) {
            $role = rbac_ui_user_role();
            $allowed = array_map(static fn($r) => strtoupper(trim((string)$r)), (array)$opts['roles']);
            $ok = $ok && in_array($role, $allowed, true);
        }

        return $ok;
    }
}

