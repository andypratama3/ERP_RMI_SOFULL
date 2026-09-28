<?php
/**
 * manufacturer_portal/_bootstrap.php
 * Bootstrap untuk Manufacturer Portal (tanpa require_login internal ERP).
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
require_once __DIR__ . '/_lang.php';

// Language switcher: ?lang=en|zh|id
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh', 'id'], true)) {
    mportal_set_lang($_GET['lang']);
    $uri = preg_replace('/[?&]lang=(en|zh|id)(&|$)/', '$2', $_SERVER['REQUEST_URI'] ?? '');
    $uri = rtrim(preg_replace('/\?&/', '?', $uri), '?&');
    rmi_redirect($uri ?: '/manufacturer_portal/');
}
