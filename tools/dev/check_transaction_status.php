<?php
/**
 * Cek status transaksi (DO/PO) dan proses selanjutnya.
 * CLI: php tools/dev/check_transaction_status.php 260303-001
 *      php tools/dev/check_transaction_status.php RMI-JKT-20260303-001
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$code = $argv[1] ?? '';
if ($code === '') {
    echo "Usage: php tools/dev/check_transaction_status.php <code>\n";
    echo "  Contoh: php tools/dev/check_transaction_status.php 260303-001\n";
    echo "          php tools/dev/check_transaction_status.php RMI-JKT-20260303-001\n";
    exit(1);
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/_shared/db.php';

function compute_next(string $status): array {
    $s = strtolower(trim($status));
    if (in_array($s, ['paid','paid_done','closed'], true)) {
        return ['DONE', 'Sudah PAID / closed.'];
    }
    if ($s === 'cancelled') {
        return ['DONE', 'DO cancelled.'];
    }
    if (in_array($s, ['crm_to_wqs','sent_wqs'], true)) {
        return ['WQS', 'Mulai proses (cek stok & siapkan barang).'];
    }
    if (in_array($s, ['wqs_processing'], true)) {
        return ['WQS', 'Upload stok before/after lalu set READY SCM.'];
    }
    if (in_array($s, ['ready_scm','wqs_done'], true)) {
        return ['SCM', 'Atur pengiriman (vendor/mode) → set ON DELIVERY / SCM DONE.'];
    }
    if (in_array($s, ['on_delivery'], true)) {
        return ['SCM', 'Konfirmasi DELIVERED + bukti terima.'];
    }
    if (in_array($s, ['delivered','scm_done'], true)) {
        return ['ACT', 'Tukar faktur / faktur pajak → set WAIT PAYMENT / ACT DONE.'];
    }
    if (in_array($s, ['wait_payment','act_done'], true)) {
        return ['FIN', 'Proses pembayaran → set PAID / FIN DONE.'];
    }
    if ($s === 'fin_done') {
        return ['DONE', 'Selesai di FIN (FIN DONE).'];
    }
    return ['WAIT', 'Status tidak dikenali: ' . strtoupper($s)];
}

try {
    $pdo = rmi_db_pdo();
    if (!$pdo instanceof PDO) {
        echo "DB connection failed.\n";
        exit(1);
    }

    $search = '%' . $code . '%';

    // Cek Sales DO
    $st = $pdo->prepare("
        SELECT id, do_code, do_date, status, office_code, customers_code, grand_total,
               wqs_started_at, wqs_ready_at, scm_delivered_at, fin_paid_at
        FROM sales_do
        WHERE do_code LIKE ?
        ORDER BY id DESC
        LIMIT 5
    ");
    $st->execute([$search]);
    $dos = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($dos)) {
        echo "=== SALES DO ===\n\n";
        foreach ($dos as $r) {
            $doCode = $r['do_code'] ?? '-';
            $status = $r['status'] ?? '-';
            [$nextRole, $nextAction] = compute_next($status);

            echo "DO Code    : " . $doCode . "\n";
            echo "Tanggal    : " . ($r['do_date'] ?? '-') . "\n";
            echo "Customer   : " . ($r['customers_code'] ?? '-') . "\n";
            echo "Office     : " . ($r['office_code'] ?? '-') . "\n";
            echo "Grand Total: " . number_format((float)($r['grand_total'] ?? 0), 0, ',', '.') . "\n";
            echo "Status     : " . strtoupper($status) . "\n";
            echo "\n--- PROSES SELANJUTNYA ---\n";
            echo "Divisi     : " . $nextRole . "\n";
            echo "Aksi       : " . $nextAction . "\n";

            if ($nextRole === 'WQS') {
                echo "\nHalaman    : /stock/wqs_do_tasks.php\n";
            } elseif ($nextRole === 'SCM') {
                echo "\nHalaman    : /sales/scm_do_tasks.php\n";
            } elseif ($nextRole === 'ACT') {
                echo "\nHalaman    : /sales/act_do_tasks.php\n";
            } elseif ($nextRole === 'FIN') {
                echo "\nHalaman    : /sales/fin_do_tasks.php\n";
            } elseif ($nextRole === 'DONE') {
                echo "\nSiklus selesai. Lihat di sales_do_view.php?id=" . ($r['id'] ?? '') . "\n";
            }

            echo "\n" . str_repeat('-', 50) . "\n";
        }
        exit(0);
    }

    // Cek Purchases PO
    $hasPo = false;
    try {
        $st = $pdo->prepare("
            SELECT id, po_code, po_date, status, office_code, vendor_code, grand_total
            FROM purchases_po
            WHERE po_code LIKE ?
            ORDER BY id DESC
            LIMIT 5
        ");
        $st->execute([$search]);
        $pos = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($pos)) {
            $hasPo = true;
            echo "=== PURCHASES PO ===\n\n";
            foreach ($pos as $r) {
                echo "PO Code    : " . ($r['po_code'] ?? '-') . "\n";
                echo "Tanggal    : " . ($r['po_date'] ?? '-') . "\n";
                echo "Vendor     : " . ($r['vendor_code'] ?? '-') . "\n";
                echo "Status     : " . strtoupper($r['status'] ?? '-') . "\n";
                echo "\nAlur P2P: PO → Forwarder → CEISA/PIB → GR → Invoice AP → Payment\n";
                echo str_repeat('-', 50) . "\n";
            }
        }
    } catch (Throwable $e) {
        // table might not exist
    }

    if (!$hasPo) {
        echo "Transaksi tidak ditemukan untuk kode: " . $code . "\n";
        echo "\nCoba cek:\n";
        echo "  - Sales DO: sales_control_tower.php (filter by DO code)\n";
        echo "  - Sales DO: sales_do_view.php?id=... (jika tahu ID)\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
