<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 1) . '/master/auth.php';
require_once dirname(__DIR__, 1) . '/_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT', 'SALES.EDIT', 'SALES.EDIT']);
} else {
    require_role(['WQS', 'ADMIN', 'SUPERADMIN', 'SYS', 'SCM', 'PQP']);
}
// -------------------------------------------------------------

// stock/wqs_picking_view.php

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}


// --- DB (centralized, env-first) ---
$pdo = function_exists('db_pdo') ? db_pdo() : rmi_db_pdo();

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) die("id required");

$st=$pdo->prepare("SELECT * FROM wqs_picking WHERE id=? LIMIT 1");
$st->execute([$id]);
$head=$st->fetch();
if(!$head) die("Picking not found");

$st2=$pdo->prepare("
  SELECT pi.*, mp.products_name, mp.unit
  FROM wqs_picking_items pi
  LEFT JOIN master_products mp ON mp.id=pi.product_id
  WHERE pi.picking_id=?
  ORDER BY pi.id ASC
");
$st2->execute([$id]);
$items=$st2->fetchAll();

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Wqs Picking View', [
  'active' => 'stock',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'Wqs Picking View',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body {
      background: radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);
      color: #e5e7eb;
      padding: 18px;
      min-height: 100vh;
    }
    .card {
      background: rgba(17, 24, 39, .80);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: 0 12px 35px rgba(0,0,0,.45);
      border-radius: 14px;
    }
    .muted{ color:#9ca3af; font-size:12px; }
    .nowrap{ white-space: nowrap; }</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted mb-1">WQS Picking</div>
    <h3 class="mb-0"><?=h($head['do_code'])?></h3>
    <div class="muted">Picked at: <?=h($head['picked_at'])?> | Office: <?=h($head['office_code'])?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light btn-sm" href="<?= h($baseProject) ?>/stock/wqs_picking.php">← Back</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($baseProject) ?>/stock/wqs_stock.php">Stock</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-1">Note</div>
    <div><?=h($head['note'])?></div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">Items</div>
    <div class="table-responsive">
      <table class="table table-dark table-sm align-middle">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Produk</th>
            <th class="nowrap">Unit</th>
            <th class="nowrap">Qty</th>
            <th class="nowrap">Office</th>
            <th class="nowrap">Depo</th>
            <th class="nowrap">LOT</th>
            <th class="nowrap">Serial</th>
            <th class="nowrap">EXP</th>
            <th class="nowrap">Allocation ID</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $it): ?>
          <tr>
            <td class="nowrap"><b><?=h(strtoupper($it['sku'] ?? ''))?></b></td>
            <td><?=h(strtoupper($it['products_name'] ?? ''))?></td>
            <td class="nowrap"><?=h(strtoupper($it['unit'] ?? 'UNIT'))?></td>
            <td class="nowrap"><?= (int)$it['qty'] ?></td>
            <td class="nowrap"><?=h(strtoupper($it['office_code'] ?? ''))?></td>
            <td class="nowrap"><?=h(strtoupper($it['depo_name'] ?? ''))?></td>
            <td class="nowrap"><?=h($it['lot_number'])?></td>
            <td class="nowrap"><?=h($it['serial_number'])?></td>
            <td class="nowrap"><?=h($it['exp_date'])?></td>
            <td class="nowrap"><?= (int)$it['allocation_id'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="muted mt-2">Next: WQS Serah Terima / Delivery Out + dokumentasi (foto/video + tanda terima).</div>
  </div>
</div>
<?php rmi_footer(); ?>
