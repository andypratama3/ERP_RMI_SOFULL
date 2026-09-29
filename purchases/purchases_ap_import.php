<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac_guard.php';
require_once __DIR__ . '/../master/_audit_master.php';
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
function ap_imp_num($v): float {
    // Robust parser for Excel/CSV currency numbers:
    // 1,734,673.00 -> 1734673.00
    // 1.734.673,00 -> 1734673.00
    // 1734673 -> 1734673
    // Empty/non numeric -> 0
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $s = str_ireplace(['Rp', 'IDR'], '', $s);
    $s = preg_replace('/[^0-9,.-]/', '', $s);
    if ($s === '' || $s === '-' || $s === ',' || $s === '.') return 0.0;

    $lastComma = strrpos($s, ',');
    $lastDot   = strrpos($s, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            // Indonesian format: 1.734.673,00
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // International format: 1,734,673.00
            $s = str_replace(',', '', $s);
        }
    } elseif ($lastComma !== false) {
        $parts = explode(',', $s);
        $lastLen = strlen(end($parts));
        if (count($parts) > 2 || $lastLen === 3) {
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
    } elseif ($lastDot !== false) {
        $parts = explode('.', $s);
        $lastLen = strlen(end($parts));
        if (count($parts) > 2 || ($lastLen === 3 && strlen($s) > 4)) {
            $s = str_replace('.', '', $s);
        }
    }

    return is_numeric($s) ? (float)$s : 0.0;
}
function ap_imp_date($v): ?string {
    // Robust parser for CSV dates from Excel / Indonesian exports.
    // Accepts: 2026-08-11, 11/8/2026, 11/08/2026, 8/11/2026, 11-08-2026, and Excel serial dates.
    $v = trim((string)$v);
    if ($v === '') return null;

    if (preg_match('/^\d+(\.0+)?$/', $v)) {
        $serial = (int)$v;
        if ($serial > 20000 && $serial < 80000) {
            $base = new DateTime('1899-12-30');
            $base->modify('+' . $serial . ' days');
            return $base->format('Y-m-d');
        }
    }

    $v = str_replace(['.', '-'], '/', $v);

    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $v, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        $mo = (int)$m[1]; $d = (int)$m[2];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}
function ap_imp_actor(): string {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return trim((string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'SYSTEM')) ?: 'SYSTEM';
}
function ap_imp_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    try {
        // NOTE: placeholder (?) TIDAK valid di SHOW TABLES LIKE (MySQL 1064).
        // Interpolasi aman karena $table sudah divalidasi regex di atas.
        $rows = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchAll(PDO::FETCH_NUM) ?: [];
        return count($rows) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function ap_imp_table_count(PDO $pdo, string $table): int {
    if (!ap_imp_table_exists($pdo, $table)) return 0;
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `".$table."`")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

ap_opening_ensure($pdo);
$success = '';
$error = '';
$details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rbac_guard_require_csrf_post();
    try {
        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Pilih file CSV terlebih dahulu.');
        }
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) throw new RuntimeException('File CSV tidak dapat dibaca.');

        $header = fgetcsv($fh);
        if (!$header) throw new RuntimeException('Header CSV tidak ditemukan.');
        if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        $header = array_map(fn($x) => strtolower(trim((string)$x)), $header);
        $required = ['supplier_name','legacy_document_no','document_date','original_amount'];
        foreach ($required as $r) {
            if (!in_array($r, $header, true)) throw new RuntimeException('Kolom wajib tidak ada: ' . $r);
        }

        $idx = array_flip($header);
        $batch = 'AP-' . date('Ymd-His');
        $ok = 0; $skip = 0; $lineNo = 1;
        $importMode = (string)($_POST['import_mode'] ?? 'upsert');
        $confirmReset = !empty($_POST['confirm_reset']);
        if ($importMode === 'reset_all') {
            if (!$confirmReset) {
                throw new RuntimeException('Centang konfirmasi hapus data import lama terlebih dahulu.');
            }
            $paymentCount = ap_imp_table_count($pdo, 'fin_ap_opening_payments');
            if ($paymentCount > 0) {
                throw new RuntimeException('Tidak bisa hapus semua data import lama karena sudah ada pembayaran hutang lama. Gunakan cancel/replace manual.');
            }
        }
        $st = $pdo->prepare("INSERT INTO fin_ap_opening
            (supplier_code,supplier_name,office_code,legacy_document_no,document_date,due_date,original_amount,paid_amount,outstanding_amount,payment_date,status,currency,notes,source,import_batch,created_by,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'IMPORT_LEGACY',?,?,NOW())
            ON DUPLICATE KEY UPDATE
              supplier_code=VALUES(supplier_code), office_code=VALUES(office_code), document_date=VALUES(document_date), due_date=VALUES(due_date),
              original_amount=VALUES(original_amount), paid_amount=VALUES(paid_amount), outstanding_amount=VALUES(outstanding_amount),
              payment_date=VALUES(payment_date), status=VALUES(status), currency=VALUES(currency), notes=VALUES(notes), import_batch=VALUES(import_batch),
              created_by=VALUES(created_by), updated_at=NOW()");

        $pdo->beginTransaction();
        $deleted = 0;
        if ($importMode === 'reset_all') {
            $deleted = (int)$pdo->exec("DELETE FROM fin_ap_opening");
        }
        $seen = [];
        while (($row = fgetcsv($fh)) !== false) {
            $lineNo++;
            if (count(array_filter($row, fn($x) => trim((string)$x) !== '')) === 0) continue;
            $g = fn($k) => isset($idx[$k]) ? trim((string)($row[$idx[$k]] ?? '')) : '';

            $name = $g('supplier_name');
            $doc = $g('legacy_document_no');
            $docDate = ap_imp_date($g('document_date'));
            $orig = ap_imp_num($g('original_amount'));
            $paid = ap_imp_num($g('paid_amount'));

            if ($name === '' || $doc === '' || !$docDate || $orig <= 0 || $paid < 0 || $paid > $orig) {
                $skip++;
                if (count($details) < 20) $details[] = "Baris {$lineNo} dilewati: supplier/dokumen/tanggal/nominal tidak valid.";
                continue;
            }
            $dedupeKey = strtoupper($doc).'|'.strtoupper($name);
            if (isset($seen[$dedupeKey])) {
                $skip++;
                if (count($details) < 20) $details[] = "Baris {$lineNo} dilewati: duplikat dalam file CSV.";
                continue;
            }
            $seen[$dedupeKey] = true;

            $out = max(0, $orig - $paid);
            $status = $out <= 0.0001 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'UNPAID');
            $payDate = ap_imp_date($g('payment_date'));
            if ($status === 'PAID' && !$payDate) $payDate = $docDate;
            $currency = strtoupper($g('currency') ?: 'IDR');
            if (!preg_match('/^[A-Z]{3,12}$/', $currency)) $currency = 'IDR';

            $st->execute([
                $g('supplier_code') ?: null, $name, strtoupper($g('office_code')) ?: null, $doc,
                $docDate, ap_imp_date($g('due_date')), $orig, $paid, $out, $payDate, $status,
                $currency, $g('notes') ?: null, $batch, ap_imp_actor()
            ]);
            $ok++;
        }
        fclose($fh);
        $pdo->commit();
        $modeText = $importMode === 'reset_all' ? " Mode hapus import lama: {$deleted} baris lama dihapus." : ' Mode update/replace per dokumen.';
        $success = "Import selesai. Berhasil {$ok} baris; dilewati {$skip} baris. Batch {$batch}." . $modeText;
        if (function_exists('master_audit')) {
            master_audit(
                $pdo,
                'fin_ap_opening',
                'fin_ap_opening',
                $importMode === 'reset_all' ? 'IMPORT_RESET_ALL' : 'IMPORT_UPSERT',
                null,
                $batch,
                "Import hutang lama: {$ok} baris masuk, {$skip} dilewati, {$deleted} baris lama dihapus (mode {$importMode}).",
                ['import_batch' => $batch, 'import_mode' => $importMode, 'inserted' => $ok, 'skipped' => $skip, 'deleted' => $deleted]
            );
        }
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
    <?php if ($details): ?><div class="alert alert-warning"><strong>Catatan:</strong><br><?=implode('<br>', array_map('ap_imp_h',$details))?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?=ap_imp_h(csrf_token())?>">
      <div class="mb-3">
        <label class="form-label">Mode Import</label>
        <select class="form-select" name="import_mode">
          <option value="upsert">Update/replace berdasarkan legacy_document_no + supplier</option>
          <option value="reset_all">Hapus semua hutang lama import, lalu import ulang</option>
        </select>
        <div class="form-text text-secondary">Gunakan mode hapus semua hanya saat belum ada pembayaran hutang lama.</div>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" value="1" name="confirm_reset" id="confirmResetAp">
        <label class="form-check-label" for="confirmResetAp">Saya paham: jika memilih mode hapus semua, data import hutang lama akan dihapus dulu sebelum import ulang.</label>
      </div>
      <div class="mb-3"><input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required></div>
      <button class="btn btn-primary">Import CSV</button>
      <a class="btn btn-outline-light" href="templates/fin_ap_opening_template.csv">Download Template</a>
    </form>
    <hr class="border-secondary">
    <div class="small text-secondary">Kolom: supplier_code, supplier_name, office_code, legacy_document_no, document_date, due_date, original_amount, paid_amount, payment_date, status, currency, notes.</div>
    <div class="small text-secondary mt-2">Status dihitung otomatis: UNPAID jika belum dibayar, PARTIAL jika dibayar sebagian, PAID jika lunas.</div>
  </div>
</div>
<?php rmi_footer(); ?>
