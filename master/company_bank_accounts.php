<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_any_permission(['MASTER.COMPANY_BANK_VIEW', 'MASTER.COMPANY_BANK_CREATE', 'MASTER.COMPANY_BANK_EDIT', 'PURCHASES.AP_PAYMENT_VIEW', 'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.AP_PAYMENT_EDIT']);

$pdo = db_pdo();
require_once __DIR__ . '/../_shared/erp_audit.php';
erp_audit_ensure($pdo);
require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function cba_table_ready(PDO $pdo): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bank_accounts'");
    $st->execute();
    if ((int)$st->fetchColumn() <= 0) return false;
    $required = ['account_code','account_name','account_number','office_code','branch','purpose','currency','is_active','note','status'];
    foreach ($required as $col) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND column_name = ?");
        $s->execute([$col]);
        if ((int)$s->fetchColumn() <= 0) return false;
    }
    return true;
}

function cba_next_code(PDO $pdo): string
{
    $prefix = 'CBA-' . date('Ymd') . '-';
    $st = $pdo->prepare("SELECT account_code FROM bank_accounts WHERE account_code LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = (string)($st->fetchColumn() ?: '');
    $n = 1;
    if ($last !== '') {
        $tail = substr($last, -4);
        if (ctype_digit($tail)) $n = ((int)$tail) + 1;
    }
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

$purposes = ['RECEIVE'=>'RECEIVE (Penerima Pembayaran)','PAYMENT'=>'PAYMENT (Rekening Pembayaran)','PAYROLL'=>'PAYROLL (Sumber Gaji)','OTHER'=>'OTHER'];
$flash = '';
$err = '';
$ready = cba_table_ready($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
    if (!$ready) {
        $err = 'Schema bank_accounts belum siap. Jalankan migration unifikasi bank account.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $officeCode = trim((string)($_POST['office_code'] ?? ''));
                $accountNumber = trim((string)($_POST['account_number'] ?? ''));
                $accountName = trim((string)($_POST['account_name'] ?? ''));
                $branch = trim((string)($_POST['branch'] ?? ''));
                $purpose = strtoupper(trim((string)($_POST['purpose'] ?? 'RECEIVE')));
                $currency = strtoupper(trim((string)($_POST['currency'] ?? 'IDR')));
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $note = trim((string)($_POST['note'] ?? ''));

                if ($accountNumber === '' || $accountName === '') {
                    throw new RuntimeException('No Rekening dan Nama Rekening wajib diisi.');
                }
                if (!isset($purposes[$purpose])) $purpose = 'RECEIVE';
                if ($currency === '') $currency = 'IDR';
                $status = $isActive === 1 ? 'ACTIVE' : 'INACTIVE';

                if ($id > 0) {
                    $stCode = $pdo->prepare("SELECT account_code FROM bank_accounts WHERE id=? LIMIT 1");
                    $stCode->execute([$id]);
                    $code = (string)($stCode->fetchColumn() ?: '');
                    $st = $pdo->prepare("UPDATE bank_accounts
                        SET office_code=?, account_name=?, account_number=?, branch=?, purpose=?, currency=?, is_active=?, note=?, status=?, updated_at=NOW()
                        WHERE id=?");
                    $st->execute([
                        $officeCode !== '' ? $officeCode : null,
                        $accountName,
                        $accountNumber,
                        $branch !== '' ? $branch : null,
                        $purpose,
                        $currency,
                        $isActive,
                        $note !== '' ? $note : null,
                        $status,
                        $id
                    ]);
                    erp_audit($pdo, 'FIN_BANK', 'BANK#'.$id, 'UPDATE', ['account_number'=>$accountNumber,'purpose'=>$purpose,'is_active'=>$isActive]);
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'company_bank_accounts', 'bank_accounts', 'UPDATE', $id, $code ?: 'BANK#'.$id, "Bank account updated: {$code}", ['account_number' => $accountNumber, 'purpose' => $purpose]);
                    }
                } else {
                    $code = cba_next_code($pdo);
                    $st = $pdo->prepare("INSERT INTO bank_accounts
                        (account_code, account_name, account_number, office_code, branch, purpose, currency, is_active, note, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $st->execute([
                        $code,
                        $accountName,
                        $accountNumber,
                        $officeCode !== '' ? $officeCode : null,
                        $branch !== '' ? $branch : null,
                        $purpose,
                        $currency,
                        $isActive,
                        $note !== '' ? $note : null,
                        $status
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    erp_audit($pdo, 'FIN_BANK', 'BANK#'.$id, 'CREATE', ['account_code'=>$code,'account_number'=>$accountNumber,'purpose'=>$purpose,'is_active'=>$isActive]);
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'company_bank_accounts', 'bank_accounts', 'CREATE', $id, $code, "Bank account created: {$code}", ['account_number' => $accountNumber, 'purpose' => $purpose]);
                    }
                }
                rmi_redirect('company_bank_accounts.php?ok=1');
            }
            if ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $to = (int)($_POST['to'] ?? 0) === 1 ? 1 : 0;
                if ($id <= 0) throw new RuntimeException('ID tidak valid.');
                $stCode = $pdo->prepare("SELECT account_code FROM bank_accounts WHERE id=? LIMIT 1");
                $stCode->execute([$id]);
                $code = (string)($stCode->fetchColumn() ?: '');
                $status = $to === 1 ? 'ACTIVE' : 'INACTIVE';
                $st = $pdo->prepare("UPDATE bank_accounts SET is_active=?, status=?, updated_at=NOW() WHERE id=?");
                $st->execute([$to, $status, $id]);
                erp_audit($pdo, 'FIN_BANK', 'BANK#'.$id, 'TOGGLE_ACTIVE', ['to'=>$to]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'company_bank_accounts', 'bank_accounts', 'TOGGLE_ACTIVE', $id, $code ?: 'BANK#'.$id, "Bank account {$status}: {$code}", ['to' => $to]);
                }
                rmi_redirect('company_bank_accounts.php?ok=1');
            }
            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('ID tidak valid.');
                $code = '';
                $st = $pdo->prepare("SELECT account_code FROM bank_accounts WHERE id=? LIMIT 1");
                $st->execute([$id]);
                $code = (string)($st->fetchColumn() ?: '');
                $st = $pdo->prepare("DELETE FROM bank_accounts WHERE id=?");
                $st->execute([$id]);
                erp_audit($pdo, 'FIN_BANK', 'BANK#'.$id, 'DELETE', []);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'company_bank_accounts', 'bank_accounts', 'DELETE', $id, $code, "Bank account deleted: {$code}", []);
                }
                rmi_redirect('company_bank_accounts.php?deleted=1');
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$purposeF = strtoupper(trim((string)($_GET['purpose'] ?? '')));
$activeF = trim((string)($_GET['active'] ?? ''));
$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(account_name LIKE ? OR account_number LIKE ? OR office_code LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($purposeF !== '' && isset($purposes[$purposeF])) {
    $where[] = "purpose = ?";
    $params[] = $purposeF;
}
if ($activeF === '1' || $activeF === '0') {
    $where[] = "is_active = ?";
    $params[] = (int)$activeF;
}
$sql = "SELECT * FROM bank_accounts";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY is_active DESC, purpose ASC, account_name ASC, account_number ASC";
$list = [];
if ($ready) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll(PDO::FETCH_ASSOC);
}

$edit = null;
$editId = (int)($_GET['id'] ?? 0);
if ($ready && $editId > 0) {
    $st = $pdo->prepare("SELECT * FROM bank_accounts WHERE id=? LIMIT 1");
    $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (isset($_GET['ok'])) $flash = 'OK';
if (isset($_GET['deleted'])) $flash = 'Data dihapus.';

$audit_rows = [];
if (function_exists('master_audit')) {
    try {
        $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='company_bank_accounts' ORDER BY created_at DESC LIMIT 50");
        $st->execute();
        $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $ready) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="company_bank_accounts.csv"');
    $out = fopen('php://output', 'w');
    $header = ['id','account_code','account_name','account_number','office_code','branch','purpose','currency','is_active','status','note','created_at','updated_at'];
    fputcsv($out, $header, ',', '"', '\\');
    foreach ($rows as $r) {
        $line = [];
        foreach ($header as $k) $line[] = (string)($r[$k] ?? '');
        fputcsv($out, $line, ',', '"', '\\');
    }
    fclose($out);
    exit;
}

rmi_header('Rekening Perusahaan', 'company_bank');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4 class="mb-1">Rekening Perusahaan</h4>
    <div class="rmi-muted">Single source of truth: <code>bank_accounts</code>.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-light" href="company_bank_accounts.php?export=csv&q=<?= h(urlencode($q)) ?>&purpose=<?= h($purposeF) ?>&active=<?= h($activeF) ?>">Export CSV</a>
    <a class="btn btn-sm btn-primary" href="company_bank_accounts.php?action=new">+ Tambah</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?>
  <div class="alert alert-danger py-2">Schema bank_accounts belum siap. Jalankan migration terbaru terlebih dahulu.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card rmi-card">
      <div class="card-header rmi-card-header"><?= $edit ? 'Edit Rekening' : 'Tambah Rekening' ?></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="mb-2"><label class="form-label">Purpose</label>
            <select class="form-select form-select-sm" name="purpose">
              <?php foreach ($purposes as $k => $lbl): ?>
                <option value="<?= h($k) ?>" <?= strtoupper((string)($edit['purpose'] ?? 'RECEIVE')) === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Office Code</label><input class="form-control form-control-sm" name="office_code" value="<?= h($edit['office_code'] ?? '') ?>"></div>
          <div class="mb-2"><label class="form-label">No Rekening</label><input class="form-control form-control-sm" name="account_number" value="<?= h($edit['account_number'] ?? '') ?>" required></div>
          <div class="mb-2"><label class="form-label">Nama Rekening</label><input class="form-control form-control-sm" name="account_name" value="<?= h($edit['account_name'] ?? '') ?>" required></div>
          <div class="mb-2"><label class="form-label">Cabang</label><input class="form-control form-control-sm" name="branch" value="<?= h($edit['branch'] ?? '') ?>"></div>
          <div class="mb-2"><label class="form-label">Currency</label><input class="form-control form-control-sm" name="currency" value="<?= h($edit['currency'] ?? 'IDR') ?>"></div>
          <div class="mb-2"><label class="form-label">Catatan</label><textarea class="form-control form-control-sm" rows="2" name="note"><?= h($edit['note'] ?? '') ?></textarea></div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= (int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div>
          <div class="d-grid gap-2">
            <button class="btn btn-primary" <?= $ready ? '' : 'disabled' ?>>Simpan</button>
            <?php if ($edit): ?><a class="btn btn-outline-light" href="company_bank_accounts.php">Batal</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card rmi-card">
      <div class="card-header rmi-card-header d-flex align-items-center justify-content-between">
        <span>Daftar Rekening</span>
        <form class="d-flex gap-2" method="get">
          <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Search">
          <select class="form-select form-select-sm" name="purpose">
            <option value="">Semua purpose</option>
            <?php foreach ($purposes as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $purposeF === $k ? 'selected' : '' ?>><?= h($k) ?></option><?php endforeach; ?>
          </select>
          <select class="form-select form-select-sm" name="active">
            <option value="">All</option>
            <option value="1" <?= $activeF === '1' ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= $activeF === '0' ? 'selected' : '' ?>>Inactive</option>
          </select>
          <button class="btn btn-sm btn-outline-light">Cari</button>
        </form>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-dark table-hover mb-0">
            <thead><tr><th>ID</th><th>Code</th><th>Purpose</th><th>Office</th><th>No Rek</th><th>Nama Rekening</th><th>Active</th><th style="width:180px;">Aksi</th></tr></thead>
            <tbody>
            <?php if (!$list): ?><tr><td colspan="8" class="text-center text-muted py-3">Belum ada data.</td></tr><?php endif; ?>
            <?php foreach ($list as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= h((string)$r['account_code']) ?></td>
                <td><?= h((string)$r['purpose']) ?></td>
                <td><?= h((string)($r['office_code'] ?? '')) ?></td>
                <td><?= h((string)($r['account_number'] ?? '')) ?></td>
                <td><?= h((string)$r['account_name']) ?></td>
                <td><?= (int)($r['is_active'] ?? 1) === 1 ? '✅' : '—' ?></td>
                <td>
                  <a class="btn btn-sm btn-outline-light" href="company_bank_accounts.php?id=<?= (int)$r['id'] ?>">Edit</a>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="to" value="<?= (int)($r['is_active'] ?? 1) === 1 ? 0 : 1 ?>">
                    <button class="btn btn-sm btn-outline-warning" onclick="return confirm('Ubah status active?')"><?= (int)($r['is_active'] ?? 1) === 1 ? 'Nonaktif' : 'Aktifkan' ?></button>
                  </form>
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus permanen rekening ini?')">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Hapus</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($audit_rows)): ?>
<div class="card rmi-card mt-3">
  <div class="card-header rmi-card-header">Audit Log (Last 50 events)</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-dark table-hover mb-0">
        <thead><tr><th>Time</th><th>Action</th><th>Code</th><th>User</th><th>Description</th></tr></thead>
        <tbody>
          <?php foreach ($audit_rows as $a): ?>
            <tr>
              <td><?= h($a['created_at'] ?? '') ?></td>
              <td><?= h($a['action'] ?? '') ?></td>
              <td><?= h($a['record_code'] ?? '') ?></td>
              <td><?= h($a['username'] ?? '') ?></td>
              <td><?= h($a['description'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>

