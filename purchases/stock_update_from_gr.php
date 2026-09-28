<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.GR_PROCESS', 'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'PURCHASES.ADMIN_STOCK_UPDATE']);
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','SCM','ACT','FIN','PQP','MANAGER']);
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Stock Update (Purchases)', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Stock Update (Purchases)',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:#0b1220;color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:16px}
    a{text-decoration:none}</style>',
]);
?>

<div class="card">
  <div class="card-body">
    <h4>Stock Update</h4>
    <p>Untuk ERP RMI saat ini, update stok masuk dilakukan melalui modul <b>Stock → WQS Incoming</b> (karena butuh LOT/Serial/EXP + dokumentasi).</p>
    <a class="btn btn-primary btn-sm" href="../stock/wqs_incoming.php">Open WQS Incoming</a>
    <a class="btn btn-secondary btn-sm" href="purchases_dashboard.php">Back</a>
  </div>
</div>
<?php rmi_footer(); ?>
