<?php
declare(strict_types=1);

require_once __DIR__ . '/_import_tools.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_route_access')) {
    require_route_access(['MASTER.IMPORT_PRODUCTS']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.IMPORT_PRODUCTS']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}

$pdo = db_pdo();
erp_audit_ensure($pdo);
require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

// Template download (sebelum output)
if (isset($_GET['download_template']) && $_GET['download_template'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_products_import_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, ['sku', 'products_name', 'category', 'unit', 'status']);
    fputcsv($out, ['ALK-001', 'Sarung Tangan Latex S', 'BMHP', 'BOX', 'active']);
    fputcsv($out, ['ALK-002', 'Tensimeter Digital',    'ALKES', 'UNIT', 'active']);
    fputcsv($out, ['ALK-003', 'Kabel Elektroda ECG',   'AKSESORIS', 'PCS', 'active']);
    fclose($out);
    exit;
}

$sessKey = '_import_products_preview';
$flash = ['type' => '', 'msg' => ''];
$preview = $_SESSION[$sessKey] ?? null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload_preview') {
            if (empty($_FILES['csv_file']['tmp_name'])) throw new RuntimeException('File CSV wajib dipilih.');
            $parsed = imp_parse_csv_assoc((string)$_FILES['csv_file']['tmp_name'], ['sku','products_name']);
            if ($parsed['errors']) throw new RuntimeException(implode(' ', $parsed['errors']));
            $_SESSION[$sessKey] = [
                'file_name' => (string)($_FILES['csv_file']['name'] ?? 'products.csv'),
                'rows' => $parsed['rows'],
                'created_at' => date('c'),
            ];
            $preview = $_SESSION[$sessKey];
            $flash = ['type' => 'success', 'msg' => 'Preview siap. Rows: ' . count($parsed['rows'])];
        } elseif ($action === 'do_import') {
            if (!$preview || !is_array($preview['rows'] ?? null)) throw new RuntimeException('Tidak ada preview import.');
            $rows = $preview['rows'];
            $runId = imp_create_run($pdo, 'MASTER_PRODUCTS', (string)($preview['file_name'] ?? 'products.csv'), count($rows));
            $ok = 0; $fail = 0;
            $pdo->beginTransaction();
            foreach ($rows as $i => $r) {
                $rowNo = $i + 2;
                $sku = strtoupper(trim((string)($r['sku'] ?? '')));
                $name = function_exists('rmi_product_name') ? rmi_product_name($r['products_name'] ?? '') : strtoupper(trim((string)($r['products_name'] ?? '')));
                $category = trim((string)($r['category'] ?? 'BMHP'));
                $unit = trim((string)($r['unit'] ?? 'PCS'));
                $status = trim((string)($r['status'] ?? 'active'));
                if ($sku === '' || $name === '') {
                    $fail++;
                    imp_log_row_error($pdo, $runId, $rowNo, $sku, 'SKU dan products_name wajib.', $r);
                    continue;
                }
                try {
                    $st = $pdo->prepare("SELECT id FROM master_products WHERE sku=? LIMIT 1");
                    $st->execute([$sku]);
                    $id = (int)($st->fetchColumn() ?: 0);
                    if ($id > 0) {
                        $up = $pdo->prepare("UPDATE master_products SET products_name=?, category=?, unit=?, status=?, updated_at=NOW() WHERE id=?");
                        $up->execute([$name, $category !== '' ? $category : null, $unit !== '' ? $unit : null, $status !== '' ? $status : 'active', $id]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO master_products (sku, products_name, category, unit, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                        $ins->execute([$sku, $name, $category !== '' ? $category : null, $unit !== '' ? $unit : null, $status !== '' ? $status : 'active']);
                    }
                    $ok++;
                } catch (Throwable $e) {
                    $fail++;
                    imp_log_row_error($pdo, $runId, $rowNo, $sku, $e->getMessage(), $r);
                }
            }
            imp_finish_run($pdo, $runId, $fail > 0 ? 'FAILED' : 'DONE', $ok, $fail, 'Products import');
            $pdo->commit();
            erp_audit($pdo, 'MASTER_IMPORT', 'RUN#'.$runId, 'IMPORT_PRODUCTS', ['rows_total'=>count($rows),'rows_success'=>$ok,'rows_failed'=>$fail]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_import_products', 'master_products', 'IMPORT_PRODUCTS', null, 'RUN#'.$runId, "Import products: {$ok} ok, {$fail} failed", ['rows_total' => count($rows), 'rows_success' => $ok, 'rows_failed' => $fail]);
            }
            unset($_SESSION[$sessKey]);
            $preview = null;
            $flash = ['type' => $fail > 0 ? 'warning' : 'success', 'msg' => "Import selesai. Success={$ok}, Failed={$fail}, RunID={$runId}."];
        } elseif ($action === 'clear_preview') {
            unset($_SESSION[$sessKey]);
            $preview = null;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
}

$audit_rows = [];
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
}
try {
    $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_import_products' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

rmi_header('Import Products CSV', ['active' => 'master']);
?>
<?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?= imp_h($flash['type']) ?>"><?= imp_h($flash['msg']) ?></div><?php endif; ?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Upload CSV</div>
  <div class="small text-muted mb-2">Header wajib: <code>sku</code>, <code>products_name</code>. Optional: <code>category</code>, <code>unit</code>, <code>status</code>.</div>
  <div class="mb-2">
    <a href="master_import_products.php?download_template=1" class="btn btn-sm btn-outline-light">Download Template CSV</a>
    <span class="small text-muted ms-2">— Jangan ubah nama kolom (sku, products_name, dll). Isi data, simpan CSV, lalu upload.</span>
  </div>
  <div class="small text-warning mb-2">⚠️ Nama kolom harus persis seperti di template. category: <strong>BMHP</strong>, <strong>ALKES</strong>, atau <strong>AKSESORIS</strong>. unit: PCS, BOX, UNIT, dll.</div>
  <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
    <input type="hidden" name="csrf_token" value="<?= imp_h(csrf_token()) ?>">
    <input type="hidden" name="action" value="upload_preview">
    <input class="form-control" type="file" name="csv_file" accept=".csv" required>
    <button class="btn btn-primary">Preview</button>
  </form>
</div>

<?php if ($preview && is_array($preview['rows'] ?? null)): ?>
<div class="rmi-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Preview Rows (<?= (int)count($preview['rows']) ?>)</div>
    <div class="d-flex gap-2">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= imp_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="do_import">
        <button class="btn btn-success" onclick="return confirm('Lanjut import products?')">Import Sekarang</button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= imp_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="clear_preview">
        <button class="btn btn-outline-light">Clear</button>
      </form>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover mb-0">
      <thead><tr><th>#</th><th>SKU</th><th>Name</th><th>Category</th><th>Unit</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($preview['rows'], 0, 200) as $i => $r): ?>
        <tr>
          <td><?= (int)$i + 1 ?></td>
          <td><?= imp_h((string)($r['sku'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['products_name'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['category'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['unit'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['status'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($audit_rows)): ?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Audit Log <span class="text-muted">(Last 50 events)</span></div>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover mb-0">
      <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:140px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
      <tbody>
      <?php foreach ($audit_rows as $a): ?>
        <tr><td><?= imp_h($a['created_at'] ?? '') ?></td><td><?= imp_h($a['action'] ?? '') ?></td><td><?= imp_h($a['record_code'] ?? '') ?></td><td><?= imp_h($a['username'] ?? '') ?></td><td><?= imp_h($a['description'] ?? '') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php rmi_footer(); ?>

