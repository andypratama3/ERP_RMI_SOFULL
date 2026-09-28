<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// master/master_pricelist_sell.php
// VIEW-ONLY: Pricelist Jual untuk CRM / MPR (tanpa Buy).
// Source: master_products + overlay master_pricelist (scope office/customer, date validity).

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission([
        'MASTER.PRICELIST_SELL_VIEW', 'MASTER.PRICELIST_SELL_CREATE', 'MASTER.PRICELIST_SELL_EDIT',
        'MASTER.PRICELIST_SELL_EDIT',
        'MPR.VIEW',
        'MASTER.PRICELIST_BUY_VIEW', 'MASTER.PRICELIST_BUY_CREATE', 'MASTER.PRICELIST_BUY_EDIT',
        'MASTER.ADMIN_CENTER',
    ]);
}
$pdo = db_pdo();

if (!function_exists('h')) {

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

}

function up($v){ return strtoupper(trim((string)$v)); }

function calc_sell(float $buy, float $markup): float {
  $markup = max(0.0, $markup);
  $sell = $buy * (1.0 + ($markup / 100.0));
  return max(0.0, $sell);
}

$scope_office = up($_GET['office_code'] ?? '');
$scope_cust   = up($_GET['customers_code'] ?? '');
$q = trim($_GET['q'] ?? '');

$params = [];
$where = ["p.status='active'"];
if ($q !== '') { $where[]="(p.sku LIKE :q OR p.products_name LIKE :q)"; $params['q']="%$q%"; }

$productsSql = "
  SELECT p.id, p.sku, p.products_name, p.unit, p.manufacture_id,
         m.manufacture_code
  FROM master_products p
  LEFT JOIN master_manufactures m ON m.id = p.manufacture_id
  WHERE ".implode(" AND ", $where)."
  ORDER BY p.products_name, p.sku
  LIMIT 5000
";
$stmt = $pdo->prepare($productsSql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// load pricelist rows for scope
$today = date('Y-m-d');
$plStmt = $pdo->prepare("
  SELECT *
  FROM master_pricelist
  WHERE deleted_at IS NULL
    AND status=1
    AND (valid_from IS NULL OR valid_from <= :today_from)
    AND (valid_to IS NULL OR valid_to >= :today_to)
    AND (office_code IS NULL OR office_code = :office)
    AND (customers_code IS NULL OR customers_code = :cust)
  ORDER BY id DESC
");
$plStmt->execute(['today_from'=>$today, 'today_to'=>$today, 'office'=>$scope_office, 'cust'=>$scope_cust]);
$plRows = $plStmt->fetchAll(PDO::FETCH_ASSOC);
$plMap = [];
foreach ($plRows as $r) { $k=up($r['sku']); if (!isset($plMap[$k])) $plMap[$k]=$r; }

$offices=[]; $customers=[];
try { $offices=$pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
try { $customers=$pdo->query("SELECT customers_code, customers_name FROM master_customers WHERE is_active=1 ORDER BY customers_name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Pricelist Jual (CRM/MPR)', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Pricelist Jual (CRM/MPR)',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{padding:16px}.small-muted{font-size:12px;color:#666}.nowrap{white-space:nowrap;}</style>',
]);
?>


<?php
$plSellRole = strtoupper(trim((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));
$plSellLevel = strtoupper(trim((string)($_SESSION['level'] ?? '')));
$plSellDept = strtoupper(trim((string)($_SESSION['department'] ?? '')));
$canManagePricelist = in_array($plSellRole, ['ADMIN', 'SUPERADMIN', 'SYS'], true)
    || in_array($plSellLevel, ['ADMIN', 'SUPERADMIN', 'SYS'], true)
    || in_array($plSellDept, ['FIN', 'PQP', 'SYS'], true);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0">Pricelist Jual (CRM / MPR)</h4>
    <div class="small-muted">Halaman ini hanya menampilkan Sell (tanpa Buy & Markup). Untuk update harga: Master Pricelist (Admin/PQP/FIN).</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="master_data.php">← Master Data</a>
    <?php if ($canManagePricelist): ?>
    <a class="btn btn-outline-primary btn-sm" href="master_pricelist.php">Kelola Pricelist</a>
    <?php endif; ?>
  </div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><input class="form-control form-control-sm" name="q" value="<?=h($q)?>" placeholder="Search SKU / Nama Produk"></div>
  <div class="col-md-3">
    <select class="form-select form-select-sm" name="office_code">
      <option value="">Office (scope)</option>
      <?php foreach($offices as $o): $oc=up($o['office_code']); ?>
        <option value="<?=h($oc)?>" <?= $scope_office===$oc?'selected':'' ?>><?=h($oc)?> - <?=h($o['office_name'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <select class="form-select form-select-sm" name="customers_code">
      <option value="">Customer (scope)</option>
      <?php foreach($customers as $c): $cc=up($c['customers_code']); ?>
        <option value="<?=h($cc)?>" <?= $scope_cust===$cc?'selected':'' ?>><?=h($cc)?> - <?=h($c['customers_name'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Apply</button></div>
</form>

<div class="alert alert-info">
  Scope: office="<?=h($scope_office)?>" | customer="<?=h($scope_cust)?>"
</div>

<table id="tbl" class="display" style="width:100%">
  <thead>
    <tr>
      <th class="nowrap">SKU</th>
      <th>Nama Produk</th>
      <th class="nowrap">Unit</th>
      <th class="nowrap">MNF</th>
      <th class="nowrap">Sell</th>
      <th class="nowrap">Cur</th>
      <th class="nowrap">Status</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($products as $p):
      $sku = up($p['sku']);
      $pr = $plMap[$sku] ?? null;
      $sell = $pr ? (float)$pr['sell_price'] : 0.0;
      $cur  = $pr ? ($pr['currency'] ?? 'IDR') : 'IDR';
      $st   = $pr ? 1 : 0;
    ?>
    <tr>
      <td class="nowrap"><?= h($sku) ?></td>
      <td><?= h(strtoupper($p['products_name'] ?? '')) ?></td>
      <td class="nowrap"><?= h($p['unit']) ?></td>
      <td class="nowrap"><?= h($p['manufacture_code']) ?></td>
      <td class="nowrap text-end"><b><?= number_format($sell,2) ?></b></td>
      <td class="nowrap"><?= h($cur) ?></td>
      <td class="nowrap"><?= $st===1?'<span class="badge bg-success">Set</span>':'<span class="badge bg-secondary">Not Set</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>

<script>
  new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });
</script>
<?php rmi_footer(); ?>
