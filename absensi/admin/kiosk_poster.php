<?php
declare(strict_types=1);
/**
 * Kiosk Poster — Halaman cetak QR Code Absensi per kantor
 * Akses: admin only, ?office=BGR
 * Print: Ctrl+P / Cmd+P → pilih ukuran A4
 */
require_once __DIR__ . "/../_inc/bootstrap.php";
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.OFFICE_SETTINGS');

$_offRole  = strtoupper(trim((string)($ABS_USER['role']  ?? '')));
$_offLevel = strtoupper(trim((string)($ABS_USER['level'] ?? '')));
if (!in_array($_offRole,  ['SYS','ADMIN','SUPERADMIN'], true) &&
    !in_array($_offLevel, ['SYS','ADMIN','SUPERADMIN'], true)) {
    http_response_code(403); exit('Akses ditolak.');
}

$filterCode = strtoupper(trim((string)($_GET['office'] ?? '')));
$appUrl     = rtrim((string)(getenv('APP_URL') ?: ''), '/');
$kioskBase  = $appUrl . '/absensi/kiosk.php';

// Ambil semua office + kiosk_token
try {
    absensi_add_col_if_missing($pdo, 'absensi_offices', 'kiosk_token', "VARCHAR(64) NULL");
    $st = $pdo->query("SELECT office_code, office_name, kiosk_token FROM absensi_offices WHERE is_active=1 ORDER BY office_code");
    $offices = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    exit('Error: ' . $e->getMessage());
}

// Filter jika dipilih
if ($filterCode !== '') {
    $offices = array_filter($offices, fn($o) => $o['office_code'] === $filterCode);
}
$offices = array_filter($offices, fn($o) => !empty($o['kiosk_token']));

if (empty($offices)) {
    exit('<p style="font-family:sans-serif;padding:40px">Belum ada office dengan kiosk token. Generate token dulu di halaman <a href="offices.php">Offices</a>.</p>');
}

// Baca logo SVG dan encode base64
$logoPath = __DIR__ . '/../../assets/rmi_logo.svg';
$logoSrc  = '';
if (file_exists($logoPath)) {
    $logoSrc = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($logoPath));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Poster Kiosk Absensi</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{
  background:#e5e7eb;
  font-family:'Plus Jakarta Sans', -apple-system, sans-serif;
  padding:20px;
}

/* Kontrol print (tidak ikut cetak) */
.print-controls{
  max-width:860px;margin:0 auto 20px;
  display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  background:#1e293b;border-radius:12px;padding:14px 18px;color:#f1f5f9;
}
.print-controls select,.print-controls a{
  padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#f1f5f9;
  text-decoration:none;cursor:pointer;outline:none;
}
.print-controls button{
  padding:8px 20px;border-radius:8px;font-size:13px;font-weight:700;
  background:#22c55e;border:none;color:#fff;cursor:pointer;
}
.print-controls .hint{font-size:11px;color:#94a3b8;margin-left:auto}
@media print {
  .print-controls{display:none}
  body{background:#fff;padding:0}
}

/* ── Poster ────────────────────────── */
.poster-page{
  width:210mm;min-height:297mm;
  background:#fff;
  border-radius:0;
  margin:0 auto 30px;
  display:flex;flex-direction:column;
  overflow:hidden;
  page-break-after:always;
  box-shadow:0 4px 32px rgba(0,0,0,.18);
}
@media print {
  .poster-page{
    width:100%;min-height:100vh;margin:0;box-shadow:none;
    border-radius:0;
  }
  /* Header gradasi + teks putih/gradasi tak terbaca di kertas:
     paksa header terang + teks gelap saat cetak. */
  .poster-header{
    background:#fff !important;
  }
  .poster-brand,
  .poster-brand-sub{
    color:#0f172a !important;
  }
  .poster-brand-name{
    background:none !important;
    -webkit-text-fill-color:#0f172a !important;
    color:#0f172a !important;
  }
}

/* Header bar */
.poster-header{
  background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 60%,#0ea5e9 100%);
  padding:32px 36px 28px;
  display:flex;align-items:center;gap:20px;
}
.poster-logo{
  width:64px;height:64px;flex-shrink:0;
  filter:drop-shadow(0 2px 8px rgba(0,0,0,.4));
}
.poster-brand{color:#fff}
.poster-brand-name{
  font-size:26px;font-weight:900;letter-spacing:-1px;
  background:linear-gradient(90deg,#38bdf8,#34d399);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  background-clip:text;
}
.poster-brand-sub{font-size:12px;color:rgba(255,255,255,.6);margin-top:3px;letter-spacing:.5px}

/* Punch-in badge */
.poster-badge{
  margin-left:auto;
  background:rgba(255,255,255,.1);
  border:1.5px solid rgba(255,255,255,.2);
  border-radius:12px;
  padding:8px 18px;
  color:#fff;
  font-size:13px;font-weight:700;text-align:center;
  white-space:nowrap;
}

/* Main content */
.poster-body{
  flex:1;display:flex;flex-direction:column;align-items:center;
  padding:32px 36px;gap:0;
}

.poster-title{
  font-size:32px;font-weight:900;letter-spacing:-1px;
  color:#0f172a;text-align:center;margin-bottom:4px;
}
.poster-sub{
  font-size:15px;color:#64748b;text-align:center;margin-bottom:6px;
}
.poster-office{
  display:inline-block;
  background:linear-gradient(90deg,#0ea5e9,#22c55e);
  color:#fff;
  font-size:18px;font-weight:800;
  border-radius:50px;padding:8px 28px;
  margin:10px auto 28px;
}

/* QR wrapper */
.poster-qr-wrap{
  position:relative;
  background:linear-gradient(135deg,#f0f9ff,#ecfdf5);
  border:3px solid #bae6fd;
  border-radius:24px;
  padding:24px;
  display:flex;flex-direction:column;align-items:center;gap:14px;
  width:280px;
}
.poster-qr-wrap::before{
  content:'';
  position:absolute;inset:-3px;
  border-radius:26px;
  background:linear-gradient(135deg,#0ea5e9,#22c55e);
  z-index:-1;
}
.poster-qr{
  width:200px;height:200px;
  border-radius:12px;
  background:#fff;
  padding:8px;
  display:block;
}
.poster-qr-label{
  font-size:12px;color:#0369a1;font-weight:700;
  text-align:center;letter-spacing:.5px;
}

/* Steps */
.poster-steps{
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:12px;margin-top:28px;width:100%;
}
.poster-step{
  background:#f8fafc;border:1px solid #e2e8f0;
  border-radius:16px;padding:16px 12px;text-align:center;
}
.step-num{
  width:32px;height:32px;border-radius:50%;
  background:linear-gradient(135deg,#0ea5e9,#22c55e);
  color:#fff;font-size:14px;font-weight:800;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 8px;
}
.step-icon{font-size:22px;margin-bottom:4px}
.step-title{font-size:11px;font-weight:700;color:#0f172a;margin-bottom:3px}
.step-desc{font-size:10px;color:#64748b;line-height:1.4}

/* Footer */
.poster-footer{
  background:#f8fafc;
  border-top:1px solid #e2e8f0;
  padding:14px 36px;
  display:flex;align-items:center;justify-content:space-between;
  font-size:10px;color:#94a3b8;
}
.poster-footer b{color:#475569}
</style>
</head>
<body>

<div class="print-controls">
  <span style="font-weight:700;font-size:14px">🖨 Poster Kiosk Absensi</span>

  <form method="get" style="display:flex;gap:8px;align-items:center">
    <select name="office" onchange="this.form.submit()">
      <option value="">— Semua kantor —</option>
      <?php
      $allOffices = $pdo->query("SELECT office_code, office_name FROM absensi_offices WHERE is_active=1 AND kiosk_token IS NOT NULL ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($allOffices as $ao):
      ?>
      <option value="<?= htmlspecialchars($ao['office_code'],ENT_QUOTES,'UTF-8') ?>"
              <?= $filterCode===$ao['office_code']?'selected':'' ?>>
        <?= htmlspecialchars($ao['office_code'],ENT_QUOTES,'UTF-8') ?> — <?= htmlspecialchars($ao['office_name'],ENT_QUOTES,'UTF-8') ?>
      </option>
      <?php endforeach; ?>
    </select>
  </form>

  <button onclick="window.print()">🖨 Print / Save PDF</button>
  <a href="offices.php">← Kembali</a>
  <span class="hint">Ukuran cetak: A4 Portrait · Scale 100%</span>
</div>

<?php foreach ($offices as $o):
  $oc       = $o['office_code'];
  $oname    = $o['office_name'];
  $tok      = $o['kiosk_token'];
  $kioskUrl = $kioskBase . '?token=' . $tok;
  $qrUrl    = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=10&data=' . urlencode($kioskUrl);
?>
<div class="poster-page">

  <!-- Header -->
  <div class="poster-header">
    <?php if ($logoSrc): ?>
    <img src="<?= $logoSrc ?>" class="poster-logo" alt="Logo RMI">
    <?php endif; ?>
    <div class="poster-brand">
      <div class="poster-brand-name">RMI SOFULL</div>
      <div class="poster-brand-sub">SISTEM INFORMASI MANAJEMEN</div>
    </div>
    <div class="poster-badge">📋 ABSENSI<br>KARYAWAN</div>
  </div>

  <!-- Body -->
  <div class="poster-body">
    <div class="poster-title">Scan untuk Absensi</div>
    <div class="poster-sub">Gunakan kamera HP Anda untuk scan QR code ini</div>
    <div class="poster-office"><?= htmlspecialchars($oname, ENT_QUOTES, 'UTF-8') ?></div>

    <!-- QR Code -->
    <div class="poster-qr-wrap">
      <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>"
           class="poster-qr" width="200" height="200"
           alt="QR Absensi <?= htmlspecialchars($oc, ENT_QUOTES, 'UTF-8') ?>">
      <div class="poster-qr-label">📷 SCAN QR CODE DI SINI</div>
    </div>

    <!-- Steps -->
    <div class="poster-steps">
      <div class="poster-step">
        <div class="step-num">1</div>
        <div class="step-icon">📷</div>
        <div class="step-title">Scan QR Code</div>
        <div class="step-desc">Buka kamera HP dan arahkan ke QR code ini</div>
      </div>
      <div class="poster-step">
        <div class="step-num">2</div>
        <div class="step-icon">🔐</div>
        <div class="step-title">Login ERP</div>
        <div class="step-desc">Masukkan username dan password akun ERP Anda</div>
      </div>
      <div class="poster-step">
        <div class="step-num">3</div>
        <div class="step-icon">🤳</div>
        <div class="step-title">Foto Selfie</div>
        <div class="step-desc">Ambil foto real-time lalu submit Check-in / Check-out</div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="poster-footer">
    <span>Kode Kantor: <b><?= htmlspecialchars($oc, ENT_QUOTES, 'UTF-8') ?></b></span>
    <span>Gunakan <b>jaringan WiFi kantor</b> saat absensi</span>
    <span>ERP RMI © <?= date('Y') ?></span>
  </div>

</div>
<?php endforeach; ?>

</body>
</html>
