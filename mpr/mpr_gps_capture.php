<?php
// /mpr/mpr_gps_capture.php
// GPS Realtime MPR - halaman khusus untuk ambil GPS dan kirim ke form kunjungan.

$return = isset($_GET['return']) && $_GET['return'] !== '' ? (string)$_GET['return'] : 'mpr_visits.php';
$target = isset($_GET['target']) && $_GET['target'] !== '' ? (string)$_GET['target'] : 'mpr_visits';

// Batasi return agar tetap lokal, jangan open redirect.
// Yang valid contoh: mpr_visits.php, mpr_plan_view.php?id=136
if (preg_match('~^https?://~i', $return) || str_contains($return, '..') || !preg_match('~^mpr_[a-z0-9_]+\.php(\?.*)?$~i', $return)) {
    $return = 'mpr_visits.php';
}

if (!headers_sent()) {
    header_remove('Permissions-Policy');
    header_remove('Feature-Policy');
    header('Permissions-Policy: geolocation=(self "https://erp.rizqullahcorp.com" "https://rizqullahcorp.com"), camera=(self), fullscreen=(self)', true);
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GPS Realtime MPR</title>
<style>
:root{--bg:#0b1220;--card:#172336;--line:#314156;--muted:#9ca3af;--txt:#e5e7eb;--blue:#2563eb;--green:#16a34a;--red:#dc2626;--gray:#334155}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:820px;margin:36px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 12px 30px rgba(0,0,0,.25)}
h1{margin:0 0 8px;font-size:28px}
p{color:var(--muted);line-height:1.5}
.status{padding:14px 16px;border-radius:12px;background:#0f172a;border:1px solid #273449;margin:16px 0;font-weight:700}
.ok{color:#22c55e}.err{color:#f87171}.warn{color:#f59e0b}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:14px 0}
.box{background:#0b1220;border:1px solid #273449;border-radius:14px;padding:14px}
.lbl{font-size:12px;color:var(--muted);margin-bottom:8px}
.val{font-size:22px;font-weight:700;word-break:break-all}
button,a.btn{display:block;width:100%;border:0;border-radius:12px;padding:15px 16px;margin-top:10px;color:white;text-align:center;text-decoration:none;font-weight:800;font-size:16px;cursor:pointer}
.blue{background:var(--blue)}.red{background:var(--red)}.green{background:var(--green)}.gray{background:var(--gray)}
textarea{width:100%;min-height:90px;margin-top:12px;background:#020617;color:#fff;border:1px solid #273449;border-radius:12px;padding:12px;font-family:monospace}
.small{font-size:13px;color:var(--muted)}
@media(max-width:640px){.grid{grid-template-columns:1fr}h1{font-size:22px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>GPS Realtime MPR</h1>
    <p>Halaman khusus untuk mengambil GPS realtime. Tidak memakai fallback IP.</p>

    <div id="status" class="status warn">Tekan Mulai GPS Realtime. Jika popup muncul, pilih Allow/Izinkan.</div>

    <div class="grid">
      <div class="box"><div class="lbl">Latitude</div><div id="latView" class="val">-</div></div>
      <div class="box"><div class="lbl">Longitude</div><div id="lngView" class="val">-</div></div>
      <div class="box"><div class="lbl">Akurasi</div><div id="accView" class="val">-</div></div>
    </div>

    <button class="blue" type="button" id="btnStart">Mulai GPS Realtime</button>
    <button class="red" type="button" id="btnStop">Stop GPS</button>
    <button class="green" type="button" id="btnSend">Kirim GPS ke Form</button>
    <a class="btn gray" id="btnReturn" href="<?= htmlspecialchars($return, ENT_QUOTES, 'UTF-8') ?>">Kembali ke Form MPR</a>

    <p class="small">Jika tidak otomatis masuk ke form, data GPS tetap tersimpan di browser dan bisa dicopy dari kotak di bawah.</p>
    <textarea id="copyBox" readonly></textarea>
  </div>
</div>

<script>
(function(){
  var returnUrl = <?= json_encode($return, JSON_UNESCAPED_SLASHES) ?>;
  var target = <?= json_encode($target, JSON_UNESCAPED_SLASHES) ?>;
  var watchId = null;
  var lastGps = null;

  function el(id){ return document.getElementById(id); }
  function setStatus(msg, cls){
    el('status').className = 'status ' + (cls || '');
    el('status').textContent = msg;
  }
  function round(v, n){ return Number(v).toFixed(n || 7); }

  function saveAllKeys(gps){
    if (!gps || !gps.lat || !gps.lng) return false;

    var lat = String(gps.lat);
    var lng = String(gps.lng);
    var acc = String(gps.acc || '');

    var pairs = {
      // Keys dibaca oleh mpr_visits.php V3
      'mpr_gps_lat': lat,
      'mpr_gps_lng': lng,
      'mpr_gps_acc': acc,
      'mprGpsLat': lat,
      'mprGpsLng': lng,
      'mprGpsAcc': acc,
      'gps_lat': lat,
      'gps_lng': lng,
      'gps_acc': acc,
      'gpsLat': lat,
      'gpsLng': lng,
      'gpsAcc': acc,
      'mpr_visit_gps_lat': lat,
      'mpr_visit_gps_lng': lng,
      'mpr_visit_gps_acc': acc,
      'mprVisitGpsLat': lat,
      'mprVisitGpsLng': lng,
      'mprVisitGpsAcc': acc,
      'last_gps_lat': lat,
      'last_gps_lng': lng,
      'last_gps_acc': acc,
      'mprLat': lat,
      'mprLng': lng,
      'mprAcc': acc,
      'visit_lat': lat,
      'visit_lng': lng,
      'visit_acc': acc,
      'visitGpsLat': lat,
      'visitGpsLng': lng,
      'visitGpsAcc': acc,
      'gpsLatitude': lat,
      'gpsLongitude': lng,
      'gpsAccuracy': acc
    };

    Object.keys(pairs).forEach(function(k){
      try { localStorage.setItem(k, pairs[k]); } catch(e) {}
      try { sessionStorage.setItem(k, pairs[k]); } catch(e) {}
      try { document.cookie = k + '=' + encodeURIComponent(pairs[k]) + '; path=/; max-age=86400; SameSite=Lax'; } catch(e) {}
    });

    var payload = {
      type: 'mpr-gps',
      event: 'MPR_GPS_FIX',
      target: target,
      fix: { lat: lat, lng: lng, acc: acc },
      lat: lat,
      lng: lng,
      acc: acc,
      latitude: lat,
      longitude: lng,
      accuracy: acc,
      saved_at: new Date().toISOString()
    };

    [
      'mpr_gps_last_fix','mpr_gps_last','mprGpsLast','mpr_gps_data','mprGpsData',
      'gps_data','gpsData','mpr_realtime_gps','mprGpsRealtime',
      'mpr_visit_gps','mprVisitGps'
    ].forEach(function(k){
      try { localStorage.setItem(k, JSON.stringify(payload)); } catch(e) {}
      try { sessionStorage.setItem(k, JSON.stringify(payload)); } catch(e) {}
    });

    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage(payload, window.location.origin);
        window.opener.postMessage({type:'MPR_GPS_FIX', target:target, fix:payload.fix}, window.location.origin);
      }
    } catch(e) {}

    return true;
  }

  function render(gps){
    lastGps = gps;
    el('latView').textContent = gps.lat;
    el('lngView').textContent = gps.lng;
    el('accView').textContent = gps.acc ? (gps.acc + ' m') : '-';
    el('copyBox').value = 'lat=' + gps.lat + '\\nlng=' + gps.lng + '\\nacc=' + gps.acc + ' m';
  }

  function onPos(pos){
    var gps = {
      lat: round(pos.coords.latitude, 7),
      lng: round(pos.coords.longitude, 7),
      acc: Math.round(pos.coords.accuracy || 0)
    };
    render(gps);
    saveAllKeys(gps);
    setStatus('GPS realtime OK. Akurasi ' + gps.acc + ' m. Tekan Kirim GPS ke Form.', 'ok');
  }

  function onErr(err){
    var msg = err && err.message ? err.message : 'GPS gagal.';
    setStatus('Gagal mengambil GPS: ' + msg, 'err');
  }

  function startGps(){
    if (!window.isSecureContext) {
      setStatus('GPS hanya berjalan di HTTPS / secure context.', 'err');
      return;
    }
    if (!navigator.geolocation) {
      setStatus('Browser tidak mendukung GPS/geolocation.', 'err');
      return;
    }

    setStatus('Mengambil GPS realtime...', 'warn');

    navigator.geolocation.getCurrentPosition(onPos, onErr, {
      enableHighAccuracy: true,
      timeout: 20000,
      maximumAge: 0
    });

    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    watchId = navigator.geolocation.watchPosition(onPos, onErr, {
      enableHighAccuracy: true,
      timeout: 20000,
      maximumAge: 0
    });
  }

  function stopGps(){
    if (watchId !== null) {
      navigator.geolocation.clearWatch(watchId);
      watchId = null;
    }
    setStatus('GPS realtime dihentikan. Data terakhir tetap tersimpan.', lastGps ? 'ok' : 'warn');
  }

  function sendToForm(){
    if (!lastGps || !lastGps.lat || !lastGps.lng) {
      setStatus('GPS belum didapat. Tekan Mulai GPS Realtime dulu.', 'err');
      alert('GPS belum didapat. Tekan Mulai GPS Realtime dulu.');
      return;
    }

    saveAllKeys(lastGps);
    setStatus('GPS tersimpan. Kembali ke form MPR. Jika belum masuk otomatis, refresh form.', 'ok');

    var sep = returnUrl.indexOf('?') >= 0 ? '&' : '?';
    var withParams = returnUrl + sep +
      'gps_lat=' + encodeURIComponent(lastGps.lat) +
      '&gps_lng=' + encodeURIComponent(lastGps.lng) +
      '&gps_acc=' + encodeURIComponent(lastGps.acc) +
      '&gps_from=capture';

    el('btnReturn').href = withParams;

    try {
      if (window.opener && !window.opener.closed) {
        window.opener.focus();
      }
    } catch(e) {}

    // Jangan paksa redirect langsung jika user ingin melihat status,
    // tapi update link kembali agar pasti membawa gps via URL.
  }

  el('btnStart').addEventListener('click', startGps);
  el('btnStop').addEventListener('click', stopGps);
  el('btnSend').addEventListener('click', sendToForm);

  // Auto-start agar user tidak perlu klik dua kali.
  startGps();
})();
</script>
</body>
</html>
