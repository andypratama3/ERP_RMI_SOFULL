<?php
/**
 * dashboards/procurement/panduan.php — Redirect ke panduan modul Purchases / Import.
 */
declare(strict_types=1);

require_once __DIR__ . '/../_dashboard_bootstrap.php';

require_login();

$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string) BASE_PROJECT, '/') : '');
rmi_redirect(rtrim($bp, '/') . '/purchases/panduan.php');
