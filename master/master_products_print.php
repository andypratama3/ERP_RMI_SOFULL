<?php
// master/master_products_print.php
// Print view A4 (tanpa harga). Bisa di-"Save as PDF" dari browser.

require_once __DIR__ . '/auth.php';
require_login();

$pdo = db_pdo();

if (!function_exists('h')) {

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

}


// Filters
$manufacture_id = (int)($_GET['manufacture_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$product_type = trim((string)($_GET['product_type'] ?? ''));

// Lookup manufactures for filter label
$manufactures = $pdo->query("SELECT id, manufacture_code, manufacture_name, manufactures_code, manufactures_name
                             FROM master_manufactures
                             ORDER BY manufacture_name ASC")->fetchAll();

$where = [];
$params = [];

if ($manufacture_id > 0) { $where[] = "p.manufacture_id = :mid"; $params[':mid'] = $manufacture_id; }
if ($status !== '') { $where[] = "p.status = :st"; $params[':st'] = $status; }
if ($product_type !== '' && in_array($product_type, ['SINGLE','PAKET'], true)) { $where[] = "p.product_type = :pt"; $params[':pt'] = $product_type; }
if ($q !== '') {
  $where[] = "(p.sku LIKE :q OR p.products_name LIKE :q OR p.category LIKE :q OR p.no_akl LIKE :q OR p.akl_reg_no LIKE :q)";
  $params[':q'] = "%{$q}%";
}

$sql = "SELECT p.id, p.sku, p.products_name, p.category, p.unit, p.status, p.product_type, p.no_akl, p.akl_reg_no, p.created_at,
               m.manufacture_code, m.manufacture_name, m.manufactures_code, m.manufactures_name
        FROM master_products p
        LEFT JOIN master_manufactures m ON m.id = p.manufacture_id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY p.products_name ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Resolve manufacture label for header
$mf_label = 'ALL';
if ($manufacture_id > 0) {
  foreach($manufactures as $m){
    if ((int)$m['id'] === $manufacture_id) {
      $code = $m['manufacture_code'] ?: $m['manufactures_code'];
      $name = $m['manufacture_name'] ?: $m['manufactures_name'];
      $mf_label = trim($code . ' — ' . $name);
      break;
    }
  }
}

$today = date('Y-m-d H:i');

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Print Master Products', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Print Master Products',
  ],
  'extra_head' => '<style>
    @page { size: A4; margin: 12mm; }
    body { font-family: Arial, sans-serif; color: #111; }
    .no-print { margin-bottom: 12px; }
    .btn { display:inline-block; padding:6px 10px; border:1px solid #333; text-decoration:none; color:#111; border-radius:6px; font-size:12px; }
    .btn + .btn { margin-left:8px; }
    h2 { margin: 0 0 4px 0; font-size: 16px; }
    .meta { font-size: 12px; margin-bottom: 10px; color:#444; }
    table { width:100%; border-collapse: collapse; font-size: 11px; }
    th, td { border: 1px solid #333; padding: 4px 6px; vertical-align: top; }
    th { background: #f0f0f0; text-align: left; }
    .muted { color:#666; }
    .sig { margin-top: 20px; display:flex; gap: 30px; }
    .sig > div { flex:1; text-align:center; }
    .line { border-top:1px solid #111; margin-top: 40px; }
    @media print { .no-print { display:none; } }
  .no-print{display:flex;}
@media print{.no-print{display:none;}}
</style>',
]);
?>

<div class="no-print" style="margin:10px 0; display:flex; justify-content:space-between; align-items:center;">
  <div style="color:#555; font-size:13px;">Print A4 • tanpa harga</div>
  <div style="display:flex; gap:8px;">
    <a href="master_products.php" style="text-decoration:none; padding:6px 10px; border:1px solid #bbb; border-radius:6px; color:#333;">Back to Master Products</a>
    <a href="#" onclick="window.print(); return false;" style="text-decoration:none; padding:6px 10px; border:1px solid #bbb; border-radius:6px; color:#333;">Print</a>
  </div>
</div>

  <div class="no-print">
    <a class="btn" href="master_products.php">← Back</a>
    <a class="btn" href="#" onclick="window.print();return false;">Print / Save as PDF</a>
  </div>

  <h2>MASTER PRODUCTS (Tanpa Harga)</h2>
  <div class="meta">
    Manufacture: <b><?=h($mf_label)?></b> &nbsp;|&nbsp;
    Status: <b><?=h($status ?: 'ALL')?></b> &nbsp;|&nbsp;
    Type: <b><?=h($product_type ?: 'ALL')?></b> &nbsp;|&nbsp;
    Search: <b><?=h($q ?: '-')?></b>
    <div class="muted">Printed at: <?=h($today)?> &nbsp;|&nbsp; Total: <?= (int)count($rows) ?> item(s)</div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:32px;">No</th>
        <th style="width:90px;">SKU</th>
        <th>Nama Produk</th>
        <th style="width:95px;">Category</th>
        <th style="width:55px;">Unit</th>
        <th style="width:60px;">Type</th>
        <th style="width:70px;">Status</th>
        <th style="width:120px;">AKD/AKL</th>
        <th style="width:160px;">Manufacture</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" style="text-align:center;color:#777;">No data</td></tr>
      <?php else: $i=1; foreach($rows as $r):
        $akl = $r['no_akl'] ?: $r['akl_reg_no'];
        $mcode = $r['manufacture_code'] ?: $r['manufactures_code'];
        $mname = $r['manufacture_name'] ?: $r['manufactures_name'];
      ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= h(strtoupper($r['sku'] ?? '')) ?></td>
          <td><?= h(strtoupper($r['products_name'] ?? '')) ?></td>
          <td><?= h($r['category']) ?></td>
          <td><?= h($r['unit']) ?></td>
          <td><?= h($r['product_type']) ?></td>
          <td><?= h($r['status']) ?></td>
          <td><?= h($akl) ?></td>
          <td><?= h(trim($mcode . ' ' . $mname)) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="sig">
    <div>
      HRL<br>
      <div class="line"></div>
      (Nama / TTD)
    </div>
    <div>
      PQP<br>
      <div class="line"></div>
      (Nama / TTD)
    </div>
    <div>
      Owner / ITC<br>
      <div class="line"></div>
      (Nama / TTD)
    </div>
  </div>
<?php rmi_footer(); ?>
