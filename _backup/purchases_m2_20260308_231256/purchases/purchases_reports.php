<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_login();
require_any_permission([
  'PURCHASES.REPORTS_VIEW',
  'PURCHASES.VIEW',
  'PURCHASES.PO_CRUD',
  'PURCHASES.AP_INVOICE_CRUD',
  'PURCHASES.AP_PAYMENT_CRUD',
]);

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-t');

$pr_by_office=[]; $po_by_man=[]; $ap_by_man=[];

try {
  $st=$pdo->prepare("SELECT pr.office_code, o.office_name, COUNT(*) cnt_pr
                     FROM wqs_pr pr
                     LEFT JOIN master_office o ON o.office_code=pr.office_code
                     WHERE pr.deleted_at IS NULL AND pr.pr_date BETWEEN ? AND ?
                     GROUP BY pr.office_code, o.office_name
                     ORDER BY cnt_pr DESC");
  $st->execute([$from,$to]);
  $pr_by_office=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
  $st=$pdo->prepare("SELECT m.manufacture_name, COUNT(*) cnt_po, SUM(po.total_amount) total_po
                     FROM purchases_po po
                     LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
                     WHERE po.deleted_at IS NULL AND po.po_date BETWEEN ? AND ?
                     GROUP BY m.manufacture_name
                     ORDER BY total_po DESC");
  $st->execute([$from,$to]);
  $po_by_man=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
  $st=$pdo->prepare("
    SELECT m.manufacture_name,
      COUNT(*) cnt_inv,
      SUM(ap.total_amount) total_inv,
      SUM(ap.total_amount - COALESCE(paid.paid_amount,0)) outstanding
    FROM purchases_invoice_ap ap
    LEFT JOIN master_manufactures m ON m.id=ap.manufacture_id
    LEFT JOIN (
      SELECT ap_id, SUM(amount) paid_amount
      FROM purchases_payment_ap
      WHERE deleted_at IS NULL
      GROUP BY ap_id
    ) paid ON paid.ap_id = ap.id
    WHERE ap.deleted_at IS NULL AND ap.invoice_date BETWEEN ? AND ?
    GROUP BY m.manufacture_name
    ORDER BY outstanding DESC
  ");
  $st->execute([$from,$to]);
  $ap_by_man=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$canPrice = p_can_view_buy_price();
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Purchases Reports', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Purchases Reports',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .form-control{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
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
    <div class="muted">Purchases</div>
    <h3 class="mb-0">Reports</h3>
    <div class="muted">Summary PR/PO/AP (<?= $canPrice ? "buy price enabled" : "buy price hidden" ?>)</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_dashboard.php">← Dashboard</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form class="d-flex gap-2 flex-wrap" method="get">
      <div>
        <div class="muted">From</div>
        <input class="form-control form-control-sm" type="date" name="from" value="<?=h($from)?>">
      </div>
      <div>
        <div class="muted">To</div>
        <input class="form-control form-control-sm" type="date" name="to" value="<?=h($to)?>">
      </div>
      <div class="align-self-end">
        <button class="btn btn-soft btn-sm">Refresh</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">PR by Office</div>
        <table id="t1" class="display" style="width:100%">
          <thead><tr><th>Office</th><th class="num">Count</th></tr></thead>
          <tbody>
            <?php foreach($pr_by_office as $r): ?>
              <tr>
                <td><?=h(($r['office_name'] ?? '').' ('.$r['office_code'].')')?></td>
                <td class="num"><?=h($r['cnt_pr'])?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">PO by Manufacture</div>
        <table id="t2" class="display" style="width:100%">
          <thead><tr><th>Manufacture</th><th class="num">Count</th><th class="num">Total</th></tr></thead>
          <tbody>
            <?php foreach($po_by_man as $r): ?>
              <tr>
                <td><?=h($r['manufacture_name'] ?? '-')?></td>
                <td class="num"><?=h($r['cnt_po'])?></td>
                <td class="num"><?=h($canPrice ? p_money($r['total_po']) : '0')?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">AP by Manufacture (Outstanding)</div>
        <table id="t3" class="display" style="width:100%">
          <thead><tr><th>Manufacture</th><th class="num">Invoice</th><th class="num">Total</th><th class="num">Outstanding</th></tr></thead>
          <tbody>
            <?php foreach($ap_by_man as $r): ?>
              <tr>
                <td><?=h($r['manufacture_name'] ?? '-')?></td>
                <td class="num"><?=h($r['cnt_inv'])?></td>
                <td class="num"><?=h(p_money($r['total_inv']))?></td>
                <td class="num"><b><?=h(p_money($r['outstanding']))?></b></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>
<script>
  ['#t1','#t2','#t3'].forEach(sel=>{
    new DataTable(sel, { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });
  });
</script>
<?php rmi_footer(); ?>
