<?php
/** Internal Funnel Summary API. Uses the same service as dashboards/funnels.php. */
declare(strict_types=1);
require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/../../../dashboards/_funnels_data.php';
use App\Api\ApiResponse;

require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['DASHBOARD.VIEW','DASHBOARD.SALES_VIEW','DASHBOARD.OWNER_VIEW','DASHBOARD.OWNER_SUMMARY']);
} else {
    require_role(['CRM','MPR','FIN','ACT','MANAGER','SYS','ADMIN','SUPERADMIN']);
}

$dateFrom=rmi_funnel_valid_date($_GET['date_from']??null,date('Y-m-01'));
$dateTo=rmi_funnel_valid_date($_GET['date_to']??null,date('Y-m-d'));
if($dateFrom>$dateTo){[$dateFrom,$dateTo]=[$dateTo,$dateFrom];}
$allowed=['crm_leads','sales_do','reg_alkes','import'];
$requested=array_values(array_intersect($allowed,array_filter(array_map('trim',explode(',',(string)($_GET['funnel']??''))))));
if(!$requested)$requested=$allowed;

try{
    $data=rmi_funnel_read(rmi_db_pdo(),$dateFrom,$dateTo);
    $funnels=[];foreach($requested as $key)$funnels[$key]=$data[$key];
    ApiResponse::ok(['date_from'=>$dateFrom,'date_to'=>$dateTo,'warnings'=>$data['warnings'],'funnels'=>$funnels]);
}catch(Throwable $e){
    ApiResponse::fail('Failed to load funnels summary.','ERR_FUNNELS_SUMMARY',500);
}
