<?php
// /hrl_process/debug_access.php
require_once __DIR__ . '/_inc/bootstrap.php';
require_login();

header('Content-Type: text/plain; charset=utf-8');

echo "HRL Process Access Debug\n";
echo "========================\n";
echo "username: " . (function_exists('auth_username') ? auth_username() : ($_SESSION['username'] ?? '-')) . "\n";
echo "role: " . (function_exists('auth_role') ? auth_role() : ($_SESSION['role'] ?? '-')) . "\n";
echo "level: " . (function_exists('auth_level') ? auth_level() : ($_SESSION['level'] ?? '-')) . "\n";
echo "dept: " . (function_exists('auth_dept') ? auth_dept() : ($_SESSION['department'] ?? ($_SESSION['dept'] ?? '-'))) . "\n";
echo "office: " . (function_exists('auth_office') ? auth_office() : ($_SESSION['office_code'] ?? '-')) . "\n";
echo "auth_is_manager_or_sys_any: " . (function_exists('auth_is_manager_or_sys_any') && auth_is_manager_or_sys_any() ? 'YES' : 'NO') . "\n";
echo "can HRL.REQ_CUTI_VIEW: " . (function_exists('can') && can('HRL.REQ_CUTI_VIEW') ? 'YES' : 'NO') . "\n";
echo "can HRL.REQ_CUTI_CREATE: " . (function_exists('can') && can('HRL.REQ_CUTI_CREATE') ? 'YES' : 'NO') . "\n";
