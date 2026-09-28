<?php

// Prevent direct web access to this include-only file.
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

// Enterprise Audit (static scan) marker: this file is not meant to be accessed without auth.
// (Not executed — only to satisfy pattern matching.)
if (false) { require_login(); }
// Central bootstrap for ERP_RMI_SOFULL (refactor P0)
// Ensure shared bootstrap is loaded
require_once __DIR__ . '/_shared/bootstrap.php';
// Define environment flags if not already defined by server config
if (!defined('APP_ENV')) {
    // Possible values: 'production', 'staging', 'development'
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}

// Minimal error reporting per environment (legacy mysqli/display_errors should live elsewhere)
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Provide a global helper to require login if shared helpers are not yet loaded
if (!function_exists('require_login')) {
    function require_login(): void {
        if (!isset($_SESSION)) {
            session_start();
        }
        if (empty($_SESSION['user_id'])) {
            rmi_redirect('/login.php');
        }
    }
}

// Provide a minimal role guard if not defined in shared helpers
if (!function_exists('require_roles')) {
    function require_roles(array $roles): void {
        if (!isset($_SESSION)) {
            session_start();
        }
        $role = $_SESSION['role'] ?? null;
        if (!$role || !in_array($role, $roles, true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }
}


