<?php
declare(strict_types=1);
/**
 * Panduan Dashboard Center — satu-satunya implementasi hub (HTML + _panduan_dashboard_hub.inc.php).
 * URL legacy tanpa konten duplikat: panduan_index.php, panduan_dashboard_center.php → redirect singkat ke file ini.
 */
require_once __DIR__ . '/_dashboard_bootstrap.php';

require_once __DIR__ . '/../_shared/rmi_panduan_helper.php';
rmi_panduan_require_for_erp('dashboards/index.php');

require_once __DIR__ . '/../_shared/rmi_layout.php';

$bp = rmi_layout_base_project();

rmi_header('Panduan Dashboard', [
    'active'      => 'dashboard',
    'subtitle'    => 'Dashboard Center · navigasi & pintasan panduan',
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => $bp . '/dashboards/index.php'],
        'Panduan',
    ],
    'actions' => [
        ['label' => '← Dashboard Center', 'url' => $bp . '/dashboards/index.php', 'class' => 'btn btn-sm btn-primary'],
        ['label' => 'Help Center', 'url' => $bp . '/docs/help_center.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

require __DIR__ . '/_panduan_dashboard_hub.inc.php';

rmi_footer();
