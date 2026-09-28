<?php
/**
 * tools/migration_download_template.php
 * Generate & download Excel template migrasi (12 sheet + REF).
 * Akses: FIN Manager atau ADMIN/SUPERADMIN.
 */
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
if (function_exists('require_fin_manager_or_admin')) {
    require_fin_manager_or_admin();
} else {
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once __DIR__ . '/_migration_template_builder.php';

$spreadsheet = mig_build_spreadsheet();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="RMI_Migration_Template_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
exit;
