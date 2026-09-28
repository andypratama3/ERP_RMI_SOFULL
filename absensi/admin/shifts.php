<?php
declare(strict_types=1);

$title = "Admin HR • Manajemen Shift & Lembur";
require_once __DIR__ . "/../_inc/bootstrap.php";
require_once __DIR__ . "/../_inc/shift_helper.php";
require_once __DIR__ . "/../../master/_audit_master.php";

if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.OFFICE_SETTINGS');

require_once __DIR__ . "/../_layout_top.php";

$_sRole  = strtoupper(trim((string)($ABS_USER['role']  ?? '')));
$_sLevel = strtoupper(trim((string)($ABS_USER['level'] ?? '')));
$_isAdmin = in_array($_sRole,  ['SYS','ADMIN','SUPERADMIN'], true)
          || in_array($_sLevel, ['SYS','ADMIN','SUPERADMIN'], true);

if (!$_isAdmin) {
    http_response_code(403);
    echo "<div class='alert alert-danger'><b>Akses ditolak.</b> Hanya SYS / ADMIN.</div>";
    require_once __DIR__ . "/../_layout_bottom.php";
    exit;
}

absensi_shift_ensure_tables($pdo);

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $mode = (string)($_POST['mode'] ?? '');

    // ── SAVE SHIFT (create / update) ────────────────────────────────────────
    if ($mode === 'save_shift') {
        $id           = (int)($_POST['shift_id'] ?? 0);
        $name         = trim((string)($_POST['shift_name']  ?? ''));
        $cin          = trim((string)($_POST['checkin_time'] ?? ''));
        $cout         = trim((string)($_POST['checkout_time'] ?? ''));
        $tol          = max(0, min(120, (int)($_POST['late_tolerance_min'] ?? 0)));
        $otThr        = max(0, min(480, (int)($_POST['overtime_threshold_min'] ?? 30)));
        $overnight    = (int)(bool)($_POST['is_overnight'] ?? 0);
        $active       = (int)(bool)($_POST['is_active'] ?? 1);
        $note         = trim((string)($_POST['note'] ?? ''));

        $errors = [];
        if ($name === '') $errors[] = 'Nama shift wajib diisi.';
        if (!preg_match('/^\d{2}:\d{2}$/', $cin))  $errors[] = 'Format jam masuk tidak valid.';
        if (!preg_match('/^\d{2}:\d{2}$/', $cout)) $errors[] = 'Format jam pulang tidak valid.';
        if (!$overnight && $cin !== '' && $cout !== '' && $cin >= $cout)
            $errors[] = 'Jam masuk harus lebih awal dari jam pulang (kecuali shift overnight).';

        if (empty($errors)) {
            $cinFull  = $cin  . ':00';
            $coutFull = $cout . ':00';
            if ($id > 0) {
                $pdo->prepare("UPDATE absensi_shifts SET shift_name=?,checkin_time=?,checkout_time=?,
                    late_tolerance_min=?,overtime_threshold_min=?,is_overnight=?,is_active=?,note=?,updated_at=NOW()
                    WHERE id=?")
                    ->execute([$name,$cinFull,$coutFull,$tol,$otThr,$overnight,$active,$note,$id]);
                absensi_audit($pdo,$ABS_USER,'SHIFT_UPDATE',['id'=>$id,'name'=>$name]);
                if (function_exists('master_audit'))
                    master_audit($pdo,'absensi_admin','absensi_shifts','SHIFT_UPDATE',$id,$name,"Shift diperbarui: {$name}",[]);
                absensi_flash_set('ok',"Shift '{$name}' berhasil diperbarui.");
            } else {
                $pdo->prepare("INSERT INTO absensi_shifts (shift_name,checkin_time,checkout_time,
                    late_tolerance_min,overtime_threshold_min,is_overnight,is_active,note,created_at)
                    VALUES (?,?,?,?,?,?,?,?,NOW())")
                    ->execute([$name,$cinFull,$coutFull,$tol,$otThr,$overnight,$active,$note]);
                $newId = (int)$pdo->lastInsertId();
                absensi_audit($pdo,$ABS_USER,'SHIFT_CREATE',['id'=>$newId,'name'=>$name]);
                if (function_exists('master_audit'))
                    master_audit($pdo,'absensi_admin','absensi_shifts','SHIFT_CREATE',$newId,$name,"Shift dibuat: {$name}",[]);
                absensi_flash_set('ok',"Shift '{$name}' berhasil dibuat.");
            }
            rmi_redirect('shifts.php');
        }
        absensi_flash_set('bad', implode(' ', $errors));
        rmi_redirect('shifts.php');
    }

    // ── DELETE SHIFT ────────────────────────────────────────────────────────
    if ($mode === 'delete_shift') {
        $id = (int)($_POST['shift_id'] ?? 0);
        if ($id > 0) {
            $row = absensi_shift_by_id($pdo, $id);
            $pdo->prepare("DELETE FROM absensi_shifts WHERE id=?")->execute([$id]);
            absensi_audit($pdo,$ABS_USER,'SHIFT_DELETE',['id'=>$id,'name'=>$row['shift_name']??'']);
            absensi_flash_set('ok', "Shift dihapus.");
        }
        rmi_redirect('shifts.php');
    }

    // ── ASSIGN USER SHIFT ───────────────────────────────────────────────────
    if ($mode === 'assign_user') {
        $username   = trim((string)($_POST['username']  ?? ''));
        $shiftId    = (int)($_POST['shift_id']      ?? 0);
        $effDate    = trim((string)($_POST['effective_date'] ?? ''));
        $endDate    = trim((string)($_POST['end_date'] ?? ''));
        $note       = trim((string)($_POST['note']    ?? ''));

        $errors = [];
        if ($username === '') $errors[] = 'Username wajib diisi.';
        if ($shiftId <= 0)    $errors[] = 'Pilih shift.';
        if ($effDate === '' || !strtotime($effDate)) $errors[] = 'Tanggal efektif tidak valid.';

        if (empty($errors)) {
            $pdo->prepare("INSERT INTO absensi_user_shifts (username,shift_id,effective_date,end_date,note,created_at)
                VALUES (?,?,?,?,?,NOW())")
                ->execute([$username,$shiftId,$effDate,$endDate ?: null,$note]);
            $shift = absensi_shift_by_id($pdo, $shiftId);
            absensi_audit($pdo,$ABS_USER,'SHIFT_USER_ASSIGN',['username'=>$username,'shift'=>$shift['shift_name']??$shiftId,'eff'=>$effDate]);
            if (function_exists('master_audit'))
                master_audit($pdo,'absensi_admin','absensi_user_shifts','SHIFT_ASSIGN',null,$username,
                    "Shift ditugaskan ke {$username}: ".($shift['shift_name']??'')." mulai {$effDate}",[]);
            absensi_flash_set('ok',"Shift berhasil ditugaskan ke {$username}.");
        } else {
            absensi_flash_set('bad', implode(' ', $errors));
        }
        rmi_redirect('shifts.php#tab-assign');
    }

    // ── REMOVE USER SHIFT ───────────────────────────────────────────────────
    if ($mode === 'remove_user_shift') {
        $id = (int)($_POST['row_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM absensi_user_shifts WHERE id=?")->execute([$id]);
            absensi_flash_set('ok','Penugasan shift dihapus.');
        }
        rmi_redirect('shifts.php#tab-assign');
    }

    // ── SET DEFAULT SHIFT ───────────────────────────────────────────────────
    if ($mode === 'set_default_shift') {
        $defId = (int)($_POST['default_shift_id'] ?? 1);
        $pdo->prepare("INSERT INTO absensi_settings (k,v,updated_at) VALUES ('default_shift_id',?,NOW())
                        ON DUPLICATE KEY UPDATE v=VALUES(v),updated_at=NOW()")
            ->execute([(string)$defId]);
        $shift = absensi_shift_by_id($pdo, $defId);
        absensi_audit($pdo,$ABS_USER,'SHIFT_SET_DEFAULT',['shift_id'=>$defId,'name'=>$shift['shift_name']??'']);
        absensi_flash_set('ok','Shift default berhasil diubah.');
        rmi_redirect('shifts.php');
    }
}

// ── Load data ────────────────────────────────────────────────────────────────
$shifts     = absensi_shifts_all($pdo);
$defaultId  = (int)(absensi_setting($pdo, 'default_shift_id', '1') ?? '1');
$userShifts = $pdo->query("
    SELECT us.*, s.shift_name, s.checkin_time, s.checkout_time
    FROM absensi_user_shifts us
    JOIN absensi_shifts s ON s.id = us.shift_id
    ORDER BY us.effective_date DESC, us.username
")->fetchAll(PDO::FETCH_ASSOC);

// User list untuk dropdown
$userList = [];
try {
    $userList = $pdo->query("SELECT username, full_name, department FROM master_system_login WHERE status='ACTIVE' ORDER BY username")
                    ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<div class="container-fluid py-3">

  <!-- Breadcrumb + nav -->
  <nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="rekap.php">Admin HR</a></li>
      <li class="breadcrumb-item active">Manajemen Shift & Lembur</li>
    </ol>
  </nav>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="rekap.php">📊 Rekap</a>
    <a class="btn btn-sm btn-outline-secondary" href="offices.php">🏢 Office</a>
    <a class="btn btn-sm btn-outline-secondary" href="settings.php">⚙️ Jam Kerja</a>
    <a class="btn btn-sm btn-primary" href="shifts.php">🔄 Shift</a>
  </div>

  <!-- Tab -->
  <ul class="nav nav-tabs mb-3" id="shiftTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-shifts">Definisi Shift</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-assign">Penugasan per User</a></li>
  </ul>

  <div class="tab-content">

    <!-- ── TAB 1: Definisi Shift ── -->
    <div class="tab-pane fade show active" id="tab-shifts">
      <div class="row g-3">

        <!-- Form tambah/edit shift -->
        <div class="col-lg-5">
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h6 class="mb-0">➕ Tambah / Edit Shift</h6>
            </div>
            <div class="card-body">
              <form method="post" id="formShift">
                <input type="hidden" name="_csrf"    value="<?= $h(csrf_token()) ?>">
                <input type="hidden" name="mode"     value="save_shift">
                <input type="hidden" name="shift_id" value="0" id="editShiftId">

                <div class="mb-3">
                  <label class="form-label fw-semibold">Nama Shift</label>
                  <input type="text" class="form-control" name="shift_name" id="editShiftName"
                         placeholder="mis: Shift Pagi, Shift Malam" required>
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="form-label fw-semibold">Jam Masuk</label>
                    <input type="time" class="form-control" name="checkin_time" id="editCin" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label fw-semibold">Jam Pulang</label>
                    <input type="time" class="form-control" name="checkout_time" id="editCout" required>
                  </div>
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="form-label">Toleransi Terlambat</label>
                    <div class="input-group input-group-sm">
                      <input type="number" class="form-control" name="late_tolerance_min" id="editTol"
                             min="0" max="120" value="0">
                      <span class="input-group-text">menit</span>
                    </div>
                  </div>
                  <div class="col-6">
                    <label class="form-label">Min. Lembur</label>
                    <div class="input-group input-group-sm">
                      <input type="number" class="form-control" name="overtime_threshold_min" id="editOt"
                             min="0" max="480" value="30">
                      <span class="input-group-text">menit</span>
                    </div>
                  </div>
                </div>

                <div class="mb-3 d-flex gap-3">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="is_overnight" id="editOvernight" value="1">
                    <label class="form-check-label small" for="editOvernight">Shift Overnight<br>
                      <span class="text-muted" style="font-size:11px">centang jika melewati tengah malam</span>
                    </label>
                  </div>
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="is_active" id="editActive" value="1" checked>
                    <label class="form-check-label small" for="editActive">Aktif</label>
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label">Keterangan</label>
                  <input type="text" class="form-control form-control-sm" name="note" id="editNote"
                         placeholder="Opsional">
                </div>

                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary btn-sm px-3">Simpan Shift</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetShiftForm()">Reset</button>
                </div>
              </form>
            </div>
          </div>

          <!-- Default shift -->
          <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">🎯 Shift Default</h6></div>
            <div class="card-body">
              <p class="small text-muted mb-2">
                User yang <b>tidak memiliki penugasan shift spesifik</b> akan otomatis menggunakan shift ini.
              </p>
              <form method="post" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="_csrf" value="<?= $h(csrf_token()) ?>">
                <input type="hidden" name="mode" value="set_default_shift">
                <select name="default_shift_id" class="form-select form-select-sm">
                  <?php foreach ($shifts as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $defaultId ? 'selected' : '' ?>>
                      <?= $h($s['shift_name']) ?> (<?= $h(absensi_shift_fmt($s['checkin_time'])) ?>–<?= $h(absensi_shift_fmt($s['checkout_time'])) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-primary">Set Default</button>
              </form>
            </div>
          </div>
        </div>

        <!-- Daftar shift -->
        <div class="col-lg-7">
          <div class="card">
            <div class="card-header"><h6 class="mb-0">📋 Daftar Shift (<?= count($shifts) ?>)</h6></div>
            <div class="card-body p-0">
              <?php if (empty($shifts)): ?>
                <p class="p-3 text-muted mb-0">Belum ada shift. Tambahkan di form kiri.</p>
              <?php else: ?>
              <table class="table table-sm table-hover mb-0">
                <thead class="table-dark">
                  <tr>
                    <th>Shift</th>
                    <th>Masuk</th>
                    <th>Pulang</th>
                    <th>Tol.</th>
                    <th>Min. Lembur</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($shifts as $s):
                    $isDefault = ((int)$s['id'] === $defaultId);
                  ?>
                  <tr>
                    <td>
                      <span class="fw-semibold"><?= $h($s['shift_name']) ?></span>
                      <?php if ($isDefault): ?>
                        <span class="badge bg-primary ms-1" style="font-size:10px">Default</span>
                      <?php endif; ?>
                      <?php if ($s['is_overnight']): ?>
                        <span class="badge bg-secondary ms-1" style="font-size:10px">Overnight</span>
                      <?php endif; ?>
                      <?php if ($s['note']): ?>
                        <div class="text-muted" style="font-size:11px"><?= $h($s['note']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="fw-semibold text-success"><?= $h(absensi_shift_fmt($s['checkin_time'])) ?></td>
                    <td class="fw-semibold text-warning"><?= $h(absensi_shift_fmt($s['checkout_time'])) ?></td>
                    <td><?= (int)$s['late_tolerance_min'] ?>m</td>
                    <td><?= (int)$s['overtime_threshold_min'] ?>m</td>
                    <td>
                      <?php if ($s['is_active']): ?>
                        <span class="badge bg-success">Aktif</span>
                      <?php else: ?>
                        <span class="badge bg-secondary">Nonaktif</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <button class="btn btn-xs btn-outline-primary"
                              onclick="editShift(<?= $h(json_encode($s)) ?>)">Edit</button>
                      <?php if (!$isDefault): ?>
                      <form method="post" class="d-inline"
                            onsubmit="return confirm('Hapus shift <?= $h(addslashes($s['shift_name'])) ?>?')">
                        <input type="hidden" name="_csrf"     value="<?= $h(csrf_token()) ?>">
                        <input type="hidden" name="mode"     value="delete_shift">
                        <input type="hidden" name="shift_id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger">Hapus</button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div><!-- /tab-shifts -->

    <!-- ── TAB 2: Penugasan per User ── -->
    <div class="tab-pane fade" id="tab-assign">
      <div class="row g-3">

        <!-- Form assign -->
        <div class="col-lg-4">
          <div class="card">
            <div class="card-header"><h6 class="mb-0">👤 Tugaskan Shift ke User</h6></div>
            <div class="card-body">
              <p class="small text-muted">
                User yang tidak ditugaskan akan memakai <b>shift default</b>.
                Bisa menambahkan beberapa baris untuk satu user (misal: ganti shift mulai tanggal tertentu).
              </p>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= $h(csrf_token()) ?>">
                <input type="hidden" name="mode"  value="assign_user">

                <div class="mb-3">
                  <label class="form-label fw-semibold">Username</label>
                  <?php if ($userList): ?>
                    <select name="username" class="form-select form-select-sm" required>
                      <option value="">— Pilih User —</option>
                      <?php foreach ($userList as $u): ?>
                        <option value="<?= $h($u['username']) ?>">
                          <?= $h($u['username']) ?><?= $u['full_name'] ? ' — '.$h($u['full_name']) : '' ?>
                          <?= $u['department'] ? ' ('.$h($u['department']).')' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <input type="text" class="form-control form-control-sm" name="username"
                           placeholder="username" required>
                  <?php endif; ?>
                </div>

                <div class="mb-3">
                  <label class="form-label fw-semibold">Shift</label>
                  <select name="shift_id" class="form-select form-select-sm" required>
                    <option value="">— Pilih Shift —</option>
                    <?php foreach ($shifts as $s): ?>
                      <?php if (!$s['is_active']) continue; ?>
                      <option value="<?= (int)$s['id'] ?>">
                        <?= $h($s['shift_name']) ?>
                        (<?= $h(absensi_shift_fmt($s['checkin_time'])) ?>–<?= $h(absensi_shift_fmt($s['checkout_time'])) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="form-label">Mulai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="effective_date"
                           value="<?= date('Y-m-d') ?>" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label">Sampai <span class="text-muted">(kosong=permanen)</span></label>
                    <input type="date" class="form-control form-control-sm" name="end_date">
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label">Keterangan</label>
                  <input type="text" class="form-control form-control-sm" name="note" placeholder="Opsional">
                </div>

                <button type="submit" class="btn btn-primary btn-sm px-3">Tugaskan</button>
              </form>
            </div>
          </div>
        </div>

        <!-- Tabel penugasan -->
        <div class="col-lg-8">
          <div class="card">
            <div class="card-header"><h6 class="mb-0">📋 Penugasan Shift per User (<?= count($userShifts) ?>)</h6></div>
            <div class="card-body p-0">
              <?php if (empty($userShifts)): ?>
                <p class="p-3 text-muted mb-0">
                  Belum ada penugasan spesifik. Semua user menggunakan <b>shift default</b>.
                </p>
              <?php else: ?>
              <table class="table table-sm table-hover mb-0">
                <thead class="table-dark">
                  <tr>
                    <th>User</th>
                    <th>Shift</th>
                    <th>Mulai</th>
                    <th>Sampai</th>
                    <th>Ket.</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($userShifts as $us): ?>
                  <tr>
                    <td class="fw-semibold"><?= $h($us['username']) ?></td>
                    <td>
                      <?= $h($us['shift_name']) ?>
                      <div class="text-muted" style="font-size:11px">
                        <?= $h(absensi_shift_fmt($us['checkin_time'])) ?>–<?= $h(absensi_shift_fmt($us['checkout_time'])) ?>
                      </div>
                    </td>
                    <td><?= $h($us['effective_date']) ?></td>
                    <td><?= $us['end_date'] ? $h($us['end_date']) : '<span class="text-muted">Permanen</span>' ?></td>
                    <td class="text-muted small"><?= $h($us['note'] ?? '') ?></td>
                    <td>
                      <form method="post" class="d-inline"
                            onsubmit="return confirm('Hapus penugasan ini?')">
                        <input type="hidden" name="_csrf"   value="<?= $h(csrf_token()) ?>">
                        <input type="hidden" name="mode"   value="remove_user_shift">
                        <input type="hidden" name="row_id" value="<?= (int)$us['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger">Hapus</button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div><!-- /tab-assign -->

  </div><!-- /tab-content -->
</div>

<script>
function editShift(s) {
  document.getElementById('editShiftId').value   = s.id;
  document.getElementById('editShiftName').value = s.shift_name;
  document.getElementById('editCin').value        = s.checkin_time.substring(0,5);
  document.getElementById('editCout').value       = s.checkout_time.substring(0,5);
  document.getElementById('editTol').value        = s.late_tolerance_min;
  document.getElementById('editOt').value         = s.overtime_threshold_min;
  document.getElementById('editOvernight').checked = s.is_overnight == 1;
  document.getElementById('editActive').checked    = s.is_active == 1;
  document.getElementById('editNote').value        = s.note || '';
  document.querySelector('#formShift button[type=submit]').textContent = 'Update Shift';
  document.querySelector('#tab-shifts').scrollIntoView({behavior:'smooth'});
}

function resetShiftForm() {
  document.getElementById('editShiftId').value = '0';
  document.getElementById('formShift').reset();
  document.querySelector('#formShift button[type=submit]').textContent = 'Simpan Shift';
}

// Aktifkan tab dari URL hash
const hash = window.location.hash;
if (hash === '#tab-assign') {
  document.querySelector('[href="#tab-assign"]').click();
}
</script>

<style>
.btn-xs { padding: 2px 8px; font-size: 12px; }
</style>

<?php require_once __DIR__ . "/../_layout_bottom.php"; ?>
