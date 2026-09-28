<?php
/**
 * Redirect ke halaman rekap di dashboards/finance
 * File rekap sebenarnya: dashboards/finance/sales_do_rekap.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/helpers.php';

$q = $_SERVER['QUERY_STRING'] ?? '';
$target = '../dashboards/finance/sales_do_rekap.php' . ($q !== '' ? '?' . $q : '');
rmi_redirect($target);
