<?php
$title = "Check-out";
require_once __DIR__ . "/_inc/bootstrap.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.CHECKIN');

$uid = (int)$ABS_USER['id'];
$uname = (string)$ABS_USER['username'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify_or_die();

  $office = $ABS_USER['office_code'] ?: 'DEFAULT';

  $geoLat = isset($_POST['geo_lat']) ? (float)$_POST['geo_lat'] : null;
  $geoLng = isset($_POST['geo_lng']) ? (float)$_POST['geo_lng'] : null;
  $geoAcc = isset($_POST['geo_acc']) ? (int)$_POST['geo_acc'] : null;

  $geo = absensi_geofence_check($pdo, $office, $geoLat, $geoLng, $geoAcc);
  absensi_geofence_or_die($geo);

  $photoPath = null;
  $dataUri = trim((string)($_POST['photo_data'] ?? ''));
  if ($dataUri !== '') {
    [$ok, $p, $err] = absensi_save_photo_datauri($dataUri, $uid);
    if (!$ok) { absensi_flash_set('bad',$err ?: 'Gagal foto'); rmi_redirect('checkout.php'); }
    $photoPath = $p;
  } else {
    [$ok, $p, $err] = absensi_save_photo_file('photo_file', $uid);
    if (!$ok) { absensi_flash_set('bad',$err ?: 'Gagal foto'); rmi_redirect('checkout.php'); }
    $photoPath = $p;
  }

  // Watermark stamp
  if ($photoPath) {
    absensi_apply_watermark($photoPath, [
      'action'   => 'CHECK-OUT',
      'username' => $uname,
      'office'   => $office,
      'lat'      => $geoLat,
      'lng'      => $geoLng,
    ]);
  }

  $stmt = $pdo->prepare("INSERT INTO absensi_logs
    (user_id, username, action_type, office_code, distance_m, geo_lat, geo_lng, geo_acc, photo_path, ip, user_agent, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
  $stmt->execute([
    $uid, $uname, 'OUT', $office,
    $geo['distance_m']===null?null:(int)round((float)$geo['distance_m']),
    $geoLat, $geoLng, $geoAcc,
    $photoPath,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  ]);

  $logId = (int)$pdo->lastInsertId();
  absensi_audit($pdo, $ABS_USER, 'CHECKOUT', ['office'=>$office,'geo'=>$geo]);
  if (function_exists('master_audit')) {
    master_audit($pdo, 'absensi', 'absensi_logs', 'CHECKOUT', $logId, (string)$ABS_USER['username'], "Absensi check-out: {$office}", []);
  }
  absensi_flash_set('ok','Check-out berhasil.');
  rmi_redirect('index.php');
}

require_once __DIR__ . '/_layout_top.php';
?>
<style>
.abs-form-wrap{max-width:420px;margin:0 auto}
.abs-card{background:var(--rmi-card,rgba(255,255,255,.06));border:1px solid var(--rmi-border,rgba(255,255,255,.12));border-radius:16px;padding:20px}
.abs-title{font-size:18px;font-weight:700;margin-bottom:4px}
.abs-sub{font-size:12px;color:var(--rmi-muted,#9ca3af);margin-bottom:14px}
.gps-bar{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:14px;background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);color:#fbbf24}
.gps-bar.ok{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.3);color:#4ade80}
.gps-bar.err{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#f87171}
.gps-dot{width:8px;height:8px;border-radius:50%;background:currentColor;flex-shrink:0;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.gps-bar.ok .gps-dot,.gps-bar.err .gps-dot{animation:none}
.photo-preview{width:100%;max-height:260px;object-fit:cover;border-radius:12px;border:3px solid rgba(59,130,246,.5);margin-bottom:12px;display:none}
.btn-cam{width:100%;padding:22px 16px;border-radius:14px;font-size:16px;font-weight:700;border:2px solid rgba(59,130,246,.4);background:rgba(59,130,246,.08);color:#60a5fa;cursor:pointer;text-align:center;transition:all .2s;display:block;margin-bottom:12px}
.btn-cam:hover{background:rgba(59,130,246,.15);border-color:#3b82f6}
.btn-cam input[type=file]{display:none}
.btn-cam.taken{border-color:rgba(59,130,246,.7);background:rgba(59,130,246,.12)}
.btn-submit{width:100%;padding:15px;border-radius:12px;font-size:16px;font-weight:700;background:#3b82f6;color:#fff;border:none;cursor:pointer;transition:all .2s;margin-top:4px}
.btn-submit:disabled{opacity:.45;cursor:not-allowed}
.btn-submit:hover:not(:disabled){background:#2563eb}
.btn-cancel{display:block;text-align:center;color:var(--rmi-muted,#9ca3af);font-size:13px;margin-top:10px;text-decoration:none}
.realtime-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3);border-radius:8px;font-size:11px;font-weight:600;padding:3px 10px;margin-bottom:12px}
</style>

<div class="abs-form-wrap">
  <div class="abs-card">
    <div class="abs-title">📸 Foto Check-out</div>
    <div class="abs-sub">Foto diambil secara <strong>real-time</strong> via kamera — tidak bisa upload dari galeri.</div>
    <div class="realtime-badge">🔴 LIVE &nbsp;Real-time Only</div>

    <div class="gps-bar" id="gpsBar">
      <span class="gps-dot"></span>
      <span id="gpsText">Mendeteksi lokasi GPS...</span>
    </div>

    <form method="post" enctype="multipart/form-data" id="checkoutForm"
          onsubmit="return absensiEnsurePhotoBeforeSubmit(event, 'photo_data', 'photoFile')">
      <?= csrf_field() ?>
      <input type="hidden" name="photo_data" id="photo_data" value="">
      <input type="hidden" name="geo_lat" id="geo_lat">
      <input type="hidden" name="geo_lng" id="geo_lng">
      <input type="hidden" name="geo_acc" id="geo_acc">

      <img id="photoPreview" class="photo-preview" alt="Preview foto">

      <label class="btn-cam" id="camLabel">
        <div style="font-size:36px;margin-bottom:8px">📷</div>
        <div id="camLabelText">Tap untuk Ambil Foto Selfie</div>
        <div style="font-size:11px;opacity:.6;margin-top:4px">Kamera depan akan terbuka otomatis</div>
        <input type="file" name="photo_file" id="photoFile"
               accept="image/*" capture="user"
               onchange="onPhotoTaken(this)">
      </label>

      <button type="submit" class="btn-submit" id="btnSubmit" disabled>✓ Submit Check-out</button>
      <a class="btn-cancel" href="index.php">Batal</a>
    </form>
  </div>
</div>

<script src="<?= htmlspecialchars($ABS_BASE_H, ENT_QUOTES, 'UTF-8') ?>/assets/absensi_photo_capture.js?v=1"></script>
<script>
const gpsBar = document.getElementById('gpsBar');
const gpsText = document.getElementById('gpsText');
const btnSubmit = document.getElementById('btnSubmit');
const MAX_GPS_ACCURACY_M = 40;
function setGPS(lat, lng, acc) {
  document.getElementById('geo_lat').value = lat;
  document.getElementById('geo_lng').value = lng;
  document.getElementById('geo_acc').value = acc;
  if (!Number.isFinite(acc) || acc <= 0 || acc > MAX_GPS_ACCURACY_M) {
    gpsBar.className = 'gps-bar err';
    gpsText.textContent = 'Akurasi GPS belum cukup baik (±' + Math.round(acc || 0) + 'm). Maksimum ±' + MAX_GPS_ACCURACY_M + 'm.';
    if (btnSubmit) btnSubmit.disabled = true;
    return;
  }
  gpsBar.className = 'gps-bar ok';
  gpsText.textContent = 'Lokasi terdeteksi ✓ (±' + Math.round(acc) + 'm)';
  if (btnSubmit) btnSubmit.disabled = false;
}
function gpsError() {
  document.getElementById('geo_lat').value = '';
  document.getElementById('geo_lng').value = '';
  document.getElementById('geo_acc').value = '';
  gpsBar.className = 'gps-bar err';
  gpsText.textContent = 'GPS wajib untuk absensi. Aktifkan lokasi lalu coba kembali.';
  if (btnSubmit) btnSubmit.disabled = true;
}
if (navigator.geolocation) {
  navigator.geolocation.getCurrentPosition(
    p => setGPS(p.coords.latitude, p.coords.longitude, p.coords.accuracy),
    gpsError, {timeout:10000, enableHighAccuracy:true}
  );
} else { gpsError(); }

function onPhotoTaken(input) {
  if (typeof absensiPhotoFromFile === 'function') {
    absensiPhotoFromFile(input, {
      photoDataId: 'photo_data',
      previewId: 'photoPreview',
      camLabelId: 'camLabel',
      camLabelTextId: 'camLabelText',
    });
  }
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      p => setGPS(p.coords.latitude, p.coords.longitude, p.coords.accuracy), gpsError
    );
  }
}
</script>
<?php require_once __DIR__ . "/_layout_bottom.php"; ?>
