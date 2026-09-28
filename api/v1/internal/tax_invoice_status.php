<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';

use App\Api\ApiResponse;

require_login();
require_role(['ACT','FIN','SYS', 'ADMIN','SUPERADMIN','MANAGER']);

$salesRef = trim((string)($_GET['sales_ref'] ?? ''));
if ($salesRef === '') {
    ApiResponse::fail('sales_ref is required.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $st = $pdo->prepare(
        "SELECT id, sales_invoice_ref, tax_no, tax_date, status, file_path, updated_at
         FROM tax_invoices
         WHERE sales_invoice_ref=?
         ORDER BY id DESC"
    );
    $st->execute([$salesRef]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    ApiResponse::ok([
        'sales_ref' => $salesRef,
        'issued' => (bool)array_filter($rows, static fn(array $r): bool => (string)$r['status'] === 'ISSUED'),
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load tax status.', 'ERR_TAX_STATUS', 500);
}
