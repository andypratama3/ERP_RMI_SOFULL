<?php
/**
 * customer_portal/_bootstrap.php
 * Bootstrap untuk Customer Portal (tanpa require_login internal ERP).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../_shared/app_init.php';
$__env = __DIR__ . '/../_shared/env.php';
if (is_file($__env)) {
    require_once $__env;
    if (function_exists('rmi_env_load')) { rmi_env_load(); }
}
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_auth.php';
