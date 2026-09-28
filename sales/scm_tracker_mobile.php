<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();

// Akses Tracker:
// - Admin/SYS dan Dept SCM mengikuti RBAC yang sudah ada.
// - Akun BRANCH diizinkan karena cabang juga dapat melakukan pengantaran.
// - BRANCH hanya boleh melihat / melacak DO milik officenya sendiri.
$trackerUser   = function_exists('auth_user') ? (array)auth_user() : [];
$trackerRole   = strtoupper(trim((string)($trackerUser['role'] ?? '')));
$trackerDept   = strtoupper(trim((string)(($trackerUser['department'] ?? '') ?: ($trackerUser['level'] ?? ''))));
$trackerOffice = strtoupper(trim((string)($trackerUser['office_code'] ?? '')));
$trackerIsBranch = ($trackerRole === 'BRANCH' || $trackerDept === 'BRANCH');
$trackerIsAdmin  = in_array($trackerRole, ['SYS','SUPERADMIN','ADMIN','OWNER'], true)
    || (function_exists('auth_is_admin') && auth_is_admin());

$allowed = $trackerIsAdmin || $trackerIsBranch;
if (!$allowed && function_exists('user_has_any_permission')) {
    $allowed = user_has_any_permission(['SCM.SHIPMENT.TRACK.UPDATE', 'SALES.EDIT', 'MASTER.ADMIN_CENTER']);
}
if (!$allowed) {
    require_role(['SCM', 'ADMIN', 'SUPERADMIN', 'SYS', 'MANAGER', 'BRANCH']);
}

require_once __DIR__ . '/../stock/_stock_office_helper.php';

$pdo = db_pdo();
if ($trackerIsBranch && $trackerOffice === '') {
    http_response_code(403);
    exit('Akses ditolak: akun BRANCH belum memiliki office_code. Lengkapi office akun terlebih dahulu.');
}
$preselectDoId = (int)($_GET['do_id'] ?? 0);
$isAndroidNative = stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'ERP-RMI-Android/') !== false;
$rows = [];
try {
    // FINAL FLOW: tracker hanya untuk DO yang SUDAH ON DELIVERY dan masih punya qty kirim.
    // FULL RETURN keluar dari tracker; PARTIAL RETURN tetap boleh lanjut.
    $fullReturnSql = '';
    try {
        $hasRetTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_returns'")->fetchColumn();
        $hasRetItemTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_return_items'")->fetchColumn();
        $hasDoItemTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_items'")->fetchColumn();
        if ($hasRetTbl && $hasRetItemTbl && $hasDoItemTbl) {
            $fullReturnSql = <<<'SQL'

        AND NOT (
            COALESCE((
                SELECT SUM(ri.qty_return)
                FROM sales_do_return_items ri
                JOIN sales_do_returns rr ON rr.id=ri.return_id
                WHERE rr.do_id=d.id
                  AND LOWER(COALESCE(rr.status,'')) IN ('return_scm_completed','return_completed','completed','closed')
            ),0) >= COALESCE((SELECT SUM(di.qty) FROM sales_do_items di WHERE di.do_id=d.id),0)
            AND COALESCE((SELECT SUM(di2.qty) FROM sales_do_items di2 WHERE di2.do_id=d.id),0) > 0
        )
SQL;
        }
    } catch (Throwable $e) {
        $fullReturnSql = '';
    }

    $trackerWhere = "LOWER(TRIM(d.status)) = 'on_delivery'";
    $trackerParams = [];

    // BRANCH hanya melihat DO kantor sendiri. Gunakan canonical-office helper
    // agar alias office lama tetap dianggap kantor yang sama.
    if ($trackerIsBranch) {
        if (function_exists('rmi_office_in_sql')) {
            $trackerWhere .= ' AND ' . rmi_office_in_sql($pdo, 'd.office_code', $trackerOffice, $trackerParams);
        } else {
            $trackerWhere .= ' AND UPPER(TRIM(d.office_code)) = ?';
            $trackerParams[] = $trackerOffice;
        }
    }

    $st = $pdo->prepare("
        SELECT
            d.id,
            d.do_code,
            d.customers_code,
            COALESCE(c.customers_name, '') AS customers_name,
            d.office_code,
            d.status
        FROM sales_do d
        LEFT JOIN master_customers c
          ON c.customers_code = d.customers_code
        WHERE {$trackerWhere}
        {$fullReturnSql}
        ORDER BY d.do_date DESC, d.id DESC
        LIMIT 300
    ");
    $st->execute($trackerParams);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#111827">
  <title>SCM Mobile Tracker</title>
  <link rel="manifest" href="scm-tracker-manifest.json">
  <style>
    body { margin:0; background:#0b1220; color:#e5e7eb; font-family:Arial,sans-serif; }
    .wrap { max-width:680px; margin:0 auto; padding:14px; }
    .card { background:#111827; border:1px solid #334155; border-radius:12px; padding:12px; margin-bottom:12px; }
    .title { font-size:18px; font-weight:700; margin-bottom:8px; }
    .muted { color:#94a3b8; font-size:12px; }
    .field { width:100%; box-sizing:border-box; padding:10px; border:1px solid #475569; border-radius:10px; background:#0f172a; color:#e5e7eb; }
    .btn { border:1px solid #475569; background:#1f2937; color:#e5e7eb; border-radius:10px; padding:10px 12px; cursor:pointer; }
    .btn.primary { background:#2563eb; border-color:#2563eb; }
    .btn.warn { background:#b45309; border-color:#b45309; }
    .btn:disabled { opacity:.55; cursor:not-allowed; }
    .btnrow { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .ok { color:#34d399; }
    .bad { color:#f87171; }
    .warntext { color:#fbbf24; }
    .health { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; font-size:12px; }
    .pill { padding:4px 8px; border-radius:999px; border:1px solid #475569; background:#0f172a; }
    .mono { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12px; }
    .list { margin:8px 0 0; padding-left:18px; }
    .list li { margin:6px 0; color:#cbd5e1; font-size:13px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="title">SCM Mobile Tracker</div>
      <div class="muted">Hanya DO berstatus ON DELIVERY yang dapat dilacak. Akun BRANCH hanya melihat DO officenya sendiri. GPS dikirim berkala ke server selama DO ON DELIVERY. Session berhenti otomatis saat DELIVERED / workflow selesai.</div>
    </div>

    <div class="card">
      <label class="muted">Pilih DO ON DELIVERY</label>
      <select id="doId" class="field">
        <option value="">-- pilih DO on delivery --</option>
        <?php foreach ($rows as $r): ?>
          <option value="<?= (int)$r['id'] ?>" data-code="<?= htmlspecialchars((string)$r['do_code'], ENT_QUOTES) ?>" <?= $preselectDoId === (int)$r['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$r['do_code']) ?> •
            <?= htmlspecialchars((string)$r['customers_code']) ?><?= trim((string)$r['customers_name']) !== '' ? ' - ' . htmlspecialchars((string)$r['customers_name']) : '' ?> •
            <?= htmlspecialchars((string)$r['office_code']) ?> • on_delivery
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!$rows): ?>
        <div class="muted" style="margin-top:8px">Belum ada DO berstatus ON DELIVERY. Ubah status dari halaman SCM Task terlebih dahulu.</div>
      <?php endif; ?>
      <?php if ($isAndroidNative): ?>
        <div class="ok" style="margin-top:8px;font-size:12px">
          Mode APK Android terdeteksi: Native Foreground GPS menjadi tracker utama. PWA tetap tersedia sebagai fallback manual.
        </div>
      <?php endif; ?>
      <div class="btnrow">
        <button id="btnStart" class="btn primary" type="button">Start Tracking</button>
        <button id="btnStop" class="btn warn" type="button" disabled>Stop Tracking</button>
        <button id="btnWake" class="btn" type="button">Keep Screen Awake</button>
        <a class="btn" href="scm_do_tasks.php" target="_blank" rel="noopener">Buka SCM Task</a>
        <a class="btn" href="scm_tracking_history.php" target="_blank" rel="noopener">History Tracking</a>
        <a class="btn" href="scm_tracker_sop.php">Buka SOP SCM</a>
      </div>
      <div id="status" class="muted" style="margin-top:10px">Ready.</div>
      <div id="coords" class="mono" style="margin-top:6px">-</div>
      <div id="last" class="mono" style="margin-top:6px">-</div>
      <div class="health">
        <span id="healthGps" class="pill">GPS: standby</span>
        <span id="healthPage" class="pill">Page: aktif</span>
        <span id="healthWake" class="pill">Wake: off</span>
      </div>
      <div id="browserWarn" class="warntext" style="margin-top:8px;font-size:12px"></div>
    </div>

    <div class="card">
      <div class="title" style="font-size:15px">Checklist Operasional Cepat</div>
      <ul class="list">
        <li>Dari SCM Task, pastikan DO sudah <b>ON DELIVERY</b>.</li>
        <li>Buka Tracker dari DO ON DELIVERY; sistem akan mencoba mulai otomatis. Jika izin browser membutuhkan interaksi, klik <b>Start Tracking</b>.</li>
        <li>Aktifkan izin lokasi dan high accuracy pada perangkat.</li>
        <li>Untuk browser/PWA, biarkan halaman Tracker tetap terbuka. Buka SCM Task lewat tombol <b>Buka SCM Task</b> agar Tracker tidak ditutup.</li>
        <li>Jika halaman Tracker direfresh/kembali dibuka, DO aktif akan dipulihkan otomatis selama masih ON DELIVERY.</li>
        <li>Setelah barang diterima, kembali ke SCM Task untuk POD + tanda tangan lalu <b>Set DELIVERED</b>.</li>
      </ul>
    </div>
  </div>

<script>
(function(){
  const API = <?= json_encode('/api/v1/internal/sales_scm_geo_ping.php', JSON_UNESCAPED_SLASHES) ?>;
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const IS_ANDROID_NATIVE = <?= $isAndroidNative ? 'true' : 'false' ?>;
  const LS_ACTIVE = 'rmi_scm_tracking_active_do_id';
  const LS_RUNNING = 'rmi_scm_tracking_running';
  const LS_DEVICE = 'rmi_scm_tracking_device_key';
  const LS_LASTPING = 'rmi_scm_tracking_last_ping_ms';

  // Browser mode: watchPosition + periodic getCurrentPosition fallback.
  // This improves reliability while the page is open, but Android may still suspend
  // JavaScript when the browser is backgrounded or the screen is locked.
  const PING_MIN_MS = 15000;
  const POLL_MS = 20000;
  const STALE_WARN_MS = 45000;
  const STALE_BAD_MS = 90000;

  const elDo = document.getElementById('doId');
  const elStatus = document.getElementById('status');
  const elCoords = document.getElementById('coords');
  const elLast = document.getElementById('last');
  const elHealthGps = document.getElementById('healthGps');
  const elHealthPage = document.getElementById('healthPage');
  const elHealthWake = document.getElementById('healthWake');
  const elBrowserWarn = document.getElementById('browserWarn');
  const btnStart = document.getElementById('btnStart');
  const btnStop = document.getElementById('btnStop');
  const btnWake = document.getElementById('btnWake');

  let watchId = null;
  let wakeLock = null;
  let pollTimer = null;
  let watchdogTimer = null;
  let running = false;
  let activeDoId = 0;
  let lastSentAt = Number(localStorage.getItem(LS_LASTPING) || 0) || 0;
  let sending = false;
  let nativeRunning = false;

  function deviceKey(){
    let v = localStorage.getItem(LS_DEVICE) || '';
    if (!v) {
      v = 'web-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2,10);
      localStorage.setItem(LS_DEVICE, v);
    }
    return v;
  }

  function setStatus(msg, good){
    elStatus.textContent = msg;
    elStatus.className = good === true ? 'ok' : (good === false ? 'bad' : 'muted');
  }

  function setHealth(){
    elHealthPage.textContent = 'Page: ' + (document.visibilityState === 'visible' ? 'aktif' : 'background');
    elHealthWake.textContent = 'Wake: ' + (wakeLock ? 'on' : 'off');
    const last = Number(localStorage.getItem(LS_LASTPING) || 0) || 0;
    if (IS_ANDROID_NATIVE && nativeRunning && !running) {
      elHealthGps.textContent = 'GPS: NATIVE';
      elBrowserWarn.textContent = 'Native Foreground GPS aktif. PWA tidak mengirim lokasi kecuali fallback dipilih manual.';
      return;
    }
    if (!running) {
      elHealthGps.textContent = 'GPS: standby';
      elBrowserWarn.textContent = '';
      return;
    }
    if (!last) {
      elHealthGps.textContent = 'GPS: menunggu';
      return;
    }
    const age = Date.now() - last;
    if (age <= STALE_WARN_MS) {
      elHealthGps.textContent = 'GPS: LIVE';
      elBrowserWarn.textContent = '';
    } else if (age <= STALE_BAD_MS) {
      elHealthGps.textContent = 'GPS: DELAY';
      elBrowserWarn.textContent = 'GPS terlambat. Pastikan tab Tracker tetap terbuka, layar tidak tidur, dan battery saver dimatikan.';
    } else {
      elHealthGps.textContent = 'GPS: OFFLINE';
      elBrowserWarn.textContent = 'Browser tidak mengirim GPS > 90 detik. Buka kembali tab Tracker untuk auto-resume.';
    }
  }

  async function apiCall(action, doId, pos){
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('tracking_action', action);
    fd.append('do_id', String(doId));
    fd.append('device_key', deviceKey());
    if (pos) {
      fd.append('lat', String(pos.coords.latitude));
      fd.append('lng', String(pos.coords.longitude));
      fd.append('accuracy_m', String(pos.coords.accuracy || ''));
      if (Number.isFinite(pos.coords.speed)) fd.append('speed_kmh', String(Math.max(0, pos.coords.speed * 3.6)));
      if (Number.isFinite(pos.coords.heading)) fd.append('heading', String(pos.coords.heading));
      fd.append('captured_at', new Date(pos.timestamp || Date.now()).toISOString());
    }
    const res = await fetch(API, {method:'POST', body:fd, credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}});
    const text = await res.text();
    let js = null;
    try { js = JSON.parse(text); }
    catch(e){
      const preview = text.replace(/\s+/g,' ').trim().slice(0,160);
      throw new Error('Endpoint GPS bukan JSON (HTTP '+res.status+'): '+(preview||'response kosong'));
    }
    if (!res.ok || !js || js.success !== true) {
      const msg = js && js.error && js.error.message ? js.error.message : (js && js.message ? js.message : ('HTTP '+res.status));
      const err = new Error(msg);
      err.code = js && js.error && js.error.code ? js.error.code : '';
      err.httpStatus = res.status;
      throw err;
    }
    return js;
  }

  function selectedDoCode(){
    const opt = elDo.options[elDo.selectedIndex];
    return opt ? (opt.getAttribute('data-code') || ('DO #'+(elDo.value||''))) : '';
  }

  function nativeBridgeAvailable(){
    return !!(
      IS_ANDROID_NATIVE &&
      window.RMIAndroid &&
      typeof window.RMIAndroid.startTracking === 'function'
    );
  }

  function nativeStartSelected(){
    if (!IS_ANDROID_NATIVE) return false;

    const doId = parseInt(elDo.value || '0', 10);
    if (!doId) {
      setStatus('Pilih DO ON DELIVERY dulu.', false);
      return false;
    }

    if (!nativeBridgeAvailable()) {
      setStatus('Bridge Native Android tidak tersedia. Gunakan PWA fallback.', false);
      return false;
    }

    try {
      window.RMIAndroid.startTracking(
        String(doId),
        selectedDoCode(),
        String(CSRF || '')
      );

      nativeRunning = true;
      btnStart.disabled = false;
      btnStart.textContent = 'Gunakan PWA Fallback';
      btnStop.disabled = false;
      btnWake.disabled = true;
      btnWake.textContent = 'Wake tidak perlu (Native)';
      setStatus('Native Android Foreground GPS aktif untuk DO '+selectedDoCode()+'.', true);
      setHealth();
      return true;
    } catch(e) {
      nativeRunning = false;
      setStatus('Gagal memulai Native Tracker: '+(e && e.message ? e.message : 'unknown'), false);
      setHealth();
      return false;
    }
  }

  function nativeStop(){
    if (
      IS_ANDROID_NATIVE &&
      window.RMIAndroid &&
      typeof window.RMIAndroid.stopTracking === 'function'
    ) {
      try { window.RMIAndroid.stopTracking(); } catch(e) {}
    }

    nativeRunning = false;
    btnStart.disabled = false;
    btnStart.textContent = IS_ANDROID_NATIVE ? 'Gunakan PWA Fallback' : 'Start Tracking';
    btnStop.disabled = true;
    setStatus('Native tracking dihentikan.', null);
    setHealth();
  }

  async function requestWakeLock(silent=false){
    if (!('wakeLock' in navigator)) {
      if (!silent) setStatus('Wake Lock tidak didukung browser ini.', null);
      setHealth();
      return false;
    }
    if (wakeLock) { setHealth(); return true; }
    try {
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release', () => { wakeLock = null; setHealth(); });
      if (!silent) setStatus('Wake Lock aktif.', true);
      setHealth();
      return true;
    } catch(e){
      if (!silent) setStatus('Gagal Wake Lock: '+(e && e.message ? e.message : 'unknown'), false);
      setHealth();
      return false;
    }
  }

  function clearWatchOnly(){
    if (watchId !== null && navigator.geolocation) navigator.geolocation.clearWatch(watchId);
    watchId = null;
  }

  function clearPoll(){
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  function currentPositionOnce(){
    if (!running || !activeDoId || !navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
      pos => handlePosition(pos, 'poll'),
      () => {},
      {enableHighAccuracy:true, timeout:12000, maximumAge:3000}
    );
  }

  async function handlePosition(pos, source){
    if (!running || !activeDoId || sending) return;
    const lat=pos.coords.latitude, lng=pos.coords.longitude, acc=pos.coords.accuracy||0;
    elCoords.textContent='lat='+lat.toFixed(7)+' lng='+lng.toFixed(7)+' acc='+Math.round(acc)+'m';
    const now=Date.now();
    if (lastSentAt && (now-lastSentAt)<PING_MIN_MS) return;
    sending = true;
    try {
      const js=await apiCall('ping',activeDoId,pos);
      lastSentAt=Date.now();
      localStorage.setItem(LS_LASTPING, String(lastSentAt));
      elLast.textContent='Last ping: '+new Date(lastSentAt).toLocaleString()+' • '+source;
      setStatus('Tracking aktif • GPS tersimpan'+(js.history_enabled?' • history ON.':'.'), true);
    } catch(err){
      const code = err && err.code ? String(err.code) : '';
      if (code === 'ERR_FLOW_LOCK' || code === 'ERR_FULL_RETURN' || (err && err.httpStatus === 409)) {
        // Workflow server sudah selesai/berubah. Matikan watcher lokal tanpa
        // mengirim STOP lagi; server sudah menutup session aktif.
        running = false;
        clearWatchOnly();
        clearPoll();
        activeDoId = 0;
        localStorage.removeItem(LS_ACTIVE);
        localStorage.removeItem(LS_RUNNING);
        localStorage.removeItem(LS_LASTPING);
        btnStart.disabled = false;
        btnStop.disabled = true;
        if (wakeLock) { try { await wakeLock.release(); } catch(e){} wakeLock=null; }
        setStatus('Tracking otomatis berhenti: '+(err && err.message ? err.message : 'workflow selesai'), false);
      } else {
        setStatus('Ping gagal: '+(err && err.message ? err.message : 'unknown'), false);
      }
    } finally {
      sending = false;
      setHealth();
    }
  }

  function installWatch(){
    clearWatchOnly();
    if (!running || !activeDoId) return;
    watchId = navigator.geolocation.watchPosition(
      pos => handlePosition(pos, 'watch'),
      err => {
        setStatus('GPS error: '+(err && err.message ? err.message : 'unknown')+'. Fallback polling tetap aktif.', false);
        clearWatchOnly();
      },
      {enableHighAccuracy:true, timeout:15000, maximumAge:3000}
    );
  }

  function installPoll(){
    clearPoll();
    currentPositionOnce();
    pollTimer=setInterval(currentPositionOnce,POLL_MS);
  }

  async function stopTracking(manual=true){
    const oldDo = activeDoId || parseInt(localStorage.getItem(LS_ACTIVE) || '0',10);
    running = false;
    clearWatchOnly();
    clearPoll();
    activeDoId = 0;
    localStorage.removeItem(LS_ACTIVE);
    localStorage.removeItem(LS_RUNNING);
    localStorage.removeItem(LS_LASTPING);
    btnStart.disabled = false;
    btnStop.disabled = true;
    if (manual && oldDo) {
      try { await apiCall('stop', oldDo, null); } catch(e) {}
    }
    if (wakeLock) { try { await wakeLock.release(); } catch(e){} wakeLock=null; }
    elLast.textContent='-';
    setStatus('Tracking berhenti.', null);
    setHealth();
  }

  async function startTracking(isResume=false){
    const doId = parseInt(elDo.value || '0',10);
    if (!doId) { setStatus('Pilih DO ON DELIVERY dulu.', false); return; }
    if (!navigator.geolocation) { setStatus('Geolocation tidak didukung.', false); return; }

    if (running && activeDoId === doId) {
      if (watchId === null) installWatch();
      if (!pollTimer) installPoll();
      if (document.visibilityState === 'visible' && !wakeLock) requestWakeLock(true);
      setHealth();
      return;
    }
    if (running && activeDoId && activeDoId !== doId) {
      if (!confirm('Tracking DO lama masih aktif. Stop DO lama dan pindah ke DO baru?')) {
        elDo.value = String(activeDoId); return;
      }
      await stopTracking(true);
    }

    running = true;
    activeDoId = doId;
    localStorage.setItem(LS_ACTIVE, String(doId));
    localStorage.setItem(LS_RUNNING, '1');
    btnStart.disabled = true;
    btnStop.disabled = false;
    setStatus(isResume ? 'Memulihkan tracking aktif...' : 'Memulai tracking...', null);

    try { await apiCall('start', doId, null); }
    catch(e) {
      localStorage.removeItem(LS_ACTIVE); localStorage.removeItem(LS_RUNNING);
      running=false; activeDoId=0; btnStart.disabled=false; btnStop.disabled=true;
      setStatus('Gagal start tracking: '+(e && e.message ? e.message : 'unknown'), false);
      setHealth();
      return;
    }

    // Auto wake-lock when tracking starts. Manual button remains as fallback.
    if (document.visibilityState === 'visible') requestWakeLock(true);
    installWatch();
    installPoll();
    setHealth();
  }

  btnStart.addEventListener('click', async () => {
    if (IS_ANDROID_NATIVE && !running) {
      if (!confirm('Gunakan PWA fallback? Native Foreground GPS akan dihentikan agar tidak terjadi double tracking.')) {
        return;
      }
      nativeStop();
      btnWake.disabled = false;
      btnWake.textContent = 'Keep Screen Awake';
      await startTracking(false);
      return;
    }
    await startTracking(false);
  });

  btnStop.addEventListener('click', async () => {
    if (IS_ANDROID_NATIVE && nativeRunning && !running) {
      nativeStop();
      return;
    }
    await stopTracking(true);
  });

  btnWake.addEventListener('click', () => requestWakeLock(false));

  elDo.addEventListener('change', async function(){
    const newDo=parseInt(elDo.value||'0',10);

    if (IS_ANDROID_NATIVE && !running) {
      if (newDo) nativeStartSelected();
      return;
    }

    if (running && activeDoId && newDo && newDo!==activeDoId) {
      if (confirm('Pindah tracking ke DO yang dipilih? Tracking DO lama akan dihentikan.')) {
        await stopTracking(true); await startTracking(false);
      } else { elDo.value=String(activeDoId); }
    }
  });

  // Jangan stop pada beforeunload. Browser state disimpan untuk auto-resume.
  // Ketika user kembali ke tab, pasang ulang watch/poll dan wake lock.
  document.addEventListener('visibilitychange', async function(){
    setHealth();
    if (document.visibilityState === 'visible' && running && activeDoId) {
      if (!wakeLock) await requestWakeLock(true);
      if (watchId === null) installWatch();
      if (!pollTimer) installPoll();
      currentPositionOnce();
    }
  });
  window.addEventListener('pageshow', function(){
    if (running && activeDoId) { if (watchId===null) installWatch(); if(!pollTimer) installPoll(); currentPositionOnce(); }
    setHealth();
  });
  window.addEventListener('focus', function(){
    if (running && activeDoId) currentPositionOnce();
    setHealth();
  });

  watchdogTimer=setInterval(setHealth,5000);

  const savedDo=parseInt(localStorage.getItem(LS_ACTIVE)||'0',10);
  const savedRunning=localStorage.getItem(LS_RUNNING)==='1';
  let restored = false;

  if (IS_ANDROID_NATIVE) {
    // Hapus state browser lama agar PWA tidak auto-resume bersamaan dengan Native.
    localStorage.removeItem(LS_ACTIVE);
    localStorage.removeItem(LS_RUNNING);
    localStorage.removeItem(LS_LASTPING);

    const preselectedDo=parseInt(elDo.value||'0',10);
    if (preselectedDo) {
      setTimeout(() => nativeStartSelected(), 500);
    } else {
      btnStart.textContent = 'Gunakan PWA Fallback';
      btnWake.disabled = true;
      btnWake.textContent = 'Wake tidak perlu (Native)';
      setStatus('Mode APK Android aktif. Pilih DO ON DELIVERY untuk memulai Native GPS.', null);
    }
  } else {
    if (savedDo && savedRunning) {
      const opt=Array.from(elDo.options).find(o => parseInt(o.value||'0',10)===savedDo);
      if (opt) {
        elDo.value=String(savedDo);
        restored = true;
        setTimeout(() => startTracking(true), 250);
      } else {
        localStorage.removeItem(LS_ACTIVE);
        localStorage.removeItem(LS_RUNNING);
        localStorage.removeItem(LS_LASTPING);
      }
    }

    // Browser/PWA fallback: preselect DO dan auto-start seperti alur lama.
    const preselectedDo=parseInt(elDo.value||'0',10);
    if (!restored && preselectedDo) {
      setTimeout(() => startTracking(false), 500);
    }
  }

  // SW may keep app assets available, but browser geolocation itself cannot run from a service worker.
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('scm-tracker-sw.js').catch(() => {});
  setHealth();
})();
</script>
</body>
</html>
