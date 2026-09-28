<?php
declare(strict_types=1);
require_once __DIR__ . '/_inc/layout.php';
require_once __DIR__ . '/_inc/fa_helpers.php';

if (function_exists('require_login')) {
    require_login();
}

fa_preflight_or_die($pdo);
rbac_require('FIXED_ASSET.VIEW');

fa_header('Panduan Fixed Asset');
?>
<div class="card bg-white p-3">
  <div class="h5 mb-3">Ringkasan modul</div>
  <p class="text-muted small mb-2">Modul <strong>aset tetap</strong>: register aset, operasi (akuisisi, maintenance, disposal),
    depresiasi fiskal, audit internal, dan laporan pajak tahunan (sesuai konfigurasi).</p>
  <ul class="small">
    <li><strong>Dashboard</strong> — ringkasan jumlah aset, depresiasi terakhir, audit terbuka.</li>
    <li><strong>Asset Register</strong> — daftar &amp; mutasi aset (hak: <code>FIXED_ASSET.ASSET_*</code>).</li>
    <li><strong>Operasional</strong> — alur akuisisi cepat, transfer, maintenance, disposal (<code>FIXED_ASSET.OPERATIONS</code>).</li>
    <li><strong>Depresiasi</strong> — run periodik (<code>FIXED_ASSET.DEPRECIATION_RUN</code>).</li>
    <li><strong>Pajak tahunan</strong> — export/rekap (<code>FIXED_ASSET.TAX_ANNUAL</code>).</li>
    <li><strong>Audit</strong> — opname / audit trail aset.</li>
  </ul>
  <p class="text-muted small mb-0">Detail permission: <strong>RBAC Center</strong> → katalog <code>FIXED_ASSET.*</code>. Dokumentasi tambahan: <code>docs/modules/fixed_asset.md</code>.</p>
</div>
<?php fa_footer(); ?>
