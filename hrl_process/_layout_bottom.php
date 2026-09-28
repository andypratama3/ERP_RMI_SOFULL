<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
// Layout partial: block direct URL access
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker (login enforced in page/bootstrap)

rmi_ui_set_extra_js(
  '<script src="' . rmi_assets_base() . '/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>' .
  '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>' .
  '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>' .
  '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>' .
  '<script src="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>' .
  '<script src="' . rmi_assets_base() . '/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>' .
  '<script src="' . rmi_assets_base() . '/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>' .
  '<script src="' . rmi_assets_base() . '/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>'
);
?>

<?php rmi_footer(); ?>
