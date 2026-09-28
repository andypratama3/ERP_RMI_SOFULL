<?php
/**
 * sales/crm_leads.php
 * Compatibility redirect untuk tautan lama CRM Leads.
 * Modul prospek aktif sudah dipindahkan ke MPR Pipeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_login();

$bp = function_exists('rmi_layout_base_project')
    ? rtrim((string)(rmi_layout_base_project() ?? ''), '/')
    : '';

$target = ($bp !== '' ? $bp : '') . '/mpr/mpr_pipeline.php';

header('Location: ' . $target, true, 302);
exit;
