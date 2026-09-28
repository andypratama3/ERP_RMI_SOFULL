<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_route_access')) {
    require_route_access(['PURCHASES.CEISA_VIEW', 'PURCHASES.CEISA_EDIT', 'PURCHASES.CEISA_PIB']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.CEISA_VIEW', 'PURCHASES.CEISA_EDIT', 'PURCHASES.CEISA_PIB']);
} else {
    require_role(['ACT','FIN','ADMIN','SUPERADMIN','SYS','MANAGER','PQP','SCM','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$flash = p_flash_get();

$rows=[];
try {
  $rows = $pdo->query("
    SELECT
      po.id, po.po_code, po.status, po.office_code,
      o.office_name,
      m.manufacture_name,
      cp.ceisa_status, cp.submitted_date, cp.reject_count,
      cp.bc11_no, cp.noa_no, cp.billing_aju_no, cp.billing_amount, cp.sppb_no,
      cp.updated_at
    FROM purchases_po po
    LEFT JOIN purchases_ceisa_pib cp ON cp.po_id=po.id
    LEFT JOIN master_office o ON o.office_code=po.office_code
    LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
    WHERE po.deleted_at IS NULL
    ORDER BY po.id DESC
    LIMIT 500
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('CEISA / PIB', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'CEISA / PIB',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    table.dataTable thead th,table.dataTable tbody td{color:#e5e7eb}
    .dt-buttons .btn,.dataTables_wrapper .dt-buttons button{border-radius:10px!important;border:1px solid rgba(255,255,255,.15)!important;background:rgba(255,255,255,.08)!important;color:#e5e7eb!important;padding:6px 10px!important;font-size:12px!important;}
    .dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.12)!important;border-radius:10px!important;}
    .num{text-align:right}</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">Purchases • ACT</div>
    <h3 class="mb-0">CEISA / PIB Tracking</h3>
    <div class="muted">Draft PIB → Submit → Billing → SPPB → Final</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_import_control_tower.php">← Control Tower</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">PO List</div>
    <div class="table-responsive">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th>PO</th>
            <th>Office</th>
            <th>Manufacture</th>
            <th>PO Status</th>
            <th>CEISA</th>
            <th>BC11</th>
            <th>NOA</th>
            <th>Billing Aju</th>
            <th class="num">Billing Amount</th>
            <th>SPPB</th>
            <th>Updated</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><b><?=h($r['po_code'])?></b></td>
              <td><?=h($r['office_name'] ?? $r['office_code'])?></td>
              <td><?=h($r['manufacture_name'] ?? '-')?></td>
              <td><?=h($r['status'])?></td>
              <td><?=h($r['ceisa_status'] ?? '—')?></td>
              <td><?=h($r['bc11_no'] ?? '—')?></td>
              <td><?=h($r['noa_no'] ?? '—')?></td>
              <td><?=h($r['billing_aju_no'] ?? '—')?></td>
              <td class="num"><?=h(p_money($r['billing_amount'] ?? 0, 'IDR'))?></td>
              <td><?=h($r['sppb_no'] ?? '—')?></td>
              <td class="muted"><?=h($r['updated_at'] ?? '')?></td>
              <td><a class="btn btn-soft btn-sm" href="purchases_ceisa_pib_view.php?id=<?=h($r['id'])?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script>
$(function(){
  $('#tbl').DataTable({pageLength:25, order:[[0,'desc']], dom:'Bfrtip', buttons:['copy','csv','excel','print']});
});
</script>
<?php rmi_footer(); ?>
