<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac_guard.php';
require_login();

if (function_exists('require_any_permission')) {
    $allowed = function_exists('can_any') && can_any([
        'PURCHASES.AP_INVOICE_CREATE', 'PURCHASES.AP_INVOICE_EDIT',
        'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.REPORTS_VIEW', 'PURCHASES.VIEW'
    ]);
    if (!$allowed && function_exists('require_role')) {
        require_role(['FIN','ADMIN','SUPERADMIN','SYS']);
    } elseif (!$allowed) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
} else {
    require_role(['FIN','ADMIN','SUPERADMIN','SYS']);
}

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

if (!p_can_fin_ops()) { http_response_code(403); echo 'Access denied.'; exit; }

function ap_opening_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_ap_opening (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        supplier_code VARCHAR(100) NULL,
        supplier_name VARCHAR(200) NOT NULL,
        office_code VARCHAR(32) NULL,
        legacy_document_no VARCHAR(100) NOT NULL,
        document_date DATE NOT NULL,
        due_date DATE NULL,
        original_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'Pembayaran historis sebelum ERP',
        outstanding_amount DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'Snapshot saat import/recalc',
        payment_date DATE NULL,
        status ENUM('UNPAID','PARTIAL','PAID','CANCELLED') NOT NULL DEFAULT 'UNPAID',
        currency VARCHAR(12) NOT NULL DEFAULT 'IDR',
        notes TEXT NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'IMPORT_LEGACY',
        import_batch VARCHAR(80) NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_fin_ap_legacy (legacy_document_no, supplier_name),
        KEY idx_fin_ap_supplier (supplier_code, supplier_name),
        KEY idx_fin_ap_due_date (due_date),
        KEY idx_fin_ap_status (status),
        KEY idx_fin_ap_office (office_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_ap_opening_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        opening_ap_id BIGINT UNSIGNED NOT NULL,
        pay_code VARCHAR(100) NOT NULL,
        pay_date DATE NOT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        method VARCHAR(30) NOT NULL DEFAULT 'TRANSFER',
        bank_name VARCHAR(150) NULL,
        reference VARCHAR(150) NULL,
        note TEXT NULL,
        doc_path VARCHAR(500) NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_fin_ap_opening_pay_code (pay_code),
        KEY idx_fin_ap_opening_pay_ap (opening_ap_id),
        KEY idx_fin_ap_opening_pay_date (pay_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ap_imp_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ap_imp_clean_header(string $v): string {
    $v = preg_replace('/^\xEF\xBB\xBF/', '', $v) ?? $v;
    $v = strtolower(trim($v));
    $v = preg_replace('/\s+/', '_', $v) ?? $v;
    return $v;
}

function ap_imp_detect_delimiter(string $line): string {
    $candidates = [',', ';', "\t", '|'];
    $best = ',';
    $bestCount = -1;
    foreach ($candidates as $delimiter) {
        $count = substr_count($line, $delimiter);
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $delimiter;
        }
    }
    return $best;
}

function ap_imp_num($v): ?float {
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $s = preg_replace('/[^0-9,\.\-]/', '', $s) ?? '';
    if ($s === '' || $s === '-' || $s === '.' || $s === ',') return null;

    $lastComma = strrpos($s, ',');
    $lastDot = strrpos($s, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($lastComma !== false) {
        $decimals = strlen($s) - $lastComma - 1;
        if ($decimals === 1 || $decimals === 2) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($lastDot !== false) {
        $decimals = strlen($s) - $lastDot - 1;
        if ($decimals === 3 && substr_count($s, '.') >= 1) {
            $s = str_replace('.', '', $s);
        }
    }

    return is_numeric($s) ? (float)$s : null;
}

function ap_imp_excel_serial_to_date(float $serial): ?string {
    if ($serial < 1 || $serial > 100000) return null;
    try {
        $base = new DateTimeImmutable('1899-12-30');
        $days = (int)floor($serial);
        return $base->modify('+' . $days . ' days')->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function ap_imp_date($value, string $preference = 'DMY'): ?string {
    $raw = trim((string)$value);
    if ($raw === '') return null;

    // Excel serial date, mis. 46253
    if (is_numeric($raw) && preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
        $serialDate = ap_imp_excel_serial_to_date((float)$raw);
        if ($serialDate !== null) return $serialDate;
    }

    $raw = preg_replace('/\s+/', '', $raw) ?? $raw;

    foreach (['Y-m-d', 'Y/m/d', 'Y.m.d'] as $format) {
        $d = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($d && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $d->format('Y-m-d');
        }
    }

    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        $y = (int)$m[3];
        $day = null;
        $month = null;

        if ($a > 12 && $b <= 12) {
            $day = $a; $month = $b;
        } elseif ($b > 12 && $a <= 12) {
            $month = $a; $day = $b;
        } elseif ($a <= 12 && $b <= 12) {
            if (strtoupper($preference) === 'MDY') {
                $month = $a; $day = $b;
            } else {
                $day = $a; $month = $b;
            }
        }

        if ($day !== null && $month !== null && checkdate($month, $day, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $month, $day);
        }
    }

    return null;
}
function ap_imp_actor(): string {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return trim((string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'SYSTEM')) ?: 'SYSTEM';
}

ap_opening_ensure($pdo);
$success = '';
$error = '';
$details = [];
$importBatch = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rbac_guard_require_csrf_post();
    try {
        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Pilih file CSV terlebih dahulu.');
        }
        $tmp = (string)$_FILES['csv_file']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if (!$fh) throw new RuntimeException('File CSV tidak dapat dibaca.');

        // Deteksi delimiter otomatis: koma / titik koma / TAB / pipe.
        $firstLine = fgets($fh);
        if ($firstLine === false) throw new RuntimeException('File CSV kosong.');
        $delimiter = ap_imp_detect_delimiter($firstLine);
        rewind($fh);

        $header = fgetcsv($fh, 0, $delimiter);
        if (!$header) throw new RuntimeException('Header CSV tidak ditemukan.');
        $header = array_map(fn($x) => ap_imp_clean_header((string)$x), $header);

        $required = ['supplier_name','legacy_document_no','document_date','original_amount'];
        foreach ($required as $r) {
            if (!in_array($r, $header, true)) {
                throw new RuntimeException('Kolom wajib tidak ada: ' . $r . '. Header terbaca: ' . implode(', ', $header));
            }
        }

        $idx = array_flip($header);
        $importBatch = 'AP-' . date('Ymd-His');
        $ok = 0; $skip = 0; $lineNo = 1;
        $st = $pdo->prepare("INSERT INTO fin_ap_opening
            (supplier_code,supplier_name,office_code,legacy_document_no,document_date,due_date,original_amount,paid_amount,outstanding_amount,payment_date,status,currency,notes,source,import_batch,created_by,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'IMPORT_LEGACY',?,?,NOW())
            ON DUPLICATE KEY UPDATE
              supplier_code=VALUES(supplier_code), office_code=VALUES(office_code), document_date=VALUES(document_date), due_date=VALUES(due_date),
              original_amount=VALUES(original_amount), paid_amount=VALUES(paid_amount), outstanding_amount=VALUES(outstanding_amount),
              payment_date=VALUES(payment_date), status=VALUES(status), currency=VALUES(currency), notes=VALUES(notes), import_batch=VALUES(import_batch),
              created_by=VALUES(created_by), updated_at=NOW()");

        $pdo->beginTransaction();
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            $lineNo++;
            if (count(array_filter($row, fn($x) => trim((string)$x) !== '')) === 0) continue;
            $g = fn($k) => isset($idx[$k]) ? trim((string)($row[$idx[$k]] ?? '')) : '';

            $name = $g('supplier_name');
            $doc = $g('legacy_document_no');
            $docDateRaw = $g('document_date');
            $dueDateRaw = $g('due_date');
            $payDateRaw = $g('payment_date');

            // File opening AP dari Excel Indonesia memakai D/M/Y, seperti 19/08/2026.
            // Excel serial date juga diterima otomatis.
            $docDate = ap_imp_date($docDateRaw, 'DMY');
            $dueDate = ap_imp_date($dueDateRaw, 'DMY');
            $payDate = ap_imp_date($payDateRaw, 'DMY');
            $orig = ap_imp_num($g('original_amount'));
            $paid = ap_imp_num($g('paid_amount'));

            $reasons = [];
            if ($name === '') $reasons[] = 'supplier_name kosong';
            if ($doc === '') $reasons[] = 'legacy_document_no kosong';
            if (!$docDate) $reasons[] = 'document_date tidak valid: ' . ($docDateRaw === '' ? '(kosong)' : $docDateRaw);
            if ($dueDateRaw !== '' && !$dueDate) $reasons[] = 'due_date tidak valid: ' . $dueDateRaw;
            if ($payDateRaw !== '' && !$payDate) $reasons[] = 'payment_date tidak valid: ' . $payDateRaw;
            if ($orig === null) $reasons[] = 'original_amount tidak valid';
            if ($paid === null) $reasons[] = 'paid_amount tidak valid';
            if ($orig !== null && $orig <= 0) $reasons[] = 'original_amount harus lebih dari 0';
            if ($paid !== null && $paid < 0) $reasons[] = 'paid_amount tidak boleh negatif';
            if ($orig !== null && $paid !== null && $paid > $orig) $reasons[] = 'paid_amount lebih besar dari original_amount';

            if ($reasons) {
                $skip++;
                $details[] = [
                    'line' => $lineNo,
                    'doc' => $doc ?: '-',
                    'supplier' => $name ?: '-',
                    'status' => 'SKIP',
                    'message' => implode('; ', $reasons),
                ];
                continue;
            }

            $orig = (float)$orig;
            $paid = (float)$paid;
            $out = max(0, $orig - $paid);
            $status = $out <= 0.0001 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'UNPAID');
            if ($status === 'PAID' && !$payDate) $payDate = $docDate;
            $currency = strtoupper($g('currency') ?: 'IDR');
            if (!preg_match('/^[A-Z]{3,12}$/', $currency)) $currency = 'IDR';

            try {
                $st->execute([
                    $g('supplier_code') ?: null,
                    $name,
                    strtoupper($g('office_code')) ?: null,
                    $doc,
                    $docDate,
                    $dueDate,
                    $orig,
                    $paid,
                    $out,
                    $payDate,
                    $status,
                    $currency,
                    $g('notes') ?: null,
                    $importBatch,
                    ap_imp_actor()
                ]);
                $ok++;
                $details[] = [
                    'line' => $lineNo,
                    'doc' => $doc,
                    'supplier' => $name,
                    'status' => 'OK',
                    'message' => 'Tersimpan. Outstanding Rp ' . number_format($out, 0, ',', '.'),
                ];
            } catch (Throwable $rowError) {
                $skip++;
                $details[] = [
                    'line' => $lineNo,
                    'doc' => $doc ?: '-',
                    'supplier' => $name ?: '-',
                    'status' => 'ERROR',
                    'message' => $rowError->getMessage(),
                ];
            }
        }
        fclose($fh);
        $pdo->commit();
        $success = "Import selesai. Berhasil {$ok} baris; dilewati/gagal {$skip} baris. Batch {$importBatch}.";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Error: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
rmi_header('Import Hutang Lama', [
    'active' => 'purchases_ap',
    'breadcrumbs' => [
        ['label'=>'Purchases (PQP)','url'=>$baseProject.'/purchases/index.php'],
        ['label'=>'AP Payment','url'=>$baseProject.'/purchases/purchases_payment_ap.php'],
        'Import Hutang Lama'
    ],
    'actions' => [
        ['label'=>'Rekap Hutang','url'=>$baseProject.'/dashboards/finance/ap_rekap.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'AP Payment','url'=>$baseProject.'/purchases/purchases_payment_ap.php','class'=>'btn btn-sm btn-outline-light'],
    ],
]);
?>
<div class="container py-4" style="max-width:980px">
  <div class="card bg-dark text-light border-secondary p-4">
    <h3>Import Hutang Lama</h3>
    <p class="text-secondary">Data opening AP disimpan terpisah dari PO/GR/AP Invoice ERP. Import ini tidak membuat PO, tidak mengubah stok, dan tidak mengubah invoice supplier yang sudah berjalan.</p>
    <?php if ($success): ?><div class="alert alert-success"><?=ap_imp_h($success)?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?=ap_imp_h($error)?></div><?php endif; ?>
    <?php if ($details): ?>
      <div class="mt-3">
        <h5>Detail Hasil Import</h5>
        <div class="table-responsive">
          <table class="table table-dark table-striped table-bordered align-middle small">
            <thead><tr><th>Baris</th><th>Dokumen</th><th>Supplier</th><th>Hasil</th><th>Keterangan</th></tr></thead>
            <tbody>
            <?php foreach ($details as $detail): ?>
              <?php
                $badge = $detail['status'] === 'OK' ? 'bg-success' : ($detail['status'] === 'ERROR' ? 'bg-danger' : 'bg-warning text-dark');
              ?>
              <tr>
                <td><?=ap_imp_h($detail['line'])?></td>
                <td><?=ap_imp_h($detail['doc'])?></td>
                <td><?=ap_imp_h($detail['supplier'])?></td>
                <td><span class="badge <?=$badge?>"><?=ap_imp_h($detail['status'])?></span></td>
                <td><?=ap_imp_h($detail['message'])?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?=ap_imp_h(csrf_token())?>">
      <div class="mb-3"><input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required></div>
      <button class="btn btn-primary">Import CSV</button>
      <a class="btn btn-outline-light" href="templates/fin_ap_opening_template.csv">Download Template</a>
    </form>
    <hr class="border-secondary">
    <div class="small text-secondary">Kolom: supplier_code, supplier_name, office_code, legacy_document_no, document_date, due_date, original_amount, paid_amount, payment_date, status, currency, notes.</div>
    <div class="small text-secondary mt-2">Tanggal menerima YYYY-MM-DD, D/M/YYYY seperti 19/08/2026, M/D/YYYY, serta serial date Excel. due_date dan payment_date boleh kosong.</div>
    <div class="small text-secondary mt-1">Status dihitung otomatis: UNPAID jika belum dibayar, PARTIAL jika dibayar sebagian, PAID jika lunas.</div>
  </div>
</div>
<?php rmi_footer(); ?>
