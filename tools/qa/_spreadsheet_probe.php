<?php
/**
 * tools/qa/_spreadsheet_probe.php
 * Helper internal untuk tools/qa/spreadsheet_regression.sh
 * Menulis XLSX lalu membacanya kembali, dan memeriksa guard ENABLE_EXCEL_EXPORT.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tmp  = $argv[1] ?? sys_get_temp_dir();

require $root . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$fail = 0;
$ok   = static function (string $m) use (&$fail): void {
    echo $m . "\n";
};
$bad  = static function (string $m) use (&$fail): void {
    echo "GAGAL: $m\n";
    $fail = 1;
};

/* ---------- 1. tulis + baca ulang ---------- */
$file = rtrim($tmp, '/') . '/probe.xlsx';
@unlink($file);

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Uji Regression');
$sh->setCellValue('A1', 'Kode');
$sh->setCellValue('B1', 'Nama');
$sh->setCellValue('C1', 'Nilai');
$sh->setCellValue('A2', 'X-1');
$sh->setCellValue('B2', 'Budi');
$sh->setCellValue('C2', 10);
$sh->setCellValue('A3', 'X-2');
$sh->setCellValue('B3', 'Sari');
$sh->setCellValue('C3', 20.5);
$sh->getStyle('C2')->getNumberFormat()->setFormatCode('#,##0.00');
$ss->setActiveSheetIndex(0);

(new Xlsx($ss))->save($file);

if (!is_file($file) || filesize($file) === 0) {
    $bad('file XLSX tidak tertulis');
} else {
    $magic = file_get_contents($file, false, null, 0, 2);
    if ($magic !== 'PK') {
        $bad('magic byte bukan PK: ' . bin2hex((string)$magic));
    } else {
        $ok('tulis XLSX  : OK (' . filesize($file) . ' bytes)');
    }

    $back = IOFactory::load($file);
    $bs   = $back->getActiveSheet();

    $checks = [
        'sheet title'   => [$bs->getTitle(), 'Uji Regression'],
        'header A1'     => [$bs->getCell('A1')->getValue(), 'Kode'],
        'header C1'     => [$bs->getCell('C1')->getValue(), 'Nilai'],
        'nilai numerik' => [(string)$bs->getCell('C2')->getValue(), '10'],
        'nilai desimal' => [(string)$bs->getCell('C3')->getValue(), '20.5'],
        'number format' => [$bs->getStyle('C2')->getNumberFormat()->getFormatCode(), '#,##0.00'],
        'baris terakhir'=> [(string)$bs->getHighestRow(), '3'],
    ];
    foreach ($checks as $what => [$got, $want]) {
        if ((string)$got === (string)$want) {
            $ok("baca $what : OK");
        } else {
            $bad("baca $what : '$got' != '$want'");
        }
    }
    @unlink($file);
}

/* ---------- 2. helper produksi ---------- */
require_once $root . '/_shared/export_excel.php';

/* XLSX harus hidup saat ENABLE_EXCEL_EXPORT=1.
 * Jalankan di proses anak lewat shell redirection: helper menulis ke
 * php://output lalu exit, jadi stdout harus diarahkan ke file di level OS. */
$runHelper = static function (string $code, string $outFile): int {
    // Wajib require autoload DULU: export_xlsx_if_available mengecek
    // class_exists(Spreadsheet::class) dan akan menolak (return false) kalau
    // vendor/autoload.php belum dimuat, sehingga jalur XLSX tidak pernah ditulis.
    $boot = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
          . '; require ' . var_export(dirname(__DIR__, 2) . '/_shared/export_excel.php', true) . '; ';
    $cmd = escapeshellcmd(PHP_BINARY) . ' -r ' . escapeshellarg($boot . $code)
         . ' > ' . escapeshellarg($outFile) . ' 2>/dev/null';
    $out = [];
    $rc  = 0;
    exec($cmd, $out, $rc);
    return $rc;
};

$outXlsx = rtrim($tmp, '/') . '/helper.xlsx';
@unlink($outXlsx);
$runHelper('putenv("ENABLE_EXCEL_EXPORT=1"); export_xlsx_if_available("p",["a","b"],[["1","2"]]);', $outXlsx);
if (is_file($outXlsx) && filesize($outXlsx) > 0) {
    $ok('helper export_xlsx_if_available : OK');
} else {
    $bad('helper export_xlsx_if_available tidak menghasilkan file');
}
@unlink($outXlsx);

/* XLSX harus tertutup saat env bukan 1 */
$closed = export_xlsx_if_available('p', ['a'], [[1]]);
if ($closed === false) {
    $ok('guard ENABLE_EXCEL_EXPORT=0 : OK (tertutup)');
} else {
    $bad('guard ENABLE_EXCEL_EXPORT=0 GAGAL: jalur XLSX terbuka');
}
putenv('ENABLE_EXCEL_EXPORT');

/* ---------- 3. jalur CSV default ---------- */
$outCsv = rtrim($tmp, '/') . '/helper.csv';
@unlink($outCsv);
$runHelper('export_csv("p",["Kode","Nilai"],[["A1",1],["A2",2]]);', $outCsv);
if (is_file($outCsv) && str_contains((string)file_get_contents($outCsv), 'Kode,Nilai')) {
    $ok('helper export_csv : OK');
} else {
    $bad('helper export_csv tidak menghasilkan header yang benar');
}
@unlink($outCsv);

exit($fail);
