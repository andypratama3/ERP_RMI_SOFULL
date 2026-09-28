<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$_SESSION['mportal_user_id'] = null;
$_SESSION['mportal_username'] = null;
$_SESSION['mportal_full_name'] = null;
$_SESSION['mportal_manufacture_code'] = null;
$_SESSION['mportal_manufacture_id'] = null;
unset($_SESSION['mportal_user_id'], $_SESSION['mportal_username'], $_SESSION['mportal_full_name'],
      $_SESSION['mportal_manufacture_code'], $_SESSION['mportal_manufacture_id']);

$base = mportal_base();
rmi_redirect($base . '/manufacturer_portal/login.php');
