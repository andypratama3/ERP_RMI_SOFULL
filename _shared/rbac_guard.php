<?php
/**
 * rbac_guard.php — Shared RBAC + CSRF helpers for POST action endpoints.
 *
 * Use on mutation handlers: login → permission → (FIN cash-out) central approver → CSRF.
 * Does not change business rules; delegates to master/auth.php.
 */
declare(strict_types=1);

if (!function_exists('rbac_guard_bootstrap_auth')) {
    function rbac_guard_bootstrap_auth(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $auth = __DIR__ . '/../master/auth.php';
        if (is_file($auth)) {
            require_once $auth;
        }
        $loaded = true;
    }
}

if (!function_exists('rbac_guard_require_login')) {
    function rbac_guard_require_login(): void {
        rbac_guard_bootstrap_auth();
        require_login();
    }
}

if (!function_exists('rbac_guard_require_csrf_post')) {
    /** Enforce POST method + CSRF token (call at start of POST branch). */
    function rbac_guard_require_csrf_post(): void {
        rbac_guard_bootstrap_auth();
        if (function_exists('require_post')) {
            require_post();
        }
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
    }
}

if (!function_exists('rbac_guard_is_sys_user')) {
    function rbac_guard_is_sys_user(): bool {
        rbac_guard_bootstrap_auth();
        if (function_exists('auth_is_admin')) {
            return auth_is_admin();
        }
        $r = strtoupper(trim((string)($_SESSION['role'] ?? '')));
        $l = strtoupper(trim((string)($_SESSION['level'] ?? '')));
        $d = strtoupper(trim((string)($_SESSION['department'] ?? '')));

        return $r === 'SYS' || $l === 'SYS' || $d === 'SYS';
    }
}

if (!function_exists('rbac_guard_require_permission')) {
    /**
     * @param string|array<int,string> $permCodes
     */
    function rbac_guard_require_permission(string|array $permCodes): void {
        rbac_guard_bootstrap_auth();
        $codes = is_array($permCodes) ? $permCodes : [$permCodes];
        if (function_exists('require_any_permission')) {
            require_any_permission($codes);
            return;
        }
        rbac_guard_deny403('permission');
    }
}

if (!function_exists('rbac_guard_deny403')) {
    /** Safe 403 response (no sensitive detail). */
    function rbac_guard_deny403(string $reasonCode = 'forbidden'): void {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body>';
        echo '<h3>403 Forbidden</h3><p>Anda tidak memiliki akses untuk aksi ini.</p>';
        echo '</body></html>';
        exit;
    }
}

if (!function_exists('rbac_guard_audit_denied_event')) {
    function rbac_guard_audit_denied_event(string $action, string $reason, string $requestId = ''): void {
        rbac_guard_bootstrap_auth();
        if (function_exists('auth_rbac_audit_deny') && function_exists('auth_rbac_route')) {
            $suffix = $requestId !== '' ? ' rid=' . $requestId : '';
            auth_rbac_audit_deny($action . ':' . $reason . $suffix, auth_rbac_route());
        }
    }
}

if (!function_exists('rbac_guard_fin_central_probe_maybe_exit')) {
    /**
     * QA probe: POST + X-RBAC-PROBE: 1 + SYS dept user → JSON { fin_central_would_allow } then exit (no mutation).
     */
    function rbac_guard_fin_central_probe_maybe_exit(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }
        if (trim((string)($_SERVER['HTTP_X_RBAC_PROBE'] ?? '')) !== '1') {
            return;
        }
        rbac_guard_bootstrap_auth();
        $dept = function_exists('auth_dept') ? strtoupper((string)auth_dept()) : '';
        if ($dept !== 'SYS' || !rbac_guard_is_sys_user()) {
            rbac_guard_deny403('probe_requires_sys_dept');
        }
        $would = function_exists('auth_is_fin_central_approver') && auth_is_fin_central_approver();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'probe' => true,
            'fin_central_would_allow' => $would,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('rbac_guard_require_fin_central_approver')) {
    /** FIN payment / cash-out approval — MgrFIN_BGR username or SYS only. */
    function rbac_guard_require_fin_central_approver(): void {
        rbac_guard_bootstrap_auth();
        rbac_guard_fin_central_probe_maybe_exit();
        if (function_exists('auth_require_fin_central_approver')) {
            auth_require_fin_central_approver();
        }
    }
}
