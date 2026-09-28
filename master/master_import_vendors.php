<?php
declare(strict_types=1);

require_once __DIR__ . '/_import_tools.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_route_access')) {
    require_route_access(['MASTER.IMPORT_VENDORS']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.IMPORT_VENDORS']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}

$pdo = db_pdo();
erp_audit_ensure($pdo);
require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

$sessKey = '_import_vendors_preview';
$flash = ['type' => '', 'msg' => ''];
$preview = $_SESSION[$sessKey] ?? null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload_preview') {
            if (empty($_FILES['csv_file']['tmp_name'])) throw new RuntimeException('File CSV wajib dipilih.');
            $parsed = imp_parse_csv_assoc((string)$_FILES['csv_file']['tmp_name'], ['vendors_code','vendors_name']);
            if ($parsed['errors']) throw new RuntimeException(implode(' ', $parsed['errors']));
            $_SESSION[$sessKey] = [
                'file_name' => (string)($_FILES['csv_file']['name'] ?? 'vendors.csv'),
                'rows' => $parsed['rows'],
                'created_at' => date('c'),
            ];
            $preview = $_SESSION[$sessKey];
            $flash = ['type' => 'success', 'msg' => 'Preview siap. Rows: ' . count($parsed['rows'])];
        } elseif ($action === 'do_import') {
            if (!$preview || !is_array($preview['rows'] ?? null)) throw new RuntimeException('Tidak ada preview import.');
            $rows = $preview['rows'];
            $runId = imp_create_run($pdo, 'MASTER_VENDORS', (string)($preview['file_name'] ?? 'vendors.csv'), count($rows));
            $ok = 0; $fail = 0;
            $pdo->beginTransaction();
            foreach ($rows as $i => $r) {
                $rowNo = $i + 2;
                $code = strtoupper(trim((string)($r['vendors_code'] ?? '')));
                $name = trim((string)($r['vendors_name'] ?? ''));
                $vendorType = trim((string)($r['vendor_type'] ?? ''));
                $city = trim((string)($r['city'] ?? ''));
                $phone = trim((string)($r['phone'] ?? ''));
                $email = trim((string)($r['email'] ?? ''));
                $npwp = trim((string)($r['npwp'] ?? ''));
                $status = trim((string)($r['status'] ?? 'active'));
                if ($code === '' || $name === '') {
                    $fail++;
                    imp_log_row_error($pdo, $runId, $rowNo, $code, 'vendors_code dan vendors_name wajib.', $r);
                    continue;
                }
                try {
                    $st = $pdo->prepare("SELECT id FROM master_vendors WHERE vendors_code=? LIMIT 1");
                    $st->execute([$code]);
                    $id = (int)($st->fetchColumn() ?: 0);
                    if ($id > 0) {
                        $up = $pdo->prepare("UPDATE master_vendors
                            SET vendors_name=?, vendor_type=?, city=?, phone=?, email=?, npwp=?, status=?, updated_at=NOW()
                            WHERE id=?");
                        $up->execute([$name, $vendorType !== '' ? $vendorType : null, $city !== '' ? $city : null, $phone !== '' ? $phone : null, $email !== '' ? $email : null, $npwp !== '' ? $npwp : null, $status !== '' ? $status : 'active', $id]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO master_vendors
                            (vendors_code, vendors_name, vendor_type, city, phone, email, npwp, status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $ins->execute([$code, $name, $vendorType !== '' ? $vendorType : null, $city !== '' ? $city : null, $phone !== '' ? $phone : null, $email !== '' ? $email : null, $npwp !== '' ? $npwp : null, $status !== '' ? $status : 'active']);
                    }
                    $ok++;
                } catch (Throwable $e) {
                    $fail++;
                    imp_log_row_error($pdo, $runId, $rowNo, $code, $e->getMessage(), $r);
                }
            }
            imp_finish_run($pdo, $runId, $fail > 0 ? 'FAILED' : 'DONE', $ok, $fail, 'Vendors import');
            $pdo->commit();
            erp_audit($pdo, 'MASTER_IMPORT', 'RUN#'.$runId, 'IMPORT_VENDORS', ['rows_total'=>count($rows),'rows_success'=>$ok,'rows_failed'=>$fail]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_import_vendors', 'master_vendors', 'IMPORT_VENDORS', null, 'RUN#'.$runId, "Import vendors: {$ok} ok, {$fail} failed", ['rows_total' => count($rows), 'rows_success' => $ok, 'rows_failed' => $fail]);
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
    $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_import_vendors' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

rmi_header('Import Vendors CSV', ['active' => 'master']);
?>
<?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?= imp_h($flash['type']) ?>"><?= imp_h($flash['msg']) ?></div><?php endif; ?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Upload CSV</div>
  <div class="small text-muted mb-2">Header minimal: <code>vendors_code,vendors_name</code>. Optional: <code>vendor_type,city,phone,email,npwp,status</code>.</div>
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
        <button class="btn btn-success" onclick="return confirm('Lanjut import vendors?')">Import Sekarang</button>
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
      <thead><tr><th>#</th><th>Code</th><th>Name</th><th>Type</th><th>City</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($preview['rows'], 0, 200) as $i => $r): ?>
        <tr>
          <td><?= (int)$i + 1 ?></td>
          <td><?= imp_h((string)($r['vendors_code'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['vendors_name'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['vendor_type'] ?? '')) ?></td>
          <td><?= imp_h((string)($r['city'] ?? '')) ?></td>
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

