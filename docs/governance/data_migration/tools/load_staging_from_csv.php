<?php
/**
 * Load CSV ke staging tables.
 * Jalankan dari CLI: php docs/governance/data_migration/tools/load_staging_from_csv.php
 *
 * Usage:
 *   php load_staging_from_csv.php stock /path/to/stock.csv
 *   php load_staging_from_csv.php ap /path/to/ap.csv
 *   php load_staging_from_csv.php ar /path/to/ar.csv
 */
declare(strict_types=1);

$type = $argv[1] ?? '';
$file = $argv[2] ?? '';

if (!in_array($type, ['stock', 'ap', 'ar'], true) || $file === '' || !is_file($file)) {
    echo "Usage: php load_staging_from_csv.php {stock|ap|ar} /path/to/file.csv\n";
    exit(1);
}

$root = dirname(__DIR__, 4); // docs/governance/data_migration/tools -> project root
require_once $root . '/_shared/bootstrap.php';

$pdo = rmi_db_pdo();
$batch = 'CUTOVER_2026Q1'; // Edit jika perlu

$handle = fopen($file, 'r');
if (!$handle) {
    echo "Cannot open: $file\n";
    exit(1);
}

$header = fgetcsv($handle);
$count = 0;

if ($type === 'stock') {
    $st = $pdo->prepare("INSERT INTO mig_opening_stock_stg (migration_batch, row_no, sku, qty_on_hand) VALUES (?, ?, ?, ?)");
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 4) continue;
        $st->execute([trim($row[0]), (int)$row[1], trim($row[2]), (int)$row[3]]);
        $count++;
    }
} elseif ($type === 'ap') {
    $st = $pdo->prepare("INSERT INTO mig_opening_ap_stg (migration_batch, row_no, invoice_number, invoice_date, due_date, manufacture_code, office_code, currency, balance_amount, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 9) continue;
        $st->execute([
            trim($row[0]), (int)$row[1], trim($row[2]), trim($row[3]), trim($row[4]) ?: null,
            trim($row[5]), trim($row[6]), trim($row[7]) ?: 'IDR', (float)$row[8], trim($row[9] ?? '')
        ]);
        $count++;
    }
} elseif ($type === 'ar') {
    $st = $pdo->prepare("INSERT INTO mig_opening_ar_stg (migration_batch, row_no, invoice_number, invoice_date, due_date, customers_code, office_code, currency, balance_amount, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 9) continue;
        $st->execute([
            trim($row[0]), (int)$row[1], trim($row[2]), trim($row[3]), trim($row[4]) ?: null,
            trim($row[5]), trim($row[6]), trim($row[7]) ?: 'IDR', (float)$row[8], trim($row[9] ?? '')
        ]);
        $count++;
    }
}

fclose($handle);
echo "Loaded $count rows into mig_opening_{$type}_stg\n";
