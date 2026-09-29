<?php
declare(strict_types=1);

$title = "Admin HR • Pengaturan Jam Kerja";
require_once __DIR__ . "/../_inc/bootstrap.php";
require_once __DIR__ . "/../../master/_audit_master.php";

if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.OFFICE_SETTINGS');

// Guard: hanya SYS / ADMIN / SUPERADMIN
$_sRole  = strtoupper(trim((string)($ABS_USER['role']  ?? '')));
$_sLevel = strtoupper(trim((string)($ABS_USER['level'] ?? '')));
$_isAdmin = in_array($_sRole,  ['SYS','ADMIN','SUPERADMIN'], true)
          || in_array($_sLevel, ['SYS','ADMIN','SUPERADMIN'], true);

if (!$_isAdmin) {
    require_once __DIR__ . "/../_layout_top.php";
    http_response_code(403);
    echo "<div class='alert alert-danger'><b>Akses ditolak.</b> Pengaturan jam kerja hanya untuk <b>SYS / ADMIN</b>.</div>";
    require_once __DIR__ . "/../_layout_bottom.php";
    exit;
}

// ── Ensure settings tersedia di DB ──────────────────────────────────────────
$defaults = [
    'checkin_std_time'   => '08:30',
    'checkout_std_time'  => '17:00',
    'late_tolerance_min' => '0',
    'geofence_enforce'   => '1',
];
foreach ($defaults as $k => $v) {
    $pdo->prepare("INSERT IGNORE INTO absensi_settings (k, v, updated_at) VALUES (?, ?, NOW())")
        ->execute([$k, $v]);
}

$errors = [];

// ── Handle POST (sebelum layout: redirect membutuhkan header belum terkirim) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $checkinTime  = trim((string)($_POST['checkin_std_time']   ?? '08:30'));
    $checkoutTime = trim((string)($_POST['checkout_std_time']  ?? '17:00'));
    $tolerance    = max(0, min(120, (int)($_POST['late_tolerance_min'] ?? 0)));
    $enforce      = (int)(bool)($_POST['geofence_enforce'] ?? 0);

    // Validasi format waktu HH:MM
    $errors = [];
    if (!preg_match('/^\d{2}:\d{2}$/', $checkinTime))  $errors[] = 'Format jam masuk tidak valid (HH:MM).';
    if (!preg_match('/^\d{2}:\d{2}$/', $checkoutTime)) $errors[] = 'Format jam pulang tidak valid (HH:MM).';
    if ($checkinTime >= $checkoutTime)                  $errors[] = 'Jam masuk harus lebih awal dari jam pulang.';

    if (empty($errors)) {
        $save = [
            'checkin_std_time'   => $checkinTime,
            'checkout_std_time'  => $checkoutTime,
            'late_tolerance_min' => (string)$tolerance,
            'geofence_enforce'   => (string)$enforce,
        ];
        $upd = $pdo->prepare("INSERT INTO absensi_settings (k, v, updated_at) VALUES (?, ?, NOW())
                               ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW()");
        foreach ($save as $k => $v) {
            $upd->execute([$k, $v]);
        }

        // Audit
        absensi_audit($pdo, $ABS_USER, 'WORKTIME_SETTINGS_SAVE', $save);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'absensi_admin', 'absensi_settings', 'WORKTIME_SETTINGS_SAVE',
                null, 'jam_kerja',
                "Jam kerja diperbarui: masuk={$checkinTime} pulang={$checkoutTime} toleransi={$tolerance}m geofence={$enforce}",
                $save);
        }

        absensi_flash_set('ok', "Pengaturan jam kerja berhasil disimpan.");
        rmi_redirect('settings.php');
    }
}

require_once __DIR__ . "/../_layout_top.php";

// ── Baca nilai form: DB (GET / sukses) atau ulang dari POST jika validasi gagal ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($errors)) {
    $checkinTime  = absensi_setting($pdo, 'checkin_std_time',   '08:30');
    $checkoutTime = absensi_setting($pdo, 'checkout_std_time',  '17:00');
    $tolerance    = (int)(absensi_setting($pdo, 'late_tolerance_min', '0') ?? '0');
    $enforce      = (int)(absensi_setting($pdo, 'geofence_enforce',   '1') ?? '1');
}

// Hitung durasi kerja untuk preview
$cinTs  = strtotime('2000-01-01 ' . $checkinTime);
$coutTs = strtotime('2000-01-01 ' . $checkoutTime);
$durMin = ($cinTs && $coutTs && $coutTs > $cinTs) ? (int)(($coutTs - $cinTs) / 60) : 0;
$durStr = ($durMin > 0) ? floor($durMin / 60) . ' jam ' . ($durMin % 60) . ' menit' : '-';
?>

<div class="container-fluid py-3">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="rekap.php">Admin HR</a></li>
      <li class="breadcrumb-item active">Pengaturan Jam Kerja</li>
    </ol>
  </nav>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <b>Gagal menyimpan:</b>
      <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- Form Utama -->
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0"><?=rmi_icon('gear')?> Jam Kerja &amp; Kehadiran</h5>
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <!-- Jam Masuk -->
            <div class="mb-3">
              <label class="form-label fw-semibold" for="checkin_std_time">
                Jam Masuk Standar
                <span class="text-muted fw-normal">(batas tepat waktu)</span>
              </label>
              <input type="time" class="form-control" id="checkin_std_time" name="checkin_std_time"
                     value="<?= htmlspecialchars($checkinTime, ENT_QUOTES, 'UTF-8') ?>" required>
              <div class="form-text">Check-in setelah jam ini dihitung <b>TERLAMBAT</b> di laporan rekap.</div>
            </div>

            <!-- Toleransi Keterlambatan -->
            <div class="mb-3">
              <label class="form-label fw-semibold" for="late_tolerance_min">
                Toleransi Keterlambatan
                <span class="text-muted fw-normal">(menit)</span>
              </label>
              <div class="input-group" style="max-width:220px">
                <input type="number" class="form-control" id="late_tolerance_min" name="late_tolerance_min"
                       min="0" max="120" value="<?= $tolerance ?>">
                <span class="input-group-text">menit</span>
              </div>
              <div class="form-text">
                Contoh: toleransi 10 menit → check-in s/d
                <b id="preview_tolerance"><?= htmlspecialchars($checkinTime, ENT_QUOTES, 'UTF-8') ?></b>
                masih dianggap tepat waktu.
                Set <b>0</b> untuk tanpa toleransi.
              </div>
            </div>

            <!-- Jam Pulang -->
            <div class="mb-3">
              <label class="form-label fw-semibold" for="checkout_std_time">
                Jam Pulang Standar
              </label>
              <input type="time" class="form-control" id="checkout_std_time" name="checkout_std_time"
                     value="<?= htmlspecialchars($checkoutTime, ENT_QUOTES, 'UTF-8') ?>" required>
              <div class="form-text">Digunakan untuk laporan durasi kerja. Checkout sebelum jam ini dianggap <b>pulang lebih awal</b>.</div>
            </div>

            <hr>

            <!-- Geofence -->
            <div class="mb-4">
              <label class="form-label fw-semibold">GeoFence Absensi</label>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="geofence_enforce" name="geofence_enforce" value="1"
                       <?= $enforce ? 'checked' : '' ?>>
                <label class="form-check-label" for="geofence_enforce">
                  Wajibkan GeoFence saat check-in &amp; check-out
                </label>
              </div>
              <div class="form-text">
                Jika aktif, user wajib berada dalam radius kantor untuk absensi.
                Koordinat radius diatur di <a href="offices.php">Pengaturan Office</a>.
              </div>
            </div>

                    <button type="submit" class="btn btn-primary px-4">
              Simpan Pengaturan
            </button>
            <a href="rekap.php" class="btn btn-outline-secondary ms-2">Batal</a>
            <a href="shifts.php" class="btn btn-outline-info ms-2"><?=rmi_icon('refresh')?> Kelola Shift & Lembur</a>
          </form>
        </div>
      </div>
    </div>

    <!-- Panel Ringkasan -->
    <div class="col-lg-5">

      <!-- Preview jam kerja aktif -->
      <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0"><?=rmi_icon('clipboard')?> Pengaturan Aktif Saat Ini</h6></div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <tbody>
              <tr>
                <td class="text-muted ps-3">Jam Masuk</td>
                <td class="fw-semibold"><?= htmlspecialchars($checkinTime, ENT_QUOTES, 'UTF-8') ?> WIB</td>
              </tr>
              <tr>
                <td class="text-muted ps-3">Toleransi</td>
                <td><?= $tolerance ?> menit</td>
              </tr>
              <tr>
                <td class="text-muted ps-3">Jam Pulang</td>
                <td class="fw-semibold"><?= htmlspecialchars($checkoutTime, ENT_QUOTES, 'UTF-8') ?> WIB</td>
              </tr>
              <tr>
                <td class="text-muted ps-3">Durasi Kerja</td>
                <td><?= htmlspecialchars($durStr, ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
              <tr>
                <td class="text-muted ps-3">GeoFence</td>
                <td>
                  <?php if ($enforce): ?>
                    <span class="badge bg-success">Aktif</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Nonaktif</span>
                  <?php endif; ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Info dampak perubahan -->
      <div class="card border-warning">
        <div class="card-header bg-warning bg-opacity-10">
          <h6 class="mb-0 text-warning-emphasis"><?=rmi_icon('warn')?> Catatan Penting</h6>
        </div>
        <div class="card-body small">
          <ul class="mb-0 ps-3">
            <li>Perubahan jam kerja <b>berlaku langsung</b> untuk laporan rekap berikutnya.</li>
            <li>Data absensi lama <b>tidak berubah</b> — status terlambat lama dihitung ulang saat laporan dibuka.</li>
            <li>Check-in user <b>tidak diblokir</b> berdasarkan jam — validasi hanya di laporan rekap.</li>
            <li>GeoFence berlaku per-office. Koordinat diatur di <a href="offices.php">Pengaturan Office</a>.</li>
          </ul>
        </div>
      </div>

    </div><!-- /col -->
  </div><!-- /row -->
</div>

<script>
// Preview toleransi: hitung jam + toleransi secara real-time
(function () {
  const cinEl  = document.getElementById('checkin_std_time');
  const tolEl  = document.getElementById('late_tolerance_min');
  const prevEl = document.getElementById('preview_tolerance');

  function update() {
    const t = cinEl.value;
    const tol = parseInt(tolEl.value, 10) || 0;
    if (!t || !/^\d{2}:\d{2}$/.test(t)) { prevEl.textContent = t || '--:--'; return; }
    const [h, m] = t.split(':').map(Number);
    const total = h * 60 + m + tol;
    const nh = String(Math.floor(total / 60) % 24).padStart(2, '0');
    const nm = String(total % 60).padStart(2, '0');
    prevEl.textContent = nh + ':' + nm;
  }

  cinEl.addEventListener('input', update);
  tolEl.addEventListener('input', update);
  update();
})();
</script>

<?php
require_once __DIR__ . "/../_layout_bottom.php";
