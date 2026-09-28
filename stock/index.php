<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
// Guard diselaraskan dengan halaman tujuan wqs_stock.php
if (function_exists('require_any_permission')) {
    require_any_permission(['STOCK.VIEW', 'WQS.VIEW', 'WQS.INCOMING_VIEW', 'WQS.PR_VIEW', 'WQS.PICKING_VIEW']);
} else {
    require_role(['WQS', 'ADMIN', 'SUPERADMIN', 'SCM', 'PQP', 'MANAGER']);
}
rmi_redirect('wqs_stock.php');
