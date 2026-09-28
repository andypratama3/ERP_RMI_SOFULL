<?php
require_once dirname(__DIR__) . '/master/auth.php'; // enforce login (static scan + runtime)
require_login(); // enforce login guard (static scan marker + runtime)
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';

$flash = payroll_flash_get();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('verify_csrf')) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

// Pastikan kolom konfigurasi PPh21/BPJS tersedia tanpa mengubah data lama.
function ps_ensure_employee_settings_columns(PDO $pdo): void {
  $columns = [
    'tax_pph21' => "DECIMAL(18,2) NOT NULL DEFAULT 0",
    'bpjs_tk'   => "DECIMAL(18,2) NOT NULL DEFAULT 0",
    'bpjs_kes'  => "DECIMAL(18,2) NOT NULL DEFAULT 0"
  ];
  foreach ($columns as $name => $ddl) {
    try {
      $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_employee_settings' AND column_name=? LIMIT 1");
      $st->execute([$name]);
      if (!$st->fetchColumn()) {
        $pdo->exec("ALTER TABLE payroll_employee_settings ADD COLUMN `{$name}` {$ddl}");
      }
    } catch (Throwable $e) {
      // Halaman tetap dapat dibuka; pesan simpan akan menunjukkan error bila DB user tidak punya izin ALTER.
    }
  }
}
ps_ensure_employee_settings_columns($pdo);

$q = trim($_GET['q'] ?? '');
$employeeId = (int)($_GET['employee_id'] ?? ($_GET['id'] ?? 0));

// Load users for mapping (master_system_login)
$users = [];
$matrixRows = [];
$matrixYear = (int)date('Y');

try {
  $stM = $pdo->prepare("
    SELECT id, payroll_status, payroll_level, job_title,
           take_home_pay, basic_salary, tunj_jabatan, tunj_anak, transport, kuota
    FROM payroll_salary_matrix
    WHERE matrix_year = ?
    ORDER BY payroll_status, payroll_level, job_title
  ");
  $stM->execute([$matrixYear]);
  $matrixRows = $stM->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $matrixRows = [];
}
if (payroll_table_exists($pdo, 'master_system_login')) {
  $stU = $pdo->query("SELECT id, username, full_name, role, level, status
                      FROM master_system_login
                      ORDER BY username ASC");
  $users = $stU->fetchAll(PDO::FETCH_ASSOC);
}

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
  $empId = (int)($_POST['employee_id'] ?? 0);
  $loginUserId = (int)($_POST['login_user_id'] ?? 0);
  $loginUserId = $loginUserId > 0 ? $loginUserId : null;

  $payType = strtoupper(trim($_POST['pay_type'] ?? 'MONTHLY'));
  if (!in_array($payType, ['MONTHLY','DAILY'], true)) $payType = 'MONTHLY';

  $salaryBasic = (float)str_replace([',',' '], '', ($_POST['salary_basic'] ?? '0'));
  $allowFixed  = (float)str_replace([',',' '], '', ($_POST['allowance_fixed'] ?? '0'));
  $allowPos    = (float)str_replace([',',' '], '', ($_POST['allowance_position'] ?? '0'));
  $allowTrans  = (float)str_replace([',',' '], '', ($_POST['allowance_transport'] ?? '0'));
  $allowChild  = (float)str_replace([',',' '], '', ($_POST['allowance_child'] ?? '0'));
  $dedFixed    = (float)str_replace([',',' '], '', ($_POST['deduction_fixed'] ?? '0'));
  $otRate      = (float)str_replace([',',' '], '', ($_POST['overtime_rate_per_hour'] ?? '0'));
  $taxPph21 = max(0.0, (float)str_replace([',',' '], '', ($_POST['tax_pph21'] ?? '0')));
  $bpjsTk    = max(0.0, (float)str_replace([',',' '], '', ($_POST['bpjs_tk'] ?? '0')));
  $bpjsKes   = max(0.0, (float)str_replace([',',' '], '', ($_POST['bpjs_kes'] ?? '0')));
  $salaryMatrixId = (int)($_POST['salary_matrix_id'] ?? 0);
$salaryMatrixId = $salaryMatrixId > 0 ? $salaryMatrixId : null;

  if ($empId <= 0) {
    payroll_flash_set('danger', 'Employee ID tidak valid.');
    rmi_redirect("{$BASE_PAYROLL}/payroll_settings.php");
  }

  try{
    $st = $pdo->prepare("INSERT INTO payroll_employee_settings
      (employee_id, login_user_id, salary_matrix_id, pay_type, salary_basic,
       allowance_fixed, allowance_position, allowance_transport, allowance_child,
       deduction_fixed, overtime_rate_per_hour,
       tax_pph21, bpjs_tk, bpjs_kes,
       updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        login_user_id = VALUES(login_user_id),
        salary_matrix_id = VALUES(salary_matrix_id),
        pay_type = VALUES(pay_type),
        salary_basic = VALUES(salary_basic),
        allowance_fixed = VALUES(allowance_fixed),
        allowance_position = VALUES(allowance_position),
        allowance_transport = VALUES(allowance_transport),
        allowance_child = VALUES(allowance_child),
        deduction_fixed = VALUES(deduction_fixed),
        overtime_rate_per_hour = VALUES(overtime_rate_per_hour),
        tax_pph21 = VALUES(tax_pph21),
        bpjs_tk = VALUES(bpjs_tk),
        bpjs_kes = VALUES(bpjs_kes),
        updated_at = NOW()");
    $st->execute([$empId, $loginUserId, $salaryMatrixId, $payType, $salaryBasic, $allowFixed, $allowPos, $allowTrans, $allowChild, $dedFixed, $otRate, $taxPph21, $bpjsTk, $bpjsKes]);

    if (function_exists('master_audit')) {
      $empCode = "EMP#{$empId}";
      try {
        $stE = $pdo->prepare("SELECT employee_code FROM master_employees WHERE id=? LIMIT 1");
        $stE->execute([$empId]);
        $c = $stE->fetchColumn();
        if ($c !== false && $c !== null) $empCode = (string)$c;
      } catch (Throwable $e) {}
      master_audit($pdo, 'payroll', 'payroll_employee_settings', 'SAVE', $empId, $empCode, "Payroll settings saved: {$empCode}", []);
    }
    payroll_flash_set('success', 'Settings payroll tersimpan.');
    rmi_redirect("{$BASE_PAYROLL}/payroll_settings.php?employee_id={$empId}");
  } catch (Throwable $e){
    payroll_flash_set('danger', 'Gagal simpan settings: ' . $e->getMessage());
    rmi_redirect("{$BASE_PAYROLL}/payroll_settings.php?employee_id={$empId}");
  }
}

// Load employee + settings for edit
$edit = null;
if ($employeeId > 0) {
  $st = $pdo->prepare("SELECT e.id, e.employee_code, e.employee_name, e.dept_code, e.office_code,
  s.login_user_id, s.salary_matrix_id, s.pay_type, s.salary_basic,
  s.allowance_fixed,
  s.allowance_position,
  s.allowance_transport,
  s.allowance_child,
  s.deduction_fixed,
  s.overtime_rate_per_hour,
  s.tax_pph21,
  s.bpjs_tk,
  s.bpjs_kes
    FROM master_employees e
    LEFT JOIN payroll_employee_settings s ON s.employee_id = e.id
    WHERE e.id = ? LIMIT 1");
  $st->execute([$employeeId]);
  $edit = $st->fetch(PDO::FETCH_ASSOC);
}

// list employees (with optional search)
$sql = "SELECT e.id, e.employee_code, e.employee_name, e.dept_code, e.office_code,
    COALESCE(s.pay_type,'') AS pay_type,
    COALESCE(s.salary_basic,0) AS salary_basic,
(
  COALESCE(s.salary_basic,0)
  + COALESCE(s.allowance_fixed,0)
  + COALESCE(s.allowance_position,0)
  + COALESCE(s.allowance_transport,0)
  + COALESCE(s.allowance_child,0)
  - COALESCE(s.deduction_fixed,0)
) AS salary_total,
    COALESCE(s.login_user_id,0) AS login_user_id
  FROM master_employees e
  LEFT JOIN payroll_employee_settings s ON s.employee_id = e.id
  WHERE e.status = 'active'";
$params = [];
if ($q !== '') {
  $sql .= " AND (e.employee_code LIKE ? OR e.employee_name LIKE ? OR e.dept_code LIKE ? OR e.office_code LIKE ?)";
  $like = '%' . $q . '%';
  $params = [$like,$like,$like,$like];
}
$sql .= " ORDER BY e.employee_name ASC LIMIT 300";
$st = $pdo->prepare($sql);
$st->execute($params);
$list = $st->fetchAll(PDO::FETCH_ASSOC);

rmi_header('Payroll Settings', 'payroll', ['base_project'=>$BASE_PROJECT]);
?>

<?php if ($flash): ?>
  <div class="alert alert-<?= payroll_h($flash['type']) ?>"><?= payroll_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card rmi-card">
      <div class="card-header">
        <div class="fw-bold">Edit Settings</div>
        <div class="rmi-muted" style="font-size:12px;">Mapping user + komponen gaji</div>
      </div>
      <div class="card-body">
        <?php if (!$edit): ?>
          <div class="rmi-muted">Pilih karyawan dari list di kanan untuk edit.</div>
        <?php else: ?>
          <div class="mb-2">
            <div class="fw-bold"><?= payroll_h($edit['employee_name']) ?></div>
            <div class="rmi-muted" style="font-size:12px;">
              <?= payroll_h($edit['employee_code']) ?> • Dept: <?= payroll_h($edit['dept_code'] ?? '-') ?> • Office: <?= payroll_h($edit['office_code'] ?? '-') ?>
            </div>
          </div>

          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="employee_id" value="<?= (int)$edit['id'] ?>">

            <div class="col-12">
              <label class="form-label">Login User (untuk tarik Absensi)</label>
              <?php if (!$users): ?>
                <div class="alert alert-warning">Tabel <b>master_system_login</b> tidak ditemukan / belum ada user.</div>
              <?php else: ?>
                <select name="login_user_id" class="form-select">
                  <option value="0">— (Tidak di-mapping)</option>
                  <?php foreach ($users as $u):
                    $label = $u['username'];
                    if (!empty($u['full_name'])) $label .= ' — ' . $u['full_name'];
                    $label .= ' (' . ($u['status'] ?? '') . ')';
                    $sel = ((int)$edit['login_user_id'] === (int)$u['id']) ? 'selected' : '';
                  ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $sel ?>><?= payroll_h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="rmi-muted mt-1" style="font-size:12px;">Kalau tidak di-mapping, absensi tidak dihitung (gaji tetap bisa dibuat).</div>
              <?php endif; ?>
            </div>

            <div class="col-12">
  <label class="form-label">Master Golongan Gaji</label>
  <select id="salary_matrix_select" name="salary_matrix_id" class="form-select">
    <option value="">— pilih golongan gaji —</option>

    <?php foreach ($matrixRows as $m): ?>
  <?php $selMatrix = ((int)($edit['salary_matrix_id'] ?? 0) === (int)$m['id']) ? 'selected' : ''; ?>
  <option
    <?= $selMatrix ?>

        value="<?= (int)$m['id'] ?>"
        data-basic="<?= payroll_h($m['basic_salary']) ?>"
        data-jabatan="<?= payroll_h($m['tunj_jabatan']) ?>"
        data-anak="<?= payroll_h($m['tunj_anak']) ?>"
        data-transport="<?= payroll_h($m['transport']) ?>"
        data-kuota="<?= payroll_h($m['kuota']) ?>"
      >
        <?= payroll_h($m['payroll_status']) ?> -
        <?= payroll_h($m['payroll_level']) ?> -
        <?= payroll_h($m['job_title']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <div class="rmi-muted mt-1" style="font-size:12px;">
    Pilih untuk otomatis mengisi gaji pokok dan tunjangan.
    <div id="selected_matrix_text" class="alert alert-info mt-2 py-2" style="display:none;"></div>
  </div>
</div>
              <label class="form-label">Pay Type</label>
              <select name="pay_type" class="form-select">
                <?php
                  $pt = strtoupper($edit['pay_type'] ?? 'MONTHLY');
                ?>
                <option value="MONTHLY" <?= $pt==='MONTHLY'?'selected':'' ?>>MONTHLY (bulanan)</option>
                <option value="DAILY" <?= $pt==='DAILY'?'selected':'' ?>>DAILY (harian)</option>
              </select>
            </div>

            <div class="col-6">
              <label class="form-label">Gaji Pokok</label>
              <input type="number" step="0.01" name="salary_basic" class="form-control" value="<?= payroll_h($edit['salary_basic'] ?? 0) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">OT Rate / Jam</label>
              <input type="number" step="0.01" name="overtime_rate_per_hour" class="form-control" value="<?= payroll_h($edit['overtime_rate_per_hour'] ?? 0) ?>">
            </div>

            <div class="col-6">
              <label class="form-label">Tunjangan Lainnya (Parkir,Pengiriman,Bonus)</label>
              <input type="number" step="0.01" name="allowance_fixed" class="form-control" value="<?= payroll_h($edit['allowance_fixed'] ?? 0) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Tunjangan Jabatan</label>
              <input type="number" step="0.01" name="allowance_position" class="form-control" value="<?= payroll_h($edit['allowance_position'] ?? 0) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Tunjangan Transport</label>
              <input type="number" step="0.01" name="allowance_transport" class="form-control" value="<?= payroll_h($edit['allowance_transport'] ?? 0) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Tunjangan Anak</label>
              <input type="number" step="0.01" name="allowance_child" class="form-control" value="<?= payroll_h($edit['allowance_child'] ?? 0) ?>">
            </div>

            <div class="col-6">
              <label class="form-label">Potongan Lainnya (barang kosong, barang minus)</label>
              <input type="number" step="0.01" name="deduction_fixed" class="form-control" value="<?= payroll_h($edit['deduction_fixed'] ?? 0) ?>">
            </div>

            <div class="col-12">
              <hr>
              <div class="fw-bold">Potongan PPh 21 dan BPJS</div>
              <div class="rmi-muted" style="font-size:12px;">Isi nominal potongan karyawan. Nilai ini dibaca otomatis saat Sync Komponen Payroll dan masuk ke payslip.</div>
            </div>
            <div class="col-12">
              <label class="form-label">PPh 21</label>
              <input type="number" step="0.01" min="0" name="tax_pph21" class="form-control" value="<?= payroll_h($edit['tax_pph21'] ?? 0) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">BPJS Ketenagakerjaan</label>
              <input type="number" step="0.01" min="0" name="bpjs_tk" class="form-control" value="<?= payroll_h($edit['bpjs_tk'] ?? 0) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">BPJS Kesehatan</label>
              <input type="number" step="0.01" min="0" name="bpjs_kes" class="form-control" value="<?= payroll_h($edit['bpjs_kes'] ?? 0) ?>">
            </div>

            <div class="col-12 d-grid mt-2">
              <button class="btn btn-rmi" type="submit">Simpan Settings</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card rmi-card mt-3">
      <div class="card-body">
        <div class="fw-bold">Kembali</div>
        <div class="rmi-muted" style="font-size:12px;">Ke dashboard Payroll.</div>
        <div class="d-grid mt-2">
          <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/index.php">← Payroll Dashboard</a>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card rmi-card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div>
          <div class="fw-bold">Daftar Karyawan (Active)</div>
          <div class="rmi-muted" style="font-size:12px;">Klik "Edit" untuk set payroll settings</div>
        </div>
        <form method="get" class="d-flex gap-2">
          <input type="text" name="q" class="form-control form-control-sm" style="width:220px;" placeholder="Search..." value="<?= payroll_h($q) ?>">
          <button class="btn btn-sm btn-outline-light" type="submit">Cari</button>
        </form>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm rmi-table mb-0">
            <thead>
              <tr>
                <th>Kode</th>
                <th>Nama</th>
                <th>Dept</th>
                <th>Office</th>
                <th>Type</th>
                <th>Gaji</th>
                <th>Map User</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$list): ?>
                <tr><td colspan="8" class="text-center rmi-muted py-4">Tidak ada data.</td></tr>
              <?php else: foreach ($list as $r): ?>
                <tr>
                  <td><?= payroll_h($r['employee_code']) ?></td>
                  <td><?= payroll_h($r['employee_name']) ?></td>
                  <td><?= payroll_h($r['dept_code'] ?? '-') ?></td>
                  <td><?= payroll_h($r['office_code'] ?? '-') ?></td>
                  <td><?= payroll_h($r['pay_type'] ?: '-') ?></td>
                  <td class="text-end"><?= number_format((float)$r['salary_total'], 2) ?></td>
                  <td class="text-center">
                    <?php if ((int)$r['login_user_id'] > 0): ?>
                      <span class="badge rmi-badge success">✓</span>
                    <?php else: ?>
                      <span class="badge rmi-badge warning">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/payroll_settings.php?employee_id=<?= (int)$r['id'] ?>">Edit</a>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
  const sel = document.getElementById('salary_matrix_select');
  const txt = document.getElementById('selected_matrix_text');
  if (!sel) return;

  function showSelected() {
    const opt = sel.options[sel.selectedIndex];

    if (!opt || !opt.value) {
      if (txt) txt.style.display = 'none';
      return;
    }

    if (txt) {
      txt.style.display = 'block';
      txt.textContent = 'Golongan terpilih: ' + opt.text.trim();
    }
  }

  sel.addEventListener('change', function () {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;

    const setVal = (name, val) => {
      const el = document.querySelector('[name="' + name + '"]');
      if (el) el.value = val || 0;
    };

    setVal('salary_basic', opt.dataset.basic);
    setVal('allowance_position', opt.dataset.jabatan);
    setVal('allowance_transport', opt.dataset.transport);
    setVal('allowance_child', opt.dataset.anak);
    setVal('allowance_fixed', opt.dataset.kuota);

    showSelected();
  });

  showSelected();
});
</script>
<?php rmi_footer(); ?>
