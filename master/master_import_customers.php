<?php
declare(strict_types=1);

require_once __DIR__ . '/_import_tools.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_route_access')) {
    require_route_access(['MASTER.IMPORT_CUSTOMERS']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.IMPORT_CUSTOMERS']);
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
    header('Content-Disposition: attachment; filename="master_customers_import_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, ['customers_code', 'customers_name', 'category', 'segment', 'city', 'office_code', 'address', 'phone', 'email', 'npwp', 'status']);
    fputcsv($out, ['CUST-001', 'RSU Contoh Jakarta', 'RS Swasta', 'Non Hermina', 'Jakarta', 'BGR', 'Jl. Contoh No.1', '0211234567', 'rs@contoh.com', '12.345.678.9-012.000', 'active']);
    fputcsv($out, ['CUST-002', 'Klinik Medika Bandung', 'RS Swasta', 'Non Hermina', 'Bandung', 'BDG', 'Jl. Medika No.2', '0227654321', 'klinik@contoh.com', '', 'active']);
    fclose($out);
    exit;
}

$sessKey = '_import_customers_preview';
$flash = ['type' => '', 'msg' => ''];
$preview = $_SESSION[$sessKey] ?? null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload_preview') {
            if (empty($_FILES['csv_file']['tmp_name'])) throw new RuntimeException('File CSV wajib dipilih.');
            $parsed = imp_parse_csv_assoc((string)$_FILES['csv_file']['tmp_name'], ['customers_code','customers_name']);
            if ($parsed['errors']) throw new RuntimeException(implode(' ', $parsed['errors']));
            $_SESSION[$sessKey] = [
                'file_name' => (string)($_FILES['csv_file']['name'] ?? 'customers.csv'),
                'rows' => $parsed['rows'],
                'created_at' => date('c'),
            ];
            $preview = $_SESSION[$sessKey];
            $flash = ['type' => 'success', 'msg' => 'Preview siap. Rows: ' . count($parsed['rows'])];
        } elseif ($action === 'do_import') {
            if (!$preview || !is_array($preview['rows'] ?? null)) throw new RuntimeException('Tidak ada preview import.');
            $rows = $preview['rows'];
            $byCode = [];
            foreach ($rows as $r) {
                $c = strtoupper(trim((string)($r['customers_code'] ?? '')));
                if ($c !== '') $byCode[$c] = $r;
            }
            $rows = array_values($byCode);
            $runId = imp_create_run($pdo, 'MASTER_CUSTOMERS', (string)($preview['file_name'] ?? 'customers.csv'), count($rows));
            $ok = 0; $fail = 0;
            $hasCoverArea = imp_table_has_column($pdo, 'master_customers', 'cover_area');
            $hasMapsUrl = imp_table_has_column($pdo, 'master_customers', 'maps_url');
            $pdo->beginTransaction();
            foreach ($rows as $i => $r) {
                $rowNo = $i + 2;
                $code = strtoupper(trim((string)($r['customers_code'] ?? '')));
                $name = trim((string)($r['customers_name'] ?? ''));
                $category = trim((string)($r['category'] ?? ''));
                $segment = trim((string)($r['segment'] ?? ''));
                $city = trim((string)($r['city'] ?? ''));
                $office = strtoupper(trim((string)($r['office_code'] ?? '')));
                $coverArea = trim((string)($r['cover_area'] ?? ''));
                $address = trim((string)($r['address'] ?? ''));
                $mapsUrl = trim((string)($r['maps_url'] ?? ''));
                $phone = trim((string)($r['phone'] ?? ''));
                $email = trim((string)($r['email'] ?? $r['email keuangan/farmasi'] ?? ''));
                $npwp = trim((string)($r['npwp'] ?? ''));
                $status = trim((string)($r['status'] ?? 'active'));
                if ($code === '' || $name === '') {
                    $fail++;
                    imp_log_row_error($pdo, $runId, $rowNo, $code, 'customers_code dan customers_name wajib.', $r);
                    continue;
                }
                try {
                    $params = [$name, $category !== '' ? $category : null, $segment !== '' ? $segment : null, $city !== '' ? $city : null, $office !== '' ? $office : null, $address !== '' ? $address : null, $phone !== '' ? $phone : null, $email !== '' ? $email : null, $npwp !== '' ? $npwp : null, $status !== '' ? $status : 'active'];
                    if ($hasCoverArea) $params[] = $coverArea !== '' ? $coverArea : null;
                    if ($hasMapsUrl) $params[] = $mapsUrl !== '' ? $mapsUrl : null;

                    $st = $pdo->prepare("SELECT id FROM master_customers WHERE customers_code=? LIMIT 1");
                    $st->execute([$code]);
                    $id = (int)($st->fetchColumn() ?: 0);
                    if ($id > 0) {
                        $sets = "customers_name=?, category=?, segment=?, city=?, office_code=?, address=?, phone=?, email=?, npwp=?, status=?, updated_at=NOW()";
                        if ($hasCoverArea) $sets .= ', cover_area=?';
                        if ($hasMapsUrl) $sets .= ', maps_url=?';
                        $up = $pdo->prepare("UPDATE master_customers SET {$sets} WHERE id=?");
                        $up->execute(array_merge($params, [$id]));
                    } else {
                        $cols = "customers_code, customers_name, category, segment, city, office_code, address, phone, email, npwp, status, created_at, updated_at";
                        $placeholders = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()";
                        if ($hasCoverArea) { $cols .= ', cover_area'; $placeholders .= ', ?'; }
                        if ($hasMapsUrl) { $cols .= ', maps_url'; $placeholders .= ', ?'; }
                        $ins = $pdo->prepare("INSERT INTO master_customers ({$cols}) VALUES ({$placeholders})");
                        $ins->execute(array_merge([$code], $params));
                    }
                    $ok++;
                } catch (Throwable $e) {
                    $fail++;
                    imp_log_row_error($pdo, $runId, $rowNo, $code, $e->getMessage(), $r);
                }
            }
            imp_finish_run($pdo, $runId, $fail > 0 ? 'FAILED' : 'DONE', $ok, $fail, 'Customers import');
            $pdo->commit();
            erp_audit($pdo, 'MASTER_IMPORT', 'RUN#'.$runId, 'IMPORT_CUSTOMERS', ['rows_total'=>count($rows),'rows_success'=>$ok,'rows_failed'=>$fail]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_import_customers', 'master_customers', 'IMPORT_CUSTOMERS', null, 'RUN#'.$runId, "Import customers: {$ok} ok, {$fail} failed", ['rows_total' => count($rows), 'rows_success' => $ok, 'rows_failed' => $fail]);
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
    $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_import_customers' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

rmi_header('Import Customers CSV', ['active' => 'master']);
?>
<?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?= imp_h($flash['type']) ?>"><?= imp_h($flash['msg']) ?></div><?php endif; ?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Upload CSV</div>
  <div class="small text-muted mb-2">Header wajib: <code>customers_code</code>, <code>customers_name</code>. Optional: <code>category</code>, <code>segment</code>, <code>city</code>, <code>office_code</code>, <code>address</code>, <code>phone</code>, <code>email</code>, <code>npwp</code>, <code>status</code>.</div>
  <div class="mb-2">
    <a href="master_import_customers.php?download_template=1" class="btn btn-sm btn-outline-light">Download Template CSV</a>
    <span class="small text-muted ms-2">— Jangan ubah nama kolom (customers_code, customers_name, dll). Isi data, simpan CSV, lalu upload.</span>
  </div>
  <div class="small text-warning mb-2">⚠️ Nama kolom harus persis seperti di template. office_code: BGR, BKS, TGR, BDG, SLO, SMG, KAL, JGY.</div>
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
        <button class="btn btn-success" onclick="return confirm('Lanjut import customers?')">Import Sekarang</button>
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
      <thead><tr><th>#</th><th>Code</th><th>Name</th><th>City</th><th>Office</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($preview['rows'], 0, 200) as $i => $r): ?>
        <tr>
          <td><?= (int)$i + 1 ?></td>
          <td><?= imp_h((string)($r['customers_code'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['customers_name'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['city'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['office_code'] ?? '')) ?></td>
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

