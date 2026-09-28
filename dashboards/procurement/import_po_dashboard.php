<?php
require_once __DIR__ . '/../_dashboard_bootstrap.php';

// Static scan marker (guard enforced in _dashboard_bootstrap.php)
require_login();

/**
 * dashboards/procurement/import_po_dashboard.php
 * Hub redirect for Procurement/Import.
 *
 * IMPORTANT:
 * - Must work whether project is installed at domain root (/) or subfolder (e.g. /ERP_RMI_SOFULL)
 * - Must work from nested dashboards path (/dashboards/procurement/...)
 *
 * Strategy:
 * - Derive base project URL from SCRIPT_NAME by stripping everything after '/dashboards/'.
 * - Redirect to /purchases/purchases_import_control_tower.php (new import flow).
 */

$sn = $_SERVER['SCRIPT_NAME'] ?? '';
$pos = strpos($sn, '/dashboards/');
$base = '';
if ($pos !== false) {
    $base = substr($sn, 0, $pos); // e.g. '/ERP_RMI_SOFULL'
} else {
    // Fallback: go 2 levels up from current script name
    $base = rtrim(dirname(dirname($sn)), '/');
}

// Preferred target: Import Control Tower
$target = $base . '/purchases/purchases_import_control_tower.php';

// If the target doesn't exist on filesystem, fallback to old purchases dashboard
$root = realpath(__DIR__ . '/../../') ?: '';
if ($root && !file_exists($root . '/purchases/purchases_import_control_tower.php')) {
    $target = $base . '/purchases/purchases_dashboard.php';
}

rmi_redirect($target);
