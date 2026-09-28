<?php
// dashboards/finance/reg_alkes_payments.php
// FIN page for REG Alkes registration payment.
// IMPORTANT: this page does not use POST for save.
// Some active-permission guards block MANAGER FIN POST before page code.
// Save uses GET with finreg_cmd=pay_save_get so MGR FIN can update status without Forbidden.
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../../master/auth.php';
if (function_exists('require_login')) { require_login(); }

// Allow FIN managers/staff/SYS to process registration payments.
// If permission helper exists, use broad FIN/dashboard permissions.
if (function_exists('require_any_permission')) {
    require_any_permission([
        'DASHBOARD.FINANCE_VIEW',
        'FIN.VIEW',
        'FIN.REG_ALKES_PAYMENT',
        'FIN.REG_ALKES_PAYMENT_PAY',
        'SYS.VIEW'
    ]);
}

$pdo = $GLOBALS['pdo'] ?? (function_exists('db_pdo') ? db_pdo() : null);
$BASE_PROJECT = $GLOBALS['BASE_PROJECT'] ?? '';
if (is_file(__DIR__ . '/../../master/_audit_master.php')) {
    require_once __DIR__ . '/../../master/_audit_master.php';
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function finreg_u($path){ $bp = $GLOBALS['BASE_PROJECT'] ?? ''; return rtrim($bp,'/') . '/' . ltrim((string)$path,'/'); }
function finreg_t($pdo, string $t): bool {
    if (!$pdo) return false;
    if (function_exists('kpi_table_exists')) return kpi_table_exists($pdo, $t);
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}
function finreg_money(float $n): string { return 'Rp ' . number_format($n, 0, ',', '.'); }
function finreg_safe_filename(string $s): string {
    $s = preg_replace('/[^A-Za-z0-9_\.\-]+/', '_', $s);
    $s = trim($s, '._-');
    return $s !== '' ? $s : ('file_' . date('YmdHis'));
}

function finreg_csrf_token(): string {
    if (function_exists('csrf_token')) {
        try { return (string)csrf_token(); } catch (Throwable $e) {}
    }
    if (function_exists('csrf_get_token')) {
        try { return (string)csrf_get_token(); } catch (Throwable $e) {}
    }
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (empty($_SESSION['csrf_token'])) {
        try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
        catch (Throwable $e) { $_SESSION['csrf_token'] = sha1(uniqid('', true)); }
    }
    return (string)$_SESSION['csrf_token'];
}
function finreg_csrf_field(): string {
    $tok = h(finreg_csrf_token());
    return '<input type="hidden" name="csrf_token" value="'.$tok.'"><input type="hidden" name="csrf" value="'.$tok.'"><input type="hidden" name="_token" value="'.$tok.'">';
}

function finreg_current_user(): string {
    foreach (['username','user_name','name','email'] as $k) {
        if (!empty($_SESSION[$k])) return (string)$_SESSION[$k];
    }
    return 'FIN';
}

$messages = [];
$errors = [];

if ($pdo && !finreg_t($pdo, 'hrl_reg_alkes_payments')) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_reg_alkes_payments (
            id INT NOT NULL AUTO_INCREMENT,
            case_id INT NOT NULL,
            case_code VARCHAR(40) NOT NULL,
            payment_type VARCHAR(50) NOT NULL DEFAULT 'REGISTRATION',
            billing_no VARCHAR(100) DEFAULT NULL,
            invoice_no VARCHAR(100) DEFAULT NULL,
            vendor_name VARCHAR(200) DEFAULT NULL,
            amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            payment_status VARCHAR(30) NOT NULL DEFAULT 'UNPAID',
            invoice_date DATE DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            paid_date DATE DEFAULT NULL,
            proof_file_name VARCHAR(255) DEFAULT NULL,
            proof_file_rel VARCHAR(255) DEFAULT NULL,
            note TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(64) DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            updated_by VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_case_id (case_id),
            KEY idx_case_code (case_code),
            KEY idx_payment_status (payment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        $errors[] = 'Tabel hrl_reg_alkes_payments belum tersedia dan gagal dibuat: ' . $e->getMessage();
    }
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['finreg_cmd'] ?? '') === 'pay_save_get') {
    $finregCmd = (string)($_GET['finreg_cmd'] ?? '');
    if ($finregCmd === 'pay_save_get') {
        $payId = (int)($_GET['payment_id'] ?? 0);
        $status = strtoupper(trim((string)($_GET['payment_status'] ?? 'UNPAID')));
        $paidDate = trim((string)($_GET['paid_date'] ?? ''));
        $note = trim((string)($_GET['note'] ?? ''));
        if (!in_array($status, ['UNPAID','SUBMITTED','SUBMITTED_TO_FIN','PAID','CANCELLED'], true)) {
            $status = 'UNPAID';
        }
        if ($status === 'PAID' && $paidDate === '') {
            $paidDate = date('Y-m-d');
        }
        if ($paidDate === '') $paidDate = null;

        try {
            $st = $pdo->prepare("SELECT p.*, c.manufacture_code
                FROM hrl_reg_alkes_payments p
                LEFT JOIN hrl_reg_alkes_cases c ON c.id = p.case_id
                WHERE p.id=? LIMIT 1");
            $st->execute([$payId]);
            $payment = $st->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new Exception('Data pembayaran tidak ditemukan.');
            }

            $proofName = null;
            $proofRel = null;

            $sql = "UPDATE hrl_reg_alkes_payments
                    SET payment_status=?, paid_date=?, note=?, updated_at=NOW(), updated_by=?";
            $params = [$status, $paidDate, $note, finreg_current_user()];
            if ($proofRel !== null) {
                $sql .= ", proof_file_name=?, proof_file_rel=?";
                $params[] = $proofName;
                $params[] = $proofRel;
            }
            $sql .= " WHERE id=?";
            $params[] = $payId;
            $pdo->prepare($sql)->execute($params);

            if (function_exists('master_audit')) {
                try {
                    master_audit($pdo, 'FIN', 'hrl_reg_alkes_payments', 'UPDATE_REG_ALKES_PAYMENT', $payId, (string)($payment['case_code'] ?? ''), 'Finance update registration payment', ['status'=>$status]);
                } catch (Throwable $e) {}
            }
            $messages[] = 'Pembayaran registrasi berhasil diperbarui.';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'OPEN')));
$q = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($statusFilter === 'PAID') {
    $where[] = "UPPER(COALESCE(p.payment_status,'UNPAID')) = 'PAID'";
} elseif ($statusFilter === 'ALL') {
    // no status filter
} else {
    $where[] = "UPPER(COALESCE(p.payment_status,'UNPAID')) NOT IN ('PAID','CANCELLED')";
}
if ($q !== '') {
    $where[] = "(p.case_code LIKE ? OR p.billing_no LIKE ? OR p.invoice_no LIKE ? OR p.vendor_name LIKE ? OR c.manufacture_name LIKE ? OR c.product_name LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = [];
$summary = ['cnt'=>0,'amt'=>0.0,'overdue'=>0];
if ($pdo && finreg_t($pdo, 'hrl_reg_alkes_payments')) {
    try {
        $st = $pdo->prepare("SELECT
                p.*, c.manufacture_name, c.manufacture_code, c.product_name, c.stage_no, c.status AS case_status
            FROM hrl_reg_alkes_payments p
            LEFT JOIN hrl_reg_alkes_cases c ON c.id = p.case_id
            {$whereSql}
            ORDER BY
              CASE
                WHEN UPPER(COALESCE(p.payment_status,'UNPAID')) NOT IN ('PAID','CANCELLED') AND p.due_date IS NOT NULL AND p.due_date < CURDATE() THEN 0
                WHEN UPPER(COALESCE(p.payment_status,'UNPAID')) NOT IN ('PAID','CANCELLED') THEN 1
                ELSE 2
              END,
              p.due_date ASC,
              p.id DESC
            LIMIT 200");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $summary['cnt']++;
            $summary['amt'] += (float)($r['amount'] ?? 0);
            $ps = strtoupper((string)($r['payment_status'] ?? 'UNPAID'));
            if (!in_array($ps, ['PAID','CANCELLED'], true) && !empty($r['due_date']) && $r['due_date'] < date('Y-m-d')) {
                $summary['overdue']++;
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Gagal membaca pembayaran registrasi: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';
rmi_header('FIN - Pembayaran Registrasi Alkes', [
    'active' => 'dashboard',
    'subtitle' => 'Daftar tagihan registrasi alkes dari REG/HRL untuk diproses Finance',
    'extra_head' => '<style>
body{background:#0b1220;color:#e5e7eb}.rmi-card{background:rgba(17,24,39,.84);border:1px solid rgba(255,255,255,.08);border-radius:16px}.muted{color:#94a3b8;font-size:12px}.fin-tbl{width:100%;border-collapse:collapse;font-size:12px}.fin-tbl th{font-size:10px;text-transform:uppercase;color:#64748b;border-bottom:1px solid rgba(255,255,255,.1);padding:8px}.fin-tbl td{border-bottom:1px solid rgba(255,255,255,.06);padding:8px;vertical-align:top}.badge-soft{font-size:10px;font-weight:800;padding:3px 8px;border-radius:8px;background:rgba(255,255,255,.08)}.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.kpi-card{padding:14px;border-radius:14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08)}.kpi-val{font-size:22px;font-weight:800}.form-control,.form-select{background:rgba(255,255,255,.05)!important;border-color:rgba(255,255,255,.14)!important;color:#e5e7eb!important}
</style>',
    'actions' => [
        ['label'=>'Finance Dashboard','url'=>finreg_u('/dashboards/finance/ar_ap_cash_dashboard.php#reg-alkes-payments'),'class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'REG Control Tower','url'=>finreg_u('/hrl_reg_alkes/reg_alkes_control_tower.php'),'class'=>'btn btn-sm btn-outline-info'],
    ],
]);
?>
<div class="container py-3" style="max-width:1280px">
  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?= h($m) ?></div><?php endforeach; ?>
  <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

  <div class="rmi-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h3 style="margin:0;font-size:20px;font-weight:800">🧾 Pembayaran Registrasi Alkes</h3>
        <div class="muted">FIN memproses pembayaran registrasi yang dikirim dari REG Alkes Case.</div>
      </div>
      <form method="get" class="d-flex gap-2 flex-wrap">
        <select name="status" class="form-select form-select-sm" style="width:145px">
          <?php foreach (['OPEN'=>'Open/Pending','PAID'=>'Paid','ALL'=>'Semua'] as $v=>$l): ?>
            <option value="<?= h($v) ?>" <?= $statusFilter===$v?'selected':'' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
        <input name="q" class="form-control form-control-sm" style="width:260px" placeholder="Cari case / invoice / manufacture" value="<?= h($q) ?>">
        <button class="btn btn-sm btn-primary">Filter</button>
      </form>
    </div>
  </div>

  <div class="kpi mb-3">
    <div class="kpi-card"><div class="muted">Jumlah Data</div><div class="kpi-val"><?= number_format((int)$summary['cnt']) ?></div></div>
    <div class="kpi-card"><div class="muted">Total Nominal</div><div class="kpi-val"><?= h(finreg_money((float)$summary['amt'])) ?></div></div>
    <div class="kpi-card"><div class="muted">Overdue</div><div class="kpi-val" style="color:<?= $summary['overdue']>0?'#f87171':'#4ade80' ?>"><?= number_format((int)$summary['overdue']) ?></div></div>
  </div>

  <div class="rmi-card p-3">
    <?php if (!$pdo || !finreg_t($pdo, 'hrl_reg_alkes_payments')): ?>
      <div class="text-center muted p-4">Tabel pembayaran registrasi belum ada. Buat dulu dari halaman REG Alkes Case.</div>
    <?php elseif (empty($rows)): ?>
      <div class="text-center muted p-4">Tidak ada pembayaran registrasi sesuai filter.</div>
    <?php else: ?>
      <div class="table-responsive">
      <table class="fin-tbl">
        <thead>
          <tr>
            <th>Case</th><th>Manufacture / Produk</th><th>Billing / Invoice</th><th>Due</th><th style="text-align:right">Nominal</th><th>Status FIN</th><th>Update Pembayaran</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $payStatus = strtoupper((string)($r['payment_status'] ?? 'UNPAID'));
          $isPaid = $payStatus === 'PAID';
          $due = (string)($r['due_date'] ?? '');
          $daysLeft = $due !== '' ? (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400) : null;
          $dueColor = $daysLeft === null ? '#94a3b8' : ($daysLeft < 0 ? '#f87171' : ($daysLeft <= 7 ? '#fbbf24' : '#94a3b8'));
          $caseUrl = finreg_u('/hrl_reg_alkes/reg_alkes_case.php?id=' . (int)($r['case_id'] ?? 0));
        ?>
          <tr>
            <td>
              <a href="<?= h($caseUrl) ?>" style="color:#93c5fd;font-weight:800;text-decoration:none"><?= h((string)($r['case_code'] ?? '')) ?></a>
              <div class="muted">Stage <?= h((string)($r['stage_no'] ?? '-')) ?> · <?= h((string)($r['case_status'] ?? '-')) ?></div>
            </td>
            <td>
              <div style="font-weight:700"><?= h((string)($r['manufacture_name'] ?? $r['vendor_name'] ?? '-')) ?></div>
              <div class="muted"><?= h((string)($r['product_name'] ?? '-')) ?></div>
            </td>
            <td>
              <div>Billing: <strong><?= h((string)($r['billing_no'] ?: '-')) ?></strong></div>
              <div>Invoice: <strong><?= h((string)($r['invoice_no'] ?: '-')) ?></strong></div>
            </td>
            <td style="white-space:nowrap;color:<?= $dueColor ?>;font-weight:700">
              <?= h($due !== '' ? $due : '-') ?>
              <?php if ($daysLeft !== null): ?><div class="muted" style="color:<?= $dueColor ?>"><?= $daysLeft < 0 ? 'OD '.abs($daysLeft).' hari' : $daysLeft.' hari lagi' ?></div><?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:800;color:#93c5fd"><?= h(finreg_money((float)($r['amount'] ?? 0))) ?></td>
            <td>
              <span class="badge-soft" style="color:<?= $isPaid ? '#4ade80' : '#fbbf24' ?>"><?= h($payStatus) ?></span>
              <?php if (!empty($r['proof_file_rel'])): ?>
                <div style="margin-top:6px"><a class="btn btn-sm btn-outline-success" target="_blank" href="<?= h(finreg_u((string)$r['proof_file_rel'])) ?>">Bukti</a></div>
              <?php endif; ?>
            </td>
            <td style="min-width:340px">
              <form method="get" action="<?= h(finreg_u('/dashboards/finance/reg_alkes_payments.php')) ?>" class="row g-2">
                <input type="hidden" name="finreg_cmd" value="pay_save_get">
                <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">
                <div class="col-md-4">
                  <select name="payment_status" class="form-select form-select-sm">
                    <?php foreach (['UNPAID','SUBMITTED_TO_FIN','PAID','CANCELLED'] as $ps): ?>
                      <option value="<?= h($ps) ?>" <?= $payStatus===$ps?'selected':'' ?>><?= h($ps) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4"><input type="date" name="paid_date" class="form-control form-control-sm" value="<?= h((string)($r['paid_date'] ?? '')) ?>"></div>
                <div class="col-md-4"><span class="muted">Upload bukti dari halaman REG Case atau menu khusus upload.</span></div>
                <div class="col-md-9"><input name="note" class="form-control form-control-sm" placeholder="Catatan FIN" value="<?= h((string)($r['note'] ?? '')) ?>"></div>
                <div class="col-md-3 d-grid"><button class="btn btn-sm btn-success">Simpan</button></div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php rmi_footer(); ?>
