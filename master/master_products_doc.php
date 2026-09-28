<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 1) . '/master/auth.php';
require_login();
// -------------------------------------------------------------

/**
 * master/master_products_doc.php
 *
 * FIX v3:
 * - Tidak asumsi kolom master_products (unit/uom, status/is_active)
 * - Tidak asumsi kolom master_products_doc (note optional)
 * - Upload aman (allowed ext + nama file disanitasi)
 * - Soft delete (is_deleted=1) bukan hard delete
 * - CSRF untuk semua POST
 * - RBAC v2:
 *   - VIEW: MASTER.PRODUCTS.VIEW
 *   - SAVE (upload/delete): MASTER.PRODUCTS.SAVE
 */

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../master_product_resolver.php';
require_once __DIR__ . '/_audit_master.php';

$pdo = rmi_db_pdo();
rbac_ensure_tables($pdo);
rbac_require($pdo, 'MASTER.PRODUCTS.VIEW');
$canSave = rbac_can($pdo, 'MASTER.PRODUCTS.SAVE');

function esc($v): string { return rmi_h($v); }

// Guard tables
if (!rmi_table_exists($pdo, 'master_products_doc')) {
    http_response_code(500);
    echo '<h2>DB belum siap</h2>';
    echo '<p>Tabel <code>master_products_doc</code> belum ada. Import migration: <code>sql/migrations/071_master_products_doc_print_restore.sql</code></p>';
    exit;
}

$mp = rmi_master_product_resolve($pdo);
if (!$mp['table']) {
    http_response_code(500);
    echo '<h2>DB belum siap</h2>';
    echo '<p>Tabel master produk tidak ditemukan. Pastikan tabel <code>master_products</code> ada.</p>';
    exit;
}

$mpTable = $mp['table'];
$mpIdCol = $mp['id_col'] ?: 'id';
$mpSkuCol = $mp['sku_col'] ?: 'sku';
$mpNameCol = $mp['name_col'] ?: 'products_name';

$mpTableQ = rmi_qi($mpTable);
$mpIdColQ = rmi_qi($mpIdCol);
$mpSkuColQ = rmi_qi($mpSkuCol);
$mpNameColQ = rmi_qi($mpNameCol);

$docCols = rmi_table_columns($pdo, 'master_products_doc');
$hasNote = isset($docCols['note']);
$hasDeletedBy = isset($docCols['deleted_by']);

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canSave) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    rmi_csrf_verify();

    $action = (string)($_POST['action'] ?? '');
    $productId = (int)($_POST['product_id'] ?? $productId);
    if ($productId <= 0) {
        rmi_flash_set('danger', 'Product tidak valid.');
        rmi_redirect('master_products_doc.php');
    }

    if ($action === 'upload') {
        $docType = trim((string)($_POST['doc_type'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($docType === '') {
            rmi_flash_set('danger', 'Doc type wajib diisi.');
            rmi_redirect('master_products_doc.php?product_id=' . $productId);
        }

        if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            rmi_flash_set('danger', 'File upload gagal / belum dipilih.');
            rmi_redirect('master_products_doc.php?product_id=' . $productId);
        }

        $tmp = (string)$_FILES['file']['tmp_name'];
        $orig = (string)$_FILES['file']['name'];
        $safe = rmi_safe_filename($orig);
        $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png'];
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            rmi_flash_set('danger', 'Ekstensi file tidak diizinkan. Allowed: ' . implode(', ', $allowed));
            rmi_redirect('master_products_doc.php?product_id=' . $productId);
        }

        $dirRel = 'uploads/master_products_docs';
        $dirAbs = rtrim(RMI_ROOT, '/\\') . '/' . $dirRel;
        if (!is_dir($dirAbs)) {
            @mkdir($dirAbs, 0775, true);
        }
        if (!is_dir($dirAbs) || !is_writable($dirAbs)) {
            rmi_flash_set('danger', 'Folder upload tidak writable: ' . $dirAbs);
            rmi_redirect('master_products_doc.php?product_id=' . $productId);
        }

        $finalName = 'P' . $productId . '_' . date('Ymd_His') . '_' . $safe;
        $destAbs = $dirAbs . '/' . $finalName;
        $destRel = $dirRel . '/' . $finalName;

        if (!move_uploaded_file($tmp, $destAbs)) {
            rmi_flash_set('danger', 'Gagal menyimpan file ke server.');
            rmi_redirect('master_products_doc.php?product_id=' . $productId);
        }

        $cols = ['product_id','doc_type','file_name','file_path','uploaded_by'];
        $vals = [':pid',':dtype',':fname',':fpath',':uby'];
        $params = [
            ':pid' => $productId,
            ':dtype' => $docType,
            ':fname' => $orig,
            ':fpath' => $destRel,
            ':uby' => (int)auth_user_id(),
        ];
        if ($hasNote) {
            $cols[] = 'note';
            $vals[] = ':note';
            $params[':note'] = $note;
        }

        $sql = 'INSERT INTO master_products_doc (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $docId = (int)$pdo->lastInsertId();
        if (function_exists('master_audit')) {
            master_audit($pdo, 'master_products_doc', 'master_products_doc', 'UPLOAD', $docId, "P#{$productId}", "Product doc uploaded: {$docType} - {$orig}", ['product_id' => $productId, 'doc_type' => $docType]);
        }

        rmi_flash_set('success', 'Dokumen berhasil di-upload.');
        rmi_redirect('master_products_doc.php?product_id=' . $productId);
    }

    if ($action === 'delete') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($docId <= 0) {
            rmi_flash_set('danger', 'Doc ID tidak valid.');
            rmi_redirect('master_products_doc.php?product_id=' . $productId);
        }

        $row = $pdo->prepare("SELECT file_name, doc_type FROM master_products_doc WHERE id=? AND product_id=? LIMIT 1");
        $row->execute([$docId, $productId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);

        $sql = 'UPDATE master_products_doc SET is_deleted=1, deleted_at=NOW()' . ($hasDeletedBy ? ', deleted_by=:uid' : '') . ' WHERE id=:id AND product_id=:pid';
        $params = [':id' => $docId, ':pid' => $productId];
        if ($hasDeletedBy) $params[':uid'] = (int)auth_user_id();
        $st = $pdo->prepare($sql);
        $st->execute($params);

        if (function_exists('master_audit') && $st->rowCount() > 0 && $r) {
            master_audit($pdo, 'master_products_doc', 'master_products_doc', 'SOFT_DELETE', $docId, "P#{$productId}", "Product doc soft deleted: " . ($r['doc_type'] ?? '') . " - " . ($r['file_name'] ?? ''), ['product_id' => $productId]);
        }
        rmi_flash_set('success', 'Dokumen dihapus (soft delete).');
        rmi_redirect('master_products_doc.php?product_id=' . $productId);
    }

    rmi_flash_set('danger', 'Action tidak dikenal.');
    rmi_redirect('master_products_doc.php' . ($productId ? ('?product_id=' . $productId) : ''));
}

// Fetch product list or product detail
$product = null;
if ($productId > 0) {
    $sql = "SELECT {$mpIdColQ} AS id, {$mpSkuColQ} AS sku, {$mpNameColQ} AS products_name FROM {$mpTableQ} WHERE {$mpIdColQ}=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$product) {
        $productId = 0;
    }
}

$flashHtml = '';
foreach (['success'=>'success','danger'=>'danger','warning'=>'warning','info'=>'info'] as $k=>$cls) {
    $raw = rmi_flash_get($k, '');
    $msg = is_array($raw) ? (string)($raw['message'] ?? '') : (string)$raw;
    if (trim($msg) !== '') {
        $flashHtml .= '<div class="alert alert-' . esc($cls) . '">' . esc($msg) . '</div>';
    }
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Products - Documents', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Products - Documents',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-0">Master Products — Documents</h3>
      <div class="text-muted" style="font-size:13px">Soft delete; upload tersimpan di <code>/uploads/master_products_docs</code></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="master_products.php">← Master Products</a>
      <?php if ($productId): ?>
        <a class="btn btn-outline-secondary" href="master_products_print.php?product_id=<?= (int)$productId ?>">Print</a>
      <?php endif; ?>
    </div>
  </div>

  <?= $flashHtml ?>

  <?php if (!$productId): ?>
    <div class="card">
      <div class="card-header">Pilih Produk</div>
      <div class="card-body">
        <form class="row g-2 mb-3" method="get">
          <div class="col-auto"><input class="form-control" type="text" name="q" value="<?= esc((string)($_GET['q'] ?? '')) ?>" placeholder="Cari SKU / Nama"></div>
          <div class="col-auto"><button class="btn btn-primary" type="submit">Cari</button></div>
        </form>
        <?php
          $q = trim((string)($_GET['q'] ?? ''));
          $where = '';
          $params = [];
          if ($q !== '') {
            $where = " WHERE {$mpSkuColQ} LIKE ? OR {$mpNameColQ} LIKE ?";
            $params = ['%' . $q . '%', '%' . $q . '%'];
          }
          $sql = "SELECT {$mpIdColQ} AS id, {$mpSkuColQ} AS sku, {$mpNameColQ} AS products_name FROM {$mpTableQ}{$where} ORDER BY {$mpIdColQ} DESC LIMIT 100";
          $st = $pdo->prepare($sql);
          $st->execute($params);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead><tr><th>ID</th><th>SKU</th><th>Nama</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="text-muted"><?= (int)$r['id'] ?></td>
                <td class="font-monospace"><?= esc($r['sku']) ?></td>
                <td><?= esc($r['products_name']) ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="master_products_doc.php?product_id=<?= (int)$r['id'] ?>">Kelola Dok</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php else: ?>

    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <strong><?= esc($product['products_name']) ?></strong>
          <div class="text-muted" style="font-size:13px">SKU: <span class="font-monospace"><?= esc($product['sku']) ?></span></div>
        </div>
      </div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <?= rmi_csrf_input() ?>
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
          <div class="col-md-3">
            <label class="form-label">Doc Type</label>
            <input class="form-control" name="doc_type" placeholder="COA/MSDS/Foto/Manual" required>
          </div>
          <div class="col-md-5">
            <label class="form-label">File (pdf/jpg/png)</label>
            <input class="form-control" type="file" name="file" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Note (opsional)</label>
            <input class="form-control" name="note" <?= $hasNote ? '' : 'disabled' ?> placeholder="Keterangan">
          </div>
          <div class="col-12">
            <button class="btn btn-primary" type="submit" <?= $canSave ? '' : 'disabled' ?>>Upload</button>
          </div>
        </form>
      </div>
    </div>

    <?php
      $sel = ['id','doc_type','file_name','file_path','uploaded_at','is_deleted'];
      if ($hasNote) $sel[] = 'note';
      $sql = 'SELECT ' . implode(',', $sel) . ' FROM master_products_doc WHERE product_id=? AND is_deleted=0 ORDER BY uploaded_at DESC';
      $st = $pdo->prepare($sql);
      $st->execute([$productId]);
      $docs = $st->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <div class="card">
      <div class="card-header">Daftar Dokumen</div>
      <div class="card-body">
        <?php if (!$docs): ?>
          <div class="text-muted">Belum ada dokumen.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead><tr><th>ID</th><th>Type</th><th>File</th><th>Uploaded</th><?php if ($hasNote): ?><th>Note</th><?php endif; ?><th></th></tr></thead>
              <tbody>
              <?php foreach ($docs as $d):
                $rel = (string)($d['file_path'] ?? '');
                $href = '../' . ltrim($rel, '/');
              ?>
                <tr>
                  <td class="text-muted"><?= (int)$d['id'] ?></td>
                  <td><?= esc($d['doc_type']) ?></td>
                  <td><a target="_blank" href="<?= esc($href) ?>"><?= esc($d['file_name']) ?></a></td>
                  <td class="text-muted"><?= esc($d['uploaded_at'] ?? '') ?></td>
                  <?php if ($hasNote): ?><td><?= esc($d['note'] ?? '') ?></td><?php endif; ?>
                  <td>
                    <form method="post" onsubmit="return confirm('Hapus dokumen ini? (soft delete)')">
                      <?= rmi_csrf_input() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                      <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" <?= $canSave ? '' : 'disabled' ?>>Delete</button>
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

  <?php endif; ?>
</div>
<?php rmi_footer(); ?>
