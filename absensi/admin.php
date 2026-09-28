<?php
declare(strict_types=1);

require_once __DIR__ . '/_inc/bootstrap.php';

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.RECAP');

rmi_redirect('admin/rekap.php');
