<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// purchases/purchases_forwarding_tasks.php
// SCM - Forwarding tasks untuk PO (vendor = jasa forwarding/logistik, bukan sumber pembelian produk)

require_once __DIR__ . '/../master/auth.php';
require_login();
require_any_permission(['PURCHASES.FORWARDING_CRUD', 'PURCHASES.VIEW', 'SALES.VIEW']);

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$flash = p_flash_get();
$err = '';

function fw_upload_dir(): string {
  $dir = __DIR__ . '/../uploads/purchases_forwarding';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}
function fw_safe_name(string $name): string {
  return rmi_safe_filename($name);
}
function fw_save_upload(string $po_code, array $file): ?string {
  if (empty($file['name']) || (int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) return null;
  $origName = (string)($file['name'] ?? '');
  $safeName = rmi_safe_filename($origName);
  if ($safeName === '') throw new Exception("Nama file tidak valid.");
  $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
  $allow = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','zip','rar'];
  if (!in_array($ext, $allow, true)) throw new Exception("Format file tidak didukung: .$ext");
  $base = fw_upload_dir();
  $fname = fw_safe_name($po_code) . '__' . date('Ymd_His') . '__' . $safeName;
  $target = $base . '/' . $fname;
  if (!move_uploaded_file($file['tmp_name'], $target)) throw new Exception("Gagal upload file.");
  return '../uploads/purchases_forwarding/' . $fname;
}

// Handle save forwarding info
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
  try {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'save') {
      $po_id = (int)($_POST['po_id'] ?? 0);
      if ($po_id <= 0) throw new Exception("PO tidak valid.");

      $st = $pdo->prepare("SELECT id, po_code FROM purchases_po WHERE id=? AND deleted_at IS NULL");
      $st->execute([$po_id]);
      $po = $st->fetch();
      if (!$po) throw new Exception("PO tidak ditemukan.");

      $factory_info = trim((string)($_POST['factory_forwarding_info'] ?? ''));
      $forwarder_vendor_id = (int)($_POST['forwarder_vendor_id'] ?? 0);
      $forwarder_status = strtoupper(trim((string)($_POST['forwarder_status'] ?? 'PENDING')));
      if (!in_array($forwarder_status, ['PENDING','IN_PROGRESS','DONE'], true)) $forwarder_status = 'PENDING';
      $forwarder_note = trim((string)($_POST['forwarder_note'] ?? ''));

      if ($forwarder_vendor_id <= 0) $forwarder_vendor_id = null;

      $u = $pdo->prepare("UPDATE purchases_po
                          SET factory_forwarding_info=?, forwarder_vendor_id=?, forwarder_status=?, forwarder_note=?, updated_at=NOW()
                          WHERE id=?");
      $u->execute([$factory_info, $forwarder_vendor_id, $forwarder_status, $forwarder_note, $po_id]);

      // Upload doc optional
      if (!empty($_FILES['doc_file']['name'])) {
        $doc_type = strtoupper(trim((string)($_POST['doc_type'] ?? 'OTHER')));
        if (!in_array($doc_type, ['CIPL','BL_DRAFT','BL_FINAL','FORM_E','BC11','NOA','BILLING_AJU','SPPB','PIB','NIE_AKL','HS_CODE','OTHER'], true)) $doc_type = 'OTHER';
        $path = fw_save_upload((string)$po['po_code'], $_FILES['doc_file']);
        if ($path) {
          $note = trim((string)($_POST['doc_note'] ?? ''));
          $ins = $pdo->prepare("INSERT INTO purchases_forwarding_docs(po_id, doc_type, file_path, note, uploaded_by)
                                VALUES(?,?,?,?,?)");
          $ins->execute([$po_id, $doc_type, $path, $note, p_username()]);
        }
      }

      p_audit($pdo,'FORWARDING',$po['po_code'],'SAVE',[
        'forwarder_vendor_id'=>$forwarder_vendor_id,
        'forwarder_status'=>$forwarder_status
      ]);

      p_flash_set('ok', "Forwarding PO {$po['po_code']} tersimpan.");
      header("Location: purchases_forwarding_tasks.php");
      exit;
    }

    if ($action === 'delete_doc') {
      $doc_id = (int)($_POST['doc_id'] ?? 0);
      if ($doc_id <= 0) throw new Exception("Doc tidak valid.");
      $st = $pdo->prepare("SELECT id, po_id, file_path FROM purchases_forwarding_docs WHERE id=? AND deleted_at IS NULL");
      $st->execute([$doc_id]);
      $doc = $st->fetch();
      if (!$doc) throw new Exception("Doc tidak ditemukan.");

      $pdo->prepare("UPDATE purchases_forwarding_docs SET deleted_at=NOW() WHERE id=?")->execute([$doc_id]);
      p_audit($pdo,'FORWARDING','PO_ID:'.$doc['po_id'],'DOC_DELETE',['doc_id'=>$doc_id,'file_path'=>$doc['file_path']]);

      p_flash_set('ok', "Doc dihapus (soft delete).");
      header("Location: purchases_forwarding_tasks.php");
      exit;
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Filters
$f_status = strtoupper(trim((string)($_GET['po_status'] ?? '')));
$f_fw = strtoupper(trim((string)($_GET['fw_status'] ?? '')));

$where = "po.deleted_at IS NULL";
$params = [];
if ($f_status !== '') { $where .= " AND po.status=?"; $params[] = $f_status; }
if ($f_fw !== '') { $where .= " AND po.forwarder_status=?"; $params[] = $f_fw; }

$rows = [];
try {
  $sql = "
    SELECT
      po.*,
      m.manufacture_name,
      v.vendors_name AS forwarder_name,
      v.vendors_code AS forwarder_code
    FROM purchases_po po
    LEFT JOIN master_manufactures m ON m.id = po.manufacture_id
    LEFT JOIN master_vendors v ON v.id = po.forwarder_vendor_id
    WHERE $where
    ORDER BY po.updated_at DESC, po.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
} catch (Throwable $e) { $rows = []; }

// Vendors list (forwarders/logistics)
$vendors = [];
try {
  $vendors = $pdo->query("SELECT id, vendors_name, vendors_code FROM master_vendors WHERE status='active' ORDER BY vendors_name ASC")->fetchAll();
} catch (Throwable $e) { $vendors = []; }

// Docs grouped by PO
$docsByPo = [];
try {
  $st = $pdo->query("SELECT * FROM purchases_forwarding_docs WHERE deleted_at IS NULL ORDER BY uploaded_at DESC, id DESC");
  foreach ($st->fetchAll() as $d) {
    $docsByPo[(int)$d['po_id']][] = $d;
  }
} catch (Throwable $e) { $docsByPo = []; }

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('SCM Forwarding Tasks (PO)', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'SCM Forwarding Tasks (PO)',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background: radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:16px;font-family:system-ui}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb;padding:8px 12px;text-decoration:none}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    .badge-soft{border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);border-radius:999px;padding:4px 10px;font-size:12px}
    table.dataTable{color:#e5e7eb !important}
    table.dataTable thead th{color:#e5e7eb !important}
    .dataTables_wrapper .dataTables_filter input{color:#e5e7eb}
    .dataTables_wrapper .dataTables_length select{color:#e5e7eb}
    .modal-content{background:#0b1220;color:#e5e7eb;border:1px solid rgba(255,255,255,.10)}
    .form-control,.form-select{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:#e5e7eb}
    .form-control:focus,.form-select:focus{background:rgba(255,255,255,.08);color:#fff;border-color:rgba(96,165,250,.6)}
    a{color:#93c5fd}
    a:hover{color:#bfdbfe}</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">Purchases ▸ SCM</div>
    <h3 class="mb-0">Forwarding Tasks (berdasarkan PO)</h3>
    <div class="muted">Vendor di sini adalah <b>jasa forwarding/logistik</b>. Pembelian produk tetap dari <b>Manufactures</b>.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn-soft" href="purchases_dashboard.php">← Purchases</a>
    <a class="btn-soft" href="../stock/wqs_incoming.php">WQS Incoming</a>
    <a class="btn-soft" href="../master/master_vendors.php" target="_blank">Master Vendor</a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert <?=($flash['type']==='ok'?'alert-success':'alert-warning')?>"><?=h($flash['msg'])?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-danger"><?=h($err)?></div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-md-3">
      <label class="form-label muted">Filter PO Status</label>
      <input class="form-control" name="po_status" value="<?=h($f_status)?>" placeholder="DRAFT / OPEN / ...">
    </div>
    <div class="col-md-3">
      <label class="form-label muted">Filter Forwarding Status</label>
      <select class="form-select" name="fw_status">
        <option value="">(Semua)</option>
        <?php foreach (['PENDING','IN_PROGRESS','DONE'] as $x): ?>
          <option value="<?=$x?>" <?=$f_fw===$x?'selected':''?>><?=$x?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6 d-flex gap-2">
      <button class="btn btn-primary">Apply</button>
      <a class="btn btn-outline-light" href="purchases_forwarding_tasks.php">Reset</a>
    </div>
  </form>
</div>

<div class="card p-3">
  <table id="tbl" class="display nowrap" style="width:100%">
    <thead>
      <tr>
        <th>PO Code</th>
        <th>Date</th>
        <th>Manufacture</th>
        <th>Office</th>
        <th>PO Status</th>
        <th>Forwarder</th>
        <th>Forwarding Status</th>
        <th>Docs</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r):
        $poId = (int)$r['id'];
        $docs = $docsByPo[$poId] ?? [];
        $fwdName = trim((string)($r['forwarder_name'] ?? ''));
        $fwdCode = trim((string)($r['forwarder_code'] ?? ''));
      ?>
        <tr>
          <td><b><?=h(strtoupper($r['po_code'] ?? ''))?></b></td>
          <td><?=h($r['po_date'] ?? '')?></td>
          <td><?=h(strtoupper($r['manufacture_name'] ?? ''))?></td>
          <td><?=h(strtoupper($r['office_code'] ?? ''))?></td>
          <td><span class="badge-soft"><?=h(strtoupper($r['status'] ?? ''))?></span></td>
          <td><?=h(strtoupper($fwdName !== '' ? ($fwdName . ($fwdCode!==''?' ('.$fwdCode.')':'')) : '-'))?></td>
          <td><span class="badge-soft"><?=h(strtoupper($r['forwarder_status'] ?? 'PENDING'))?></span></td>
          <td><?=count($docs)?> file</td>
          <td>
            <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#m<?=$poId?>">Manage</button>
          </td>
        </tr>

        <!-- Modal Manage -->
        <div class="modal fade" id="m<?=$poId?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <div>
                  <h5 class="modal-title mb-0">Forwarding: <?=h($r['po_code'])?></h5>
                  <div class="muted">Manufacture: <?=h($r['manufacture_name'] ?? '')?></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <form method="post" enctype="multipart/form-data" class="mb-3">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="save">
                  <input type="hidden" name="po_id" value="<?=$poId?>">

                  <div class="mb-2">
                    <label class="form-label muted">Info Forwarding dari Pabrik (Manufacture)</label>
                    <textarea class="form-control" rows="2" name="factory_forwarding_info" placeholder="Contoh: alamat pickup, contact pabrik, jadwal pickup..."><?=h($r['factory_forwarding_info'] ?? '')?></textarea>
                  </div>

                  <div class="row g-2">
                    <div class="col-md-6">
                      <label class="form-label muted">Vendor Forwarder Lokal (Master Vendor)</label>
                      <select class="form-select" name="forwarder_vendor_id">
                        <option value="0">— pilih forwarder —</option>
                        <?php $sel = (int)($r['forwarder_vendor_id'] ?? 0); foreach($vendors as $v): ?>
                          <option value="<?= (int)$v['id'] ?>" <?=$sel===(int)$v['id']?'selected':''?>>
                            <?=h(strtoupper($v['vendors_name'] ?? '').' ('.strtoupper($v['vendors_code'] ?? '').')')?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label muted">Forwarding Status</label>
                      <?php $cur = strtoupper((string)($r['forwarder_status'] ?? 'PENDING')); ?>
                      <select class="form-select" name="forwarder_status">
                        <?php foreach(['PENDING','IN_PROGRESS','DONE'] as $x): ?>
                          <option value="<?=$x?>" <?=$cur===$x?'selected':''?>><?=$x?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>

                  <div class="mt-2">
                    <label class="form-label muted">Catatan SCM</label>
                    <textarea class="form-control" rows="2" name="forwarder_note" placeholder="Negosiasi, estimasi waktu, catatan keamanan..."><?=h($r['forwarder_note'] ?? '')?></textarea>
                  </div>

                  <hr class="border-secondary">

                  <div class="row g-2">
                    <div class="col-md-4">
                      <label class="form-label muted">Upload Dokumen</label>
                      <input class="form-control" type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.zip,.rar">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label muted">Tipe Dokumen</label>
                      <select class="form-select" name="doc_type">
                        <?php foreach(['CIPL','BL_DRAFT','BL_FINAL','FORM_E','BC11','NOA','BILLING_AJU','SPPB','PIB','NIE_AKL','HS_CODE','OTHER'] as $x): ?>
                          <option value="<?=$x?>"><?=$x?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label muted">Catatan Doc</label>
                      <input class="form-control" name="doc_note" placeholder="opsional">
                    </div>
                  </div>

                  <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary">Simpan</button>
                    <span class="muted">Upload doc bersifat opsional (bisa simpan tanpa upload).</span>
                  </div>
                </form>

                <div class="card p-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-semibold">Dokumen Tersimpan</div>
                    <div class="muted"><?=count($docs)?> file</div>
                  </div>
                  <?php if (!$docs): ?>
                    <div class="muted">Belum ada dokumen.</div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-dark table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Type</th>
                            <th>File</th>
                            <th>Note</th>
                            <th>By</th>
                            <th>At</th>
                            <th style="width:90px">Aksi</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach($docs as $d): ?>
                            <tr>
                              <td><span class="badge-soft"><?=h($d['doc_type'])?></span></td>
                              <td><a href="<?=h($d['file_path'])?>" target="_blank">open</a></td>
                              <td><?=h($d['note'] ?? '')?></td>
                              <td><?=h($d['uploaded_by'] ?? '')?></td>
                              <td class="muted"><?=h($d['uploaded_at'] ?? '')?></td>
                              <td>
                                <form method="post" onsubmit="return confirm('Hapus doc ini? (soft delete)');">
                                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                  <input type="hidden" name="action" value="delete_doc">
                                  <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                                  <button class="btn btn-sm btn-outline-danger">Del</button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>

              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>
<script>
$(function(){
  $('#tbl').DataTable({
    pageLength: 25,
    order: [[1,'desc']],
    dom: 'Bfrtip',
    buttons: ['copy','csv','excel','pdf','print']
  });
});
</script>
<?php rmi_footer(); ?>
