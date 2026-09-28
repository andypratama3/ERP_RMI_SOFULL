<?php
/**
 * customer_portal/logout.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$_SESSION['portal_user_id'] = null;
$_SESSION['portal_username'] = null;
$_SESSION['portal_full_name'] = null;
$_SESSION['portal_customers_code'] = null;
$_SESSION['portal_office_code'] = null;
$_SESSION['portal_cart'] = null;
unset($_SESSION['portal_user_id'], $_SESSION['portal_username'], $_SESSION['portal_full_name'],
      $_SESSION['portal_customers_code'], $_SESSION['portal_office_code'], $_SESSION['portal_cart']);

$base = function_exists('portal_base') ? portal_base() : '';
rmi_redirect($base . '/customer_portal/login.php');
