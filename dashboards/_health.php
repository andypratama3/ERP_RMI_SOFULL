<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/master/auth.php';
require_once dirname(__DIR__) . '/_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.VIEW', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/_shared/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'checked_at' => date(DateTimeInterface::ATOM),
    'app_env' => defined('APP_ENV') ? APP_ENV : 'local',
], JSON_UNESCAPED_SLASHES);

