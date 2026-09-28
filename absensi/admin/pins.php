<?php
$title="Admin HR • PIN Employee";
require_once __DIR__ . "/../_inc/bootstrap.php";
require_once __DIR__ . "/../../master/_audit_master.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.ADMIN_PINS');

require_once __DIR__ . "/../_layout_top.php";


if (!absensi_is_hr_admin($pdo, $ABS_USER)) {
  http_response_code(403);
  die('Akses ditolak');
}

// Pastikan schema pin ada
if (function_exists('absensi_pin_schema_ensure')) {
  try { absensi_pin_schema_ensure($pdo); } catch (Throwable $e) {}
}

// Cek apakah master_employees tersedia
$meTableOk = true;
try {
  $pdo->query("SELECT 1 FROM master_employees LIMIT 1");
} catch (Throwable $e) {
  $meTableOk = false;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['op'])) {
  csrf_verify_or_die();

  $op  = (string)($_POST['op'] ?? '');
  $emp = trim((string)($_POST['employee_code'] ?? ''));

  try {
    if (!$meTableOk) {
      throw new RuntimeException('Table master_employees belum ada. Aktifkan modul Master Employees dulu.');
    }

    if ($op === 'set') {
      $pin = trim((string)($_POST['pin'] ?? ''));
      absensi_pin_set($pdo, $emp, $pin, (string)($ABS_USER['username'] ?? ''));
      absensi_audit($pdo, $ABS_USER, 'PIN_SET', ['employee_code'=>$emp]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'absensi_admin', 'absensi_employee_pin', 'PIN_SET', null, $emp, "PIN set for employee: {$emp}", []);
      }
      absensi_flash_set('ok', "PIN berhasil diset untuk {$emp}.");
    } elseif ($op === 'disable') {
      absensi_pin_disable($pdo, $emp, (string)($ABS_USER['username'] ?? ''));
      absensi_audit($pdo, $ABS_USER, 'PIN_DISABLE', ['employee_code'=>$emp]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'absensi_admin', 'absensi_employee_pin', 'PIN_DISABLE', null, $emp, "PIN disabled for employee: {$emp}", []);
      }
      absensi_flash_set('ok', "PIN dinonaktifkan untuk {$emp}.");
    }
  } catch (Throwable $e) {
    absensi_flash_set('bad', $e->getMessage());
  }

  rmi_redirect('pins.php');
}

$employees = [];
if ($meTableOk) {
  // Jangan terlalu ketat filter status (biar tidak "kosong" gara-gara beda huruf)
  $stmt = $pdo->query("SELECT e.employee_code, e.employee_name, e.dept_code, e.office_code, e.level_type, e.status,
                              p.status AS pin_status, p.updated_at AS pin_updated_at, p.updated_by AS pin_updated_by
                       FROM master_employees e
                       LEFT JOIN absensi_employee_pin p ON p.employee_code = e.employee_code
                       WHERE (e.status IS NULL OR e.status='' OR LOWER(e.status) IN ('active','aktif'))
                       ORDER BY e.office_code ASC, e.dept_code ASC, e.level_type ASC, e.employee_name ASC
                       LIMIT 1000");
  $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$employeeCount = count($employees);
?>

<div class="grid">
  <div class="card col-12">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="h1">PIN Employee (Absensi)</div>
        <div class="muted small">PIN disimpan dalam hash (tidak plaintext). Dipakai untuk validasi Absensi pada model akun jabatan.</div>
      </div>
      <div class="row">
        <a class="btn" href="rekap.php">Rekap</a>
        <a class="btn" href="approval.php">Approval</a>
      </div>
    </div>

    <?php if (!$meTableOk): ?>
      <div class="flash bad" style="margin-top:12px">
        Table <b>master_employees</b> tidak ditemukan. Pastikan modul <b>Master Employees</b> aktif.
      </div>
    <?php else: ?>

      <?php if ($employeeCount <= 0): ?>
        <div class="flash bad" style="margin-top:12px">
          Data <b>master_employees</b> masih kosong. Tambahkan employee dulu (Create/Import) agar bisa set PIN.
        </div>
      <?php elseif ($employeeCount <= 2): ?>
        <div class="flash" style="margin-top:12px">
          Jika yang tampil masih "Dummy", berarti data <b>master_employees</b> masih sample. Silakan ganti dengan data employee asli.
        </div>
      <?php endif; ?>

      <form method="post" class="row" style="margin-top:12px; gap:10px; align-items:end">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="set">
        <div style="width:220px">
          <label class="muted small">Employee Code</label>
          <input name="employee_code" list="emp_list" placeholder="EMP001" required>
          <datalist id="emp_list">
            <?php foreach ($employees as $e): ?>
              <option value="<?= htmlspecialchars((string)$e['employee_code'],ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars((string)$e['employee_name'],ENT_QUOTES,'UTF-8') ?></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div style="width:220px">
          <label class="muted small">PIN Baru</label>
          <input name="pin" type="password" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="10" placeholder="4-10 digit" required>
        </div>
        <div>
          <button class="btn ok" type="submit">Set / Reset PIN</button>
        </div>
      </form>

      <div class="muted small" style="margin-top:10px">
        Tip: Saat employee resign, cukup ubah holder akun jabatan ke employee baru. PIN employee lama tidak akan terpakai lagi.
      </div>

      <table class="table" style="margin-top:12px">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Dept</th>
            <th>Office</th>
            <th>Level</th>
            <th>PIN</th>
            <th>Updated</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($employees as $e): ?>
            <tr>
              <td>
                <?= htmlspecialchars((string)$e['employee_name'],ENT_QUOTES,'UTF-8') ?>
                <div class="muted small"><?= htmlspecialchars((string)$e['employee_code'],ENT_QUOTES,'UTF-8') ?></div>
              </td>
              <td><?= htmlspecialchars(strtoupper((string)$e['dept_code']),ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars(strtoupper((string)$e['office_code']),ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$e['level_type'],ENT_QUOTES,'UTF-8') ?></td>
              <td>
                <?php if (!empty($e['pin_status']) && strtolower((string)$e['pin_status'])==='active'): ?>
                  <span class="badge">ACTIVE</span>
                <?php elseif (!empty($e['pin_status'])): ?>
                  <span class="badge">INACTIVE</span>
                <?php else: ?>
                  <span class="badge">NOT SET</span>
                <?php endif; ?>
              </td>
              <td class="muted small">
                <?= htmlspecialchars((string)($e['pin_updated_at'] ?? '-'),ENT_QUOTES,'UTF-8') ?><br>
                <?= htmlspecialchars((string)($e['pin_updated_by'] ?? '-'),ENT_QUOTES,'UTF-8') ?>
              </td>
              <td>
                <?php if (!empty($e['pin_status']) && strtolower((string)$e['pin_status'])==='active'): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="op" value="disable">
                    <input type="hidden" name="employee_code" value="<?= htmlspecialchars((string)$e['employee_code'],ENT_QUOTES,'UTF-8') ?>">
                    <button class="btn warn" type="submit">Disable</button>
                  </form>
                <?php else: ?>
                  <span class="muted small">Set via form atas</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . "/../_layout_bottom.php"; ?>
