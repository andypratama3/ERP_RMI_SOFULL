<?php
declare(strict_types=1);
/**
 * Kiosk Absensi — Fixed-point attendance
 * Akses: ?token=<kiosk_token> (tanpa login ERP)
 * Titik GPS = koordinat kantor yang sudah disimpan admin → tidak bergantung GPS HP karyawan
 */
define('ABSENSI_BOOTSTRAP_PUBLIC_KIOSK', true);
require_once __DIR__ . "/_inc/bootstrap.php";

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(404);
    exit('<h2 style="font-family:sans-serif;padding:40px">Kiosk tidak valid. Hubungi admin untuk mendapatkan link kiosk.</h2>');
}

// Cari office berdasarkan token
$stO = $pdo->prepare("SELECT * FROM absensi_offices WHERE kiosk_token = ? AND is_active = 1 LIMIT 1");
$stO->execute([$token]);
$office = $stO->fetch(PDO::FETCH_ASSOC);
if (!$office) {
    http_response_code(403);
    exit('<h2 style="font-family:sans-serif;padding:40px">Token kiosk tidak dikenal atau sudah dinonaktifkan.</h2>');
}

$officeCode = $office['office_code'];
$officeName = $office['office_name'];
$officeLat  = ($office['lat']  !== null && $office['lat']  != 0) ? (float)$office['lat']  : null;
$officeLng  = ($office['lng']  !== null && $office['lng']  != 0) ? (float)$office['lng']  : null;

$flash = ['type' => '', 'msg' => ''];
$step  = 'identify'; // identify → photo → done

// ─── POST handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    // Step 1: identifikasi karyawan via username+password
    if ($mode === 'identify') {
        csrf_verify_or_die();
        $inputUser = trim((string)($_POST['kiosk_username'] ?? ''));
        $inputPass = (string)($_POST['kiosk_password'] ?? '');

        if ($inputUser === '' || $inputPass === '') {
            $flash = ['type'=>'bad', 'msg'=>'Username dan password wajib diisi.'];
            $step  = 'identify';
        } else {
            // Verifikasi via tabel users ERP (gunakan fungsi auth yang ada)
            try {
                $stU = $pdo->prepare("SELECT id, username, password, status FROM users WHERE username = ? LIMIT 1");
                $stU->execute([$inputUser]);
                $uRow = $stU->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $uRow = null;
            }

            $authOk = false;
            if ($uRow && isset($uRow['status']) && in_array((string)$uRow['status'], ['active','1'], true)) {
                if (password_verify($inputPass, (string)$uRow['password'])) {
                    $authOk = true;
                } elseif ((string)$uRow['password'] === md5($inputPass)) {
                    $authOk = true; // legacy md5
                }
            }

            if (!$authOk) {
                $flash = ['type'=>'bad', 'msg'=>'Username atau password salah.'];
                $step  = 'identify';
            } else {
                // Simpan ke session kiosk
                session_start();
                $_SESSION['kiosk_uid']   = (int)$uRow['id'];
                $_SESSION['kiosk_uname'] = (string)$uRow['username'];
                $_SESSION['kiosk_token'] = $token;
                $step = 'photo';
            }
        }
    }

    // Step 2: submit foto & rekam absensi
    elseif ($mode === 'submit_photo') {
        csrf_verify_or_die();
        session_start();

        if (($_SESSION['kiosk_token'] ?? '') !== $token) {
            $flash = ['type'=>'bad','msg'=>'Sesi tidak valid, ulangi dari awal.'];
            $step  = 'identify';
        } else {
            $uid   = (int)$_SESSION['kiosk_uid'];
            $uname = (string)$_SESSION['kiosk_uname'];
            $action = strtoupper(trim((string)($_POST['action_type'] ?? 'IN')));
            if (!in_array($action, ['IN','OUT'], true)) $action = 'IN';

            // Simpan foto
            $photoPath = null;
            $dataUri = trim((string)($_POST['photo_data'] ?? ''));
            if ($dataUri !== '') {
                [$ok, $p, $err] = absensi_save_photo_datauri($dataUri, $uid);
                if (!$ok) {
                    $flash = ['type'=>'bad','msg'=>$err ?: 'Gagal menyimpan foto.'];
                    $step  = 'photo';
                    goto render;
                }
                $photoPath = $p;
            }

            // Watermark dengan koordinat kantor
            if ($photoPath) {
                absensi_apply_watermark($photoPath, [
                    'action'   => $action === 'IN' ? 'KIOSK CHECK-IN' : 'KIOSK CHECK-OUT',
                    'username' => $uname,
                    'office'   => $officeName,
                    'lat'      => $officeLat,
                    'lng'      => $officeLng,
                ]);
            }

            // Simpan log — distance_m = 0 (titik kantor), kiosk_mode = 1
            try {
                $st = $pdo->prepare("INSERT INTO absensi_logs
                    (user_id, username, action_type, office_code, distance_m, geo_lat, geo_lng, geo_acc, kiosk_mode, photo_path, ip, user_agent, created_at)
                    VALUES (?,?,?,?,0,?,?,NULL,1,?,?,?,NOW())");
                $st->execute([
                    $uid, $uname, $action, $officeCode,
                    $officeLat, $officeLng,
                    $photoPath,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
                $logId = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                $flash = ['type'=>'bad','msg'=>'Gagal menyimpan absensi: '.$e->getMessage()];
                $step  = 'photo';
                goto render;
            }

            // Audit
            try {
                absensi_audit($pdo, ['id'=>$uid,'username'=>$uname], 'KIOSK_'.$action, [
                    'office' => $officeCode, 'kiosk_token' => substr($token, 0, 8).'***',
                ]);
            } catch (Throwable $e) {}

            // Hapus session kiosk
            unset($_SESSION['kiosk_uid'], $_SESSION['kiosk_uname'], $_SESSION['kiosk_token']);

            $step  = 'done';
            $flash = ['type'=>'ok','msg'=>($action==='IN'?'✅ Check-in':'✅ Check-out')." berhasil! Selamat bekerja, {$uname}."];
        }
    }
}

// Cek session yang sudah ada (jika POST gagal di step photo)
if ($step === 'identify') {
    session_start();
    if (!empty($_SESSION['kiosk_uid']) && ($_SESSION['kiosk_token'] ?? '') === $token) {
        $step = 'photo';
    }
}

render:
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<title>Kiosk Absensi — <?= htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0f172a;--card:#1e293b;--border:rgba(255,255,255,.1);
  --text:#f1f5f9;--muted:#94a3b8;--green:#22c55e;--red:#ef4444;--blue:#38bdf8;
}
body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100dvh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px}
.wrap{width:100%;max-width:420px}
.kiosk-header{text-align:center;margin-bottom:24px}
.kiosk-logo{font-size:48px;margin-bottom:8px}
.kiosk-office{font-size:22px;font-weight:800;letter-spacing:-.5px}
.kiosk-sub{font-size:13px;color:var(--muted);margin-top:4px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:24px}
.label{font-size:12px;color:var(--muted);margin-bottom:6px;display:block;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
input[type=text],input[type=password]{
  width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);
  border-radius:12px;padding:14px 16px;font-size:16px;color:var(--text);outline:none;
  transition:border-color .15s;-webkit-appearance:none
}
input:focus{border-color:var(--blue)}
.field{margin-bottom:16px}
.btn{width:100%;padding:16px;border-radius:14px;font-size:16px;font-weight:700;border:none;cursor:pointer;transition:all .2s;margin-top:4px}
.btn-primary{background:var(--green);color:#fff}
.btn-primary:hover{background:#16a34a}
.btn-danger{background:var(--red);color:#fff}
.btn-outline{background:transparent;border:1.5px solid var(--border);color:var(--muted)}
.btn-outline:hover{border-color:var(--blue);color:var(--blue)}
.flash{padding:12px 16px;border-radius:12px;font-size:14px;font-weight:600;margin-bottom:16px;text-align:center}
.flash.ok{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.flash.bad{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#f87171}
.action-tabs{display:flex;gap:8px;margin-bottom:20px}
.action-tab{flex:1;padding:12px;border-radius:12px;border:2px solid var(--border);background:transparent;font-size:15px;font-weight:700;color:var(--muted);cursor:pointer;transition:all .2s;text-align:center}
.action-tab.in.active{border-color:var(--green);color:var(--green);background:rgba(34,197,94,.1)}
.action-tab.out.active{border-color:var(--red);color:var(--red);background:rgba(239,68,68,.1)}
.photo-preview{width:100%;max-height:240px;object-fit:cover;border-radius:14px;border:3px solid rgba(34,197,94,.5);margin-bottom:14px;display:none}
.btn-cam{width:100%;padding:24px 16px;border-radius:14px;font-size:15px;font-weight:700;border:2px solid rgba(34,197,94,.4);background:rgba(34,197,94,.07);color:#4ade80;cursor:pointer;text-align:center;margin-bottom:14px;display:block;transition:all .2s}
.btn-cam:hover{background:rgba(34,197,94,.14)}
.btn-cam input[type=file]{display:none}
.btn-cam.taken{border-color:rgba(34,197,94,.8);background:rgba(34,197,94,.13)}
.kiosk-clock{font-size:40px;font-weight:800;text-align:center;letter-spacing:2px;margin-bottom:4px}
.kiosk-date{text-align:center;color:var(--muted);font-size:13px;margin-bottom:20px}
.back-link{text-align:center;margin-top:14px;font-size:13px;color:var(--muted);cursor:pointer;text-decoration:underline}
.done-icon{font-size:72px;text-align:center;margin-bottom:12px}
.done-msg{font-size:18px;font-weight:700;text-align:center;margin-bottom:8px}
.done-sub{font-size:13px;color:var(--muted);text-align:center;margin-bottom:20px}
.countdown{font-size:12px;color:var(--muted);text-align:center;margin-top:14px}
input[type=hidden]{display:none}
</style>
<script src="assets/absensi_photo_capture.js?v=1"></script>
</head>
<body>
<div class="wrap">

  <div class="kiosk-header">
    <div class="kiosk-logo">🏢</div>
    <div class="kiosk-office"><?= htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="kiosk-sub">Kiosk Absensi · <?= htmlspecialchars($officeCode, ENT_QUOTES, 'UTF-8') ?></div>
  </div>

  <?php if ($flash['msg']): ?>
  <div class="flash <?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <!-- ── STEP: DONE ───────────────────────────────────── -->
  <?php if ($step === 'done'): ?>
  <div class="card">
    <div class="done-icon">✅</div>
    <div class="done-msg"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
    <div class="done-sub">Data absensi telah dicatat. Anda dapat menutup layar ini.</div>
    <button class="btn btn-outline" onclick="resetKiosk()">↩ Karyawan berikutnya</button>
    <div class="countdown" id="countdown">Otomatis reset dalam <b id="ctNum">10</b> detik...</div>
  </div>
  <script>
  let c = 10;
  const ct = document.getElementById('ctNum');
  const iv = setInterval(()=>{
    ct.textContent = --c;
    if(c<=0){ clearInterval(iv); resetKiosk(); }
  }, 1000);
  function resetKiosk(){ window.location.href = window.location.href.split('?')[0]+'?token=<?= urlencode($token) ?>'; }
  </script>

  <!-- ── STEP: PHOTO ──────────────────────────────────── -->
  <?php elseif ($step === 'photo'): ?>
  <?php
    session_start();
    $kioskUname = htmlspecialchars((string)($_SESSION['kiosk_uname'] ?? ''), ENT_QUOTES, 'UTF-8');
  ?>
  <div class="kiosk-clock" id="kioskClock">--:--:--</div>
  <div class="kiosk-date" id="kioskDate">-</div>

  <div class="card">
    <div style="font-size:13px;color:var(--muted);margin-bottom:14px;text-align:center">
      👤 <b><?= $kioskUname ?></b>
    </div>

    <!-- Pilih IN / OUT -->
    <div class="action-tabs">
      <button type="button" class="action-tab in active" id="tabIn"  onclick="setAction('IN')">🟢 Check-in</button>
      <button type="button" class="action-tab out"       id="tabOut" onclick="setAction('OUT')">🔴 Check-out</button>
    </div>

    <form method="post" id="kioskPhotoForm"
          onsubmit="return absensiEnsurePhotoBeforeSubmit(event, 'photo_data_k', 'photoFileK')">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="submit_photo">
      <input type="hidden" name="action_type" id="action_type" value="IN">
      <input type="hidden" name="photo_data" id="photo_data_k" value="">

      <img id="photoPreview" class="photo-preview" alt="Preview">

      <label class="btn-cam" id="camLabel">
        <div style="font-size:36px;margin-bottom:8px">📷</div>
        <div id="camLabelText">Tap untuk Ambil Foto Selfie</div>
        <div style="font-size:11px;opacity:.6;margin-top:4px">Kamera depan akan terbuka otomatis</div>
        <input type="file" id="photoFileK" accept="image/*" capture="user" onchange="onPhotoTaken(this)">
      </label>

      <button type="submit" class="btn btn-primary" id="btnSubmitK">✓ Submit Absensi</button>
    </form>

    <div class="back-link" onclick="resetKiosk()">↩ Bukan saya? Ganti karyawan</div>
  </div>

  <script>
  // Clock
  function tick(){
    const now = new Date();
    document.getElementById('kioskClock').textContent = now.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('kioskDate').textContent = now.toLocaleDateString('id-ID',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  }
  tick(); setInterval(tick, 1000);

  // Action toggle
  function setAction(v){
    document.getElementById('action_type').value = v;
    document.getElementById('tabIn').classList.toggle('active', v==='IN');
    document.getElementById('tabOut').classList.toggle('active', v==='OUT');
    const btn = document.getElementById('btnSubmitK');
    btn.textContent = v==='IN' ? '✓ Submit Check-in' : '✓ Submit Check-out';
    btn.style.background = v==='IN' ? 'var(--green)' : 'var(--red)';
  }

  // Foto → JPEG di photo_data_k (sama seperti checkin mobile)
  function onPhotoTaken(input){
    if (!input.files || !input.files[0]) return;
    if (typeof absensiPhotoFromFile === 'function') {
      absensiPhotoFromFile(input, {
        photoDataId: 'photo_data_k',
        previewId: 'photoPreview',
        camLabelId: 'camLabel',
        camLabelTextId: 'camLabelText',
      });
      return;
    }
    const reader = new FileReader();
    reader.onload = e => {
      const prev = document.getElementById('photoPreview');
      prev.src = e.target.result;
      prev.style.display = 'block';
      document.getElementById('photo_data_k').value = e.target.result;
      document.getElementById('camLabel').classList.add('taken');
      document.getElementById('camLabelText').textContent = '✓ Foto siap — tap untuk ambil ulang';
    };
    reader.readAsDataURL(input.files[0]);
  }

  function resetKiosk(){ window.location.href = window.location.href.split('?')[0]+'?token=<?= urlencode($token) ?>'; }
  </script>

  <!-- ── STEP: IDENTIFY ──────────────────────────────── -->
  <?php else: ?>
  <div class="kiosk-clock" id="kioskClock">--:--:--</div>
  <div class="kiosk-date" id="kioskDate">-</div>

  <div class="card">
    <div style="font-size:15px;font-weight:700;margin-bottom:4px">👤 Identifikasi Karyawan</div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:20px">Masukkan username & password ERP Anda</div>

    <form method="post" id="identifyForm">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="identify">

      <div class="field">
        <label class="label">Username</label>
        <input type="text" name="kiosk_username" autocomplete="username" placeholder="Masukkan username" autofocus required>
      </div>
      <div class="field">
        <label class="label">Password</label>
        <input type="password" name="kiosk_password" autocomplete="current-password" placeholder="••••••••" required>
      </div>

      <button type="submit" class="btn btn-primary">Lanjut →</button>
    </form>
  </div>

  <script>
  function tick(){
    const now = new Date();
    document.getElementById('kioskClock').textContent = now.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('kioskDate').textContent = now.toLocaleDateString('id-ID',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  }
  tick(); setInterval(tick, 1000);
  </script>
  <?php endif; ?>

</div>
</body>
</html>
