<?php
/**
 * Cek DO yang sudah CRM Submitted - status crm_to_wqs dan seterusnya.
 * CLI: php tools/dev/check_crm_submitted_do.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/_shared/db.php';

$statuses = [
    'crm_to_wqs', 'sent_wqs', 'wqs_processing', 'wqs_done', 'ready_scm', 'scm_done',
    'on_delivery', 'delivered', 'act_done', 'wait_payment', 'paid', 'fin_done', 'paid_done', 'closed',
];

$placeholders = implode(',', array_fill(0, count($statuses), '?'));

try {
    $pdo = rmi_db_pdo();
    if (!$pdo instanceof PDO) {
        echo "DB connection failed.\n";
        exit(1);
    }

    // Total count
    $st = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ($placeholders)");
    $st->execute(array_map('strtolower', $statuses));
    $total = (int)$st->fetchColumn();
    echo "=== DO CRM Submitted (status: crm_to_wqs, sent_wqs, wqs_done, delivered, dll.) ===\n";
    echo "Total: $total DO\n\n";

    if ($total === 0) {
        // Cek semua status yang ada
        $st2 = $pdo->query("SELECT status, COUNT(*) cnt FROM sales_do GROUP BY status ORDER BY cnt DESC");
        echo "Status yang ada di sales_do:\n";
        while ($r = $st2->fetch(PDO::FETCH_ASSOC)) {
            echo "  - " . ($r['status'] ?: '(kosong)') . ": " . $r['cnt'] . "\n";
        }
        exit(0);
    }

    // List DO
    $st = $pdo->prepare("SELECT id, do_code, do_date, status, office_code, customers_code, grand_total, total_amount 
        FROM sales_do 
        WHERE LOWER(COALESCE(status,'')) IN ($placeholders) 
        ORDER BY do_date DESC, id DESC 
        LIMIT 100");
    $st->execute(array_map('strtolower', $statuses));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo "Daftar DO (max 100 terbaru):\n";
    echo str_repeat('-', 80) . "\n";
    printf("%-6s %-25s %-12s %-12s %-8s %-12s %s\n", 'ID', 'DO Code', 'Do Date', 'Status', 'Office', 'Customer', 'Amount');
    echo str_repeat('-', 80) . "\n";
    foreach ($rows as $r) {
        $amt = (float)($r['grand_total'] ?? $r['total_amount'] ?? 0);
        printf("%-6s %-25s %-12s %-12s %-8s %-12s %s\n",
            $r['id'] ?? '-',
            $r['do_code'] ?? '-',
            $r['do_date'] ?? '-',
            $r['status'] ?? '-',
            $r['office_code'] ?? '-',
            substr($r['customers_code'] ?? '-', 0, 12),
            number_format($amt, 0, ',', '.')
        );
    }
    echo str_repeat('-', 80) . "\n";
    if ($total > 100) {
        echo "... dan " . ($total - 100) . " DO lainnya.\n";
    }

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
