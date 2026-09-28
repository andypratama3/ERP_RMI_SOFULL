<?php
declare(strict_types=1);
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/_lib/bootstrap.php';
// Placeholder: smoke results viewer.
rmi_redirect((defined('BASE_PROJECT') ? BASE_PROJECT : '') . '/tools/smoke_view.php');
