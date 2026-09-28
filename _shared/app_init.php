<?php
declare(strict_types=1);

/**
 * Global GPS Permission Policy
 * Dipanggil sangat awal oleh master/auth.php via _shared/app_init.php.
 */
if (!function_exists('rmi_allow_gps_permission_policy')) {
    function rmi_allow_gps_permission_policy(): void {
        if (headers_sent()) {
            return;
        }

        header_remove('Permissions-Policy');
        header_remove('Feature-Policy');
        header('Permissions-Policy: geolocation=(self "https://erp.rizqullahcorp.com" "https://rizqullahcorp.com"), camera=(self "https://erp.rizqullahcorp.com" "https://rizqullahcorp.com"), fullscreen=(self)', true);
        header("Feature-Policy: geolocation 'self' https://erp.rizqullahcorp.com https://rizqullahcorp.com; camera 'self' https://erp.rizqullahcorp.com https://rizqullahcorp.com; fullscreen 'self'", true);
    }
}
rmi_allow_gps_permission_policy();


if (!defined('RMI_ROOT')) {
    $root = realpath(__DIR__ . '/..');
    define('RMI_ROOT', $root ?: dirname(__DIR__));
}

$vendorAutoload = RMI_ROOT . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = RMI_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

if (class_exists(\App\Bootstrap\AppBootstrap::class)) {
    \App\Bootstrap\AppBootstrap::init();
    rmi_allow_gps_permission_policy(); // re-apply after AppBootstrap security headers
}
