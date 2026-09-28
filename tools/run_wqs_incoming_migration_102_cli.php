#!/usr/bin/env php
<?php
/**
 * CLI: Jalankan migrasi 102 (po_id, po_item_id)
 * Usage: php run_wqs_incoming_migration_102_cli.php
 *
 * Tanpa login web. Untuk deploy/SSH.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die('CLI only. Run: php run_wqs_incoming_migration_102_cli.php');
}

$base = dirname(__DIR__);
require_once $base . '/_shared/bootstrap.php';
require_once $base . '/_shared/db.php';

$pdo = rmi_db_pdo();

function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $idx): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?");
    $st->execute([$table, $idx]);
    return (int)$st->fetchColumn() > 0;
}

echo "WQS Incoming Migration 102 (CLI)\n";
echo str_repeat('-', 40) . "\n";

// Buat tabel jika belum ada
if (!table_exists($pdo, 'wqs_incoming')) {
    $pdo->exec("CREATE TABLE wqs_incoming (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incoming_code VARCHAR(50) NOT NULL,
        received_date DATE NOT NULL,
        po_id INT NULL,
        po_code VARCHAR(60) NULL,
        office_code VARCHAR(30) NULL,
        depo_name VARCHAR(60) NULL,
        ref_note VARCHAR(255) NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_incoming_code (incoming_code),
        KEY idx_received_date (received_date),
        KEY idx_po_id (po_id),
        KEY idx_po (po_code),
        KEY idx_office (office_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: wqs_incoming tabel dibuat\n";
}
if (!table_exists($pdo, 'wqs_incoming_items')) {
    $pdo->exec("CREATE TABLE wqs_incoming_items (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incoming_id INT NOT NULL,
        po_item_id INT NULL,
        product_id INT NOT NULL,
        sku VARCHAR(50) NOT NULL,
        lot_number VARCHAR(80) NULL,
        serial_number VARCHAR(80) NULL,
        exp_date DATE NULL,
        qty INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_incoming (incoming_id),
        KEY idx_po_item_id (po_item_id),
        KEY idx_product (product_id),
        KEY idx_sku (sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: wqs_incoming_items tabel dibuat\n";
}

$ok = true;
if (!col_exists($pdo, 'wqs_incoming', 'po_id')) {
    $pdo->exec("ALTER TABLE wqs_incoming ADD COLUMN po_id INT NULL");
    echo "OK: wqs_incoming.po_id ditambahkan\n";
} else {
    echo "SKIP: wqs_incoming.po_id sudah ada\n";
}
if (!index_exists($pdo, 'wqs_incoming', 'idx_po_id')) {
    $pdo->exec("ALTER TABLE wqs_incoming ADD INDEX idx_po_id (po_id)");
    echo "OK: wqs_incoming.idx_po_id ditambahkan\n";
}

if (!col_exists($pdo, 'wqs_incoming_items', 'po_item_id')) {
    $pdo->exec("ALTER TABLE wqs_incoming_items ADD COLUMN po_item_id INT NULL");
    echo "OK: wqs_incoming_items.po_item_id ditambahkan\n";
} else {
    echo "SKIP: wqs_incoming_items.po_item_id sudah ada\n";
}
if (!index_exists($pdo, 'wqs_incoming_items', 'idx_po_item_id')) {
    $pdo->exec("ALTER TABLE wqs_incoming_items ADD INDEX idx_po_item_id (po_item_id)");
    echo "OK: wqs_incoming_items.idx_po_item_id ditambahkan\n";
}

echo str_repeat('-', 40) . "\n";
echo "Migrasi 102 selesai. Refresh halaman WQS Incoming.\n";
exit(0);
