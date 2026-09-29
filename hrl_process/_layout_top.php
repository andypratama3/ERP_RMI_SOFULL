<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/_inc/bootstrap.php';
require_login(); // explicit guard + static scan marker

$page_title = $page_title ?? 'HRL Process Tower';
$f = flash_get();

require_once __DIR__ . '/../_shared/rmi_layout.php';

$navActiveKey = 'hrl_process';
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'tower.php') {
    $rtNav = strtoupper(trim((string)($_GET['req_type'] ?? '')));
    $hrlTowerNavMap = [
        'CUTI'                => 'hrl_tower_cuti',
        'IZIN'                => 'hrl_tower_izin',
        'LEMBUR'              => 'hrl_tower_lembur',
        'PERJADIN'            => 'hrl_tower_perjadin',
        'PERMINTAAN_KARYAWAN' => 'hrl_tower_permintaan_karyawan',
        'KENAIKAN_GAJI'       => 'hrl_tower_kenaikan_gaji',
        'REKRUTMEN'           => 'hrl_tower_rekrutmen',
    ];
    if ($rtNav !== '' && isset($hrlTowerNavMap[$rtNav])) {
        $navActiveKey = $hrlTowerNavMap[$rtNav];
    }
}

$extraHead = '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/jquery.dataTables.min.css?v=20260209">' .
  '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.dataTables.min.css?v=20260209">';

rmi_header($page_title, [
  'active' => $navActiveKey,
  'subtitle' => 'Pengajuan ke HRL: Cuti/Izin/Lembur/Permintaan Karyawan/Kenaikan Gaji/Rekrutmen (+ Perjadin form-only).',
  'extra_head' => $extraHead,
]);
?>

<div class="rmi-card mb-3">
  <div class="rmi-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div class="fw-semibold">HRL Process Tower</div>
      <span class="badge-pill">EnterprisePPP • Fase 1–3</span>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span class="badge-pill"><?= h(me_username()) ?> • <?= h(me_role() ?: 'STAFF') ?> • <?= h(me_dept() ?: '-') ?> • <?= h(me_office() ?: '-') ?></span>
      <a class="btn btn-ghost btn-sm" href="<?= h(u('/master_data.php')) ?>">Master Data</a>
      <a class="btn btn-ghost btn-sm" href="<?= h(u('/master/logout.php')) ?>">Logout</a>
    </div>
  </div>
  <div class="card-body">
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-soft btn-sm" href="<?= h(um('tower.php')) ?>">Tower</a>
      <a class="btn btn-ghost btn-sm" href="<?= h(um('my_pin.php')) ?>">PIN TTD</a>
      <a class="btn btn-ghost btn-sm" href="<?= h(um('panduan.php')) ?>"><?=rmi_icon('books')?> Panduan</a>
    </div>
  </div>
</div>

<?php if (!empty($f)): ?>
  <div class="alert alert-<?= h($f['type'] ?? 'success') ?>"><?= h($f['msg'] ?? '') ?></div>
<?php endif; ?>
