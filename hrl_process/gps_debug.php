<?php
// /hrl_process/gps_debug.php
require_once __DIR__ . '/../master/auth.php';
require_login();

if (!headers_sent()) {
    header_remove('Permissions-Policy');
    header_remove('Feature-Policy');
    header('Permissions-Policy: geolocation=(self "https://erp.rizqullahcorp.com" "https://rizqullahcorp.com"), camera=(self "https://erp.rizqullahcorp.com" "https://rizqullahcorp.com"), fullscreen=(self)', true);
}
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>GPS Debug HRL</title>
<style>
body{font-family:Arial,sans-serif;background:#0f172a;color:#e5e7eb;padding:24px}
button{padding:12px 18px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:bold}
pre{background:#020617;padding:16px;border-radius:8px;white-space:pre-wrap}
.ok{color:#22c55e}.err{color:#ef4444}
</style>
</head>
<body>
<h2>GPS Debug HRL Process</h2>
<button id="btn">Ambil GPS</button>
<pre id="out">Klik tombol Ambil GPS.</pre>
<script>
const out = document.getElementById('out');
document.getElementById('btn').onclick = function(){
  out.textContent = 'secureContext=' + window.isSecureContext + '\n';
  out.textContent += 'navigator.geolocation=' + (!!navigator.geolocation) + '\n';
  if (!window.isSecureContext) {
    out.textContent += 'ERROR: halaman bukan HTTPS secure context.\n';
    return;
  }
  if (!navigator.geolocation) {
    out.textContent += 'ERROR: navigator.geolocation tidak tersedia / diblokir Permissions-Policy.\n';
    return;
  }
  navigator.geolocation.getCurrentPosition(function(pos){
    out.textContent += 'OK\nlat=' + pos.coords.latitude + '\nlng=' + pos.coords.longitude + '\nacc=' + pos.coords.accuracy + ' m\n';
  }, function(err){
    out.textContent += 'ERROR code=' + err.code + '\nmessage=' + err.message + '\n';
  }, {enableHighAccuracy:true, timeout:20000, maximumAge:0});
};
</script>
</body>
</html>
