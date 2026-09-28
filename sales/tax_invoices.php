<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login();
// Tax Invoice: hanya ACT/FIN/ADMIN — BRANCH tidak perlu akses dokumen pajak
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.EDIT', 'SALES.EDIT', 'MASTER.ADMIN_CENTER']);
} else {
    require_role(['ACT','FIN','ADMIN','SUPERADMIN','SYS','MANAGER']);
}
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
rmi_block_branch('Tax Invoice hanya untuk Dept ACT/FIN atau Admin.');

$pdo = db_pdo();
erp_audit_ensure($pdo);
$svc = new \App\Accounting\TaxInvoiceService();

function tx_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function tx_upload_pdf(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') return null;
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $dir = $root . '/uploads/tax_invoices';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $safe = 'tax_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
    $target = $dir . '/' . $safe;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) return null;
    return '/uploads/tax_invoices/' . $safe;
}

$flash = ['type' => '', 'msg' => ''];
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'create') {
            $salesRef = trim((string)($_POST['sales_invoice_ref'] ?? ''));
            $taxDate = trim((string)($_POST['tax_date'] ?? date('Y-m-d')));
            $taxNo = trim((string)($_POST['tax_no'] ?? ''));
            if ($salesRef === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $taxDate)) throw new Exception('Sales ref/tanggal tidak valid.');
            // Validasi: Sales Ref harus DO code yang ada di sales_do
            $stCheck = $pdo->prepare("SELECT id FROM sales_do WHERE do_code = ? LIMIT 1");
            $stCheck->execute([$salesRef]);
            if (!$stCheck->fetch()) throw new Exception('Kode DO tidak ditemukan. Gunakan dropdown untuk memilih DO yang valid.');
            if ($taxNo === '') $taxNo = $svc->generateNumber($pdo);

            $pdo->prepare(
                "INSERT INTO tax_invoices (sales_invoice_ref, tax_no, tax_date, status, file_path, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, 'DRAFT', NULL, ?, NOW(), NOW())"
            )->execute([$salesRef, $taxNo, $taxDate, (int)($_SESSION['user_id'] ?? 0) ?: null]);
            $id = (int)$pdo->lastInsertId();
            $svc->log($pdo, $id, 'CREATE_DRAFT', (int)($_SESSION['user_id'] ?? 0), 'Draft created');
            erp_audit($pdo, 'TAX_INVOICE', 'TAX#' . $id, 'CREATE_DRAFT', ['sales_ref' => $salesRef, 'tax_no' => $taxNo]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'tax_invoices', 'tax_invoices', 'CREATE_DRAFT', $id, 'TAX#' . $id, "Tax invoice draft created: #{$id}", ['sales_ref' => $salesRef, 'tax_no' => $taxNo]);
            }
            $flash = ['type' => 'success', 'msg' => 'Tax invoice draft dibuat.'];
        }

        if ($action === 'upload_doc') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0 || empty($_FILES['file'])) throw new Exception('File tidak ditemukan.');
            $path = tx_upload_pdf($_FILES['file']);
            if ($path === null) throw new Exception('Dokumen harus PDF.');
            $pdo->prepare("UPDATE tax_invoices SET file_path=?, updated_at=NOW() WHERE id=?")->execute([$path, $id]);
            $svc->log($pdo, $id, 'UPLOAD_FILE', (int)($_SESSION['user_id'] ?? 0), $path);
            erp_audit($pdo, 'TAX_INVOICE', 'TAX#' . $id, 'UPLOAD_DOC', ['path' => $path]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'tax_invoices', 'tax_invoices', 'UPLOAD_DOC', $id, 'TAX#' . $id, "Tax invoice doc uploaded: #{$id}", ['path' => $path]);
            }
            $flash = ['type' => 'success', 'msg' => 'Dokumen tax invoice diupload.'];
        }

        if ($action === 'set_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = strtoupper(trim((string)($_POST['status'] ?? 'DRAFT')));
            if (!in_array($status, ['DRAFT','ISSUED','REVISED','CANCELLED'], true)) $status = 'DRAFT';
            if ($id <= 0) throw new Exception('ID tidak valid.');
            $pdo->prepare("UPDATE tax_invoices SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
            $svc->log($pdo, $id, 'SET_STATUS_' . $status, (int)($_SESSION['user_id'] ?? 0), '');
            erp_audit($pdo, 'TAX_INVOICE', 'TAX#' . $id, 'SET_STATUS', ['status' => $status]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'tax_invoices', 'tax_invoices', 'SET_STATUS', $id, 'TAX#' . $id, "Tax invoice status: {$status}", ['status' => $status]);
            }
            $flash = ['type' => 'success', 'msg' => 'Status tax invoice: ' . $status];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $where = [];
    $params = [];
    if ($statusFilter !== '' && in_array($statusFilter, ['DRAFT','ISSUED','REVISED','CANCELLED'], true)) {
        $where[] = 'status=?';
        $params[] = $statusFilter;
    }
    $sql = "SELECT id, sales_invoice_ref, tax_no, tax_date, status, file_path, created_at, updated_at FROM tax_invoices";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tax_invoices.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','sales_invoice_ref','tax_no','tax_date','status','file_path','created_at','updated_at']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

$where = [];
$params = [];
if ($statusFilter !== '' && in_array($statusFilter, ['DRAFT','ISSUED','REVISED','CANCELLED'], true)) {
    $where[] = 'ti.status=?';
    $params[] = $statusFilter;
}
$sql = "SELECT ti.*, sd.id AS do_id,
            (SELECT COUNT(*) FROM tax_invoice_logs l WHERE l.tax_invoice_id=ti.id) AS log_count
        FROM tax_invoices ti
        LEFT JOIN sales_do sd ON sd.do_code COLLATE utf8mb4_unicode_ci = ti.sales_invoice_ref COLLATE utf8mb4_unicode_ci";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY ti.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// DO list untuk dropdown: delivered/wait_payment/paid yang belum punya Tax Invoice ISSUED
$doList = [];
try {
    $stDo = $pdo->query(
        "SELECT d.id, d.do_code, d.do_date, d.customers_code
         FROM sales_do d
         WHERE LOWER(d.status) IN ('delivered','wait_payment','paid','scm_done','act_done','fin_done','paid_done','closed')
         AND NOT EXISTS (SELECT 1 FROM tax_invoices ti WHERE ti.sales_invoice_ref COLLATE utf8mb4_unicode_ci = d.do_code COLLATE utf8mb4_unicode_ci AND ti.status = 'ISSUED')
         ORDER BY d.do_date DESC, d.id DESC
         LIMIT 200"
    );
    if ($stDo) $doList = $stDo->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* fail-soft */ }

$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
    $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'tax_invoices' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();
rmi_header('Tax Invoice Workflow', [
    'active' => 'sales',
    'subtitle' => 'DRAFT -> ISSUED -> REVISED/CANCELLED with file upload and export',
    'breadcrumbs' => [
        ['label' => 'Sales', 'url' => $base . '/sales/sales_dashboard.php'],
        'Tax Invoice Workflow',
    ],
    'actions' => [
      ['label' => 'Export CSV', 'url' => '?export=csv&status=' . urlencode($statusFilter), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<?php if ($flash['msg'] !== ''): ?>
  <div class="alert alert-<?= tx_h($flash['type'] !== '' ? $flash['type'] : 'info') ?>"><?= tx_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="rmi-card p-3">
      <h6>Create Tax Invoice Draft</h6>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-12">
          <label class="form-label">Pilih DO (delivered/wait_payment)</label>
          <?php if (!empty($doList)): ?>
            <select class="form-select form-select-sm" name="sales_invoice_ref" required>
              <option value="">— Pilih DO dari daftar —</option>
              <?php foreach ($doList as $d): ?>
                <option value="<?= tx_h($d['do_code']) ?>"><?= tx_h($d['do_code']) ?> — <?= tx_h($d['do_date']) ?> (<?= tx_h($d['customers_code'] ?? '-') ?>)</option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input class="form-control form-control-sm" name="sales_invoice_ref" placeholder="Ketik kode DO (mis. RMI-BGR-260306-001)" required>
            <div class="form-text text-warning">Tidak ada DO dalam daftar. Ketik kode DO manual (harus ada di sales_do).</div>
          <?php endif; ?>
        </div>
        <div class="col-12"><label class="form-label">Tax Date</label><input type="date" class="form-control form-control-sm" name="tax_date" value="<?= tx_h(date('Y-m-d')) ?>" required></div>
        <div class="col-12"><label class="form-label">Tax Number (blank=auto)</label><input class="form-control form-control-sm" name="tax_no"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Create Draft</button></div>
      </form>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="rmi-card p-3 mb-3">
      <form method="get" class="d-flex gap-2 align-items-center">
        <label class="form-label mb-0">Filter Status</label>
        <select class="form-select form-select-sm" style="width:180px" name="status">
          <option value="">ALL</option>
          <?php foreach (['DRAFT','ISSUED','REVISED','CANCELLED'] as $s): ?>
            <option value="<?= tx_h($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= tx_h($s) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-light">Apply</button>
      </form>
    </div>

    <div class="rmi-card p-3">
      <h6>Tax Invoices</h6>
      <div class="table-responsive">
        <table class="table table-sm table-dark table-hover mb-0">
          <thead><tr><th>ID</th><th>Sales Ref</th><th>Tax No</th><th>Date</th><th>Status</th><th>File</th><th>Logs</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="8" class="text-muted">No data</td></tr><?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td>
                <?php $doId = (int)($r['do_id'] ?? 0); $ref = (string)($r['sales_invoice_ref'] ?? ''); ?>
                <?php if ($doId > 0): ?>
                  <a href="<?= tx_h($base . '/sales/sales_do_view.php?id=' . $doId) ?>" target="_blank" rel="noopener"><?= tx_h($ref) ?></a>
                <?php else: ?>
                  <?= tx_h($ref) ?>
                <?php endif; ?>
              </td>
              <td><?= tx_h((string)$r['tax_no']) ?></td>
              <td><?= tx_h((string)$r['tax_date']) ?></td>
              <td><span class="badge text-bg-secondary"><?= tx_h((string)$r['status']) ?></span></td>
              <td>
                <?php if (!empty($r['file_path'])): ?>
                  <a href="<?= tx_h((string)$r['file_path']) ?>" target="_blank">View PDF</a>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <td><?= (int)$r['log_count'] ?></td>
              <td>
                <div class="d-flex gap-1 flex-wrap">
                  <form method="post" enctype="multipart/form-data" class="d-flex gap-1">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="upload_doc">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="file" name="file" accept=".pdf" class="form-control form-control-sm" style="width:160px">
                    <button class="btn btn-sm btn-outline-light">Upload</button>
                  </form>
                  <?php foreach (['DRAFT','ISSUED','REVISED','CANCELLED'] as $s): ?>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="status" value="<?= tx_h($s) ?>">
                      <button class="btn btn-sm btn-outline-light"><?= tx_h($s) ?></button>
                    </form>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="rmi-card p-3 mt-3">
      <h6>Audit Log <span class="text-muted">(Last 50 events)</span></h6>
      <?php if (empty($audit_rows)): ?>
        <div class="text-muted">Belum ada audit log.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-dark table-hover mb-0">
            <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:140px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($audit_rows as $a): ?>
              <tr><td><?= tx_h($a['created_at'] ?? '') ?></td><td><?= tx_h($a['action'] ?? '') ?></td><td><?= tx_h($a['record_code'] ?? '') ?></td><td><?= tx_h($a['username'] ?? '') ?></td><td><?= tx_h($a['description'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
