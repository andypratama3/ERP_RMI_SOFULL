<?php
$title="Admin HR • GeoFence Offices (Master Office)";
require_once __DIR__ . "/../_inc/bootstrap.php";
require_once __DIR__ . "/../../master/_audit_master.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.OFFICE_SETTINGS');

require_once __DIR__ . "/../_layout_top.php";

// Guard: Absensi Office Settings — HANYA ADMIN / SUPERADMIN / SYS
// Koordinat GPS kantor adalah data kritis. Tidak ada dept lain yang boleh ubah ini.
$_offRole  = strtoupper(trim((string)($ABS_USER['role']  ?? '')));
$_offLevel = strtoupper(trim((string)($ABS_USER['level'] ?? '')));
$_offIsAdmin = in_array($_offRole,  ['SYS','ADMIN','SUPERADMIN'], true)
            || in_array($_offLevel, ['SYS','ADMIN','SUPERADMIN'], true);

if (!$_offIsAdmin) {
    http_response_code(403);
    echo "<div class='card'><b>Akses ditolak.</b> Pengaturan office hanya untuk <b>ADMIN / SUPERADMIN</b>.</div>";
    require_once __DIR__ . "/../_layout_bottom.php";
    exit;
}

// Pastikan master_office ada
if (!absensi_master_office_exists($pdo)) {
  echo '<div class="card col-12"><div class="h1">GeoFence</div><div class="flash bad">Tabel <b>master_office</b> tidak ditemukan. GeoFence tidak bisa pakai Master Office.</div></div>';
  require_once __DIR__ . "/../_layout_bottom.php";
  exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify_or_die();
  $mode = $_POST['mode'] ?? '';

  if ($mode === 'save_setting') {
    $enforce = (int)($_POST['geofence_enforce'] ?? 1);
    $stmt = $pdo->prepare("UPDATE absensi_settings SET v=?, updated_at=NOW() WHERE k='geofence_enforce'");
    $stmt->execute([strval($enforce)]);
    absensi_audit($pdo, $ABS_USER, 'SETTING_SAVE', ['geofence_enforce'=>$enforce]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'absensi_admin', 'absensi_settings', 'SETTING_SAVE', null, 'geofence_enforce', "Geofence setting saved: enforce={$enforce}", []);
    }
    absensi_flash_set('ok','Setting tersimpan.');
    rmi_redirect('offices.php');
  }

  if ($mode === 'gen_kiosk') {
    // Generate atau regenerate kiosk token untuk office
    $code = strtoupper(trim((string)($_POST['office_code'] ?? '')));
    $code = absensi_office_code_normalize($code);
    if ($code === '') { absensi_flash_set('bad','Office code diperlukan.'); rmi_redirect('offices.php'); }

    // Pastikan kolom kiosk_token ada
    absensi_add_col_if_missing($pdo, 'absensi_offices', 'kiosk_token', "VARCHAR(64) NULL");

    $newToken = bin2hex(random_bytes(20)); // 40 char hex
    $stKiosk = $pdo->prepare("UPDATE absensi_offices SET kiosk_token=?, updated_at=NOW() WHERE office_code=?");
    $ok = $stKiosk->execute([$newToken, $code]);
    if ($ok) {
      absensi_audit($pdo, $ABS_USER, 'KIOSK_TOKEN_GEN', ['office_code'=>$code]);
      absensi_flash_set('ok', "Kiosk token untuk {$code} berhasil di-generate.");
    } else {
      absensi_flash_set('bad','Gagal generate token. Pastikan tabel absensi_offices sudah ada.');
    }
    rmi_redirect('offices.php');
  }

  if ($mode === 'save_office') {
    $code = strtoupper(trim((string)($_POST['office_code'] ?? '')));
    $code = absensi_office_code_normalize($code);
    $lat = ($_POST['lat'] === '' || !isset($_POST['lat'])) ? null : (float)$_POST['lat'];
    $lng = ($_POST['lng'] === '' || !isset($_POST['lng'])) ? null : (float)$_POST['lng'];
    $radius = ($_POST['radius_m'] === '' || !isset($_POST['radius_m'])) ? null : (int)$_POST['radius_m'];

    if ($code === '') {
      absensi_flash_set('bad','Office code wajib dipilih.');
      rmi_redirect('offices.php');
    }

    // Pastikan office ada di master_office
    $row = absensi_office($pdo, $code);
    if (!$row) {
      absensi_flash_set('bad','Office tidak ditemukan di master_office: '.$code);
      rmi_redirect('offices.php');
    }

    $ok = absensi_master_office_update_geofence($pdo, $code, $lat, $lng, $radius);
    if ($ok) {
      absensi_audit($pdo, $ABS_USER, 'MASTER_OFFICE_GEOFENCE_SAVE', ['code'=>$code,'lat'=>$lat,'lng'=>$lng,'radius_m'=>$radius]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'absensi_admin', 'master_office', 'GEOFENCE_SAVE', null, $code, "GeoFence saved for office: {$code}", ['lat' => $lat, 'lng' => $lng, 'radius_m' => $radius]);
      }
      absensi_flash_set('ok','GeoFence office tersimpan (master_office).');
    } else {
      absensi_flash_set('bad','Gagal update GeoFence. Pastikan kolom geofence ada di master_office (office_lat/office_lng/office_radius_m).');
    }
    rmi_redirect('offices.php');
  }
}

$enforceVal = (int)absensi_setting($pdo,'geofence_enforce', (string)ABSENSI_GEOFENCE_ENFORCE_DEFAULT);
$rows = absensi_master_office_list($pdo, false);

// Merge kiosk_token dari absensi_offices
try {
  absensi_add_col_if_missing($pdo, 'absensi_offices', 'kiosk_token', "VARCHAR(64) NULL");
  $stKT = $pdo->query("SELECT office_code, kiosk_token FROM absensi_offices");
  $kioskTokens = [];
  foreach ($stKT->fetchAll(PDO::FETCH_ASSOC) as $kt) {
    $kioskTokens[$kt['office_code']] = $kt['kiosk_token'];
  }
  foreach ($rows as &$r) {
    $r['kiosk_token'] = $kioskTokens[$r['office_code']] ?? null;
  }
  unset($r);
} catch (Throwable $e) {}

$officesJson = json_encode(array_column($rows, null, 'office_code'), JSON_UNESCAPED_UNICODE);
$defaultRadius = (int)ABSENSI_DEFAULT_RADIUS_M;
$kioskBaseUrl = rtrim((string)(getenv('APP_URL') ?: ''), '/') . '/absensi/kiosk.php';

// Statistik log 7 hari terakhir per kantor
$logStats = [];
try {
  $stLog = $pdo->query("
    SELECT office_code,
           COUNT(*) AS total,
           SUM(CASE WHEN distance_m IS NOT NULL AND distance_m > 0 THEN 1 ELSE 0 END) AS with_dist,
           ROUND(AVG(distance_m)) AS avg_dist,
           MAX(distance_m) AS max_dist,
           ROUND(AVG(geo_acc)) AS avg_acc
    FROM absensi_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY office_code
  ");
  foreach ($stLog->fetchAll(PDO::FETCH_ASSOC) as $ls) {
    $logStats[$ls['office_code']] = $ls;
  }
} catch (Throwable $e) { /* table mungkin belum ada */ }
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
#geo-map{height:320px;border-radius:10px;border:1px solid rgba(255,255,255,.12);margin-top:10px}
.radius-preview{font-size:13px;font-weight:700;color:#38bdf8;margin-left:8px}
.office-card-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-top:12px}
.office-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px;cursor:pointer;transition:all .18s}
.office-card:hover{border-color:#38bdf8;background:rgba(56,189,248,.07)}
.office-card.has-geo{border-left:3px solid #22c55e}
.office-card.no-geo{border-left:3px solid #ef4444}
.office-card .oc-code{font-weight:800;font-size:13px}
.office-card .oc-name{font-size:11px;color:#64748b;margin-top:2px}
.office-card .oc-geo{font-size:10px;margin-top:5px;color:#94a3b8}
.office-card .oc-edit{font-size:10px;color:#38bdf8;margin-top:4px;font-weight:600}
.office-card.warn-radius{border-left-color:#f59e0b;background:rgba(245,158,11,.06)}
.kiosk-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);border-radius:8px;padding:3px 10px;font-size:10px;font-weight:700;color:#38bdf8;margin-top:5px}
.kiosk-badge.no-token{background:rgba(148,163,184,.07);border-color:rgba(148,163,184,.2);color:#64748b}
.kiosk-url{font-size:9px;color:#64748b;word-break:break-all;margin-top:3px;display:none}
.kiosk-section{margin-top:18px;padding-top:14px;border-top:1px solid rgba(255,255,255,.07)}
.qr-canvas{display:block;margin:10px auto;border-radius:8px;background:#fff;padding:6px}
</style>

<div class="grid">

  <!-- Navigasi Admin HR -->
  <div class="col-12 d-flex flex-wrap gap-2 mb-1">
    <a class="btn btn-sm btn-outline-secondary" href="rekap.php">📊 Rekap</a>
    <a class="btn btn-sm btn-outline-secondary" href="approval.php">📋 Approval</a>
    <a class="btn btn-sm btn-outline-secondary" href="users.php">👥 Users</a>
    <a class="btn btn-sm btn-outline-secondary" href="payroll_gate.php">💰 Payroll Gate</a>
    <a class="btn btn-sm btn-primary" href="settings.php">⚙️ Jam Kerja</a>
  </div>

  <!-- Info box: GPS accuracy -->
  <div class="card col-12" style="border-left:3px solid #f59e0b;background:rgba(245,158,11,.06)">
    <div style="font-weight:800;font-size:13px;margin-bottom:6px">⚠️ Tentang Kendala Jarak GPS</div>
    <div class="muted small" style="line-height:1.7">
      GPS di HP tidak selalu akurat — bisa meleset <b>30–80 meter</b> tergantung sinyal, gedung, dan cuaca.
      Sistem sudah otomatis menambah <b>toleransi 70% dari akurasi GPS</b> (maks 80m) ke radius kantor.<br>
      Contoh: radius 150m + akurasi GPS 50m → efektif <b>185m</b>.
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
      <div style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:8px 14px;font-size:12px">
        📏 <b>Rekomendasi radius minimum:</b> 150–200m untuk area terbuka, 200–300m untuk gedung/dalam ruangan
      </div>
      <div style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:8px 14px;font-size:12px">
        🔓 Atau ubah mode ke <b>Longgar</b> (tetap catat jarak tapi tidak ditolak)
      </div>
    </div>
  </div>

  <div class="card col-12">
    <div class="h1">GeoFence • Master Office</div>
    <div class="muted small">Koordinat & radius absensi per kantor. Klik kartu kantor untuk edit.</div>

    <form method="post" style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="save_setting">
      <label class="small muted">Mode GeoFence:</label>
      <select name="geofence_enforce">
        <option value="1" <?= $enforceVal===1?'selected':'' ?>>🔒 Wajib (tolak di luar radius)</option>
        <option value="0" <?= $enforceVal===0?'selected':'' ?>>🔓 Longgar (catat jarak, tidak ditolak)</option>
      </select>
      <button class="btn ok" type="submit">Simpan</button>
    </form>
  </div>

  <!-- Daftar Office Cards -->
  <div class="card col-12">
    <div class="h1">Daftar Kantor</div>
    <div class="office-card-row">
      <?php foreach ($rows as $r):
        $hasGeo = ($r['lat'] != 0 || $r['lng'] != 0) && $r['lat'] !== null && $r['lng'] !== null;
        $oc     = $r['office_code'];
        $stat   = $logStats[$oc] ?? null;
        $radius = (int)($r['radius_m'] ?: $defaultRadius);
        // Peringatkan jika avg_dist > 70% radius
        $needsLarger = $stat && $stat['avg_dist'] && (int)$stat['avg_dist'] > $radius * 0.7;
      ?>
      <div class="office-card <?= $hasGeo ? 'has-geo' : 'no-geo' ?> <?= $needsLarger ? 'warn-radius' : '' ?>"
           onclick="openEditor('<?= htmlspecialchars($oc,ENT_QUOTES,'UTF-8') ?>')">
        <div class="oc-code"><?= htmlspecialchars($oc,ENT_QUOTES,'UTF-8') ?></div>
        <div class="oc-name"><?= htmlspecialchars($r['office_name'],ENT_QUOTES,'UTF-8') ?></div>
        <div class="oc-geo">
          <?php if ($hasGeo): ?>
            📍 <?= number_format((float)$r['lat'],6) ?>, <?= number_format((float)$r['lng'],6) ?><br>
            📏 Radius: <b><?= $radius ?>m</b>
            <?php if ($stat && $stat['avg_dist']): ?>
              · Avg jarak: <b><?= (int)$stat['avg_dist'] ?>m</b>
              <?php if ($needsLarger): ?> <span style="color:#fbbf24">⚠ radius mungkin terlalu kecil</span><?php endif; ?>
            <?php endif; ?>
          <?php else: ?>
            ⚠️ Belum ada koordinat
          <?php endif; ?>
        </div>
        <?php if ($stat): ?>
        <div style="font-size:10px;color:#64748b;margin-top:4px">
          7 hari: <?= $stat['total'] ?> absensi
          <?= $stat['avg_acc'] ? '· GPS acc rata2 '.(int)$stat['avg_acc'].'m' : '' ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($r['kiosk_token'])): ?>
        <div class="kiosk-badge">📟 Kiosk aktif</div>
        <?php else: ?>
        <div class="kiosk-badge no-token">📟 Kiosk belum dibuat</div>
        <?php endif; ?>
        <div class="oc-edit">✏️ Klik untuk setup radius & koordinat</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Kiosk Management -->
  <div class="card col-12">
    <div class="h1">📟 Kiosk Absensi</div>
    <div class="muted small" style="margin-bottom:14px">
      Mode kiosk: satu tablet/HP ditempatkan di pintu kantor. Karyawan login dan foto di sana — <b>tidak perlu GPS HP karyawan</b>.
      Titik absensi = koordinat kantor yang sudah disimpan.
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px">
    <?php foreach ($rows as $r):
      $oc    = $r['office_code'];
      $oname = $r['office_name'];
      $tok   = $r['kiosk_token'] ?? null;
      $kioskUrl = $tok ? $kioskBaseUrl.'?token='.$tok : null;
    ?>
    <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:14px">
      <div style="font-weight:800;font-size:13px;margin-bottom:2px"><?= htmlspecialchars($oc,ENT_QUOTES,'UTF-8') ?></div>
      <div style="font-size:11px;color:#64748b;margin-bottom:10px"><?= htmlspecialchars($oname,ENT_QUOTES,'UTF-8') ?></div>

      <?php if ($tok): ?>
        <!-- QR Code via Google Charts API -->
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=<?= urlencode($kioskUrl) ?>"
             alt="QR Kiosk <?= htmlspecialchars($oc,ENT_QUOTES,'UTF-8') ?>"
             class="qr-canvas" width="140" height="140"
             title="Scan QR untuk buka kiosk <?= htmlspecialchars($oc,ENT_QUOTES,'UTF-8') ?>">
        <div style="font-size:10px;color:#64748b;text-align:center;margin-bottom:8px;word-break:break-all">
          <?= htmlspecialchars($kioskUrl,ENT_QUOTES,'UTF-8') ?>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <a href="<?= htmlspecialchars($kioskUrl,ENT_QUOTES,'UTF-8') ?>" target="_blank"
             class="btn ok" style="flex:1;text-align:center;font-size:12px;padding:8px">🖥 Buka Kiosk</a>
          <a href="kiosk_poster.php?office=<?= urlencode($oc) ?>" target="_blank"
             class="btn" style="flex:1;text-align:center;font-size:12px;padding:8px;background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.3);text-decoration:none">🖨 Print Poster</a>
        </div>
        <form method="post" style="margin-top:6px" onsubmit="return confirm('Reset token kiosk <?= htmlspecialchars($oc,ENT_QUOTES,'UTF-8') ?>? Link lama tidak bisa dipakai lagi.')">
          <?= csrf_field() ?>
          <input type="hidden" name="mode" value="gen_kiosk">
          <input type="hidden" name="office_code" value="<?= htmlspecialchars($oc,ENT_QUOTES,'UTF-8') ?>">
          <button class="btn" style="width:100%;font-size:11px;padding:6px;background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.2)">🔄 Reset Token</button>
        </form>
      <?php else: ?>
        <div style="text-align:center;padding:20px 0;color:#64748b;font-size:13px">Belum ada token kiosk</div>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="mode" value="gen_kiosk">
          <input type="hidden" name="office_code" value="<?= htmlspecialchars($oc,ENT_QUOTES,'UTF-8') ?>">
          <button class="btn ok" style="width:100%;font-size:13px;padding:10px">📟 Generate Kiosk Token</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- Editor GeoFence -->
  <div class="card col-12" id="geo-editor" style="display:none">
    <div class="h1">✏️ Setup GeoFence — <span id="geo-editor-title"></span></div>
    <div class="muted small">Klik di peta untuk set koordinat, atau isi manual. Geser lingkaran untuk pindah titik.</div>

    <div id="geo-map"></div>

    <form method="post" style="margin-top:16px" id="geo-form">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="save_office">
      <input type="hidden" name="office_code" id="f-code">

      <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin-top:10px">
        <div>
          <label class="small muted">Latitude</label><br>
          <input id="f-lat" name="lat" placeholder="-6.xxxxxxx" style="width:160px" oninput="updateMapFromInput()">
        </div>
        <div>
          <label class="small muted">Longitude</label><br>
          <input id="f-lng" name="lng" placeholder="106.xxxxxxx" style="width:160px" oninput="updateMapFromInput()">
        </div>
        <div style="flex:1;min-width:200px">
          <label class="small muted">Radius: <span class="radius-preview" id="radius-label"><?= $defaultRadius ?>m</span></label><br>
          <input type="range" id="f-radius-range" min="50" max="1000" step="10" value="<?= $defaultRadius ?>"
                 oninput="syncRadius(this.value)" style="width:100%;margin-top:6px">
          <input type="hidden" name="radius_m" id="f-radius">
          <div style="display:flex;justify-content:space-between;font-size:10px;color:#64748b;margin-top:3px">
            <span>50m</span><span style="color:#f59e0b">⭐ 150–200m</span><span>1000m</span>
          </div>
        </div>
        <div>
          <button class="btn ok" type="submit">💾 Simpan GeoFence</button>
          <button type="button" class="btn" onclick="closeEditor()" style="margin-left:6px">Batal</button>
        </div>
      </div>
      <div class="muted small" style="margin-top:8px">💡 Tip: Klik di peta untuk set titik, atau geser marker.</div>
    </form>
  </div>
</div>

<script>
const OFFICES = <?= $officesJson ?>;
const DEFAULT_RADIUS = <?= $defaultRadius ?>;
let map, marker, circle;

function initMap(lat, lng, radius) {
  if (map) { map.remove(); map = null; }
  const center = (lat && lng) ? [lat, lng] : [-6.2088, 106.8456];
  map = L.map('geo-map').setView(center, lat ? 15 : 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19
  }).addTo(map);

  if (lat && lng) {
    placeMarker(lat, lng, radius);
  }

  map.on('click', function(e) {
    placeMarker(e.latlng.lat, e.latlng.lng, currentRadius());
    document.getElementById('f-lat').value = e.latlng.lat.toFixed(7);
    document.getElementById('f-lng').value = e.latlng.lng.toFixed(7);
  });
}

function placeMarker(lat, lng, radius) {
  if (marker) { marker.remove(); }
  if (circle) { circle.remove(); }
  marker = L.marker([lat, lng], {draggable: true}).addTo(map);
  circle = L.circle([lat, lng], {radius: radius, color:'#38bdf8', fillColor:'#38bdf8', fillOpacity:0.15}).addTo(map);
  map.setView([lat, lng], 16);
  marker.on('dragend', function(e) {
    const pos = e.target.getLatLng();
    document.getElementById('f-lat').value = pos.lat.toFixed(7);
    document.getElementById('f-lng').value = pos.lng.toFixed(7);
    circle.setLatLng(pos);
  });
}

function currentRadius() {
  return parseInt(document.getElementById('f-radius-range').value) || DEFAULT_RADIUS;
}

function syncRadius(val) {
  document.getElementById('radius-label').textContent = val + 'm';
  document.getElementById('f-radius').value = val;
  if (circle) circle.setRadius(parseInt(val));
}

function updateMapFromInput() {
  const lat = parseFloat(document.getElementById('f-lat').value);
  const lng = parseFloat(document.getElementById('f-lng').value);
  if (!isNaN(lat) && !isNaN(lng)) {
    placeMarker(lat, lng, currentRadius());
  }
}

function openEditor(code) {
  const o = OFFICES[code];
  if (!o) return;
  document.getElementById('geo-editor').style.display = '';
  document.getElementById('geo-editor-title').textContent = code + ' — ' + o.office_name;
  document.getElementById('f-code').value = code;
  const lat = parseFloat(o.lat) || 0;
  const lng = parseFloat(o.lng) || 0;
  const r   = parseInt(o.radius_m) || DEFAULT_RADIUS;
  document.getElementById('f-lat').value = lat || '';
  document.getElementById('f-lng').value = lng || '';
  document.getElementById('f-radius-range').value = r;
  document.getElementById('f-radius').value = r;
  document.getElementById('radius-label').textContent = r + 'm';
  document.getElementById('geo-editor').scrollIntoView({behavior:'smooth'});
  setTimeout(() => initMap(lat || null, lng || null, r), 200);
}

function closeEditor() {
  document.getElementById('geo-editor').style.display = 'none';
  if (map) { map.remove(); map = null; }
}

// Pre-fill dari URL hash jika ada ?edit=CODE
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('edit')) openEditor(urlParams.get('edit'));
</script>
<?php require_once __DIR__ . "/../_layout_bottom.php"; ?>
