<?php
/**
 * dashboards/itc/panduan.php — Panduan ITC Dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../_panduan_helpers.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
require_once __DIR__ . '/../../_shared/rmi_layout.php';

rmi_header('Panduan ITC Dashboard', [
    'active' => 'dashboard',
    'subtitle' => 'User, MFA, health & keamanan',
    'breadcrumbs' => [
        ['label' => 'ITC Dashboard', 'url' => ds_panduan_u('/dashboards/itc/itc_dashboard.php')],
        'Panduan',
    ],
    'actions' => [
        ['label' => rmi_icon('gear').' ITC Dashboard', 'url' => ds_panduan_u('/dashboards/itc/itc_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

echo ds_panduan_styles();
?>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px"><?=rmi_icon('gear')?></div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan IT &amp; Cloud (ITC)</div>
  <div class="pnd-desc" style="color:#94a3b8">
    ITC mengelola <b>akun pengguna</b>, <b>reset password</b>, kebijakan <b>MFA</b>, serta monitoring <b>health</b> dan audit keamanan.
    Staff ITC non-admin menggunakan pintasan terbatas (mis. reset password); SYS/Admin mengelola master login penuh.
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-purple">
      <h5 style="color:#c4b5fd;margin-bottom:12px"><?=rmi_icon('gear')?> Tugas utama</h5>
      <ul class="pnd-list">
        <li>Aktivasi / nonaktif user, reset password — lewat <b>Manajemen User (ITC)</b> atau Master Login (admin).</li>
        <li>Dorong adoption <b>MFA</b>: cek jumlah “Belum MFA” di KPI dashboard.</li>
        <li>Rutin cek <b>Health Check</b> dan <b>Security Audit</b> dari grup Tools.</li>
      </ul>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-teal">
      <h5 style="color:#2dd4bf;margin-bottom:12px"><?=rmi_icon('clipboard')?> SOP singkat</h5>
      <div class="pnd-step">
        <div class="pnd-num">1</div>
        <div>
          <div class="pnd-title">Verifikasi identitas</div>
          <div class="pnd-desc">Sebelum reset password, pastikan pemohon sesuai prosedur internal.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num">2</div>
        <div>
          <div class="pnd-title">Dokumentasikan</div>
          <div class="pnd-desc">Perubahan sensitif sebaiknya tercatat (audit log / tiket).</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:12px"><?=rmi_icon('doc')?> Pintasan</h5>
      <div class="pnd-quick">
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/itc/itc_dashboard.php')) ?>"><?=rmi_icon('gear')?> ITC Dashboard</a>
        <a href="<?= rmi_h(ds_panduan_u('/master/itc_reset_password.php')) ?>"><?=rmi_icon('users')?> Reset Password (ITC)</a>
        <a href="<?= rmi_h(ds_panduan_u('/tools/health.php')) ?>"><?=rmi_icon('check')?> Health Check</a>
        <a href="<?= rmi_h(ds_panduan_u('/tools/security_audit.php')) ?>"><?=rmi_icon('gear')?> Security Audit</a>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
