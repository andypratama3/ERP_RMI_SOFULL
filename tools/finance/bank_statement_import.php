<?php
/**
 * tools/finance/bank_statement_import.php
 * Bank statement CSV import (Phase 3)
 * require_login + require_role FIN/ADMIN. Preview then Import (POST+CSRF).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../../_shared/helpers.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';
require_once __DIR__ . '/../../master/auth.php';
require_login();

$allowedRoles = ['FIN', 'ADMIN', 'SUPERADMIN', 'SYS'];
$role = strtoupper((string)($_SESSION['role'] ?? $_SESSION['level'] ?? ''));
if (!in_array($role, $allowedRoles, true) && function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.AP_PAYMENT_VIEW', 'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.AP_PAYMENT_EDIT', 'MASTER.COMPANY_BANK_VIEW', 'MASTER.COMPANY_BANK_CREATE', 'MASTER.COMPANY_BANK_EDIT']);
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$flash = ['type' => '', 'msg' => ''];
$preview = [];
$maxFileSize = 2 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = $_POST['action'] ?? 'preview';
    if ($action === 'import' && !empty($_SESSION['_bank_import_preview'])) {
        $data = $_SESSION['_bank_import_preview'];
        $bankAccountId = (int)($data['bank_account_id'] ?? 0);
        $periodKey = trim((string)($data['period_key'] ?? ''));
        $rows = $data['rows'] ?? [];
        if ($bankAccountId > 0 && preg_match('/^\d{4}-\d{2}$/', $periodKey) && !empty($rows)) {
            $pdo->beginTransaction();
            try {
                $batchCode = 'BANK-' . date('YmdHis');
                $pdo->prepare("INSERT INTO bank_statement_raw (batch_code, bank_account_id, file_name, row_count, status, uploaded_by) VALUES (?,?,?,?,'PARSED',?)")
                    ->execute([$batchCode, $bankAccountId, $data['file_name'] ?? 'upload.csv', count($rows), $_SESSION['user_id'] ?? null]);
                $rawId = (int)$pdo->lastInsertId();
                $st = $pdo->prepare("SELECT id FROM bank_statements WHERE bank_account_id=? AND period_key=? LIMIT 1");
                $st->execute([$bankAccountId, $periodKey]);
                $stmtRow = $st->fetch();
                if (!$stmtRow) {
                    $pdo->prepare("INSERT INTO bank_statements (bank_account_id, period_key, opening_balance, closing_balance, created_by) VALUES (?,?,0,0,?)")
                        ->execute([$bankAccountId, $periodKey, $_SESSION['user_id'] ?? null]);
                    $statementId = (int)$pdo->lastInsertId();
                } else {
                    $statementId = (int)$stmtRow['id'];
                }
                $ins = $pdo->prepare("INSERT INTO bank_statement_lines (statement_id, txn_date, description, reference_no, amount, txn_type) VALUES (?,?,?,?,?,?)");
                foreach ($rows as $r) {
                    $date = $r['date'] ?? date('Y-m-d');
                    $desc = substr($r['description'] ?? '', 0, 255);
                    $ref = substr($r['reference'] ?? '', 0, 100);
                    $amount = (float)($r['amount'] ?? 0);
                    $type = strtoupper(substr($r['type'] ?? 'D', 0, 1)) === 'C' ? 'CREDIT' : 'DEBIT';
                    $ins->execute([$statementId, $date, $desc, $ref ?: null, $amount, $type]);
                }
                $pdo->prepare("UPDATE bank_statement_raw SET status='PARSED' WHERE id=?")->execute([$rawId]);
                $pdo->commit();
                if (function_exists('audit_event')) {
                    audit_event($pdo, 'BANK_STATEMENT_IMPORTED', 'FINANCE', 'BANK', (string)$rawId, 'Bank statement imported', [
                        'actor_username' => $_SESSION['username'] ?? 'SYSTEM',
                        'row_count' => count($rows),
                        'bank_account_id' => $bankAccountId,
                        'period_key' => $periodKey,
                    ]);
                }
                unset($_SESSION['_bank_import_preview']);
                $flash = ['type' => 'success', 'msg' => 'Import berhasil. ' . count($rows) . ' baris disimpan.'];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $flash = ['type' => 'danger', 'msg' => 'Gagal: ' . $e->getMessage()];
            }
        } else {
            $flash = ['type' => 'warning', 'msg' => 'Data tidak valid.'];
        }
    } elseif ($action === 'preview' && !empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        if ($_FILES['csv_file']['size'] > $maxFileSize) {
            $flash = ['type' => 'danger', 'msg' => 'File terlalu besar (max 2MB).'];
        } else {
            $mime = mime_content_type($tmp);
            if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
                $flash = ['type' => 'danger', 'msg' => 'Hanya file CSV.'];
            } else {
                $fh = fopen($tmp, 'r');
                $header = fgetcsv($fh);
                $map = [];
                foreach ($header as $i => $h) { $map[strtolower(trim($h))] = $i; }
                $rows = [];
                $max = 500;
                while ($max-- > 0 && ($row = fgetcsv($fh)) !== false) {
                    $r = [];
                    $r['date'] = isset($map['date']) ? trim($row[$map['date']] ?? '') : (isset($map['tanggal']) ? trim($row[$map['tanggal']] ?? '') : '');
                    $r['description'] = isset($map['description']) ? trim($row[$map['description']] ?? '') : (isset($map['keterangan']) ? trim($row[$map['keterangan']] ?? '') : '');
                    $r['reference'] = isset($map['reference']) ? trim($row[$map['reference']] ?? '') : (isset($map['reference_no']) ? trim($row[$map['reference_no']] ?? '') : '');
                    $r['amount'] = isset($map['amount']) ? (float)($row[$map['amount']] ?? 0) : (isset($map['jumlah']) ? (float)($row[$map['jumlah']] ?? 0) : 0);
                    $r['type'] = isset($map['type']) ? trim($row[$map['type']] ?? 'D') : (isset($map['tipe']) ? trim($row[$map['tipe']] ?? 'D') : 'D');
                    $rows[] = $r;
                }
                fclose($fh);
                $_SESSION['_bank_import_preview'] = [
                    'rows' => $rows,
                    'file_name' => $_FILES['csv_file']['name'] ?? 'upload.csv',
                    'bank_account_id' => (int)($_POST['bank_account_id'] ?? 0),
                    'period_key' => trim((string)($_POST['period_key'] ?? date('Y-m'))),
                ];
                $preview = $rows;
                $flash = ['type' => 'success', 'msg' => 'Preview: ' . count($rows) . ' baris. Klik Import untuk menyimpan.'];
            }
        }
    } else {
        $flash = ['type' => 'warning', 'msg' => 'Pilih file CSV.'];
    }
}

if (!empty($_SESSION['_bank_import_preview']) && empty($preview)) {
    $preview = $_SESSION['_bank_import_preview']['rows'] ?? [];
}

$bankAccounts = [];
try {
    $st = $pdo->query("SELECT id, account_code, account_name FROM bank_accounts WHERE status='ACTIVE' ORDER BY account_code");
    $bankAccounts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once __DIR__ . '/../../_shared/rmi_layout.php';
rmi_header('Bank Statement Import', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => '../index.php'], ['label' => 'Finance', 'url' => '#'], ['label' => 'Bank Statement Import', 'url' => '']]]);
?>
<?php if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v??''), ENT_QUOTES, 'UTF-8'); } } ?>
<?php if ($flash['msg']): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

<?php if (empty($preview)): ?>
<form method="post" enctype="multipart/form-data" class="mb-3">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="preview">
  <div class="mb-2">
    <label class="form-label">Bank Account</label>
    <select name="bank_account_id" class="form-select" required>
      <option value="">-- Pilih --</option>
      <?php foreach ($bankAccounts as $ba): ?>
      <option value="<?= (int)$ba['id'] ?>"><?= h($ba['account_code']) ?> - <?= h($ba['account_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="mb-2">
    <label class="form-label">Period (YYYY-MM)</label>
    <input type="text" name="period_key" class="form-control" value="<?= h(date('Y-m')) ?>" pattern="\d{4}-\d{2}" required>
  </div>
  <div class="mb-2">
    <label class="form-label">CSV File (kolom: date, description, reference, amount, type)</label>
    <input type="file" name="csv_file" accept=".csv" class="form-control" required>
  </div>
  <button type="submit" class="btn btn-primary">Preview</button>
</form>
<?php else: ?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="import">
  <p class="text-muted"><?= count($preview) ?> baris. Klik Import untuk menyimpan ke bank_statement_lines.</p>
  <button type="submit" class="btn btn-primary">Import</button>
  <a href="bank_statement_import.php" class="btn btn-outline-secondary">Batal</a>
</form>
<div class="table-responsive mt-3">
  <table class="table table-sm table-dark">
    <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th>Amount</th><th>Type</th></tr></thead>
    <tbody>
      <?php foreach (array_slice($preview, 0, 50) as $r): ?>
      <tr>
        <td><?= h($r['date'] ?? '') ?></td>
        <td><?= h($r['description'] ?? '') ?></td>
        <td><?= h($r['reference'] ?? '') ?></td>
        <td><?= h($r['amount'] ?? 0) ?></td>
        <td><?= h($r['type'] ?? 'D') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php rmi_footer(); ?>
