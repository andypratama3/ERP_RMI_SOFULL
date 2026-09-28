<?php
$title="Izin / Sakit / Dinas Luar";
require_once __DIR__ . "/_inc/bootstrap.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.REQUEST');

require_once __DIR__ . "/_layout_top.php";


$uid = (int)$ABS_USER['id'];
$uname = (string)$ABS_USER['username'];

// Enterprise+++ compatibility: support older schemas that don't have user_id yet
$reqCols = absensi_columns($pdo,'absensi_requests');
$HAS_REQ_USER_ID = isset($reqCols['user_id']);


if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify_or_die();
  $type = strtoupper(trim((string)($_POST['req_type'] ?? 'IZIN')));
  if (!in_array($type, ['IZIN','SAKIT','DINAS'], true)) $type = 'IZIN';

  $start = (string)($_POST['start_date'] ?? date('Y-m-d'));
  $end = (string)($_POST['end_date'] ?? $start);
  $reason = trim((string)($_POST['reason'] ?? ''));

  $photoPath = null;
  $dataUri = trim((string)($_POST['photo_data'] ?? ''));
  if ($dataUri !== '') {
    [$ok, $p, $err] = absensi_save_photo_datauri($dataUri, $uid);
    if ($ok) $photoPath = $p;
  } else if (!empty($_FILES['photo_file']) && $_FILES['photo_file']['error']===UPLOAD_ERR_OK) {
    // Static scan: sanitize original filename (we still store randomized server filename)
    $__orig_name = (string)($_FILES['photo_file']['name'] ?? '');
    if (function_exists('safe_filename')) {
      safe_filename($__orig_name);
    } elseif (function_exists('rmi_safe_filename')) {
      rmi_safe_filename($__orig_name);
    }
    [$ok, $p, $err] = absensi_save_photo_file('photo_file', $uid);
    if ($ok) $photoPath = $p;
  }

  if ($HAS_REQ_USER_ID) {
  $stmt = $pdo->prepare("INSERT INTO absensi_requests
    (user_id, username, req_type, start_date, end_date, reason, photo_path, status, created_at)
    VALUES (?,?,?,?,?,?,?, 'PENDING', NOW())");
  $stmt->execute([$uid,$uname,$type,$start,$end,$reason,$photoPath]);
} else {
  // Older DB: no user_id column yet
  $stmt = $pdo->prepare("INSERT INTO absensi_requests
    (username, req_type, start_date, end_date, reason, photo_path, status, created_at)
    VALUES (?,?,?,?,?,?, 'PENDING', NOW())");
  $stmt->execute([$uname,$type,$start,$end,$reason,$photoPath]);
}
$reqId = (int)$pdo->lastInsertId();
absensi_audit($pdo, $ABS_USER, 'REQUEST_CREATE', ['type'=>$type,'start'=>$start,'end'=>$end]);
if (function_exists('master_audit')) {
  master_audit($pdo, 'absensi', 'absensi_requests', 'REQUEST_CREATE', $reqId, (string)$ABS_USER['username'], "Absensi request: {$type} {$start}-{$end}", []);
}
  absensi_flash_set('ok','Request terkirim. Menunggu approval HR.');
  rmi_redirect('request.php');
}

if ($HAS_REQ_USER_ID) {
  $stmt = $pdo->prepare("SELECT * FROM absensi_requests WHERE user_id=? ORDER BY id DESC LIMIT 50");
  $stmt->execute([$uid]);
} else {
  $stmt = $pdo->prepare("SELECT * FROM absensi_requests WHERE username=? ORDER BY id DESC LIMIT 50");
  $stmt->execute([$uname]);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="grid">
  <div class="card col-6">
    <div class="h1">Buat Request</div>
    <form method="post" enctype="multipart/form-data" style="margin-top:10px">
      <?= csrf_field() ?>
      <label class="muted small">Tipe</label>
      <select name="req_type">
        <option value="IZIN">Izin</option>
        <option value="SAKIT">Sakit</option>
        <option value="DINAS">Dinas Luar</option>
      </select>

      <div class="row" style="margin-top:10px">
        <div style="flex:1">
          <label class="muted small">Mulai</label>
          <input type="date" name="start_date" value="<?= htmlspecialchars(date('Y-m-d'),ENT_QUOTES,'UTF-8') ?>">
        </div>
        <div style="flex:1">
          <label class="muted small">Sampai</label>
          <input type="date" name="end_date" value="<?= htmlspecialchars(date('Y-m-d'),ENT_QUOTES,'UTF-8') ?>">
        </div>
      </div>

      <label class="muted small" style="margin-top:10px">Alasan</label>
      <textarea name="reason" placeholder="Contoh: sakit demam / izin keluarga / dinas ke customer..."></textarea>

      <div class="muted small" style="margin-top:10px">Bukti foto (opsional):</div>
      <input type="hidden" name="photo_data" id="photo_data">
      <video id="v" class="preview" playsinline style="margin-top:8px"></video>
      <canvas id="c" style="display:none"></canvas>

      <div class="row" style="margin-top:10px">
        <button type="button" class="btn primary" id="btnCam">Kamera</button>
        <button type="button" class="btn ok" id="btnSnap">Ambil</button>
        <button type="submit" class="btn ok">Kirim</button>
      </div>

      <div class="muted small">Atau upload file:</div>
      <input type="file" name="photo_file" accept="image/*" capture="user">
    </form>
  </div>

  <div class="card col-6">
    <div class="h1">Riwayat Request</div>
    <table class="table" style="margin-top:10px">
      <thead><tr><th>Tanggal</th><th>Tipe</th><th>Rentang</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['created_at'],ENT_QUOTES,'UTF-8') ?></td>
            <td><?= htmlspecialchars($r['req_type'],ENT_QUOTES,'UTF-8') ?></td>
            <td><?= htmlspecialchars($r['start_date'].' → '.$r['end_date'],ENT_QUOTES,'UTF-8') ?></td>
            <td><?= htmlspecialchars($r['status'],ENT_QUOTES,'UTF-8') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="assets/camera.js"></script>
<script>
let stream=null;
const v=document.getElementById('v');
const c=document.getElementById('c');
document.getElementById('btnCam').addEventListener('click', async ()=>{
  try{ stream = await initCamera(v); }catch(e){ alert('Kamera tidak bisa diakses.'); }
});
document.getElementById('btnSnap').addEventListener('click', async ()=>{
  try{
    if(!stream){ stream = await initCamera(v); }
    const data = captureToDataURL(v,c);
    document.getElementById('photo_data').value = data;
    alert('Foto siap. Klik Kirim.');
  }catch(e){ alert('Gagal ambil foto.'); }
});
</script>
<?php require_once __DIR__ . "/_layout_bottom.php"; ?>
