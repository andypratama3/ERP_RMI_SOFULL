<?php

require_once __DIR__ . '/auth.php';

if (!function_exists('require_admin_critical')) {
    function require_admin_critical(): void
    {
        require_login();
        $role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
        $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
        $ok = in_array($role, ['SYS', 'ADMIN', 'SUPERADMIN'], true) || in_array($level, ['SYS', 'ADMIN', 'SUPERADMIN'], true);
        if (!$ok) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }
}

require_admin_critical();