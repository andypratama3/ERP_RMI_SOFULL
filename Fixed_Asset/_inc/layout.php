<?php
require_once __DIR__ . '/../../_shared/assets.php'; // RMI asset loader
// Fixed_Asset_Lite/_inc/layout.php
require_once __DIR__ . '/bootstrap.php';

// Explicit auth guard (for static scan + safety)
if (function_exists('require_login')) { require_login(); }


function fa_header(string $title){
    global $BASE_FA, $BASE_PROJECT;
    $flash = flash_get();
    require_once __DIR__ . '/../../_shared/rmi_layout.php';

    $extraHead = '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209">' .
      '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209">';

    rmi_header($title, [
      'active' => 'fixed_asset',
      'subtitle' => 'Fase 1–3 + Audit • Depresiasi fiskal Indonesia',
      'extra_head' => $extraHead,
    ]);
?>
<div class="rmi-card mb-3">
  <div class="rmi-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="fw-semibold">RMI • Fixed Asset (Lite)</div>
    <div class="rmi-muted small">User: <?= h($_SESSION['username'] ?? '-') ?></div>
  </div>
  <div class="card-body">
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_FA) ?>/index.php">Dashboard</a>
      <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_FA) ?>/assets.php">Asset Register</a>
      <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_FA) ?>/depreciation.php">Depresiasi</a>
      <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_FA) ?>/tax_annual.php">Pajak Tahunan</a>
      <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_FA) ?>/ops.php">Operasional</a>
      <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_FA) ?>/audit.php">Audit / Stock Opname</a>
      <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_PROJECT) ?>/master/master_data.php">Kembali ke Master</a>
      <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_PROJECT) ?>/master/logout.php">Logout</a>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>
<?php
}

function fa_footer(){
  rmi_ui_set_extra_js(
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>' .
    '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>' .
    '<script>' .
      'window.initDT = function(sel){' .
        'try{' .
          '$(sel).DataTable({' .
            'pageLength:25,dom:"Bfrtip",' .
            'buttons:[' .
              '{extend:"copy",className:"btn btn-sm btn-outline-light"},' .
              '{extend:"csv",className:"btn btn-sm btn-outline-light"},' .
              '{extend:"excel",className:"btn btn-sm btn-outline-light"},' .
              '{extend:"pdf",className:"btn btn-sm btn-outline-light"},' .
              '{extend:"print",className:"btn btn-sm btn-outline-light"}' .
            ']' .
          '});' .
        '}catch(e){}' .
      '}' .
    '</script>'
  );
  rmi_footer();
}
?>