<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader


// --- upload safety (auto-enforced) ---
require_once dirname(__DIR__, 1) . '/_shared/upload_safety.php';
if (!empty($_FILES)) {
    // enforce safe_filename() for all uploaded names
    rmi_sanitize_uploads($_FILES);
}
// --- /upload safety ---
// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 1) . '/master/auth.php';
require_once dirname(__DIR__, 1) . '/_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'WQS.INCOMING_EDIT', 'PURCHASES.GR_PROCESS']);
} else {
    require_role(['WQS', 'ADMIN', 'SUPERADMIN', 'SYS', 'SCM', 'PQP']);
}
// -------------------------------------------------------------

// stock/wqs_incoming_view.php
// View incoming detail + items + docs list


if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function up($v){ return strtoupper(trim((string)($v ?? ''))); }

// --- DB (centralized, env-first) ---
$pdo = function_exists('db_pdo') ? db_pdo() : rmi_db_pdo();

$code = up($_GET['code'] ?? '');
if ($code === '') die("code required");

$headStmt = $pdo->prepare("SELECT * FROM wqs_incoming WHERE UPPER(incoming_code)=UPPER(?) LIMIT 1");
$headStmt->execute([$code]);
$head = $headStmt->fetch();
if (!$head) die("Incoming tidak ditemukan: ".h($code));

$itemStmt = $pdo->prepare("
  SELECT i.*, p.products_name, p.unit
  FROM wqs_incoming_items i
  LEFT JOIN master_products p ON p.id=i.product_id
  WHERE i.incoming_id=?
  ORDER BY i.id ASC
");
$itemStmt->execute([(int)$head['id']]);
$items = $itemStmt->fetchAll();

// docs
$docsDir = __DIR__ . '/../uploads/wqs_incoming/' . $head['incoming_code'];
$docs = [];
if (is_dir($docsDir)) {
  $files = array_values(array_filter(scandir($docsDir), fn($f)=> $f!=='.' && $f!=='..'));
  foreach ($files as $f) {
    $docs[] = $f;
  }
}


$arrivalPhotos = json_decode((string)($head['goods_arrival_photos'] ?? '[]'), true);
$arrivalVideos = json_decode((string)($head['goods_arrival_videos'] ?? '[]'), true);
if (!is_array($arrivalPhotos)) $arrivalPhotos = [];
if (!is_array($arrivalVideos)) $arrivalVideos = [];

// handle docs upload/delete (no DB changes)
$flash = null;

function is_allowed_ext($name){
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $allow = ['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','mp4'];
  return in_array($ext, $allow, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
  $action = $_POST['action'] ?? '';
  if ($action === 'upload_doc') {
    if (!isset($_FILES['doc']) || !is_uploaded_file($_FILES['doc']['tmp_name'])) {
      $flash = ['type'=>'danger','msg'=>'Pilih file dulu.'];
    } else {
      $name = $_FILES['doc']['name'] ?? '';
      $size = (int)($_FILES['doc']['size'] ?? 0);
      if ($size <= 0 || $size > 50*1024*1024) {
        $flash = ['type'=>'danger','msg'=>'Ukuran file max 50MB.'];
      } elseif (!is_allowed_ext($name)) {
        $flash = ['type'=>'danger','msg'=>'Ekstensi tidak diizinkan. (pdf/doc/docx/xls/xlsx/ppt/pptx/jpg/png/mp4)'];
      } else {
        if (!is_dir($docsDir)) @mkdir($docsDir, 0777, true);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $dest = $docsDir . '/' . $safe;
        if (move_uploaded_file($_FILES['doc']['tmp_name'], $dest)) {
          $flash = ['type'=>'success','msg'=>'Dokumen berhasil diupload.'];
        } else {
          $flash = ['type'=>'danger','msg'=>'Gagal upload dokumen.'];
        }
      }
    }
  } elseif ($action === 'delete_doc') {
    $fname = $_POST['fname'] ?? '';
    $safe = basename($fname);
    $path = $docsDir . '/' . $safe;
    if ($safe === '' || !is_file($path)) {
      $flash = ['type'=>'danger','msg'=>'File tidak ditemukan.'];
    } else {
      @unlink($path);
      $flash = ['type'=>'success','msg'=>'Dokumen dihapus.'];
    }
  }
  // refresh docs list
  $docs = [];
  if (is_dir($docsDir)) {
    $files = array_values(array_filter(scandir($docsDir), fn($f)=> $f!=='.' && $f!=='..'));
    foreach ($files as $f) $docs[] = $f;
  }
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Wqs Incoming View', [
  'active' => 'stock',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'Wqs Incoming View',
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
    .nowrap{ white-space: nowrap; }
    a { color: #93c5fd; }</style>',
]);
?>



<?php if ($flash): ?>
  <div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div>
<?php endif; ?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted mb-1">WQS Incoming</div>
    <h3 class="mb-0"><?=h(up($head['incoming_code'] ?? ''))?></h3>
    <div class="muted">Received: <?=h($head['received_date'])?> | PO: <?=h(up($head['po_code'] ?? ''))?> | Office: <?=h(up($head['office_code'] ?? ''))?> | Depo: <?=h(up($head['depo_name'] ?? ''))?></div>
  </div>
  <div class="d-flex gap-2">
    <?php $bp = defined('BASE_PROJECT') ? rtrim(BASE_PROJECT, '/') : (string)($GLOBALS['BASE_PROJECT'] ?? ''); ?>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_incoming.php">← Back</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock.php">Stock</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_allocation.php?code=<?=urlencode($head['incoming_code'])?>">Allocate</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-1">PO Code</div>
    <div class="mb-2"><b><?=h($head['po_code'] ?? '')?></b></div>
    <div class="fw-semibold mb-1">Ref Note</div>
    <div><?=h($head['ref_note'])?></div>
    <?php if (!empty(trim((string)($head['wqs_stock_before'] ?? ''))) || !empty(trim((string)($head['wqs_stock_after'] ?? '')))): ?>
    <div class="mt-2 pt-2 border-top border-secondary">
      <div class="fw-semibold mb-1">Bukti Kartu Stok</div>
      <div class="d-flex gap-3 small">
        <div>Before: <?php echo !empty(trim((string)($head['wqs_stock_before'] ?? ''))) ? '<a href="'.h($head['wqs_stock_before']).'" target="_blank">lihat</a>' : '<span class="muted">-</span>'; ?></div>
        <div>After: <?php echo !empty(trim((string)($head['wqs_stock_after'] ?? ''))) ? '<a href="'.h($head['wqs_stock_after']).'" target="_blank">lihat</a>' : '<span class="muted">-</span>'; ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>


<div class="card mb-3">
 <div class="card-body">
  <div class="fw-semibold mb-2">Bukti Barang Datang</div>
  <div class="muted mb-3">Dokumentasi fisik barang diterima WQS. Terpisah dari Foto Kartu Stok SEBELUM / SESUDAH.</div>
  <?php if (!$arrivalPhotos && !$arrivalVideos): ?><div class="muted">Belum ada foto/video bukti barang datang.</div><?php endif; ?>
  <?php if ($arrivalPhotos): ?><div class="row g-2 mb-3"><?php foreach($arrivalPhotos as $p): ?>
   <div class="col-6 col-md-3"><a href="<?=h($p)?>" target="_blank"><img src="<?=h($p)?>" alt="Bukti barang datang" style="width:100%;height:160px;object-fit:cover;border-radius:10px"></a></div>
  <?php endforeach; ?></div><?php endif; ?>
  <?php if ($arrivalVideos): ?><div class="row g-2"><?php foreach($arrivalVideos as $v): ?>
   <div class="col-12 col-md-6"><video controls preload="metadata" style="width:100%;max-height:320px;background:#000;border-radius:10px"><source src="<?=h($v)?>"></video><div><a href="<?=h($v)?>" target="_blank">Buka video</a></div></div>
  <?php endforeach; ?></div><?php endif; ?>
 </div>
</div>

<div class="card mb-3">
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
            <th class="nowrap">LOT</th>
            <th class="nowrap">Serial</th>
            <th class="nowrap">EXP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $it): ?>
          <tr>
            <td class="nowrap"><b><?=h(strtoupper($it['sku'] ?? ''))?></b></td>
            <td><?=h(strtoupper($it['products_name'] ?? ''))?></td>
            <td class="nowrap"><?=h(strtoupper($it['unit'] ?? 'UNIT'))?></td>
            <td class="nowrap"><?= (int)$it['qty'] ?></td>
            <td class="nowrap"><?=h($it['lot_number'])?></td>
            <td class="nowrap"><?=h($it['serial_number'])?></td>
            <td class="nowrap"><?=h($it['exp_date'])?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">Dokumen</div>
    <form method="post" enctype="multipart/form-data" class="row g-2 mb-3">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="upload_doc">
      <div class="col-md-8">
        <input class="form-control form-control-sm" type="file" name="doc" required>
        <div class="muted mt-1">Allowed: pdf, doc/docx, xls/xlsx, ppt/pptx, jpg/png, mp4 (max 50MB/file)</div>
      </div>
      <div class="col-md-4 d-grid">
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
      </div>
    </form>

    <?php if (!$docs): ?>
      <div class="muted">Tidak ada dokumen.</div>
    <?php else: ?>
      <ul class="mb-0">
        <?php foreach($docs as $f): ?>
          <li class="d-flex justify-content-between align-items-center gap-2">
            <a href="<?= '/uploads/wqs_incoming/'.rawurlencode($head['incoming_code']).'/'.rawurlencode($f) ?>" target="_blank"><?=h($f)?></a>
            <form method="post" class="m-0">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete_doc">
              <input type="hidden" name="fname" value="<?=h($f)?>">
              <button class="btn btn-outline-danger btn-sm" type="submit" onclick="return confirm('Hapus dokumen ini?')">Delete</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <div class="muted mt-2">Folder: <code>/uploads/wqs_incoming/<?=h($head['incoming_code'])?>/</code></div>
  </div>
</div>
<?php rmi_footer(); ?>
