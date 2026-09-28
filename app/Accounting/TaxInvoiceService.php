<?php
declare(strict_types=1);

namespace App\Accounting;

use PDO;

final class TaxInvoiceService
{
    public function generateNumber(PDO $pdo): string
    {
        $prefix = 'TAX-' . date('Ym') . '-';
        $st = $pdo->prepare("SELECT tax_no FROM tax_invoices WHERE tax_no LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)$st->fetchColumn();
        $next = 1;
        if ($last !== '') {
            $seq = (int)substr($last, -5);
            $next = $seq + 1;
        }
        return $prefix . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
    }

    public function isIssuedForRef(PDO $pdo, string $salesRef): bool
    {
        $st = $pdo->prepare(
            "SELECT COUNT(*)
             FROM tax_invoices
             WHERE sales_invoice_ref = ? AND status = 'ISSUED'"
        );
        $st->execute([$salesRef]);
        return ((int)$st->fetchColumn()) > 0;
    }

    public function log(PDO $pdo, int $taxInvoiceId, string $action, int $byUserId = 0, string $note = ''): void
    {
        $pdo->prepare(
            "INSERT INTO tax_invoice_logs (tax_invoice_id, action_name, action_by, action_at, note)
             VALUES (?, ?, ?, NOW(), ?)"
        )->execute([$taxInvoiceId, $action, $byUserId ?: null, $note !== '' ? $note : null]);
    }
}
