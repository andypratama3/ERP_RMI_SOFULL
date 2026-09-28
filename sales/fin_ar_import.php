<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.EDIT', 'SALES.VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','FIN']);
}
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

function ar_opening_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_ar_opening (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_code VARCHAR(100) NULL,
        customer_name VARCHAR(200) NOT NULL,
        office_code VARCHAR(32) NULL,
        legacy_document_no VARCHAR(100) NOT NULL,
        document_date DATE NOT NULL,
        due_date DATE NULL,
        original_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        outstanding_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        payment_date DATE NULL,
        status ENUM('UNPAID','PAID','CANCELLED') NOT NULL DEFAULT 'UNPAID',
        notes TEXT NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'IMPORT_LEGACY',
        import_batch VARCHAR(80) NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_legacy_document (legacy_document_no, customer_name),
        KEY idx_customer (customer_code, customer_name),
        KEY idx_due_date (due_date),
        KEY idx_status (status),
        KEY idx_office (office_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function numv($v): float {
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
        // Decimal separator is whichever appears last.
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
            // Thousands separator: 1,734,673
            $s = str_replace(',', '', $s);
        } else {
            // Decimal separator: 1734673,00
            $s = str_replace(',', '.', $s);
        }
    } elseif ($lastDot !== false) {
        $parts = explode('.', $s);
        $lastLen = strlen(end($parts));
        if (count($parts) > 2 || ($lastLen === 3 && strlen($s) > 4)) {
            // Thousands separator: 1.734.673
            $s = str_replace('.', '', $s);
        }
    }

    return is_numeric($s) ? (float)$s : 0.0;
}
function datev($v): ?string {
    // Robust parser for CSV dates from Excel / Indonesian exports.
    // Accepts: 2026-08-11, 11/8/2026, 11/08/2026, 8/11/2026, 11-08-2026, and Excel serial dates.
    $v = trim((string)$v);
    if ($v === '') return null;

    // Excel serial date support, e.g. 45516.
    if (preg_match('/^\d+(\.0+)?$/', $v)) {
        $serial = (int)$v;
        if ($serial > 20000 && $serial < 80000) {
            $base = new DateTime('1899-12-30');
            $base->modify('+' . $serial . ' days');
            return $base->format('Y-m-d');
        }
    }

    // Nama bulan Indonesia dari export Excel/CSV, mis. 02 Okt 2026, 08 Agu 2026.
    // strtotime() PHP tidak mengenali Okt/Agu/Mei/Des sehingga baris sebelumnya ter-skip diam-diam.
    if (preg_match('/^(\d{1,2})\s+([[:alpha:]]+)\s+(\d{4})$/u', $v, $m)) {
        $bulan = [
            'jan'=>1, 'januari'=>1,
            'feb'=>2, 'februari'=>2,
            'mar'=>3, 'maret'=>3,
            'apr'=>4, 'april'=>4,
            'mei'=>5,
            'jun'=>6, 'juni'=>6,
            'jul'=>7, 'juli'=>7,
            'agu'=>8, 'agt'=>8, 'agustus'=>8,
            'sep'=>9, 'sept'=>9, 'september'=>9,
            'okt'=>10, 'oktober'=>10,
            'nov'=>11, 'november'=>11,
            'des'=>12, 'desember'=>12,
        ];
        $key = strtolower($m[2]);
        if (isset($bulan[$key])) {
            $d = (int)$m[1]; $mo = (int)$bulan[$key]; $y = (int)$m[3];
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
            return null;
        }
    }

    $v = str_replace(['.', '-'], '/', $v);

    // ISO-like YYYY/MM/DD
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $v, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
    }

    // Business template uses Indonesian date: DD/MM/YYYY.
    // If ambiguous like 11/8/2026, treat it as 11 Aug 2026, not Nov 8.
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        // Fallback for MM/DD/YYYY if DD/MM/YYYY is impossible.
        $mo = (int)$m[1]; $d = (int)$m[2];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}
function actor(): string {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return trim((string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'SYSTEM')) ?: 'SYSTEM';
}
function ar_table_exists(PDO $pdo, string $table): bool {
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
function ar_table_count(PDO $pdo, string $table): int {
    if (!ar_table_exists($pdo, $table)) return 0;
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `".$table."`")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

ar_opening_ensure($pdo);
$success=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    try {
        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {
            throw new Exception('Pilih file CSV terlebih dahulu.');
        }
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) throw new Exception('File CSV tidak dapat dibaca.');
        $header = fgetcsv($fh);
        if (!$header) throw new Exception('Header CSV tidak ditemukan.');
        $header = array_map(fn($x)=>strtolower(trim((string)$x)), $header);
        if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $required=['customer_name','legacy_document_no','document_date','original_amount'];
        foreach($required as $r) if(!in_array($r,$header,true)) throw new Exception('Kolom wajib tidak ada: '.$r);
        $idx=array_flip($header); $batch='AR-'.date('Ymd-His'); $ok=0; $skip=0; $protected=0; $inserted=0; $updated=0;
        $importMode = (string)($_POST['import_mode'] ?? 'upsert');
        $confirmReset = !empty($_POST['confirm_reset']);
        if ($importMode === 'reset_all') {
            if (!$confirmReset) {
                throw new Exception('Centang konfirmasi hapus data import lama terlebih dahulu.');
            }
            $paymentCount = ar_table_count($pdo, 'fin_ar_opening_payments');
            $processedOpeningCount = (int)$pdo->query("SELECT COUNT(*) FROM fin_ar_opening WHERE paid_amount > 0 OR payment_date IS NOT NULL OR status = 'PAID'")->fetchColumn();
            if ($paymentCount > 0 || $processedOpeningCount > 0) {
                throw new Exception('RESET DIBLOKIR: sudah ada piutang lama yang diproses FIN/pembayaran. Gunakan mode incremental (aman), jangan hapus semua data lama.');
            }
        }
        $findExisting=$pdo->prepare("SELECT id, paid_amount, payment_date, status FROM fin_ar_opening WHERE legacy_document_no = ? AND customer_name = ? LIMIT 1");
        $st=$pdo->prepare("INSERT INTO fin_ar_opening
            (customer_code,customer_name,office_code,legacy_document_no,document_date,due_date,original_amount,paid_amount,outstanding_amount,payment_date,status,notes,source,import_batch,created_by,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'IMPORT_LEGACY',?,?,NOW())
            ON DUPLICATE KEY UPDATE
              customer_code=VALUES(customer_code), office_code=VALUES(office_code), document_date=VALUES(document_date), due_date=VALUES(due_date),
              original_amount=VALUES(original_amount), paid_amount=VALUES(paid_amount), outstanding_amount=VALUES(outstanding_amount),
              payment_date=VALUES(payment_date), status=VALUES(status), notes=VALUES(notes), import_batch=VALUES(import_batch),
              created_by=VALUES(created_by), updated_at=NOW()");
        $pdo->beginTransaction();
        $deleted = 0;
        if ($importMode === 'reset_all') {
            $deleted = (int)$pdo->exec("DELETE FROM fin_ar_opening");
        }
        $seen = [];
        while(($row=fgetcsv($fh))!==false){
            if(count(array_filter($row,fn($x)=>trim((string)$x)!==''))===0) continue;
            $g=fn($k)=>isset($idx[$k]) ? trim((string)($row[$idx[$k]] ?? '')) : '';
            $name=$g('customer_name'); $doc=$g('legacy_document_no'); $docDate=datev($g('document_date'));
            $orig=numv($g('original_amount')); $paid=numv($g('paid_amount'));
            if($name===''||$doc===''||!$docDate||$orig<0||$paid<0||$paid>$orig){$skip++;continue;}
            $dedupeKey = strtoupper($doc).'|'.strtoupper($name);
            if (isset($seen[$dedupeKey])) { $skip++; continue; }
            $seen[$dedupeKey] = true;
            $findExisting->execute([$doc, $name]);
            $existing = $findExisting->fetch(PDO::FETCH_ASSOC) ?: null;
            // SAFETY: jangan overwrite transaksi opening yang sudah diproses FIN.
            // Pembayaran/status existing adalah sumber kebenaran setelah FIN mulai memproses.
            if ($existing && ((float)($existing['paid_amount'] ?? 0) > 0 || !empty($existing['payment_date']) || strtoupper((string)($existing['status'] ?? '')) === 'PAID')) {
                $protected++;
                continue;
            }
            $out=max(0,$orig-$paid); $status=$out<=0.0001?'PAID':'UNPAID';
            $payDate=datev($g('payment_date')); if($status==='PAID' && !$payDate) $payDate=$docDate;
            $st->execute([$g('customer_code')?:null,$name,$g('office_code')?:null,$doc,$docDate,datev($g('due_date')),$orig,$paid,$out,$payDate,$status,$g('notes')?:null,$batch,actor()]);
            if ($existing) $updated++; else $inserted++;
            $ok++;
        }
        fclose($fh); $pdo->commit();
        $modeText = $importMode === 'reset_all' ? " Mode hapus import lama: {$deleted} baris lama dihapus." : ' Mode update/replace per dokumen.';
        $success="Import selesai. Diproses {$ok} baris (baru {$inserted}, diperbarui {$updated}); dilindungi karena sudah diproses FIN {$protected}; dilewati/invalid {$skip}. Batch {$batch}.".$modeText;
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $error='Error: '.$e->getMessage(); }
}

$baseProject=rmi_layout_base_project();
rmi_header('Import Piutang Lama',[
 'active'=>'sales',
 'breadcrumbs'=>[['label'=>'FIN Task DO','url'=>$baseProject.'/sales/fin_do_tasks.php'],'Import Piutang Lama'],
 'actions'=>[['label'=>'Rekap Piutang','url'=>$baseProject.'/sales/fin_ar_recap.php','class'=>'btn btn-sm btn-outline-light']],
]);
?>
<div class="container py-4" style="max-width:980px">
  <div class="card bg-dark text-light border-secondary p-4">
    <h3>Import Piutang Lama</h3>
    <p class="text-secondary">Data lama masuk ke tabel khusus dan tidak membuat DO, tidak mengubah stok, serta tidak membuat task CRM/WQS/SCM.</p>
    <?php if($success):?><div class="alert alert-success"><?=h($success)?></div><?php endif;?>
    <?php if($error):?><div class="alert alert-danger"><?=h($error)?></div><?php endif;?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <div class="mb-3">
        <label class="form-label">Mode Import</label>
        <select class="form-select" name="import_mode">
          <option value="upsert">Incremental aman — tambah baru / update yang belum diproses FIN</option>
          <option value="reset_all">Hapus semua piutang lama import, lalu import ulang</option>
        </select>
        <div class="form-text text-secondary">Disarankan: Incremental aman. Data yang sudah dibayar/diproses FIN tidak akan ditimpa. Reset otomatis diblokir bila sudah ada proses FIN/pembayaran.</div>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" value="1" name="confirm_reset" id="confirmResetAr">
        <label class="form-check-label" for="confirmResetAr">Saya paham: jika memilih mode hapus semua, data import piutang lama akan dihapus dulu sebelum import ulang.</label>
      </div>
      <div class="mb-3"><input class="form-control" type="file" name="csv_file" accept=".csv" required></div>
      <button class="btn btn-primary">Import CSV</button>
      <a class="btn btn-outline-light" href="templates/fin_ar_opening_template.csv">Download Template</a>
    </form>
    <hr class="border-secondary">
    <div class="small text-secondary">Kolom: customer_code, customer_name, office_code, legacy_document_no, document_date, due_date, original_amount, paid_amount, payment_date, status, notes.</div>
  </div>
</div>
<?php rmi_footer(); ?>
