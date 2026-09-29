<?php
declare(strict_types=1);
require_once __DIR__ . '/../_shared/rmi_icons.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
if (function_exists('auth_require_login')) auth_require_login(); else require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.RBAC_MANAGE','SYSTEM.RBAC_VIEW']);
}
require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();

rmi_header('Panduan RBAC Center', [
    'active'      => 'rbac',
    'breadcrumbs' => [['label'=>'RBAC Center','url'=>$base.'/rbac/index.php'], 'Panduan'],
    'actions'     => [
        ['label'=>rmi_icon('gear') . ' Buka RBAC Center','url'=>$base.'/rbac/index.php','class'=>'btn btn-sm btn-rmi'],
    ],
]);
?>
<style>
.pd-hero{background:linear-gradient(135deg,rgba(59,130,246,.12),rgba(139,92,246,.08));border:1px solid rgba(59,130,246,.3);border-radius:16px;padding:24px 28px;margin-bottom:22px}
.pd-section{background:var(--rmi-card,#1a2235);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px 24px;margin-bottom:16px}
.pd-section h3{font-size:15px;font-weight:700;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.pd-steps{display:flex;flex-direction:column;gap:10px}
.pd-step{display:flex;gap:12px;align-items:flex-start}
.pd-num{flex-shrink:0;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;background:rgba(59,130,246,.2);border:1px solid rgba(59,130,246,.35);color:#93c5fd}
.pd-content{flex:1;font-size:13px;line-height:1.6;padding-top:4px}
.pd-content b{color:#e2e8f0}
.pd-content code{background:rgba(255,255,255,.08);padding:1px 6px;border-radius:4px;font-size:11px;font-family:ui-monospace,monospace;color:#c7d2fe}
.pd-warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:10px 14px;font-size:12px;color:#fcd34d;margin-top:12px}
.pd-ok{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:10px;padding:10px 14px;font-size:12px;color:#86efac;margin-top:12px}
.pd-info{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:10px 14px;font-size:12px;color:#93c5fd;margin-top:12px}
.action-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-top:12px}
.action-chip{padding:8px 12px;border-radius:10px;font-size:11px;font-weight:700;text-align:center;border:1px solid}
.ac-access {background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.3);color:#6ee7b7}
.ac-create {background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.3);color:#93c5fd}
.ac-approve{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.3);color:#fcd34d}
.ac-edit   {background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.3);color:#c4b5fd}
.ac-view   {background:rgba(156,163,175,.1);border-color:rgba(156,163,175,.3);color:#d1d5db}
.ac-delete {background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#fca5a5}
.ac-import {background:rgba(6,182,212,.1);border-color:rgba(6,182,212,.3);color:#67e8f9}
.ac-export {background:rgba(6,182,212,.1);border-color:rgba(6,182,212,.3);color:#67e8f9}
.ac-print  {background:rgba(168,85,247,.1);border-color:rgba(168,85,247,.3);color:#d8b4fe}
.flow{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0}
.flow-step{padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1)}
.flow-arrow{color:rgba(255,255,255,.3);font-size:14px}
table.pd-tbl{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}
.pd-tbl th{padding:7px 12px;text-align:left;font-size:10px;font-weight:700;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid rgba(255,255,255,.08)}
.pd-tbl td{padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.pd-tbl tr:hover td{background:rgba(255,255,255,.02)}
.badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700}
.b-sys{background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.25)}
.b-staff{background:rgba(59,130,246,.15);color:#93c5fd;border:1px solid rgba(59,130,246,.25)}
.b-mgr{background:rgba(168,85,247,.15);color:#d8b4fe;border:1px solid rgba(168,85,247,.25)}
.b-ok{background:rgba(34,197,94,.15);color:#86efac}
.b-no{background:rgba(239,68,68,.15);color:#fca5a5}
.scenario{background:rgba(0,0,0,.2);border-radius:10px;padding:12px 16px;margin-top:10px;font-size:12px}
.scenario .s-title{font-weight:700;color:#e2e8f0;margin-bottom:6px}
.scenario code{color:#c7d2fe;font-family:ui-monospace,monospace;font-size:11px}
</style>

<!-- Hero -->
<div class="pd-hero">
  <h4 style="margin:0 0 6px;font-size:18px"><?= rmi_icon('gear') ?> Panduan RBAC Center</h4>
  <div style="font-size:13px;opacity:.8;line-height:1.6">
    Panduan lengkap untuk Admin SYS mengatur akses user di ERP RMI.<br>
    RBAC Center mengelola <b>226 halaman</b> × <b>9 aksi</b> × semua Dept/Role.
  </div>
</div>

<!-- 1. Konsep Dasar -->
<div class="pd-section">
  <h3><?= rmi_icon('target') ?> 1. Konsep Dasar RBAC ERP</h3>
  <div class="flow">
    <div class="flow-step">RBAC Center<br><small style="opacity:.5">assign permission</small></div>
    <div class="flow-arrow">→</div>
    <div class="flow-step">Permission aktif<br><small style="opacity:.5">di database</small></div>
    <div class="flow-arrow">→</div>
    <div class="flow-step">Menu sidebar<br><small style="opacity:.5">tampil otomatis</small></div>
    <div class="flow-arrow">+</div>
    <div class="flow-step">Halaman terbuka<br><small style="opacity:.5">guard pass</small></div>
  </div>
  <div class="pd-ok"><?= rmi_icon('check') ?> <b>Prinsip utama:</b> Assign permission di RBAC Center → menu sidebar langsung muncul untuk user yang bersangkutan — tanpa perlu atur Nav Manager terpisah.</div>

  <table class="pd-tbl" style="margin-top:16px">
    <thead><tr><th>Siapa</th><th>Behavior</th><th>Keterangan</th></tr></thead>
    <tbody>
      <tr><td><span class="badge b-sys">SYS</span></td><td>Allow All otomatis</td><td>Semua halaman terbuka, semua menu tampil, bypass matrix</td></tr>
      <tr><td><span class="badge b-mgr">MANAGER</span></td><td>Ikut matrix Dept+Role</td><td>Cek <code>rbac_dept_role_permissions</code></td></tr>
      <tr><td><span class="badge b-staff">STAFF</span></td><td>Ikut matrix Dept+Role</td><td>Sama, tapi biasanya permission lebih terbatas</td></tr>
    </tbody>
  </table>
</div>

<!-- 2. Dua Tab Utama -->
<div class="pd-section">
  <h3><?= rmi_icon('gear') ?> 2. Dua Tab Utama RBAC Center</h3>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:4px">
    <div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:12px;padding:14px">
      <div style="font-size:13px;font-weight:700;color:#6ee7b7;margin-bottom:8px"><?= rmi_icon('clipboard') ?> Per Halaman</div>
      <div style="font-size:12px;line-height:1.6;color:var(--rmi-muted,#9ca3af)">
        Tampilkan 226 halaman nyata × 9 aksi.<br>
        Pilih dept → klik <?= rmi_icon('tick') ?>/<?= rmi_icon('x') ?> per aksi per halaman.<br>
        <b>Kolom STAFF & MANAGER side by side.</b><br>
        Klik toggle → langsung simpan ke DB.
      </div>
    </div>
    <div style="background:rgba(168,85,247,.06);border:1px solid rgba(168,85,247,.2);border-radius:12px;padding:14px">
      <div style="font-size:13px;font-weight:700;color:#c084fc;margin-bottom:8px"><?= rmi_icon('user') ?> Per User Active</div>
      <div style="font-size:12px;line-height:1.6;color:var(--rmi-muted,#9ca3af)">
        Pilih satu user dari dropdown.<br>
        Lihat permission efektif (matrix + override).<br>
        <b>Bisa toggle override per-user individual.</b><br>
        <?= rmi_icon('tick') ?> hijau = dari matrix · <?= rmi_icon('tick') ?> teal = override grant · <?= rmi_icon('x') ?> oranye = override deny
      </div>
    </div>
  </div>

  <div class="pd-info" style="margin-top:12px">
    <?= rmi_icon('question') ?> <b>Perbedaan kritis:</b>
    <b>Per Halaman</b> mengedit matrix <code>Dept+Role</code> (berlaku untuk SEMUA user dept itu).
    <b>Per User Active</b> mengedit override <code>per-user individual</code> via <code>rbac_user_permissions</code>.
  </div>
</div>

<!-- 3. 9 Kolom Aksi -->
<div class="pd-section">
  <h3><?= rmi_icon('zap') ?> 3. Arti 9 Kolom Aksi</h3>
  <div class="action-grid">
    <div class="action-chip ac-access"><div>ACCESS</div><div style="font-weight:400;margin-top:2px;opacity:.7">Buka halaman</div></div>
    <div class="action-chip ac-create"><div>CREATE</div><div style="font-weight:400;margin-top:2px;opacity:.7">Buat data baru</div></div>
    <div class="action-chip ac-approve"><div>APPROVE</div><div style="font-weight:400;margin-top:2px;opacity:.7">Approve/finalisasi</div></div>
    <div class="action-chip ac-edit"><div>EDIT</div><div style="font-weight:400;margin-top:2px;opacity:.7">Ubah data</div></div>
    <div class="action-chip ac-view"><div>VIEW</div><div style="font-weight:400;margin-top:2px;opacity:.7">Lihat detail</div></div>
    <div class="action-chip ac-delete"><div>DELETE</div><div style="font-weight:400;margin-top:2px;opacity:.7">Hapus data</div></div>
    <div class="action-chip ac-import"><div>IMPORT</div><div style="font-weight:400;margin-top:2px;opacity:.7">Import bulk CSV</div></div>
    <div class="action-chip ac-export"><div>EXPORT</div><div style="font-weight:400;margin-top:2px;opacity:.7">Export CSV/Excel</div></div>
    <div class="action-chip ac-print"><div>PRINT</div><div style="font-weight:400;margin-top:2px;opacity:.7">Print/PDF</div></div>
  </div>
  <div class="pd-warn" style="margin-top:14px">
    <?= rmi_icon('target') ?> <b>APPROVE khusus</b> (AP Payment, GL Reversal, Payroll Lock) tampil sebagai baris tersendiri bertanda <?= rmi_icon('target') ?> dan hanya bisa di-assign SYS ke user tertentu (MgrFIN_BGR / MgrHRL). Toggle via <b>Per User Active</b>.
  </div>
</div>

<!-- 4. Cara Atur Akses -->
<div class="pd-section">
  <h3><?= rmi_icon('zap') ?> 4. Cara Atur Akses — Langkah per Langkah</h3>

  <div style="font-size:13px;font-weight:700;margin-bottom:10px">Skenario: Beri CRM/Staff akses Sales</div>
  <div class="pd-steps">
    <div class="pd-step">
      <div class="pd-num">1</div>
      <div class="pd-content">Buka <a href="<?= $base ?>/rbac/index.php" style="color:#93c5fd">RBAC Center</a> → tab <b><?= rmi_icon('clipboard') ?> Per Halaman</b></div>
    </div>
    <div class="pd-step">
      <div class="pd-num">2</div>
      <div class="pd-content">Di bar <b>DEPT</b> — klik <code>CRM</code></div>
    </div>
    <div class="pd-step">
      <div class="pd-num">3</div>
      <div class="pd-content">Lihat kolom <b>◀ STAFF ▶</b> di sebelah kiri dan <b>◀ MANAGER ▶</b> di kanan</div>
    </div>
    <div class="pd-step">
      <div class="pd-num">4</div>
      <div class="pd-content">Scroll ke seksi <b>CRM / SALES</b> → klik <?= rmi_icon('x') ?> untuk jadi <?= rmi_icon('tick') ?> di kolom yang diinginkan (misal: ACCESS, CREATE, EDIT, VIEW)</div>
    </div>
    <div class="pd-step">
      <div class="pd-num">5</div>
      <div class="pd-content">Perubahan <b>langsung tersimpan</b> (tanpa klik Save) — icon berubah + animasi kecil konfirmasi <?= rmi_icon('check') ?></div>
    </div>
    <div class="pd-step">
      <div class="pd-num">6</div>
      <div class="pd-content"><b>Otomatis:</b> menu Sales Dashboard, Control Tower, DO akan muncul di sidebar semua user CRM/Staff saat refresh</div>
    </div>
  </div>

  <div style="margin-top:20px;font-size:13px;font-weight:700;margin-bottom:10px">Skenario: Override akses untuk satu user spesifik</div>
  <div class="pd-steps">
    <div class="pd-step">
      <div class="pd-num">1</div>
      <div class="pd-content">Buka tab <b><?= rmi_icon('user') ?> Per User Active</b></div>
    </div>
    <div class="pd-step">
      <div class="pd-num">2</div>
      <div class="pd-content">Pilih user dari dropdown (dikelompokkan per dept)</div>
    </div>
    <div class="pd-step">
      <div class="pd-num">3</div>
      <div class="pd-content">Lihat tabel 226 halaman × 9 aksi untuk user tersebut:<br>
        <span style="color:#6ee7b7;font-weight:700"><?= rmi_icon('tick') ?> hijau</span> = dari matrix dept/role &nbsp;
        <span style="color:#34d399;font-weight:700"><?= rmi_icon('tick') ?> teal</span> = override grant (ditambah manual) &nbsp;
        <span style="color:#f59e0b;font-weight:700"><?= rmi_icon('x') ?> oranye</span> = override deny (dicabut manual)
      </div>
    </div>
    <div class="pd-step">
      <div class="pd-num">4</div>
      <div class="pd-content">Klik <?= rmi_icon('tick') ?>/<?= rmi_icon('x') ?> untuk tambah/cabut override <b>khusus user ini saja</b> (tidak mempengaruhi user lain di dept yang sama)</div>
    </div>
  </div>
</div>

<!-- 5. Tools Sidebar -->
<div class="pd-section">
  <h3><?= rmi_icon('gear') ?> 5. Tools di Sidebar RBAC Center</h3>
  <table class="pd-tbl">
    <thead><tr><th>Tool</th><th>Fungsi</th><th>Kapan Dipakai</th></tr></thead>
    <tbody>
      <tr>
        <td><b><?= rmi_icon('refresh') ?> Sync Full Permissions</b></td>
        <td>Update DB dari <code>config/rbac_permissions.php</code></td>
        <td>Setelah developer tambah permission baru ke config</td>
      </tr>
      <tr>
        <td><b><?= rmi_icon('cross') ?> Hapus Orphan Registry</b></td>
        <td>Hapus permission di DB yang sudah tidak ada di config</td>
        <td>Setelah permission lama dihapus dari config (cleanup)</td>
      </tr>
      <tr>
        <td><b><?= rmi_icon('inbox') ?> Export JSON</b></td>
        <td>Backup konfigurasi permission dept/role aktif</td>
        <td>Sebelum perubahan besar / untuk dokumentasi</td>
      </tr>
      <tr>
        <td><b><?= rmi_icon('clipboard') ?> Salin</b></td>
        <td>Salin semua permission dari satu dept/role ke dept/role lain</td>
        <td>Onboarding dept baru dengan pola mirip dept lain</td>
      </tr>
      <tr>
        <td><b><?= rmi_icon('box') ?> Paket</b></td>
        <td>Terapkan preset cepat (VIEW Saja, Ops Standar, dll.)</td>
        <td>Setup awal user baru / reset cepat</td>
      </tr>
      <tr>
        <td><b><?= rmi_icon('warn') ?> Default</b></td>
        <td>Reset ke RBAC baseline aman</td>
        <td>Hanya untuk setup awal sistem</td>
      </tr>
      <tr>
        <td><b><?= rmi_icon('inbox') ?> Import JSON</b></td>
        <td>Restore backup JSON permission</td>
        <td>Restore setelah backup Export JSON</td>
      </tr>
    </tbody>
  </table>
  <div class="pd-warn">
    <?= rmi_icon('warn') ?> <b>Urutan wajib setelah tambah permission baru:</b><br>
    1. Sync Full Permissions → 2. Hapus Orphan Registry → 3. Set permission di Per Halaman
  </div>
</div>

<!-- 6. Warna & Indikator -->
<div class="pd-section">
  <h3><?= rmi_icon('memo') ?> 6. Arti Warna & Indikator</h3>
  <table class="pd-tbl">
    <thead><tr><th>Simbol</th><th>Warna</th><th>Arti (Per Halaman)</th><th>Arti (Per User)</th></tr></thead>
    <tbody>
      <tr><td style="font-size:18px;font-weight:700;color:#4ade80"><?= rmi_icon('tick') ?></td><td>Hijau</td><td>Permission aktif untuk STAFF</td><td>Dari matrix dept/role</td></tr>
      <tr><td style="font-size:18px;font-weight:700;color:#a78bfa"><?= rmi_icon('tick') ?></td><td>Ungu</td><td>Permission aktif untuk MANAGER</td><td>—</td></tr>
      <tr><td style="font-size:18px;font-weight:700;color:#34d399"><?= rmi_icon('tick') ?></td><td>Teal</td><td>—</td><td>Override grant (ditambah manual untuk user ini)</td></tr>
      <tr><td style="font-size:18px;font-weight:700;color:#ef444466"><?= rmi_icon('x') ?></td><td>Merah pudar</td><td>Belum di-assign</td><td>Tidak punya permission ini</td></tr>
      <tr><td style="font-size:18px;font-weight:700;color:#f59e0b"><?= rmi_icon('x') ?></td><td>Oranye</td><td>—</td><td>Override deny (dicabut dari matrix)</td></tr>
      <tr><td style="font-size:18px;font-weight:700;color:#fcd34d"><?= rmi_icon('tick') ?></td><td>Emas</td><td>SYS = Allow All</td><td>User Privileged = Allow All</td></tr>
      <tr><td style="font-size:16px;color:var(--rmi-muted)">—</td><td>Abu gelap</td><td>Aksi tidak relevan untuk halaman ini</td><td>Sama</td></tr>
      <tr><td style="font-size:14px;color:#fcd34d"><?= rmi_icon('target') ?></td><td>Emas bintang</td><td>Permission Central Approver — SYS assign manual</td><td>Sama</td></tr>
    </tbody>
  </table>
</div>

<!-- 7. ⭐ Special Permissions -->
<div class="pd-section">
  <h3><?= rmi_icon('target') ?> 7. Permission Special (Central Approver)</h3>
  <div style="font-size:13px;line-height:1.6;margin-bottom:12px">
    Tiga permission ini <b>tidak bisa di-toggle via Per Halaman</b> karena risikonya sangat tinggi (uang keluar dari perusahaan). Hanya SYS yang bisa assign via <b>Per User Active</b>.
  </div>
  <table class="pd-tbl">
    <thead><tr><th>Permission</th><th>Halaman</th><th>Siapa yang bisa dapat</th></tr></thead>
    <tbody>
      <tr>
        <td><code>PURCHASES.AP_PAYMENT_APPROVE_POST</code></td>
        <td>AP Payment — Approve/Post</td>
        <td>Hanya <b>MgrFIN_BGR</b></td>
      </tr>
      <tr>
        <td><code>PURCHASES.GL_REVERSAL_APPROVE</code></td>
        <td>GL Reversal — Approve</td>
        <td>Hanya <b>MgrFIN_BGR</b></td>
      </tr>
      <tr>
        <td><code>PAYROLL.APPROVE</code></td>
        <td>Payroll — Post/Lock Run</td>
        <td>Hanya <b>MgrFIN</b> atau <b>MgrHRL</b></td>
      </tr>
    </tbody>
  </table>
  <div class="pd-steps" style="margin-top:14px">
    <div style="font-size:13px;font-weight:700;margin-bottom:8px">Cara assign:</div>
    <div class="pd-step">
      <div class="pd-num">1</div>
      <div class="pd-content">Buka tab <b><?= rmi_icon('user') ?> Per User Active</b></div>
    </div>
    <div class="pd-step">
      <div class="pd-num">2</div>
      <div class="pd-content">Pilih user yang berhak (misal: <code>MgrFIN_BGR</code>)</div>
    </div>
    <div class="pd-step">
      <div class="pd-num">3</div>
      <div class="pd-content">Scroll ke seksi <b>FIN</b> → baris <b><?= rmi_icon('target') ?> AP Approve/Post</b> → klik <?= rmi_icon('x') ?> di kolom APPROVE → jadi <?= rmi_icon('tick') ?></div>
    </div>
  </div>
</div>

<!-- 8. Skenario Umum -->
<div class="pd-section">
  <h3><?= rmi_icon('clipboard') ?> 8. Skenario Umum Admin</h3>

  <div class="scenario">
    <div class="s-title"><?= rmi_icon('zap') ?> Onboarding User Baru (misal: StaffCRM_BGR)</div>
    <ol style="margin:6px 0 0 16px;font-size:12px;line-height:1.8">
      <li>Pastikan dept/role CRM/STAFF sudah punya permission di <b>Per Halaman</b></li>
      <li>Jika belum: set ACCESS + VIEW untuk halaman yang diperlukan (DO, Dashboard, Control Tower)</li>
      <li>User login → menu langsung muncul sesuai permission CRM/Staff</li>
      <li>Jika perlu akses ekstra di luar pola dept: gunakan <b>Per User Active</b> untuk override</li>
    </ol>
  </div>

  <div class="scenario" style="margin-top:10px">
    <div class="s-title"><?= rmi_icon('refresh') ?> Ubah Akses Satu Halaman untuk Semua Staff HRL</div>
    <ol style="margin:6px 0 0 16px;font-size:12px;line-height:1.8">
      <li>Buka <b>Per Halaman</b> → pilih dept <code>HRL</code></li>
      <li>Cari baris halaman yang ingin diubah (misal: Rekap Absensi)</li>
      <li>Klik <?= rmi_icon('x') ?> di kolom STAFF → ACCESS, VIEW, EXPORT jadi <?= rmi_icon('tick') ?></li>
      <li>Semua user HRL/Staff otomatis bisa akses</li>
    </ol>
  </div>

  <div class="scenario" style="margin-top:10px">
    <div class="s-title"><?= rmi_icon('search') ?> Investigasi: Kenapa user X tidak bisa akses halaman Y?</div>
    <ol style="margin:6px 0 0 16px;font-size:12px;line-height:1.8">
      <li>Buka <b>Per User Active</b> → pilih user X</li>
      <li>Cari halaman Y → lihat apakah ACCESS = <?= rmi_icon('x') ?> (merah) atau <?= rmi_icon('x') ?> oranye (override deny)</li>
      <li>Jika <?= rmi_icon('x') ?> merah: tambah permission di <b>Per Halaman</b> untuk dept user tersebut</li>
      <li>Jika <?= rmi_icon('x') ?> oranye: ada override deny di user ini → klik untuk hapus override</li>
    </ol>
  </div>

  <div class="scenario" style="margin-top:10px">
    <div class="s-title"><?= rmi_icon('cross') ?> Cabut akses user tertentu tanpa mempengaruhi user lain</div>
    <ol style="margin:6px 0 0 16px;font-size:12px;line-height:1.8">
      <li>Buka <b>Per User Active</b> → pilih user yang ingin dicabut aksesnya</li>
      <li>Klik <?= rmi_icon('tick') ?> pada permission yang ingin dicabut → jadi <?= rmi_icon('x') ?> oranye (override deny)</li>
      <li>User ini tidak bisa akses, user lain di dept sama tidak terpengaruh</li>
    </ol>
  </div>
</div>

<!-- 9. FAQ -->
<div class="pd-section">
  <h3><?= rmi_icon('question') ?> 9. FAQ</h3>
  <table class="pd-tbl">
    <thead><tr><th style="width:45%">Pertanyaan</th><th>Jawaban</th></tr></thead>
    <tbody>
      <tr>
        <td><b>Sudah assign permission tapi menu tidak muncul?</b></td>
        <td>User perlu logout → login ulang agar session refresh. Atau cek apakah permission yang di-assign adalah kolom <b>ACCESS</b> (bukan hanya VIEW).</td>
      </tr>
      <tr>
        <td><b>Berapa permission total di sistem?</b></td>
        <td><b>410 permission</b> dari config. Cek DB: Sidebar → tab Sync → angka di atas tombol Sync.</td>
      </tr>
      <tr>
        <td><b>Apa beda Sync dan Hapus Orphan?</b></td>
        <td><b>Sync</b> = tambahkan permission baru ke DB (additive, tidak hapus yang ada). <b>Hapus Orphan</b> = hapus permission di DB yang sudah dihapus dari config.</td>
      </tr>
      <tr>
        <td><b>Per User vs Per Halaman — mana yang lebih kuat?</b></td>
        <td><b>Per User (override)</b> menang atas <b>Per Halaman (matrix)</b>. Override deny mencabut permission meski matrix memberi akses.</td>
      </tr>
      <tr>
        <td><b>SYS harus assign permission juga?</b></td>
        <td>Tidak. SYS bypass semua permission check di runtime — always allow all. Matrix SYS/SYS di DB hanya untuk akun non-privileged ber-dept System.</td>
      </tr>
      <tr>
        <td><b>Bagaimana memberi akses cross-dept?</b></td>
        <td>Gunakan <b>Per User Active</b> → override grant untuk halaman spesifik. Misal: user HRL perlu lihat Sales Dashboard → override grant ACCESS di baris Sales Dashboard untuk user tersebut.</td>
      </tr>
    </tbody>
  </table>
</div>

<!-- 10. Referensi Cepat -->
<div class="pd-section">
  <h3><?= rmi_icon('zap') ?> 10. Referensi Cepat</h3>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div>
      <div style="font-size:11px;font-weight:700;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">File Konfigurasi</div>
      <div style="font-size:12px;line-height:2">
        <code>config/rbac_permissions.php</code> — 410 permission (source of truth)<br>
        <code>config/page_registry.php</code> — 226 halaman × 9 aksi<br>
        <code>config/doc_numbering.php</code> — format nomor DO/PO/PR<br>
        <code>_shared/nav_config.php</code> — susunan menu sidebar<br>
        <code>_shared/rmi_layout.php</code> — permission control sidebar
      </div>
    </div>
    <div>
      <div style="font-size:11px;font-weight:700;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Tabel Database</div>
      <div style="font-size:12px;line-height:2">
        <code>rbac_permissions</code> — registry semua permission<br>
        <code>rbac_dept_role_permissions</code> — matrix Dept+Role ← Per Halaman<br>
        <code>rbac_user_permissions</code> — override per-user ← Per User Active<br>
        <code>system_audit_logs</code> — log semua perubahan RBAC
      </div>
    </div>
  </div>
  <div class="pd-info" style="margin-top:14px">
    <?= rmi_icon('chart') ?> <b>Audit Log:</b> Semua perubahan permission (siapa, kapan, permission apa) tercatat di bagian bawah RBAC Center (<?= rmi_icon('clipboard') ?> Audit Log RBAC). Bisa digunakan untuk investigasi.
  </div>
</div>

<?php rmi_footer(); ?>
