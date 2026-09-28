<?php
/**
 * dashboards/hrl/panduan.php — Redirect ke panduan modul HRL (satu sumber kebenaran).
 */
declare(strict_types=1);

require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string) BASE_PROJECT, '/') : '');
rmi_redirect(rtrim($bp, '/') . '/hrl/panduan.php');
