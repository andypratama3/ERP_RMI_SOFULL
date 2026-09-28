<?php
require_once dirname(__DIR__) . '/master/auth.php'; // enforce login (static scan + runtime)
require_login(); // enforce login guard (static scan marker + runtime)
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';

$flash = payroll_flash_get();

function payroll_loans_can_manage(): bool {
    $role = strtoupper((string)($_SESSION['role'] ?? ''));
    $level = strtoupper((string)($_SESSION['level'] ?? ''));
    $dept = strtoupper((string)($_SESSION['department'] ?? ($_SESSION['dept'] ?? '')));
    return in_array($role, ['SYS','ADMIN'], true)
        || in_array($level, ['SYS','ADMIN'], true)
        || in_array($dept, ['FIN','PAYROLL'], true);
}

function payroll_loans_table_columns(PDO $pdo): array {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM payroll_loans");
        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower((string)$r['Field'])] = (string)$r['Field'];
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function payroll_loans_ensure_hrl_link(PDO $pdo): void {
    $cols = payroll_loans_table_columns($pdo);
    if (!isset($cols['hrl_request_id'])) {
        try { $pdo->exec("ALTER TABLE payroll_loans ADD COLUMN hrl_request_id INT NULL"); } catch (Throwable $e) {}
    }
    try { $pdo->exec("ALTER TABLE payroll_loans ADD UNIQUE KEY uq_payroll_loans_hrl_request_id (hrl_request_id)"); } catch (Throwable $e) {}
}

payroll_loans_ensure_hrl_link($pdo);
$canManageLoans = payroll_loans_can_manage();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('verify_csrf')) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

// ------------------------- CSV Export -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $type = trim($_GET['type'] ?? '');

    $sql = "SELECT l.*, e.employee_code, e.employee_name, e.dept_code, e.office_code AS emp_office
            FROM payroll_loans l
            LEFT JOIN master_employees e ON e.id = l.employee_id
            WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (e.employee_code LIKE ? OR e.employee_name LIKE ? OR l.note LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }
    if ($status !== '') {
        $sql .= " AND l.status = ?";
        $params[] = $status;
    }
    if ($type !== '') {
        $sql .= " AND l.loan_type = ?";
        $params[] = $type;
    }
    $sql .= " ORDER BY l.id DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_loans.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','employee_code','employee_name','dept','office','type','principal','tenor_months','installment_amount','start_period','end_period','status','note','created_at']);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $tenor = max(1, (int)$r['tenor_months']);
        $install = (float)$r['installment_amount'];
        if ($install <= 0) $install = ((float)$r['principal']) / $tenor;
        $endPeriod = payroll_ym_add_months((string)$r['start_period_ym'], $tenor - 1);

        fputcsv($out, [
            $r['id'],
            $r['employee_code'],
            $r['employee_name'],
            $r['dept_code'],
            $r['emp_office'],
            $r['loan_type'],
            $r['principal'],
            $tenor,
            round($install,2),
            $r['start_period_ym'],
            $endPeriod,
            $r['status'],
            $r['note'],
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ------------------------- Actions -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageLoans) {
        payroll_flash_set('danger', 'Akses ditolak. Input/update Pinjaman langsung hanya untuk FIN/PAYROLL/SYS. Karyawan mengajukan lewat HRL Process Tower.');
        rmi_redirect("{$BASE_PAYROLL}/loans.php");
    }
    $action = $_POST['action'] ?? '';

    // Save (create/update)
    if ($action === 'save_loan') {
        $id = (int)($_POST['id'] ?? 0);
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $loanType = strtoupper(trim($_POST['loan_type'] ?? 'LOAN'));
        $principal = (float)str_replace([',',' '], '', ($_POST['principal'] ?? '0'));
        $tenor = (int)($_POST['tenor_months'] ?? 1);
        $install = (float)str_replace([',',' '], '', ($_POST['installment_amount'] ?? '0'));
        $startYm = trim($_POST['start_period_ym'] ?? '');
        $status = strtoupper(trim($_POST['status'] ?? 'ACTIVE'));
        $note = trim($_POST['note'] ?? '');

        if ($employeeId <= 0) {
            payroll_flash_set('danger', 'Pilih karyawan dulu.');
            rmi_redirect("{$BASE_PAYROLL}/loans.php");
        }
        if (!in_array($loanType, ['KASBON','LOAN','PINJAMAN'], true)) {
            $loanType = 'LOAN';
        }
        if ($loanType === 'PINJAMAN') $loanType = 'LOAN';

        if ($principal <= 0) {
            payroll_flash_set('danger', 'Nominal principal harus > 0');
            rmi_redirect("{$BASE_PAYROLL}/loans.php");
        }
        $tenor = max(1, $tenor);

        if (!preg_match('/^\d{4}-\d{2}$/', $startYm)) {
            payroll_flash_set('danger', 'Start Period wajib format YYYY-MM (contoh 2025-12)');
            rmi_redirect("{$BASE_PAYROLL}/loans.php");
        }
        if (!in_array($status, ['ACTIVE','PAUSED','CLOSED'], true)) {
            $status = 'ACTIVE';
        }

        if ($install <= 0) {
            $install = $principal / $tenor;
        }

        try {
            if ($id > 0) {
                $st = $pdo->prepare("UPDATE payroll_loans
                    SET employee_id=?, loan_type=?, principal=?, tenor_months=?, installment_amount=?, start_period_ym=?, status=?, note=?, updated_at=NOW()
                    WHERE id=?");
                $st->execute([$employeeId, $loanType, $principal, $tenor, $install, $startYm, $status, $note, $id]);

                erp_audit($pdo, 'PAYROLL', 'LOAN#'.$id, 'update_loan', [
                    'employee_id'=>$employeeId,
                    'loan_type'=>$loanType,
                    'principal'=>$principal,
                    'tenor_months'=>$tenor,
                    'installment_amount'=>round($install,2),
                    'start_period_ym'=>$startYm,
                    'status'=>$status,
                    'note'=>$note,
                ]);
                if (function_exists('master_audit')) {
                    $empCode = "EMP#{$employeeId}";
                    try {
                        $stEmp = $pdo->prepare("SELECT employee_code FROM master_employees WHERE id=? LIMIT 1");
                        $stEmp->execute([$employeeId]);
                        $c = $stEmp->fetchColumn();
                        if ($c !== false && $c !== null) $empCode = (string)$c;
                    } catch (Throwable $e) {}
                    master_audit($pdo, 'payroll', 'payroll_loans', 'UPDATE', $id, "LOAN#{$id}", "Loan updated: {$empCode} {$loanType} Rp " . number_format($principal, 0, ',', '.'), []);
                }

                payroll_flash_set('success', 'Pinjaman berhasil diupdate.');
            } else {
                $st = $pdo->prepare("INSERT INTO payroll_loans
                    (employee_id, loan_type, principal, tenor_months, installment_amount, start_period_ym, status, note, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $st->execute([$employeeId, $loanType, $principal, $tenor, $install, $startYm, $status, $note, (int)($_SESSION['user_id'] ?? 0)]);
                $newId = (int)$pdo->lastInsertId();

                erp_audit($pdo, 'PAYROLL', 'LOAN#'.$newId, 'create_loan', [
                    'employee_id'=>$employeeId,
                    'loan_type'=>$loanType,
                    'principal'=>$principal,
                    'tenor_months'=>$tenor,
                    'installment_amount'=>round($install,2),
                    'start_period_ym'=>$startYm,
                    'status'=>$status,
                    'note'=>$note,
                ]);
                if (function_exists('master_audit')) {
                    $empCode = "EMP#{$employeeId}";
                    try {
                        $stEmp = $pdo->prepare("SELECT employee_code FROM master_employees WHERE id=? LIMIT 1");
                        $stEmp->execute([$employeeId]);
                        $c = $stEmp->fetchColumn();
                        if ($c !== false && $c !== null) $empCode = (string)$c;
                    } catch (Throwable $e) {}
                    master_audit($pdo, 'payroll', 'payroll_loans', 'CREATE', $newId, "LOAN#{$newId}", "Loan created: {$empCode} {$loanType} Rp " . number_format($principal, 0, ',', '.'), []);
                }

                payroll_flash_set('success', 'Pinjaman berhasil dibuat.');
            }
        } catch (Throwable $e) {
            payroll_flash_set('danger', 'Gagal simpan pinjaman: ' . $e->getMessage());
        }

        rmi_redirect("{$BASE_PAYROLL}/loans.php");
    }

    // Set status
    if ($action === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = strtoupper(trim($_POST['status'] ?? ''));
        if ($id > 0 && in_array($status, ['ACTIVE','PAUSED','CLOSED'], true)) {
            try {
                $pdo->prepare("UPDATE payroll_loans SET status=?, updated_at=NOW() WHERE id=?")
                    ->execute([$status, $id]);
                erp_audit($pdo, 'PAYROLL', 'LOAN#'.$id, 'set_loan_status', ['status'=>$status]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'payroll', 'payroll_loans', 'SET_STATUS', $id, "LOAN#{$id}", "Loan status set: #{$id} -> {$status}", ['status' => $status]);
                }
                payroll_flash_set('success', "Status pinjaman #{$id} => {$status}");
            } catch (Throwable $e) {
                payroll_flash_set('danger', 'Gagal update status: ' . $e->getMessage());
            }
        }
        rmi_redirect("{$BASE_PAYROLL}/loans.php");
    }

    // Delete
    if ($action === 'delete_loan') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->prepare("DELETE FROM payroll_loans WHERE id=? LIMIT 1")->execute([$id]);
                erp_audit($pdo, 'PAYROLL', 'LOAN#'.$id, 'delete_loan', []);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'payroll', 'payroll_loans', 'DELETE', $id, "LOAN#{$id}", "Loan deleted: #{$id}", []);
                }
                payroll_flash_set('success', "Pinjaman #{$id} dihapus.");
            } catch (Throwable $e) {
                payroll_flash_set('danger', 'Gagal hapus: ' . $e->getMessage());
            }
        }
        rmi_redirect("{$BASE_PAYROLL}/loans.php");
    }
}

// ------------------------- Data -------------------------
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$type = trim($_GET['type'] ?? '');
$editId = (int)($_GET['edit'] ?? 0);

// employees for select
$empSt = $pdo->query("SELECT id, employee_code, employee_name, dept_code, office_code
                      FROM master_employees
                      WHERE status='active'
                      ORDER BY employee_name ASC");
$employees = $empSt->fetchAll(PDO::FETCH_ASSOC);

$edit = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM payroll_loans WHERE id=? LIMIT 1");
    $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC);
}

$sql = "SELECT l.*, e.employee_code, e.employee_name, e.dept_code, e.office_code AS emp_office
        FROM payroll_loans l
        LEFT JOIN master_employees e ON e.id = l.employee_id
        WHERE 1=1";
$params = [];
if ($q !== '') {
    $sql .= " AND (e.employee_code LIKE ? OR e.employee_name LIKE ? OR l.note LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
if ($status !== '') {
    $sql .= " AND l.status = ?";
    $params[] = $status;
}
if ($type !== '') {
    $sql .= " AND l.loan_type = ?";
    $params[] = strtoupper($type);
}
$sql .= " ORDER BY l.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$loans = $st->fetchAll(PDO::FETCH_ASSOC);

rmi_header('Pinjaman / Kasbon', 'payroll', ['base_project'=>$BASE_PROJECT]);
?>

<?php if ($flash): ?>
  <div class="alert alert-<?= payroll_h($flash['type']) ?>"><?= payroll_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card rmi-card">
      <div class="card-header">
        <div class="fw-bold"><?= $edit ? 'Edit Pinjaman' : 'Buat Pinjaman Baru' ?></div>
        <div class="rmi-muted" style="font-size:12px;">Data ini akan otomatis masuk potongan saat Generate Run (bisa Sync Pinjaman di run).</div>
      </div>
      <div class="card-body">
        <?php if (!$canManageLoans): ?>
          <div class="alert alert-warning" style="font-size:13px">
            Halaman ini hanya untuk FIN/PAYROLL/SYS mengelola pinjaman yang sudah disetujui.
            Pengajuan baru oleh karyawan dibuat dari HRL Process Tower tipe <b>Pinjaman / Kasbon</b>.
          </div>
        <?php else: ?>
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
          <input type="hidden" name="action" value="save_loan">
          <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

          <div class="col-12">
            <label class="form-label">Karyawan</label>
            <select name="employee_id" class="form-select" required>
              <option value="">-- pilih karyawan --</option>
              <?php foreach ($employees as $e):
                $selected = ((int)($edit['employee_id'] ?? 0) === (int)$e['id']) ? 'selected' : '';
              ?>
                <option value="<?= (int)$e['id'] ?>" <?= $selected ?>><?= payroll_h($e['employee_name']) ?> (<?= payroll_h($e['employee_code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Tipe</label>
            <?php $curType = strtoupper($edit['loan_type'] ?? 'LOAN'); ?>
            <select name="loan_type" class="form-select">
              <option value="LOAN" <?= $curType==='LOAN'?'selected':'' ?>>PINJAMAN</option>
              <option value="KASBON" <?= $curType==='KASBON'?'selected':'' ?>>KASBON</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Principal (Nominal)</label>
            <input type="number" step="0.01" name="principal" class="form-control" value="<?= payroll_h($edit['principal'] ?? '') ?>" required>
          </div>

          <div class="col-6">
            <label class="form-label">Tenor (bulan)</label>
            <input type="number" name="tenor_months" class="form-control" value="<?= payroll_h($edit['tenor_months'] ?? 1) ?>" min="1" required>
          </div>
          <div class="col-6">
            <label class="form-label">Cicilan / bulan</label>
            <input type="number" step="0.01" name="installment_amount" class="form-control" value="<?= payroll_h($edit['installment_amount'] ?? '') ?>" placeholder="auto = principal/tenor">
          </div>

          <div class="col-6">
            <label class="form-label">Mulai Periode</label>
            <input type="month" name="start_period_ym" class="form-control" value="<?= payroll_h($edit['start_period_ym'] ?? '') ?>" required>
          </div>
          <div class="col-6">
            <label class="form-label">Status</label>
            <?php $curStatus = strtoupper($edit['status'] ?? 'ACTIVE'); ?>
            <select name="status" class="form-select">
              <option value="ACTIVE" <?= $curStatus==='ACTIVE'?'selected':'' ?>>ACTIVE</option>
              <option value="PAUSED" <?= $curStatus==='PAUSED'?'selected':'' ?>>PAUSED</option>
              <option value="CLOSED" <?= $curStatus==='CLOSED'?'selected':'' ?>>CLOSED</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Note</label>
            <input type="text" name="note" class="form-control" value="<?= payroll_h($edit['note'] ?? '') ?>" placeholder="opsional">
          </div>

          <div class="col-12 d-grid mt-2">
            <button class="btn btn-rmi" type="submit"><?= $edit ? 'Update' : 'Simpan' ?></button>
          </div>

          <?php if ($edit): ?>
            <div class="col-12 d-grid">
              <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/loans.php">Batal Edit</a>
            </div>
          <?php endif; ?>

        </form>
        <?php endif; ?>

        <hr>
        <div class="d-grid gap-2">
          <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/index.php">← Kembali ke Payroll Dashboard</a>
          <a class="btn btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/loans.php?export=csv&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>&type=<?= urlencode($type) ?>">Export CSV</a>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card rmi-card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div>
          <div class="fw-bold">Daftar Pinjaman / Kasbon</div>
          <div class="rmi-muted" style="font-size:12px;">Tips: setelah menambah pinjaman, buka Payroll Run lalu klik <b>Sync Pinjaman</b>.</div>
        </div>
      </div>
      <div class="card-body">

        <form method="get" class="row g-2 mb-3">
          <div class="col-12 col-md-6">
            <input type="text" name="q" class="form-control" value="<?= payroll_h($q) ?>" placeholder="Cari nama / kode / note...">
          </div>
          <div class="col-6 col-md-3">
            <select name="type" class="form-select">
              <option value="">All Type</option>
              <option value="LOAN" <?= strtoupper($type)==='LOAN'?'selected':'' ?>>PINJAMAN</option>
              <option value="KASBON" <?= strtoupper($type)==='KASBON'?'selected':'' ?>>KASBON</option>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <select name="status" class="form-select">
              <option value="">All Status</option>
              <option value="ACTIVE" <?= strtoupper($status)==='ACTIVE'?'selected':'' ?>>ACTIVE</option>
              <option value="PAUSED" <?= strtoupper($status)==='PAUSED'?'selected':'' ?>>PAUSED</option>
              <option value="CLOSED" <?= strtoupper($status)==='CLOSED'?'selected':'' ?>>CLOSED</option>
            </select>
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-outline-light" type="submit">Filter</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-sm rmi-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Karyawan</th>
                <th>Type</th>
                <th class="text-end">Principal</th>
                <th class="text-end">Tenor</th>
                <th class="text-end">Cicilan</th>
                <th>Start</th>
                <th>End</th>
                <th>Status</th>
                <th>Note</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$loans): ?>
                <tr><td colspan="11" class="text-center rmi-muted py-4">Belum ada data pinjaman.</td></tr>
              <?php else: foreach ($loans as $l):
                $tenor = max(1, (int)$l['tenor_months']);
                $install = (float)$l['installment_amount'];
                if ($install <= 0) $install = ((float)$l['principal']) / $tenor;
                $endPeriod = payroll_ym_add_months((string)$l['start_period_ym'], $tenor - 1);

                $stLabel = strtoupper((string)$l['status']);
                $stBadge = $stLabel==='ACTIVE' ? 'success' : ($stLabel==='PAUSED' ? 'warning' : 'secondary');
              ?>
                <tr>
                  <td><?= (int)$l['id'] ?></td>
                  <td>
                    <div class="fw-bold"><?= payroll_h($l['employee_name'] ?? '-') ?></div>
                    <div class="rmi-muted" style="font-size:12px;">
                      <?= payroll_h($l['employee_code'] ?? '') ?> • Dept <?= payroll_h($l['dept_code'] ?? '-') ?> • Office <?= payroll_h($l['emp_office'] ?? '-') ?>
                    </div>
                  </td>
                  <td><?= payroll_h($l['loan_type']) ?></td>
                  <td class="text-end"><?= number_format((float)$l['principal'],2) ?></td>
                  <td class="text-end"><?= $tenor ?></td>
                  <td class="text-end"><?= number_format((float)$install,2) ?></td>
                  <td><?= payroll_h($l['start_period_ym']) ?></td>
                  <td><?= payroll_h($endPeriod) ?></td>
                  <td><span class="badge rmi-badge <?= payroll_h($stBadge) ?>"><?= payroll_h($stLabel) ?></span></td>
                  <td><?= payroll_h($l['note'] ?? '') ?></td>
                  <td class="text-end" style="min-width:220px;">
                    <div class="d-flex flex-wrap gap-1 justify-content-end">
                      <?php if ($canManageLoans): ?>
                      <a class="btn btn-sm btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/loans.php?edit=<?= (int)$l['id'] ?>">Edit</a>

                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <input type="hidden" name="status" value="ACTIVE">
                        <button class="btn btn-sm btn-outline-success" type="submit">Active</button>
                      </form>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <input type="hidden" name="status" value="PAUSED">
                        <button class="btn btn-sm btn-outline-warning" type="submit">Pause</button>
                      </form>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <input type="hidden" name="status" value="CLOSED">
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Close</button>
                      </form>

                      <form method="post" class="d-inline" onsubmit="return confirm('Hapus pinjaman #<?= (int)$l['id'] ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
                        <input type="hidden" name="action" value="delete_loan">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                      </form>
                      <?php else: ?>
                        <span class="badge rmi-badge secondary">View only</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
