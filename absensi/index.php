<?php
$title = "Dashboard Absensi";
require_once __DIR__ . "/_inc/bootstrap.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.VIEW');

require_once __DIR__ . "/_layout_top.php";

$uid = (int)$ABS_USER['id'];
$today = date('Y-m-d');

$stmt = $pdo->prepare("SELECT action_type, created_at, photo_path, office_code, distance_m
                       FROM absensi_logs
                       WHERE user_id=? AND DATE(created_at)=?
                       ORDER BY id DESC");
$stmt->execute([$uid, $today]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lastIn = null; $lastOut = null;
foreach ($rows as $r) {
  if ($r['action_type']==='IN' && !$lastIn) $lastIn = $r;
  if ($r['action_type']==='OUT' && !$lastOut) $lastOut = $r;
  if ($lastIn && $lastOut) break;
}

$canCheckin = ($lastIn === null);
$canCheckout = ($lastIn !== null && $lastOut === null);
$cnt = count($rows);
?>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="rmi-card p-3 h-100">
      <div class="rmi-muted small">Aktivitas Hari Ini</div>
      <div class="fs-2 fw-bold"><?= (int)$cnt ?></div>
      <div class="rmi-muted small"><?= htmlspecialchars($today, ENT_QUOTES,'UTF-8') ?></div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="rmi-card p-3 h-100">
      <div class="rmi-muted small">Check-in</div>
      <div class="fs-5 fw-bold">
        <?= $lastIn ? htmlspecialchars(substr($lastIn['created_at'], 11, 8), ENT_QUOTES,'UTF-8') : '—' ?>
      </div>
      <?php if ($lastIn): ?>
        <div class="rmi-muted small">
          <?= htmlspecialchars($lastIn['office_code'] ?? '', ENT_QUOTES,'UTF-8') ?>
          <?php if (!empty($lastIn['distance_m'])): ?>
            · <?= number_format((float)$lastIn['distance_m'], 0) ?>m
          <?php endif; ?>
        </div>
        <?php if (!empty($lastIn['photo_path'])): ?>
          <img src="<?= htmlspecialchars($ABS_BASE_H . '/photo.php?f=' . ltrim($lastIn['photo_path'],'/'), ENT_QUOTES,'UTF-8') ?>" alt="Checkin"
               style="width:100%;max-height:180px;object-fit:cover;border-radius:12px;border:1px solid var(--rmi-border);margin-top:8px">
        <?php endif; ?>
      <?php else: ?>
        <div class="rmi-muted small">Belum check-in hari ini</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-md-4">
    <div class="rmi-card p-3 h-100">
      <div class="rmi-muted small">Check-out</div>
      <div class="fs-5 fw-bold">
        <?= $lastOut ? htmlspecialchars(substr($lastOut['created_at'], 11, 8), ENT_QUOTES,'UTF-8') : '—' ?>
      </div>
      <?php if ($lastOut): ?>
        <div class="rmi-muted small">
          <?= htmlspecialchars($lastOut['office_code'] ?? '', ENT_QUOTES,'UTF-8') ?>
          <?php if (!empty($lastOut['distance_m'])): ?>
            · <?= number_format((float)$lastOut['distance_m'], 0) ?>m
          <?php endif; ?>
        </div>
        <?php if (!empty($lastOut['photo_path'])): ?>
          <img src="<?= htmlspecialchars($ABS_BASE_H . '/photo.php?f=' . ltrim($lastOut['photo_path'],'/'), ENT_QUOTES,'UTF-8') ?>" alt="Checkout"
               style="width:100%;max-height:180px;object-fit:cover;border-radius:12px;border:1px solid var(--rmi-border);margin-top:8px">
        <?php endif; ?>
      <?php else: ?>
        <div class="rmi-muted small">Belum check-out</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Aksi Cepat -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Aksi Cepat</div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($canCheckin): ?>
      <a class="btn btn-rmi btn-sm" href="checkin.php">Check-in</a>
    <?php else: ?>
      <span class="btn btn-sm btn-outline-light disabled">Sudah Check-in</span>
    <?php endif; ?>

    <?php if ($canCheckout): ?>
      <a class="btn btn-rmi btn-sm" href="checkout.php">Check-out</a>
    <?php else: ?>
      <span class="btn btn-sm btn-outline-light disabled">Belum bisa Check-out</span>
    <?php endif; ?>

    <a class="btn btn-sm btn-outline-light" href="history.php">Riwayat</a>
    <a class="btn btn-sm btn-outline-light" href="request.php">Izin / Dinas</a>
    <a class="btn btn-sm btn-outline-light" href="panduan.php">Panduan</a>

    <?php if (absensi_is_hr_admin($pdo, $ABS_USER)): ?>
      <a class="btn btn-sm btn-outline-light" href="admin/rekap.php">Rekap HR</a>
      <a class="btn btn-sm btn-outline-light" href="admin/approval.php">Approval</a>
      <a class="btn btn-sm btn-outline-light" href="admin/offices.php">Office Settings</a>
      <a class="btn btn-sm btn-outline-light" href="admin/users.php">User Settings</a>
    <?php endif; ?>
  </div>
  <div class="rmi-muted small mt-2">
    Jika GeoFence aktif, check-in/out akan ditolak bila di luar radius kantor (titik dari Google Maps).
  </div>
</div>

<?php require_once __DIR__ . "/_layout_bottom.php"; ?>
