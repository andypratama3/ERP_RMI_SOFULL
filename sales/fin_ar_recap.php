<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) require_any_permission(['SALES.VIEW']); else require_role(['SYS','SUPERADMIN','ADMIN','FIN']);
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_exists(PDO $pdo, string $t): bool {
    try {
        $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`','',$table) . "` LIKE " . $pdo->quote($column));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function norm_text($v): string {
    $s = trim((string)$v);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}
function row_matches(array $r, string $q, string $status, string $office, string $dateFr = '', string $dateTo = ''): bool {
    if ($status !== '' && norm_text($r['ar_status'] ?? '') !== norm_text($status)) return false;
    if ($office !== '' && norm_text($r['office_code'] ?? '') !== norm_text($office)) return false;

    // Filter tanggal mengikuti konteks status:
    // - PAID    => tanggal pembayaran/lunas
    // - selain PAID => tanggal dokumen/DO (alur lama tetap)
    $filterDate = norm_text($status) === 'PAID'
        ? trim((string)($r['payment_date'] ?? ''))
        : trim((string)($r['document_date'] ?? ''));
    if ($filterDate !== '') $filterDate = substr($filterDate, 0, 10);
    if ($dateFr !== '' && ($filterDate === '' || $filterDate < $dateFr)) return false;
    if ($dateTo !== '' && ($filterDate === '' || $filterDate > $dateTo)) return false;

    if ($q === '') return true;

    $haystack = norm_text(
        ($r['document_no'] ?? '') . ' ' .
        ($r['customer_code'] ?? '') . ' ' .
        ($r['customer_name'] ?? '') . ' ' .
        ($r['office_code'] ?? '')
    );
    $tokens = preg_split('/\s+/u', norm_text($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($tokens as $token) {
        if ($token !== '' && strpos($haystack, $token) === false) return false;
    }
    return true;
}
function actor_name(): string {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    foreach (['username','user_name','login_name','name'] as $k) {
        $v = trim((string)($_SESSION[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return 'SYSTEM';
}
function money_input($v): float {
    $s = trim((string)$v);
    $s = str_ireplace(['Rp', ' '], '', $s);
    if ($s === '') return 0.0;
    // UI dianjurkan mengirim angka polos. Tetap toleran terhadap format Indonesia 1.234.567,89.
    if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (substr_count($s, ',') === 1 && substr_count($s, '.') === 0) {
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
}
function ensure_opening_payment_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_ar_opening_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        opening_id BIGINT UNSIGNED NOT NULL,
        payment_date DATE NOT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        method VARCHAR(40) NULL,
        reference_no VARCHAR(120) NULL,
        notes TEXT NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_opening (opening_id),
        KEY idx_payment_date (payment_date),
        KEY idx_reference (reference_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$q = trim((string)($_GET['q'] ?? ''));
$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$office = strtoupper(trim((string)($_GET['office'] ?? '')));
$dateFr = trim((string)($_GET['date_fr'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$rows = [];
$error = '';
$success = trim((string)($_GET['msg'] ?? ''));

// Pembayaran hanya berlaku untuk sumber IMPORT. Alur ERP/sales_do tidak disentuh.
if (table_exists($pdo, 'fin_ar_opening')) {
    try { ensure_opening_payment_table($pdo); } catch (Throwable $e) { $error = 'Tabel riwayat pembayaran belum dapat disiapkan: ' . $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'pay_import') {
    // CSRF POST sudah diverifikasi oleh require_login(); form tetap mengirim csrf_token.
    $openingId = (int)($_POST['opening_id'] ?? 0);
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $amount = money_input($_POST['amount'] ?? 0);
    $method = trim((string)($_POST['method'] ?? ''));
    $reference = trim((string)($_POST['reference_no'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $returnQ = trim((string)($_POST['return_q'] ?? ''));
    $returnStatus = strtoupper(trim((string)($_POST['return_status'] ?? '')));
    $returnOffice = strtoupper(trim((string)($_POST['return_office'] ?? '')));
    $returnDateFr = trim((string)($_POST['return_date_fr'] ?? ''));
    $returnDateTo = trim((string)($_POST['return_date_to'] ?? ''));

    try {
        if (!table_exists($pdo, 'fin_ar_opening')) throw new Exception('Tabel fin_ar_opening tidak tersedia.');
        ensure_opening_payment_table($pdo);
        if ($openingId <= 0) throw new Exception('ID piutang import tidak valid.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) throw new Exception('Tanggal pembayaran wajib diisi.');
        if ($amount <= 0) throw new Exception('Nominal pembayaran harus lebih besar dari Rp 0.');

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT id, legacy_document_no, customer_name, original_amount, paid_amount, outstanding_amount, status
                            FROM fin_ar_opening WHERE id=? FOR UPDATE");
        $st->execute([$openingId]);
        $ar = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ar) throw new Exception('Piutang import tidak ditemukan.');
        if (strtoupper(trim((string)($ar['status'] ?? ''))) === 'CANCELLED') throw new Exception('Piutang CANCELLED tidak dapat menerima pembayaran.');

        $original = max(0.0, (float)($ar['original_amount'] ?? 0));
        $currentPaid = max(0.0, (float)($ar['paid_amount'] ?? 0));
        $currentOutstanding = max(0.0, $original - $currentPaid);

        if ($currentOutstanding <= 0.004) throw new Exception('Piutang ini sudah lunas.');
        if ($amount - $currentOutstanding > 0.004) {
            throw new Exception('Nominal pembayaran melebihi outstanding Rp ' . number_format($currentOutstanding,0,',','.')); 
        }

        $ins = $pdo->prepare("INSERT INTO fin_ar_opening_payments
            (opening_id,payment_date,amount,method,reference_no,notes,created_by,created_at)
            VALUES (?,?,?,?,?,?,?,NOW())");
        $ins->execute([
            $openingId,
            $paymentDate,
            round($amount, 2),
            $method !== '' ? $method : null,
            $reference !== '' ? $reference : null,
            $notes !== '' ? $notes : null,
            actor_name()
        ]);

        $newPaid = min($original, round($currentPaid + $amount, 2));
        $newOutstanding = max(0.0, round($original - $newPaid, 2));
        $newStatus = $newOutstanding <= 0.004 ? 'PAID' : 'UNPAID';

        $sqlUpd = "UPDATE fin_ar_opening
                   SET paid_amount=?, outstanding_amount=?, payment_date=?, status=?";
        if (column_exists($pdo, 'fin_ar_opening', 'updated_at')) $sqlUpd .= ", updated_at=NOW()";
        $sqlUpd .= " WHERE id=?";
        $upd = $pdo->prepare($sqlUpd);
        $upd->execute([$newPaid, $newOutstanding, $paymentDate, $newStatus, $openingId]);

        $pdo->commit();

        $label = $newStatus === 'PAID' ? 'LUNAS' : 'PARTIAL';
        $msg = 'Pembayaran ' . ($ar['legacy_document_no'] ?? '') . ' berhasil dicatat. Status: ' . $label . '.';
        $params = array_filter([
            'q' => $returnQ,
            'status' => $returnStatus,
            'office' => $returnOffice,
            'date_fr' => $returnDateFr,
            'date_to' => $returnDateTo,
            'msg' => $msg,
        ], static fn($v) => $v !== '');
        header('Location: fin_ar_recap.php' . ($params ? '?' . http_build_query($params) : ''));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
        // Pertahankan filter dari form ketika validasi pembayaran gagal.
        $q = $returnQ;
        $status = $returnStatus;
        $office = $returnOffice;
        $dateFr = $returnDateFr;
        $dateTo = $returnDateTo;
    }
}

$paymentsByOpening = [];
try {
    // 1) ERP AR: alur lama tetap dipertahankan.
    $sqlErp = "SELECT
        NULL AS source_id,
        d.do_code AS document_no,
        d.do_date AS document_date,
        d.act_due_date AS due_date,
        d.customers_code AS customer_code,
        COALESCE(NULLIF(TRIM(c.customers_name),''), d.customers_code) AS customer_name,
        d.office_code,
        COALESCE(NULLIF(d.act_amount,0), d.grand_total, 0) AS original_amount,
        CASE WHEN LOWER(TRIM(d.status))='paid' THEN COALESCE(NULLIF(d.act_amount,0), d.grand_total, 0) ELSE 0 END AS paid_amount,
        CASE WHEN LOWER(TRIM(d.status))='paid' THEN 0 ELSE COALESCE(NULLIF(d.act_amount,0), d.grand_total, 0) END AS outstanding_amount,
        d.fin_paid_date AS payment_date,
        CASE WHEN LOWER(TRIM(d.status))='paid' THEN 'PAID' ELSE 'UNPAID' END AS ar_status,
        'ERP' AS source
      FROM sales_do d
      LEFT JOIN master_customers c ON c.customers_code = d.customers_code
      WHERE LOWER(TRIM(d.status)) IN ('wait_payment','paid')";
    $erpRows = $pdo->query($sqlErp)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($erpRows as $r) {
        if (row_matches($r, $q, $status, $office, $dateFr, $dateTo)) $rows[] = $r;
    }

    // 2) Piutang lama: dibaca langsung dari fin_ar_opening.
    if (table_exists($pdo, 'fin_ar_opening')) {
        $hasPaymentDate = column_exists($pdo, 'fin_ar_opening', 'payment_date');
        $paymentExpr = $hasPaymentDate ? 'o.payment_date' : 'NULL';

        $sqlImport = "SELECT
            o.id AS source_id,
            o.legacy_document_no AS document_no,
            o.document_date,
            o.due_date,
            o.customer_code,
            o.customer_name,
            o.office_code,
            COALESCE(o.original_amount,0) AS original_amount,
            COALESCE(o.paid_amount,0) AS paid_amount,
            GREATEST(COALESCE(o.original_amount,0)-COALESCE(o.paid_amount,0),0) AS outstanding_amount,
            {$paymentExpr} AS payment_date,
            CASE
              WHEN UPPER(TRIM(COALESCE(o.status,'')))='PAID'
                   OR GREATEST(COALESCE(o.original_amount,0)-COALESCE(o.paid_amount,0),0) <= 0 THEN 'PAID'
              WHEN COALESCE(o.paid_amount,0) > 0 THEN 'PARTIAL'
              ELSE 'UNPAID'
            END AS ar_status,
            'IMPORT' AS source
          FROM fin_ar_opening o
          WHERE UPPER(TRIM(COALESCE(o.status,'UNPAID'))) <> 'CANCELLED'";

        $importRows = $pdo->query($sqlImport)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($importRows as $r) {
            if (row_matches($r, $q, $status, $office, $dateFr, $dateTo)) $rows[] = $r;
        }

        // Riwayat hanya untuk baris IMPORT yang sedang tampil.
        if (table_exists($pdo, 'fin_ar_opening_payments')) {
            $ids = array_values(array_filter(array_map(static fn($r) => ($r['source'] ?? '') === 'IMPORT' ? (int)($r['source_id'] ?? 0) : 0, $rows)));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stp = $pdo->prepare("SELECT id, opening_id, payment_date, amount, method, reference_no, notes, created_by, created_at
                                     FROM fin_ar_opening_payments
                                     WHERE opening_id IN ({$ph}) ORDER BY payment_date DESC, id DESC");
                $stp->execute($ids);
                foreach ($stp->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
                    $paymentsByOpening[(int)$p['opening_id']][] = $p;
                }
            }
        }
    }
} catch (Throwable $e) {
    $error = $error !== '' ? $error . ' | ' . $e->getMessage() : $e->getMessage();
}

usort($rows, static function(array $a, array $b): int {
    return strcmp((string)($b['document_date'] ?? ''), (string)($a['document_date'] ?? ''));
});
$totalOrig = array_sum(array_map(fn($r)=>(float)($r['original_amount'] ?? 0), $rows));
$totalPaid = array_sum(array_map(fn($r)=>(float)($r['paid_amount'] ?? 0), $rows));
$totalOut  = array_sum(array_map(fn($r)=>(float)($r['outstanding_amount'] ?? 0), $rows));

$baseProject = rmi_layout_base_project();
rmi_header('Rekap Piutang Customer', [
    'active'=>'sales',
    'breadcrumbs'=>[['label'=>'FIN Task DO','url'=>$baseProject.'/sales/fin_do_tasks.php'],'Rekap Piutang'],
    'actions'=>[['label'=>'Import Piutang Lama','url'=>$baseProject.'/sales/fin_ar_import.php','class'=>'btn btn-sm btn-outline-light']]
]);
?>
<div class="container-fluid py-4 px-4">
  <h3>Rekap Piutang Customer</h3>
  <div class="text-muted mb-3">Piutang ERP tetap mengikuti FIN Task DO. Pembayaran pada halaman ini hanya untuk data <b>IMPORT</b> / piutang lama.</div>
  <?php if ($success !== ''): ?><div class="alert alert-success"><?=h($success)?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-4"><input class="form-control" name="q" value="<?=h($q)?>" placeholder="Customer / DO / dokumen lama"></div>
    <div class="col-md-2">
      <select class="form-select" name="status">
        <option value="">Semua status</option>
        <option value="UNPAID" <?=$status==='UNPAID'?'selected':''?>>Belum Lunas</option>
        <option value="PARTIAL" <?=$status==='PARTIAL'?'selected':''?>>Bayar Sebagian</option>
        <option value="PAID" <?=$status==='PAID'?'selected':''?>>Lunas</option>
      </select>
    </div>
    <div class="col-md-2"><input class="form-control" name="office" value="<?=h($office)?>" placeholder="Office"></div>
    <div class="col-md-2"><input class="form-control" type="date" name="date_fr" value="<?=h($dateFr)?>" title="Tanggal dokumen/DO dari"></div>
    <div class="col-md-2"><input class="form-control" type="date" name="date_to" value="<?=h($dateTo)?>" title="Tanggal dokumen/DO sampai"></div>
    <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary">Filter</button><a class="btn btn-outline-secondary" href="fin_ar_recap.php">Reset</a></div>
    <div class="col-12"><small class="text-muted">Jika status <b>Lunas/PAID</b>, filter tanggal memakai <b>Tanggal Pembayaran/Lunas</b>. Untuk status lain, tetap memakai <b>Tanggal Dokumen / Tanggal DO</b>.</small></div>
  </form>

  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card p-3"><small>Total Tagihan</small><b>Rp <?=number_format($totalOrig,0,',','.')?></b></div></div>
    <div class="col-md-4"><div class="card p-3"><small>Total Sudah Dibayar</small><b>Rp <?=number_format($totalPaid,0,',','.')?></b></div></div>
    <div class="col-md-4"><div class="card p-3"><small>Total Belum Lunas</small><b>Rp <?=number_format($totalOut,0,',','.')?></b></div></div>
  </div>

  <div class="table-responsive">
    <table class="table table-dark table-striped table-bordered align-middle">
      <thead><tr><th>Dokumen</th><th>Tanggal</th><th>Customer</th><th>Office</th><th>Tagihan</th><th>Dibayar</th><th>Outstanding</th><th>Jatuh Tempo</th><th>Tgl Bayar</th><th>Status</th><th>Sumber</th><th style="min-width:280px">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $source = strtoupper((string)($r['source'] ?? ''));
          $sourceId = (int)($r['source_id'] ?? 0);
          $outstanding = (float)($r['outstanding_amount'] ?? 0);
          $arStatus = strtoupper((string)($r['ar_status'] ?? ''));
          $history = $sourceId > 0 ? ($paymentsByOpening[$sourceId] ?? []) : [];
      ?>
        <tr>
          <td><?=h($r['document_no'] ?? '')?></td>
          <td><?=h($r['document_date'] ?? '')?></td>
          <td><b><?=h($r['customer_name'] ?? '')?></b><br><small><?=h($r['customer_code'] ?? '')?></small></td>
          <td><?=h($r['office_code'] ?? '')?></td>
          <td>Rp <?=number_format((float)($r['original_amount'] ?? 0),0,',','.')?></td>
          <td>Rp <?=number_format((float)($r['paid_amount'] ?? 0),0,',','.')?></td>
          <td><b>Rp <?=number_format($outstanding,0,',','.')?></b></td>
          <td><?=h($r['due_date'] ?? '')?></td>
          <td><?=h(substr((string)($r['payment_date'] ?? ''),0,10))?></td>
          <td>
            <?php if ($arStatus === 'PAID'): ?><span class="badge bg-success">PAID</span>
            <?php elseif ($arStatus === 'PARTIAL'): ?><span class="badge bg-warning text-dark">PARTIAL</span>
            <?php else: ?><span class="badge bg-danger">UNPAID</span><?php endif; ?>
          </td>
          <td><?=h($source)?></td>
          <td>
            <?php if ($source === 'IMPORT'): ?>
              <?php if ($outstanding > 0.004): ?>
                <details>
                  <summary class="btn btn-sm btn-primary">Catat Pembayaran</summary>
                  <form method="post" class="mt-2 p-2 border rounded bg-dark">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="pay_import">
                    <input type="hidden" name="opening_id" value="<?=$sourceId?>">
                    <input type="hidden" name="return_q" value="<?=h($q)?>">
                    <input type="hidden" name="return_status" value="<?=h($status)?>">
                    <input type="hidden" name="return_office" value="<?=h($office)?>">
                    <input type="hidden" name="return_date_fr" value="<?=h($dateFr)?>">
                    <input type="hidden" name="return_date_to" value="<?=h($dateTo)?>">
                    <div class="mb-2">
                      <label class="form-label small">Tanggal Pembayaran</label>
                      <input class="form-control form-control-sm" type="date" name="payment_date" value="<?=date('Y-m-d')?>" required>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small">Nominal Pembayaran</label>
                      <input class="form-control form-control-sm" type="number" name="amount" min="1" max="<?=h(number_format($outstanding,2,'.',''))?>" step="0.01" placeholder="Maks. <?=h(number_format($outstanding,0,',','.'))?>" required>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small">Metode</label>
                      <select class="form-select form-select-sm" name="method">
                        <option value="BANK_TRANSFER">Bank Transfer</option>
                        <option value="CASH">Cash</option>
                        <option value="GIRO">Giro</option>
                        <option value="OTHER">Lainnya</option>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small">Referensi Bank</label>
                      <input class="form-control form-control-sm" name="reference_no" maxlength="120" placeholder="No. transaksi / giro">
                    </div>
                    <div class="mb-2">
                      <label class="form-label small">Catatan</label>
                      <textarea class="form-control form-control-sm" name="notes" rows="2"></textarea>
                    </div>
                    <button class="btn btn-sm btn-success" onclick="return confirm('Simpan pembayaran piutang ini?')">Simpan Pembayaran</button>
                  </form>
                </details>
              <?php else: ?>
                <span class="text-success fw-bold">Lunas</span>
              <?php endif; ?>

              <?php if ($history): ?>
                <details class="mt-2">
                  <summary class="small text-info" style="cursor:pointer">Riwayat pembayaran (<?=count($history)?>)</summary>
                  <div class="mt-1 small">
                    <?php foreach ($history as $p): ?>
                      <div class="border-top py-1">
                        <b><?=h($p['payment_date'] ?? '')?></b> — Rp <?=number_format((float)($p['amount'] ?? 0),0,',','.')?><br>
                        <?=h($p['method'] ?? '')?><?=($p['reference_no'] ?? '') !== '' ? ' • '.h($p['reference_no']) : ''?><br>
                        <span class="text-muted"><?=h($p['created_by'] ?? '')?> <?=h($p['created_at'] ?? '')?></span>
                        <?php if (($p['notes'] ?? '') !== ''): ?><br><?=h($p['notes'])?><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endif; ?>
            <?php else: ?>
              <a class="btn btn-sm btn-outline-info" href="<?=h($baseProject.'/sales/fin_do_tasks.php')?>">FIN Task DO</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="12">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php rmi_footer(); ?>
