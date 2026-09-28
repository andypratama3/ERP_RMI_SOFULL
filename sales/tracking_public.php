<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https://maps.googleapis.com https://maps.gstatic.com; img-src 'self' data: https://maps.googleapis.com https://maps.gstatic.com https://*.googleapis.com https://*.gstatic.com; script-src 'self' 'unsafe-inline' https://maps.googleapis.com https://maps.gstatic.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; connect-src 'self' https://maps.googleapis.com https://maps.gstatic.com https://*.googleapis.com https://*.gstatic.com; frame-ancestors 'self'");

$isDemo = (string)($_GET['demo'] ?? '') === '1';
$token = trim((string)($_GET['t'] ?? ''));
$events = [];

// Google Maps browser key untuk Synology Web Station.
// File config berada satu level di atas /sales, yaitu /config/google_maps.php.
$googleMapsApiKey = '';
$googleMapsConfigFile = __DIR__ . '/../config/google_maps.php';
if (is_file($googleMapsConfigFile)) {
    $googleMapsConfig = require $googleMapsConfigFile;
    if (is_array($googleMapsConfig)) {
        $googleMapsApiKey = trim((string)($googleMapsConfig['google_maps_api_key'] ?? ''));
    }
}

if ($isDemo) {
    $token = 'demo-live-tracking';
    $row = [
        'id' => 0,
        'do_code' => 'DO-DEMO-001',
        'do_date' => date('Y-m-d'),
        'status' => 'on_delivery',
        'tracking_code' => 'TRK-DEMO-001',
        'carrier_provider' => 'INTERNAL',
        'carrier_tracking_no' => 'TRK-DEMO-001',
        'carrier_courier_code' => 'SCM',
        'tracking_last_sync_at' => date('Y-m-d H:i:s'),
        'tracking_last_status' => 'in_transit',
        'fallback_live_location_url' => 'https://maps.google.com/?q=-6.2088,106.8456',
        'scm_live_lat' => '-6.2088',
        'scm_live_lng' => '106.8456',
        'scm_live_accuracy_m' => '18',
        'scm_live_at' => date('Y-m-d H:i:s'),
        'customer_phone' => '081234567890',
        'customers_name' => 'Customer Demo',
    ];
    $events = [
        ['event_time' => date('Y-m-d H:i:s', time() - 1200), 'provider_status' => 'picked_up', 'internal_status' => 'ready_scm', 'description' => 'Barang diambil kurir', 'location' => 'Gudang'],
        ['event_time' => date('Y-m-d H:i:s', time() - 600), 'provider_status' => 'in_transit', 'internal_status' => 'on_delivery', 'description' => 'Kurir menuju customer', 'location' => 'Jakarta Pusat'],
    ];
} else {
    if ($token === '' || !preg_match('/^[a-zA-Z0-9\-_]{32,120}$/', $token)) {
        http_response_code(404);
        exit('Tracking tidak ditemukan.');
    }

    $pdo = db_pdo();
    $st = $pdo->prepare("
        SELECT d.id, d.do_code, d.do_date, d.status, d.tracking_code,
               d.carrier_provider, d.carrier_tracking_no, d.carrier_courier_code,
               d.tracking_last_sync_at, d.tracking_last_status, d.fallback_live_location_url,
               d.scm_live_lat, d.scm_live_lng, d.scm_live_accuracy_m, d.scm_live_at,
               d.customer_phone, c.customers_name
        FROM sales_do d
        LEFT JOIN master_customers c ON c.customers_code = d.customers_code
        WHERE d.tracking_public_token = ?
        LIMIT 1
    ");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit('Tracking tidak ditemukan.');
    }

    try {
        $ev = $pdo->prepare("
            SELECT event_time, provider_status, internal_status, description, location
            FROM sales_do_tracking_events
            WHERE do_id = ?
            ORDER BY event_time DESC, id DESC
            LIMIT 30
        ");
        $ev->execute([(int)$row['id']]);
        $events = $ev->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $events = [];
    }
}

$mask = static function (string $v): string {
    $v = trim($v);
    if ($v === '') return '-';
    if (mb_strlen($v) <= 4) return str_repeat('*', mb_strlen($v));
    return mb_substr($v, 0, 2) . str_repeat('*', max(2, mb_strlen($v) - 4)) . mb_substr($v, -2);
};

$customer = $mask((string)($row['customers_name'] ?? ''));
$phone = $mask((string)($row['customer_phone'] ?? ''));
$statusInternal = strtoupper((string)($row['status'] ?? '-'));
$statusProvider = strtoupper((string)($row['tracking_last_status'] ?? '-'));
$liveLat = (string)($row['scm_live_lat'] ?? '');
$liveLng = (string)($row['scm_live_lng'] ?? '');
$liveAcc = (string)($row['scm_live_accuracy_m'] ?? '');
$liveAt = (string)($row['scm_live_at'] ?? '');
$fallback = (string)($row['fallback_live_location_url'] ?? '');
$trackingNo = (string)($row['carrier_tracking_no'] ?? ($row['tracking_code'] ?? '-'));
$provider = strtoupper((string)($row['carrier_provider'] ?? 'BITESHIP'));
$doCode = (string)($row['do_code'] ?? '-');
$lastSync = (string)($row['tracking_last_sync_at'] ?? '-');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Live Tracking <?= htmlspecialchars($doCode) ?></title>
  <style>
    body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; background:#0b1220; color:#e5e7eb; }
    .wrap { max-width:960px; margin:0 auto; padding:14px; }
    .card { background:#111827; border:1px solid rgba(255,255,255,.1); border-radius:14px; padding:12px; margin-bottom:12px; }
    .title { font-size:18px; font-weight:700; margin-bottom:8px; }
    .muted { color:#94a3b8; font-size:12px; }
    .chips { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .chip { border:1px solid rgba(255,255,255,.2); border-radius:999px; padding:4px 10px; font-size:12px; }
    #map { height: 360px; border-radius:12px; border:1px solid rgba(255,255,255,.12); overflow:hidden; }
    .map-wrap { position: relative; }
    .hud {
      position:absolute; z-index:500; right:10px; top:10px;
      background:rgba(2,6,23,.86); border:1px solid rgba(148,163,184,.35);
      border-radius:10px; padding:8px 10px; font-size:12px; min-width:170px;
      backdrop-filter: blur(2px);
    }
    .hud-row { display:flex; justify-content:space-between; gap:8px; margin-bottom:4px; }
    .hud-row:last-child { margin-bottom:0; }
    .live-dot {
      display:inline-block; width:8px; height:8px; border-radius:999px;
      background:#22c55e; box-shadow:0 0 0 0 rgba(34,197,94,.8); animation:pulse 1.6s infinite;
      margin-right:6px; vertical-align:middle;
    }
    @keyframes pulse {
      0% { box-shadow:0 0 0 0 rgba(34,197,94,.7); }
      70% { box-shadow:0 0 0 10px rgba(34,197,94,0); }
      100% { box-shadow:0 0 0 0 rgba(34,197,94,0); }
    }
    .courier-pin {
      width:16px; height:16px; border-radius:999px; background:#16a34a;
      border:2px solid #dcfce7; box-shadow:0 0 0 0 rgba(22,163,74,.7);
      animation:pulse 1.6s infinite;
      position:relative;
      transform-origin: 50% 50%;
    }
    .courier-pin::after {
      content:'';
      position:absolute;
      left:50%;
      top:-7px;
      transform:translateX(-50%);
      width:0;
      height:0;
      border-left:4px solid transparent;
      border-right:4px solid transparent;
      border-bottom:7px solid #86efac;
    }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .evt { padding:10px; border:1px solid rgba(255,255,255,.08); border-radius:10px; margin-bottom:8px; }
    a { color:#93c5fd; }
    @media (max-width: 760px) { .grid { grid-template-columns:1fr; } #map { height:300px; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="title">Live Shipment Tracking</div>
      <div class="muted">DO <?= htmlspecialchars($doCode) ?> • Last Sync <?= htmlspecialchars($lastSync) ?></div>
      <div class="chips">
        <div class="chip">Internal: <b id="chipInternal"><?= htmlspecialchars($statusInternal) ?></b></div>
        <div class="chip">Provider: <b id="chipProvider"><?= htmlspecialchars($statusProvider) ?></b></div>
        <div class="chip">No Resi: <b><?= htmlspecialchars($trackingNo) ?></b></div>
        <div class="chip">Provider: <b><?= htmlspecialchars($provider) ?></b></div>
      </div>
      <div class="muted" style="margin-top:8px">Customer: <?= htmlspecialchars($customer) ?> / <?= htmlspecialchars($phone) ?></div>
      <div class="muted">GPS: <span id="gpsText"><?= htmlspecialchars(($liveLat !== '' && $liveLng !== '') ? ($liveLat . ',' . $liveLng) : '-') ?></span> • <span id="gpsAt"><?= htmlspecialchars($liveAt !== '' ? $liveAt : '-') ?></span> <?= $liveAcc !== '' ? '(±' . htmlspecialchars($liveAcc) . ' m)' : '' ?></div>
      <?php if ($fallback !== ''): ?>
        <div style="margin-top:6px"><a id="fallbackLink" href="<?= htmlspecialchars($fallback) ?>" target="_blank" rel="noopener">Open Google Maps</a></div>
      <?php else: ?>
        <div style="margin-top:6px"><a id="fallbackLink" href="#" target="_blank" rel="noopener" style="display:none">Open Google Maps</a></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="map-wrap">
        <div id="map"></div>
        <div class="hud">
          <div class="hud-row">
            <span><span class="live-dot"></span>Live</span>
            <span id="hudConn">connecting...</span>
          </div>
          <div class="hud-row">
            <span>Speed</span>
            <span id="hudSpeed">- km/h</span>
          </div>
          <div class="hud-row">
            <span>Updated</span>
            <span id="hudAgo">-</span>
          </div>
          <div class="hud-row">
            <span>Distance</span>
            <span id="hudDistance">- km</span>
          </div>
          <div class="hud-row">
            <span>ETA</span>
            <span id="hudEta">-</span>
          </div>
        </div>
      </div>
      <div class="muted" style="margin-top:8px">Posisi kurir akan update otomatis setiap beberapa detik.</div>
    </div>

    <div class="card">
      <div class="title" style="font-size:16px">Timeline</div>
      <div id="timelineBox">
        <?php if (!$events): ?>
          <div class="evt"><div class="muted">Belum ada event.</div></div>
        <?php else: ?>
          <?php foreach ($events as $ev): ?>
            <div class="evt">
              <div><b><?= htmlspecialchars((string)($ev['provider_status'] ?? '-')) ?></b> -> <?= htmlspecialchars((string)($ev['internal_status'] ?? '-')) ?></div>
              <div><?= htmlspecialchars((string)($ev['description'] ?? '-')) ?></div>
              <div class="muted"><?= htmlspecialchars((string)($ev['location'] ?? '-')) ?> • <?= htmlspecialchars((string)($ev['event_time'] ?? '-')) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
  (function(){
    const TOKEN = <?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>;
    const IS_DEMO = <?= $isDemo ? 'true' : 'false' ?>;
    const HAS_MAP_KEY = <?= $googleMapsApiKey !== '' ? 'true' : 'false' ?>;
    const API = IS_DEMO
      ? 'tracking_public_live.php?demo=1&t=' + encodeURIComponent(TOKEN)
      : 'tracking_public_live.php?t=' + encodeURIComponent(TOKEN);
    const fallbackLink = document.getElementById('fallbackLink');
    const chipInternal = document.getElementById('chipInternal');
    const chipProvider = document.getElementById('chipProvider');
    const gpsText = document.getElementById('gpsText');
    const gpsAt = document.getElementById('gpsAt');
    const hudConn = document.getElementById('hudConn');
    const hudSpeed = document.getElementById('hudSpeed');
    const hudAgo = document.getElementById('hudAgo');
    const hudDistance = document.getElementById('hudDistance');
    const hudEta = document.getElementById('hudEta');
    const mapEl = document.getElementById('map');

    let map = null, marker = null, trail = null, destinationMarker = null;
    let trailPath = [];
    let inited = false, lastPoint = null, lastPointAtMs = 0, lastServerAtMs = 0;
    let animFrame = 0, followMode = true, currentSpeedKmh = 0, destinationPoint = null;
    let pollingStarted = false;

    function parseDateToMs(v) {
      if (!v) return 0;
      const m = Date.parse(String(v).replace(' ', 'T'));
      return Number.isFinite(m) ? m : 0;
    }
    function haversineKm(lat1, lng1, lat2, lng2) {
      const r = Math.PI / 180, dLat = (lat2-lat1)*r, dLng = (lng2-lng1)*r;
      const a = Math.sin(dLat/2)**2 + Math.cos(lat1*r)*Math.cos(lat2*r)*Math.sin(dLng/2)**2;
      return 6371 * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)));
    }
    function updateUpdatedAgo() {
      if (!lastServerAtMs) { hudAgo.textContent = '-'; return; }
      const s = Math.max(0, Math.floor((Date.now()-lastServerAtMs)/1000));
      hudAgo.textContent = s < 60 ? s+'s ago' : Math.floor(s/60)+'m ago';
    }
    function parseFallbackToLatLng(url) {
      if (!url) return null;
      const m = String(url).match(/[?&]q=([-0-9.]+),([-0-9.]+)/i);
      return m ? [parseFloat(m[1]), parseFloat(m[2])] : null;
    }
    function setDestination(lat,lng) {
      if (!map || !isFinite(lat) || !isFinite(lng)) return;
      destinationPoint = [lat,lng];
      const pos = {lat:lat,lng:lng};
      if (!destinationMarker) {
        destinationMarker = new google.maps.Marker({map:map, position:pos, title:'Destination',
          icon:{path:google.maps.SymbolPath.CIRCLE, scale:7, fillColor:'#ef4444', fillOpacity:0.9, strokeColor:'#ffffff', strokeWeight:2}});
      } else destinationMarker.setPosition(pos);
    }
    function updateEtaAndDistance(currentPoint) {
      if (!destinationPoint || !currentPoint) { hudDistance.textContent='- km'; hudEta.textContent='-'; return; }
      const distKm = haversineKm(currentPoint[0],currentPoint[1],destinationPoint[0],destinationPoint[1]);
      hudDistance.textContent = distKm.toFixed(2)+' km';
      hudEta.textContent = currentSpeedKmh > 3 ? Math.max(1,Math.round((distKm/currentSpeedKmh)*60))+' min' : '-';
    }
    function smoothMoveMarker(from,to,durationMs) {
      if (!marker) return;
      if (animFrame) cancelAnimationFrame(animFrame);
      const start=performance.now(), d=Math.max(250,durationMs||900);
      function frame(now){
        const t=Math.min(1,(now-start)/d), k=1-Math.pow(1-t,3);
        const p={lat:from[0]+(to[0]-from[0])*k,lng:from[1]+(to[1]-from[1])*k};
        marker.setPosition(p);
        if (followMode && map) map.panTo(p);
        if(t<1) animFrame=requestAnimationFrame(frame);
      }
      animFrame=requestAnimationFrame(frame);
    }
    function setPos(lat,lng,pointAtMs) {
      if (!map || !isFinite(lat) || !isFinite(lng)) return;
      const p=[lat,lng], pos={lat:lat,lng:lng};
      if (!marker) {
        marker = new google.maps.Marker({map:map, position:pos, title:'SCM Courier',
          icon:{path:google.maps.SymbolPath.CIRCLE, scale:9, fillColor:'#16a34a', fillOpacity:1, strokeColor:'#dcfce7', strokeWeight:3}});
      } else {
        const prev=marker.getPosition();
        smoothMoveMarker([prev.lat(),prev.lng()],p,900);
      }
      if (!lastPoint || haversineKm(lastPoint[0],lastPoint[1],p[0],p[1]) > 0.005) {
        trailPath.push(pos); trail.setPath(trailPath);
      }
      if (!inited) { map.setCenter(pos); map.setZoom(15); inited=true; }
      if (lastPoint && lastPointAtMs>0 && pointAtMs>lastPointAtMs) {
        const distKm=haversineKm(lastPoint[0],lastPoint[1],p[0],p[1]);
        const hour=(pointAtMs-lastPointAtMs)/3600000;
        currentSpeedKmh=hour>0 ? Math.min(distKm/hour,120) : 0;
        hudSpeed.textContent=currentSpeedKmh.toFixed(1)+' km/h';
      }
      lastPoint=p; lastPointAtMs=pointAtMs||Date.now(); updateEtaAndDistance(p);
    }
    async function tick(){
      try {
        hudConn.textContent='syncing';
        const r=await fetch(API,{credentials:'same-origin',cache:'no-store'});
        if(!r.ok){hudConn.textContent='offline';return;}
        const js=await r.json();
        if(!js || js.success!==true || !js.data){hudConn.textContent='offline';return;}
        const d=js.data; hudConn.textContent='online';
        chipInternal.textContent=String(d.status||'-').toUpperCase();
        chipProvider.textContent=String(d.tracking_last_status||'-').toUpperCase();
        gpsText.textContent=(d.scm_live_lat&&d.scm_live_lng)?d.scm_live_lat+','+d.scm_live_lng:'-';
        gpsAt.textContent=d.scm_live_at||'-';
        lastServerAtMs=parseDateToMs(d.scm_live_at||d.tracking_last_sync_at||''); updateUpdatedAgo();
        if(d.fallback_live_location_url){
          fallbackLink.href=d.fallback_live_location_url; fallbackLink.style.display='';
          const dp=parseFallbackToLatLng(d.fallback_live_location_url); if(dp) setDestination(dp[0],dp[1]);
        }
        if(d.scm_live_lat&&d.scm_live_lng) setPos(parseFloat(d.scm_live_lat),parseFloat(d.scm_live_lng),parseDateToMs(d.scm_live_at||d.tracking_last_sync_at||''));
      } catch(e){ hudConn.textContent='offline'; }
    }
    function startPolling(){
      if(pollingStarted)return; pollingStarted=true; tick(); setInterval(tick,4000); setInterval(updateUpdatedAgo,1000);
    }
    window.initRmiTrackingMap=function(){
      map=new google.maps.Map(mapEl,{center:{lat:-6.2,lng:106.8},zoom:12,mapTypeControl:false,streetViewControl:false,fullscreenControl:true});
      trail=new google.maps.Polyline({map:map,path:[],strokeColor:'#22c55e',strokeOpacity:0.95,strokeWeight:4});
      map.addListener('dragstart',()=>{followMode=false;});
      map.addListener('zoom_changed',()=>{if(inited) followMode=false;});
      startPolling();
    };
    if(!HAS_MAP_KEY){
      mapEl.innerHTML='<div style="height:100%;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center;color:#94a3b8">Google Maps API key belum dikonfigurasi di server.</div>';
      hudConn.textContent='map config'; startPolling();
    }
  })();
  </script>
  <?php if ($googleMapsApiKey !== ''): ?>
  <script src="https://maps.googleapis.com/maps/api/js?key=<?= rawurlencode($googleMapsApiKey) ?>&callback=initRmiTrackingMap&v=weekly&loading=async" async></script>
  <?php endif; ?>

</body>
</html>
