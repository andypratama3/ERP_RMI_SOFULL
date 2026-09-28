<?php
/**
 * Generate Excel template migrasi data (12 sheet + REF).
 * Jalankan dari root project:
 *   php docs/governance/data_migration/tools/generate_migration_excel.php
 * Output: docs/governance/data_migration/templates/RMI_Migration_Template.xlsx
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/vendor/autoload.php';
require_once $root . '/tools/_migration_template_builder.php';

$spreadsheet = mig_build_spreadsheet();

$outDir  = dirname(__DIR__) . '/templates';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/RMI_Migration_Template.xlsx';

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save($outPath);

echo "✅ Template berhasil dibuat: $outPath\n";
echo "   Sheet: 1_Manufactures, 2_Vendors, 3_Customers, 4_Products,\n";
echo "          5_Stock, 6_AP, 7_AR, 8_Office, 9_Bank,\n";
echo "          10_GL_Opening, 11_FixedAssets, 12_Cara_Pengisian, REF_ValidValues\n";
echo "\n";
echo "   Upload via: Tools → Upload Migrasi Data\n";
