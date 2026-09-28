<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';

use App\Api\ApiResponse;

require_login();
require_role(['FIN','SYS', 'ADMIN','SUPERADMIN','ACT','MANAGER']);

$period = trim((string)($_GET['period'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    ApiResponse::fail('Invalid period format (YYYY-MM).', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $st = $pdo->prepare(
        "SELECT r.id, r.status, r.period_key, a.account_code, a.account_name,
                (SELECT COUNT(*) FROM bank_statement_lines l JOIN bank_statements s ON s.id=l.statement_id WHERE s.bank_account_id=r.bank_account_id AND s.period_key=r.period_key) AS statement_lines,
                (SELECT COUNT(*) FROM bank_recon_matches m WHERE m.reconciliation_id=r.id) AS matched_lines
         FROM bank_reconciliations r
         JOIN bank_accounts a ON a.id=r.bank_account_id
         WHERE r.period_key=?
         ORDER BY r.id DESC"
    );
    $st->execute([$period]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    ApiResponse::ok(['period' => $period, 'rows' => $rows]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load summary.', 'ERR_BANK_RECON_SUMMARY', 500);
}
