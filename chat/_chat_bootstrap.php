<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../master/auth.php';

require_login();

if (!function_exists('chat_is_admin')) {
    function chat_is_admin(): bool
    {
        $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
        return in_array($level, ['ADMIN', 'SUPERADMIN'], true);
    }
}

