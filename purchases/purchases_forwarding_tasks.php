<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// purchases/purchases_forwarding_tasks.php
// SCM - Forwarding tasks untuk PO (vendor = jasa forwarding/logistik, bukan sumber pembelian produk)

require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    $allowed = function_exists('can_any') && can_any(['PURCHASES.FORWARDING_VIEW', 'PURCHASES.FORWARDING_CREATE', 'PURCHASES.FORWARDING_EDIT']);
    if (!$allowed && function_exists('require_role')) {
        require_role(['SCM','ADMIN','SUPERADMIN','SYS','PQP','FIN','WQS','BRANCH','MANAGER','STAFF']);
    } elseif (!$allowed) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
} else {
    require_role(['SCM','ADMIN','SUPERADMIN','SYS','PQP','FIN','WQS','BRANCH','MANAGER','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/../master/_audit_master.php';
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

/**
 * Canonical scope Forwarding = PO IMPORT saja.
 *
 * Prioritas sumber:
 * 1) penanda eksplisit di purchases_po (is_import / purchase_type / dst),
 * 2) penanda eksplisit di PR asal (wqs_pr) melalui po.pr_id,
 * 3) fallback legacy: PO sudah tercatat di purchases_import_control.
 *
 * Jika penanda eksplisit tersedia, fallback TIDAK digabungkan. Ini penting agar
 * PR/PO LOCAL yang kebetulan punya data legacy/stale forwarding tidak ikut masuk.
 */
function fw_table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $out = [];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`','',$table) . "`");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $f = trim((string)($r['Field'] ?? ''));
      if ($f !== '') $out[$f] = true;
    }
  } catch (Throwable $e) {}
  return $cache[$table] = $out;
}
function fw_table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function fw_text_import_condition(string $expr): string {
  $u = "UPPER(TRIM(COALESCE({$expr},'')))";
  return "(({$u} IN ('IMPORT','IMPOR','OVERSEAS','FOREIGN','INTERNATIONAL') OR {$u} LIKE '%IMPORT%' OR {$u} LIKE '%IMPOR%') AND {$u} NOT LIKE '%LOCAL%' AND {$u} NOT LIKE '%LOKAL%')";
}
function fw_import_scope(PDO $pdo, string $poAlias='po'): array {
  $pc = fw_table_columns($pdo, 'purchases_po');

  // Boolean marker paling kuat.
  foreach (['is_import','import_flag','is_overseas'] as $c) {
    if (isset($pc[$c])) {
      return ['sql'=>"COALESCE({$poAlias}.`{$c}`,0)=1", 'source'=>"purchases_po.{$c}"];
    }
  }

  // Text marker pada PO.
  foreach (['purchase_type','po_type','procurement_type','source_type','purchase_source','order_type'] as $c) {
    if (isset($pc[$c])) {
      return ['sql'=>fw_text_import_condition("{$poAlias}.`{$c}`"), 'source'=>"purchases_po.{$c}"];
    }
  }

  // Penanda pada PR asal.
  if (isset($pc['pr_id']) && fw_table_exists($pdo, 'wqs_pr')) {
    $prc = fw_table_columns($pdo, 'wqs_pr');
    foreach (['is_import','import_flag','is_overseas'] as $c) {
      if (isset($prc[$c])) {
        return [
          'sql'=>"EXISTS (SELECT 1 FROM wqs_pr fwpr WHERE fwpr.id={$poAlias}.pr_id AND COALESCE(fwpr.`{$c}`,0)=1)",
          'source'=>"wqs_pr.{$c}"
        ];
      }
    }
    foreach (['purchase_type','pr_type','request_type','procurement_type','source_type','purchase_source'] as $c) {
      if (isset($prc[$c])) {
        $cond = fw_text_import_condition("fwpr.`{$c}`");
        return [
          'sql'=>"EXISTS (SELECT 1 FROM wqs_pr fwpr WHERE fwpr.id={$poAlias}.pr_id AND {$cond})",
          'source'=>"wqs_pr.{$c}"
        ];
      }
    }
  }

  /*
   * Schema produksi saat ini tidak punya marker LOCAL/IMPORT pada wqs_pr/purchases_po.
   * Data aktual menunjukkan purchases_import_control juga berisi PO lokal IDR legacy,
   * sehingga keberadaan row import_control TIDAK boleh dipakai sebagai penanda import.
   *
   * Fallback canonical untuk schema sekarang:
   * - currency valuta asing = PO import;
   * - IDR/Rupiah = lokal, tidak masuk forwarding.
   *
   * Daftar dibuat eksplisit (bukan "currency <> IDR") agar nilai kosong/aneh fail-closed.
   */
  if (isset($pc['currency'])) {
    return [
      'sql'=>"UPPER(TRIM(COALESCE({$poAlias}.currency,''))) IN ('USD','CNY','RMB','EUR','SGD','JPY','HKD','GBP','AUD')",
      'source'=>'purchases_po.currency'
    ];
  }

  // Fail closed: jangan pernah menganggap seluruh PO sebagai forwarding.
  return ['sql'=>'1=0', 'source'=>'NO_IMPORT_MARKER'];
}
function fw_assert_import_po(PDO $pdo, int $poId): void {
  $scope = fw_import_scope($pdo, 'po');
  $st = $pdo->prepare("SELECT 1 FROM purchases_po po WHERE po.id=? AND po.deleted_at IS NULL AND ({$scope['sql']}) LIMIT 1");
  $st->execute([$poId]);
  if (!$st->fetchColumn()) {
    throw new Exception('Forwarding hanya berlaku untuk PO Import. PO Lokal tidak boleh diproses sebagai forwarding.');
  }
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

/**
 * Resolver URL dokumen forwarding (read-only).
 * Memperbaiki path legacy seperti:
 *   uploads/purchases_forwarding/...
 *   ../uploads/purchases_forwarding/...
 *   /purchases/uploads/purchases_forwarding/...
 * menjadi URL project-root /uploads/purchases_forwarding/...
 * tanpa mengubah record DB ataupun memindahkan file.
 */
function fw_doc_href(string $rawPath, string $baseProject=''): string {
  $rawPath = trim($rawPath);
  if ($rawPath === '') return '#';
  if (preg_match('~^https?://~i', $rawPath)) return $rawPath;

  $path = str_replace('\\', '/', $rawPath);
  $path = preg_replace('~/+~', '/', $path);

  // Ambil suffix canonical mulai dari uploads/purchases_forwarding.
  $marker = 'uploads/purchases_forwarding/';
  $pos = stripos($path, $marker);
  if ($pos !== false) {
    $rel = substr($path, $pos);
    return rtrim($baseProject, '/') . '/' . ltrim($rel, '/');
  }

  // Path project-relative lain tetap diarahkan melalui base project.
  $path = preg_replace('~^(?:\.\./)+~', '', $path);
  return rtrim($baseProject, '/') . '/' . ltrim($path, '/');
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

      // Guard server-side: PO Lokal tidak boleh masuk proses Forwarding.
      fw_assert_import_po($pdo, $po_id);

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
      if (function_exists('master_audit')) {
        master_audit($pdo, 'purchases_forwarding', 'purchases_po', 'SAVE', $po_id, $po['po_code'], "Forwarding PO saved: {$po['po_code']}", ['forwarder_status' => $forwarder_status]);
      }
      p_flash_set('ok', "Forwarding PO {$po['po_code']} tersimpan.");
      rmi_redirect("purchases_forwarding_tasks.php");
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
      if (function_exists('master_audit')) {
        $poCode = '';
        try { $st2 = $pdo->prepare("SELECT po_code FROM purchases_po WHERE id=? LIMIT 1"); $st2->execute([$doc['po_id']]); $poCode = (string)($st2->fetchColumn() ?: ''); } catch (Throwable $e) {}
        master_audit($pdo, 'purchases_forwarding', 'purchases_forwarding_docs', 'DOC_DELETE', $doc_id, $poCode, "Forwarding doc deleted: PO {$poCode}", ['doc_id' => $doc_id]);
      }
      p_flash_set('ok', "Doc dihapus (soft delete).");
      rmi_redirect("purchases_forwarding_tasks.php");
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Filters
$f_status = strtoupper(trim((string)($_GET['po_status'] ?? '')));
$f_fw = strtoupper(trim((string)($_GET['fw_status'] ?? '')));

$fwImportScope = fw_import_scope($pdo, 'po');
$where = "po.deleted_at IS NULL AND (" . $fwImportScope['sql'] . ")";
$params = [];

// Default view = task forwarding aktif saja.
// History DONE/CLOSED/CANCELLED tetap bisa dicari lewat filter eksplisit.
if ($f_status === '') {
  $where .= " AND UPPER(COALESCE(po.status,'')) NOT IN ('DONE','CLOSED','CANCELLED','CANCELED','COMPLETED','DELIVERED','PAID','VOID')";
} else {
  $where .= " AND UPPER(COALESCE(po.status,''))=?";
  $params[] = $f_status;
}
if ($f_fw === '') {
  $where .= " AND UPPER(COALESCE(po.forwarder_status,'PENDING')) IN ('PENDING','IN_PROGRESS')";
} else {
  $where .= " AND UPPER(COALESCE(po.forwarder_status,'PENDING'))=?";
  $params[] = $f_fw;
}

// Barang yang sudah tiba di warehouse bukan lagi task forwarding aktif.
// Rule ini hanya berlaku pada default view; history tetap dapat dicari dengan filter.
if ($f_status === '' && $f_fw === '' && fw_table_exists($pdo, 'purchases_import_control')) {
  $icCols = fw_table_columns($pdo, 'purchases_import_control');
  if (isset($icCols['po_id']) && isset($icCols['arrived_warehouse_date'])) {
    $where .= " AND NOT EXISTS (
      SELECT 1 FROM purchases_import_control fw_done
      WHERE fw_done.po_id=po.id
        AND fw_done.arrived_warehouse_date IS NOT NULL
    )";
  }
}

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

// Vendors list (hanya Forwarding & Logistic/Expedisi — SCM)
$vendors = [];
try {
  $vendors = $pdo->query("
    SELECT id, vendors_name, vendors_code FROM master_vendors
    WHERE status='active'
    AND (vendor_type = 'Forwarding' OR vendor_type = 'Forwarder'
         OR vendor_type IN ('Logistic/Expedisi','Logistics','Logistic','Ekspedisi','Expedisi'))
    ORDER BY vendors_name ASC
  ")->fetchAll();
} catch (Throwable $e) { $vendors = []; }

// Docs grouped by PO
$docsByPo = [];
try {
  $st = $pdo->query("SELECT * FROM purchases_forwarding_docs WHERE deleted_at IS NULL ORDER BY uploaded_at DESC, id DESC");
  foreach ($st->fetchAll() as $d) {
    $docsByPo[(int)$d['po_id']][] = $d;
  }
} catch (Throwable $e) { $docsByPo = []; }

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'purchases_forwarding' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $audit_rows = []; }

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('SCM Forwarding Tasks (PO)', [
  'active' => 'purchases_forwarding',
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
    .badge-soft{border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);border-radius:999px;padding:4px 10px;font-size:12px;white-space:nowrap}
    .fw-table-wrap{width:100%;overflow-x:auto}
    #tbl{width:100%!important;table-layout:auto}
    #tbl th,#tbl td{vertical-align:middle;white-space:nowrap}
    #tbl td:nth-child(3){white-space:normal;min-width:260px}
    #tbl td:nth-child(6){white-space:normal;min-width:220px}
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
    <div class="muted">Hanya <b>PO Import</b> yang masuk task ini. Vendor di sini adalah <b>jasa forwarding/logistik</b>; PR/PO Lokal tidak masuk Forwarding.</div>
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

<div class="card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div>
      <div class="fw-semibold">Forwarding Aktif — PO Import</div>
      <div class="muted">Default menampilkan PENDING / IN_PROGRESS. DONE/CANCELLED dapat dicari melalui filter.</div>
    </div>
    <div class="muted"><?=count($rows)?> task</div>
  </div>

  <div class="fw-table-wrap">
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
            <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#m<?=$poId?>">Manage</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal diletakkan DI LUAR table/tbody.
     Ini penting karena <div class="modal"> di dalam <tbody> membuat browser memperbaiki DOM
     secara otomatis dan DataTables menjadi rusak/berantakan. -->
<?php foreach($rows as $r):
  $poId = (int)$r['id'];
  $docs = $docsByPo[$poId] ?? [];
?>
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

          <div class="d-flex gap-2 mt-3 align-items-center flex-wrap">
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
                    <td><a href="<?=h(fw_doc_href((string)($d['file_path'] ?? ''), $baseProject))?>" target="_blank" rel="noopener">open</a></td>
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

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Audit Log <span class="muted">(Last 50 events)</span></div>
  <?php if (empty($audit_rows)): ?>
    <div class="muted">Belum ada audit log.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-dark align-middle">
        <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:140px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
        <tbody>
        <?php foreach ($audit_rows as $a): ?>
          <tr><td><?= h($a['created_at'] ?? '') ?></td><td><?= h($a['action'] ?? '') ?></td><td><?= h($a['record_code'] ?? '') ?></td><td><?= h($a['username'] ?? '') ?></td><td><?= h($a['description'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
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
    autoWidth: false,
    scrollX: true,
    deferRender: true,
    dom: 'Bfrtip',
    buttons: ['copy','csv','excel','pdf','print']
  });
});
</script>
<?php rmi_footer(); ?>
