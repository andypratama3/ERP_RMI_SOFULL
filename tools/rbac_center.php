<?php
/**
 * tools/rbac_center.php
 * DEPRECATED — Dipindah ke rbac/index.php (satu titik tunggal RBAC Center).
 * File ini hanya redirect agar backward-compatible dengan link lama.
 */
declare(strict_types=1);
require_once __DIR__ . '/../master/auth.php';
require_login();

$base = defined('BASE_PROJECT') ? rtrim(BASE_PROJECT, '/') : '';
if (empty($base) && function_exists('rmi_layout_base_project')) {
    require_once __DIR__ . '/../_shared/rmi_layout.php';
    $base = rtrim(rmi_layout_base_project(), '/');
}

rmi_redirect($base . '/rbac/index.php', 301);
