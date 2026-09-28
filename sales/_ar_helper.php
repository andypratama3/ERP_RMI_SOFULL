<?php
/**
 * RMI Accounts Receivable helper.
 * Menambah lapisan AR tanpa mengubah alur DO/stock yang sudah berjalan.
 */

if (!function_exists('rmi_ar_actor')) {
    function rmi_ar_actor(): string {
        if (function_exists('auth_user')) {
            $u = auth_user();
            foreach (['username','user_name','name','full_name'] as $k) {
                $v = trim((string)($u[$k] ?? ''));
                if ($v !== '') return $v;
            }
        }
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        return trim((string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'SYSTEM')) ?: 'SYSTEM';
    }
}

if (!function_exists('rmi_ar_ensure_schema')) {
    function rmi_ar_ensure_schema(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ar_invoices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(80) NOT NULL,
            do_id INT NOT NULL,
            do_code VARCHAR(80) NULL,
            customer_code VARCHAR(100) NULL,
            invoice_date DATE NOT NULL,
            due_date DATE NOT NULL,
            total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            outstanding_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            status ENUM('DRAFT','UNPAID','PARTIAL','PAID','OVERPAID','CANCELLED') NOT NULL DEFAULT 'DRAFT',
            source_tax_file VARCHAR(255) NULL,
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_ar_invoice_number (invoice_number),
            UNIQUE KEY uq_ar_invoice_do (do_id),
            KEY idx_ar_invoice_customer (customer_code),
            KEY idx_ar_invoice_due (due_date),
            KEY idx_ar_invoice_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ar_receipts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receipt_number VARCHAR(80) NOT NULL,
            customer_code VARCHAR(100) NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(18,2) NOT NULL,
            bank_reference VARCHAR(150) NULL,
            payment_file VARCHAR(255) NULL,
            note TEXT NULL,
            status ENUM('VERIFIED','CANCELLED') NOT NULL DEFAULT 'VERIFIED',
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ar_receipt_number (receipt_number),
            KEY idx_ar_receipt_customer (customer_code),
            KEY idx_ar_receipt_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ar_receipt_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receipt_id BIGINT UNSIGNED NOT NULL,
            invoice_id BIGINT UNSIGNED NOT NULL,
            allocated_amount DECIMAL(18,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ar_receipt_invoice (receipt_id, invoice_id),
            KEY idx_ar_alloc_invoice (invoice_id),
            KEY idx_ar_alloc_receipt (receipt_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ar_credit_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            credit_note_number VARCHAR(80) NOT NULL,
            invoice_id BIGINT UNSIGNED NOT NULL,
            return_id INT NULL,
            credit_date DATE NOT NULL,
            amount DECIMAL(18,2) NOT NULL,
            reason TEXT NULL,
            status ENUM('APPROVED','CANCELLED') NOT NULL DEFAULT 'APPROVED',
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ar_credit_note_number (credit_note_number),
            UNIQUE KEY uq_ar_credit_return (return_id),
            KEY idx_ar_credit_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('rmi_ar_refresh_invoice')) {
    function rmi_ar_refresh_invoice(PDO $pdo, int $invoiceId): array {
        $st = $pdo->prepare("SELECT total_amount FROM ar_invoices WHERE id=? LIMIT 1 FOR UPDATE");
        $st->execute([$invoiceId]);
        $total = $st->fetchColumn();
        if ($total === false) throw new Exception('AR invoice tidak ditemukan.');
        $total = (float)$total;

        $st = $pdo->prepare("SELECT COALESCE(SUM(a.allocated_amount),0)
            FROM ar_receipt_allocations a JOIN ar_receipts r ON r.id=a.receipt_id
            WHERE a.invoice_id=? AND r.status='VERIFIED'");
        $st->execute([$invoiceId]);
        $paid = (float)($st->fetchColumn() ?: 0);

        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM ar_credit_notes
            WHERE invoice_id=? AND status='APPROVED'");
        $st->execute([$invoiceId]);
        $credit = (float)($st->fetchColumn() ?: 0);

        $net = max(0.0, $total - $credit);
        $outstanding = $net - $paid;
        if ($paid <= 0.00001 && $net > 0.00001) $status='UNPAID';
        elseif ($outstanding > 0.00001) $status='PARTIAL';
        elseif ($outstanding < -0.00001) $status='OVERPAID';
        else $status='PAID';

        $up=$pdo->prepare("UPDATE ar_invoices SET credit_amount=?,paid_amount=?,outstanding_amount=?,status=?,updated_by=?,updated_at=NOW() WHERE id=?");
        $up->execute([$credit,$paid,$outstanding,$status,rmi_ar_actor(),$invoiceId]);
        return compact('total','credit','paid','outstanding','status');
    }
}

if (!function_exists('rmi_ar_create_or_update_invoice_from_do')) {
    function rmi_ar_create_or_update_invoice_from_do(PDO $pdo, int $doId, float $amount, string $dueDate, string $taxFile=''): int {
        rmi_ar_ensure_schema($pdo);
        if ($doId<=0 || $amount<=0) throw new Exception('Nominal invoice harus lebih dari Rp 0.');
        $d=$pdo->prepare("SELECT id,do_code,customers_code,do_date,status FROM sales_do WHERE id=? LIMIT 1 FOR UPDATE");
        $d->execute([$doId]); $do=$d->fetch(PDO::FETCH_ASSOC);
        if (!$do) throw new Exception('DO tidak ditemukan saat membuat AR invoice.');
        $invoiceDate = date('Y-m-d');
        if ($dueDate==='' || $dueDate < $invoiceDate) throw new Exception('Tanggal jatuh tempo tidak boleh sebelum tanggal invoice.');
        $invoiceNo='INV-'.preg_replace('/[^A-Z0-9-]/','',strtoupper((string)$do['do_code']));
        $actor=rmi_ar_actor();
        $sql="INSERT INTO ar_invoices(invoice_number,do_id,do_code,customer_code,invoice_date,due_date,total_amount,outstanding_amount,status,source_tax_file,created_by)
              VALUES(?,?,?,?,?,?,?,?, 'UNPAID',?,?)
              ON DUPLICATE KEY UPDATE due_date=VALUES(due_date),total_amount=VALUES(total_amount),source_tax_file=VALUES(source_tax_file),updated_by=VALUES(created_by),updated_at=NOW()";
        $pdo->prepare($sql)->execute([$invoiceNo,$doId,$do['do_code'],$do['customers_code'],$invoiceDate,$dueDate,$amount,$amount,$taxFile,$actor]);
        $st=$pdo->prepare("SELECT id FROM ar_invoices WHERE do_id=? LIMIT 1"); $st->execute([$doId]);
        $id=(int)$st->fetchColumn(); rmi_ar_refresh_invoice($pdo,$id); return $id;
    }
}

if (!function_exists('rmi_ar_invoice_by_do')) {
    function rmi_ar_invoice_by_do(PDO $pdo, int $doId, bool $forUpdate=false): ?array {
        rmi_ar_ensure_schema($pdo);
        $sql="SELECT * FROM ar_invoices WHERE do_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
        $st=$pdo->prepare($sql); $st->execute([$doId]); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?:null;
    }
}

if (!function_exists('rmi_ar_record_payment')) {
    function rmi_ar_record_payment(PDO $pdo, int $doId, float $amount, string $paymentDate, string $file, string $bankRef='', string $note=''): array {
        rmi_ar_ensure_schema($pdo);
        if ($amount<=0) throw new Exception('Nominal pembayaran harus lebih dari Rp 0.');
        if ($paymentDate==='') throw new Exception('Tanggal pembayaran wajib diisi.');
        if ($file==='') throw new Exception('Bukti pembayaran wajib diunggah.');
        $invoice=rmi_ar_invoice_by_do($pdo,$doId,true);
        if (!$invoice) throw new Exception('AR invoice belum terbentuk. Kirim ulang dari ACT ke FIN.');
        $state=rmi_ar_refresh_invoice($pdo,(int)$invoice['id']);
        $remaining=max(0.0,(float)$state['outstanding']);
        if ($remaining<=0.00001) throw new Exception('Invoice sudah lunas. Pembayaran tambahan harus diproses sebagai deposit/kelebihan bayar terpisah.');
        if ($amount-$remaining>0.00001) throw new Exception('Nominal pembayaran melebihi outstanding Rp '.number_format($remaining,0,',','.').'.');
        $receiptNo='RCT-'.date('ymd-His').'-'.str_pad((string)random_int(1,999),3,'0',STR_PAD_LEFT);
        $actor=rmi_ar_actor();
        $pdo->prepare("INSERT INTO ar_receipts(receipt_number,customer_code,payment_date,amount,bank_reference,payment_file,note,status,created_by) VALUES(?,?,?,?,?,?,?,'VERIFIED',?)")
            ->execute([$receiptNo,$invoice['customer_code'],$paymentDate,$amount,$bankRef?:null,$file,$note,$actor]);
        $receiptId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO ar_receipt_allocations(receipt_id,invoice_id,allocated_amount) VALUES(?,?,?)")
            ->execute([$receiptId,(int)$invoice['id'],$amount]);
        return rmi_ar_refresh_invoice($pdo,(int)$invoice['id']);
    }
}

if (!function_exists('rmi_ar_create_credit_note_from_return')) {
    function rmi_ar_create_credit_note_from_return(PDO $pdo, int $returnId): ?array {
        rmi_ar_ensure_schema($pdo);
        $st=$pdo->prepare("SELECT r.id,r.do_id,r.return_code,r.reason,i.id invoice_id,i.total_amount
            FROM sales_do_returns r LEFT JOIN ar_invoices i ON i.do_id=r.do_id WHERE r.id=? LIMIT 1 FOR UPDATE");
        $st->execute([$returnId]); $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['invoice_id'])) return null; // DO belum menjadi piutang
        $st=$pdo->prepare("SELECT COALESCE(SUM(qty_return),0) ret_qty FROM sales_do_return_items WHERE return_id=?");
        $st->execute([$returnId]); $retQty=(float)($st->fetchColumn()?:0);
        $st=$pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM sales_do_items WHERE do_id=?");
        $st->execute([(int)$r['do_id']]); $doQty=(float)($st->fetchColumn()?:0);
        if ($retQty<=0 || $doQty<=0) return null;
        $amount=round(min((float)$r['total_amount'],((float)$r['total_amount']*$retQty/$doQty)),2);
        if ($amount<=0) return null;
        $cn='CN-'.preg_replace('/[^A-Z0-9-]/','',strtoupper((string)$r['return_code']));
        $pdo->prepare("INSERT INTO ar_credit_notes(credit_note_number,invoice_id,return_id,credit_date,amount,reason,status,created_by)
            VALUES(?,?,?,?,?,?,'APPROVED',?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),reason=VALUES(reason),status='APPROVED'")
            ->execute([$cn,(int)$r['invoice_id'],$returnId,date('Y-m-d'),$amount,$r['reason'],rmi_ar_actor()]);
        return rmi_ar_refresh_invoice($pdo,(int)$r['invoice_id']);
    }
}
?>
