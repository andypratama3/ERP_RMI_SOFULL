<?php
declare(strict_types=1);

require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login();
require_any_permission([
    'MASTER.COMPANY_BANK_CRUD',
    'PURCHASES.AP_PAYMENT_CRUD',
    'PURCHASES.REPORTS_VIEW',
    'PURCHASES.VIEW',
]);

$pdo = db_pdo();
erp_audit_ensure($pdo);

$flash = ['type' => '', 'msg' => ''];
$period = trim((string)($_GET['period'] ?? date('Y-m')));
$reconId = (int)($_GET['recon_id'] ?? 0);

function br_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function br_save_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'csv') return null;
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $dir = $root . '/uploads/bank_recon';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $target = $dir . '/stmt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.csv';
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) return null;
    return str_replace($root, '', $target);
}

$svc = new \App\Accounting\BankReconService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'create_bank_account') {
            $code = trim((string)($_POST['account_code'] ?? ''));
            $name = trim((string)($_POST['account_name'] ?? ''));
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'IDR')));
            $gl = (int)($_POST['gl_account_id'] ?? 0);
            if ($code === '' || $name === '') throw new Exception('Bank account code/name wajib.');
            $pdo->prepare(
                "INSERT INTO bank_accounts (account_code, account_name, gl_account_id, currency, status, created_at)
                 VALUES (?, ?, ?, ?, 'ACTIVE', NOW())"
            )->execute([$code, $name, $gl ?: null, $currency !== '' ? $currency : 'IDR']);
            $bankAccountId = (int)$pdo->lastInsertId();
            erp_audit($pdo, 'BANK_RECON', $code, 'CREATE_BANK_ACCOUNT', ['currency' => $currency, 'gl_account_id' => $gl]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'bank_recon', 'bank_accounts', 'CREATE_BANK_ACCOUNT', $bankAccountId, $code, "Bank account created: {$code}", ['currency' => $currency, 'gl_account_id' => $gl]);
            }
            $flash = ['type' => 'success', 'msg' => 'Bank account dibuat.'];
        }

        if ($action === 'create_statement') {
            $bankAccountId = (int)($_POST['bank_account_id'] ?? 0);
            $period = trim((string)($_POST['period_key'] ?? date('Y-m')));
            $opening = (float)($_POST['opening_balance'] ?? 0);
            $closing = (float)($_POST['closing_balance'] ?? 0);
            if ($bankAccountId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $period)) throw new Exception('Data statement tidak valid.');

            $uploadPath = null;
            if (!empty($_FILES['csv_file']['name'])) {
                $uploadPath = br_save_upload($_FILES['csv_file']);
                if ($uploadPath === null) throw new Exception('File CSV tidak valid.');
            }

            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO bank_statements (bank_account_id, period_key, opening_balance, closing_balance, uploaded_file, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE opening_balance=VALUES(opening_balance), closing_balance=VALUES(closing_balance), uploaded_file=VALUES(uploaded_file)"
            )->execute([$bankAccountId, $period, $opening, $closing, $uploadPath, (int)($_SESSION['user_id'] ?? 0) ?: null]);

            $st = $pdo->prepare("SELECT id FROM bank_statements WHERE bank_account_id=? AND period_key=? LIMIT 1");
            $st->execute([$bankAccountId, $period]);
            $statementId = (int)$st->fetchColumn();

            $pdo->prepare(
                "INSERT INTO bank_reconciliations (bank_account_id, period_key, status, created_by, created_at, updated_at)
                 VALUES (?, ?, 'DRAFT', ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE updated_at=NOW()"
            )->execute([$bankAccountId, $period, (int)($_SESSION['user_id'] ?? 0) ?: null]);

            $st2 = $pdo->prepare("SELECT id FROM bank_reconciliations WHERE bank_account_id=? AND period_key=? LIMIT 1");
            $st2->execute([$bankAccountId, $period]);
            $newReconId = (int)$st2->fetchColumn();

            if ($uploadPath !== null) {
                $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
                $abs = $root . $uploadPath;
                $imported = $svc->importCsvLines($pdo, $statementId, $abs);
                $flash = ['type' => 'success', 'msg' => 'Statement tersimpan. Imported lines: ' . $imported];
            } else {
                $flash = ['type' => 'success', 'msg' => 'Statement tersimpan.'];
            }

            erp_audit($pdo, 'BANK_RECON', 'RECON#' . $newReconId, 'CREATE_STATEMENT', [
                'period' => $period,
                'bank_account_id' => $bankAccountId,
            ]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'bank_recon', 'bank_reconciliations', 'CREATE_STATEMENT', $newReconId, 'RECON#' . $newReconId, "Statement created: period {$period}", ['period' => $period, 'bank_account_id' => $bankAccountId]);
            }
            $pdo->commit();
            rmi_redirect('bank_recon.php?period=' . urlencode($period) . '&recon_id=' . $newReconId);
        }

        if ($action === 'auto_match') {
            $reconId = (int)($_POST['recon_id'] ?? 0);
            $statementId = (int)($_POST['statement_id'] ?? 0);
            if ($reconId <= 0 || $statementId <= 0) throw new Exception('Data auto match tidak valid.');
            $n = $svc->autoMatch($pdo, $reconId, $statementId, 3);
            erp_audit($pdo, 'BANK_RECON', 'RECON#' . $reconId, 'AUTO_MATCH', ['matched' => $n]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'bank_recon', 'bank_reconciliations', 'AUTO_MATCH', $reconId, 'RECON#' . $reconId, "Auto-match: {$n} matched", ['matched' => $n]);
            }
            $flash = ['type' => 'success', 'msg' => 'Auto-match selesai. Matched: ' . $n];
        }

        if ($action === 'manual_match') {
            $reconId = (int)($_POST['recon_id'] ?? 0);
            $lineId = (int)($_POST['line_id'] ?? 0);
            $erpType = trim((string)($_POST['erp_txn_type'] ?? ''));
            $erpId = (int)($_POST['erp_txn_id'] ?? 0);
            $matchedAmount = (float)($_POST['matched_amount'] ?? 0);
            if ($reconId <= 0 || $lineId <= 0 || $erpType === '' || $erpId <= 0 || $matchedAmount <= 0) {
                throw new Exception('Manual match input tidak valid.');
            }
            $pdo->prepare(
                "INSERT INTO bank_recon_matches (reconciliation_id, statement_line_id, erp_txn_type, erp_txn_id, matched_amount, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            )->execute([$reconId, $lineId, $erpType, $erpId, $matchedAmount]);
            erp_audit($pdo, 'BANK_RECON', 'RECON#' . $reconId, 'MANUAL_MATCH', ['line_id' => $lineId, 'erp' => $erpType . '#' . $erpId]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'bank_recon', 'bank_recon_matches', 'MANUAL_MATCH', $reconId, 'RECON#' . $reconId, "Manual match: {$erpType}#{$erpId}", ['line_id' => $lineId, 'erp' => $erpType . '#' . $erpId]);
            }
            $flash = ['type' => 'success', 'msg' => 'Manual match tersimpan.'];
        }

        if ($action === 'set_status') {
            $reconId = (int)($_POST['recon_id'] ?? 0);
            $status = strtoupper(trim((string)($_POST['status'] ?? 'DRAFT')));
            if (!in_array($status, ['DRAFT','RECONCILED','LOCKED'], true)) $status = 'DRAFT';
            if ($reconId <= 0) throw new Exception('Recon id tidak valid.');
            $pdo->prepare("UPDATE bank_reconciliations SET status=?, updated_at=NOW() WHERE id=?")
                ->execute([$status, $reconId]);
            erp_audit($pdo, 'BANK_RECON', 'RECON#' . $reconId, 'SET_STATUS', ['status' => $status]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'bank_recon', 'bank_reconciliations', 'SET_STATUS', $reconId, 'RECON#' . $reconId, "Recon status: {$status}", ['status' => $status]);
            }
            $flash = ['type' => 'success', 'msg' => 'Status reconciliation: ' . $status];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('bank_recon', $e, ['action' => 'BANK_RECON_SAVE']);
        $flash = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
}

$accounts = [];
$recons = [];
$current = null;
$statement = null;
$lines = [];
$lineCandidates = [];
$unmatchedErp = [];

try {
    $accounts = $pdo->query("SELECT id, account_code, account_name, currency FROM bank_accounts WHERE status='ACTIVE' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
    $stR = $pdo->prepare(
        "SELECT r.id, r.bank_account_id, r.period_key, r.status, a.account_code, a.account_name
         FROM bank_reconciliations r
         JOIN bank_accounts a ON a.id = r.bank_account_id
         WHERE r.period_key = ?
         ORDER BY r.id DESC"
    );
    $stR->execute([$period]);
    $recons = $stR->fetchAll(PDO::FETCH_ASSOC);

    if ($reconId > 0) {
        $st = $pdo->prepare(
            "SELECT r.*, a.account_code, a.account_name
             FROM bank_reconciliations r
             JOIN bank_accounts a ON a.id=r.bank_account_id
             WHERE r.id=? LIMIT 1"
        );
        $st->execute([$reconId]);
        $current = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif (!empty($recons)) {
        $current = $recons[0];
        $reconId = (int)$current['id'];
    }

    if ($current) {
        $stS = $pdo->prepare("SELECT * FROM bank_statements WHERE bank_account_id=? AND period_key=? LIMIT 1");
        $stS->execute([(int)$current['bank_account_id'], (string)$current['period_key']]);
        $statement = $stS->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($statement) {
            $stL = $pdo->prepare(
                "SELECT l.*, m.id AS match_id, m.erp_txn_type, m.erp_txn_id, m.matched_amount
                 FROM bank_statement_lines l
                 LEFT JOIN bank_recon_matches m ON m.statement_line_id = l.id
                 WHERE l.statement_id=?
                 ORDER BY l.txn_date ASC, l.id ASC"
            );
            $stL->execute([(int)$statement['id']]);
            $lines = $stL->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lines as $ln) {
                if (!empty($ln['match_id'])) continue;
                $lineCandidates[(int)$ln['id']] = $svc->candidateTxns($pdo, (string)$ln['txn_date'], (float)$ln['amount'], 7);
            }
            $unmatchedErp = $svc->unmatchedErpTxns($pdo, (string)$current['period_key']);
        }
    }
} catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('bank_recon', $e, ['action' => 'BANK_RECON_LOAD']);
    $flash = ['type' => 'danger', 'msg' => 'Load error: ' . $e->getMessage()];
}

$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
    $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'bank_recon' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();
rmi_header('Bank Reconciliation', [
    'active' => 'purchases',
    'subtitle' => 'Import CSV statement, auto/manual matching, status lifecycle',
    'breadcrumbs' => [
        ['label' => 'Purchases', 'url' => $base . '/purchases/index.php'],
        'Bank Reconciliation',
    ],
]);
?>

<?php if ($flash['msg'] !== ''): ?>
  <div class="alert alert-<?= br_h($flash['type'] !== '' ? $flash['type'] : 'info') ?>"><?= br_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="rmi-card p-3 mb-3">
      <h6>New Bank Account</h6>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create_bank_account">
        <div class="col-12"><input class="form-control form-control-sm" name="account_code" placeholder="Code (e.g. BCA-IDR)" required></div>
        <div class="col-12"><input class="form-control form-control-sm" name="account_name" placeholder="Account name" required></div>
        <div class="col-8"><input class="form-control form-control-sm" name="currency" value="IDR"></div>
        <div class="col-4"><button class="btn btn-sm btn-primary w-100">Save</button></div>
      </form>
    </div>

    <div class="rmi-card p-3">
      <h6>New Statement / Reconciliation</h6>
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create_statement">
        <div class="col-12">
          <label class="form-label">Bank account</label>
          <select class="form-select form-select-sm" name="bank_account_id" required>
            <option value="">-- choose --</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= br_h($a['account_code'] . ' - ' . $a['account_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><label class="form-label">Period (YYYY-MM)</label><input class="form-control form-control-sm" name="period_key" value="<?= br_h($period) ?>" required></div>
        <div class="col-6"><label class="form-label">Opening</label><input type="number" step="0.01" class="form-control form-control-sm" name="opening_balance" value="0"></div>
        <div class="col-6"><label class="form-label">Closing</label><input type="number" step="0.01" class="form-control form-control-sm" name="closing_balance" value="0"></div>
        <div class="col-12"><label class="form-label">CSV file</label><input type="file" class="form-control form-control-sm" name="csv_file" accept=".csv"></div>
        <div class="col-12"><button class="btn btn-sm btn-success">Create</button></div>
      </form>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="rmi-card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Reconciliations (<?= br_h($period) ?>)</h6>
        <form method="get" class="d-flex gap-2 align-items-center">
          <input class="form-control form-control-sm" style="width:120px" name="period" value="<?= br_h($period) ?>">
          <button class="btn btn-sm btn-outline-light">Filter</button>
        </form>
      </div>
      <div class="table-responsive mt-2">
        <table class="table table-sm table-dark table-hover mb-0">
          <thead><tr><th>ID</th><th>Bank</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (!$recons): ?><tr><td colspan="4" class="text-muted">No data</td></tr><?php endif; ?>
            <?php foreach ($recons as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= br_h($r['account_code'] . ' - ' . $r['account_name']) ?></td>
                <td><span class="badge text-bg-secondary"><?= br_h((string)$r['status']) ?></span></td>
                <td><a class="btn btn-sm btn-outline-light" href="?period=<?= urlencode($period) ?>&recon_id=<?= (int)$r['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($current && $statement): ?>
      <div class="rmi-card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
          <div>
            <h6 class="mb-0">Recon #<?= (int)$current['id'] ?> - <?= br_h($current['account_code']) ?></h6>
            <small class="text-muted">Status: <?= br_h((string)$current['status']) ?> | Statement lines: <?= count($lines) ?></small>
          </div>
          <div class="d-flex gap-2">
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="auto_match">
              <input type="hidden" name="recon_id" value="<?= (int)$current['id'] ?>">
              <input type="hidden" name="statement_id" value="<?= (int)$statement['id'] ?>">
              <button class="btn btn-sm btn-primary">Auto Match</button>
            </form>
            <?php foreach (['DRAFT','RECONCILED','LOCKED'] as $st): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="recon_id" value="<?= (int)$current['id'] ?>">
                <input type="hidden" name="status" value="<?= br_h($st) ?>">
                <button class="btn btn-sm btn-outline-light"><?= br_h($st) ?></button>
              </form>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="rmi-card p-3 mb-3">
        <h6>Statement Lines</h6>
        <div class="table-responsive">
          <table class="table table-sm table-dark table-hover mb-0">
            <thead><tr><th>Date</th><th>Description</th><th>Amount</th><th>Match</th><th>Manual Match</th></tr></thead>
            <tbody>
              <?php foreach ($lines as $ln): ?>
                <tr>
                  <td><?= br_h((string)$ln['txn_date']) ?></td>
                  <td><?= br_h((string)($ln['description'] ?? '')) ?></td>
                  <td><?= br_h(number_format((float)$ln['amount'], 2, '.', ',')) ?></td>
                  <td>
                    <?php if (!empty($ln['match_id'])): ?>
                      <span class="badge text-bg-success"><?= br_h((string)$ln['erp_txn_type']) ?>#<?= (int)$ln['erp_txn_id'] ?></span>
                    <?php else: ?>
                      <span class="badge text-bg-warning">UNMATCHED</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (empty($ln['match_id'])): ?>
                      <?php $cands = $lineCandidates[(int)$ln['id']] ?? []; ?>
                      <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="manual_match">
                        <input type="hidden" name="recon_id" value="<?= (int)$current['id'] ?>">
                        <input type="hidden" name="line_id" value="<?= (int)$ln['id'] ?>">
                        <input type="hidden" name="matched_amount" value="<?= br_h((string)$ln['amount']) ?>">
                        <select class="form-select form-select-sm" name="erp_txn_pick" onchange="this.form.erp_txn_type.value=this.value.split('#')[0]; this.form.erp_txn_id.value=this.value.split('#')[1];">
                          <option value="">--choose txn--</option>
                          <?php foreach ($cands as $c): ?>
                            <option value="<?= br_h((string)$c['type'] . '#' . (string)$c['id']) ?>">
                              <?= br_h((string)$c['type'] . '#' . (string)$c['id'] . ' ' . (string)$c['txn_date']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="erp_txn_type" value="">
                        <input type="hidden" name="erp_txn_id" value="">
                        <button class="btn btn-sm btn-outline-light">Match</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="rmi-card p-3">
        <h6>Unmatched ERP Transactions (<?= br_h($period) ?>)</h6>
        <div class="table-responsive">
          <table class="table table-sm table-dark table-hover mb-0">
            <thead><tr><th>Type</th><th>ID</th><th>Date</th><th>Amount</th></tr></thead>
            <tbody>
            <?php if (!$unmatchedErp): ?><tr><td colspan="4" class="text-muted">No unmatched ERP transactions.</td></tr><?php endif; ?>
            <?php foreach ($unmatchedErp as $t): ?>
              <tr>
                <td><?= br_h((string)$t['type']) ?></td>
                <td><?= (int)$t['id'] ?></td>
                <td><?= br_h((string)$t['txn_date']) ?></td>
                <td><?= br_h(number_format((float)$t['amount'], 2, '.', ',')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="rmi-card p-3 mb-3">
  <h6>Audit Log <span class="text-muted">(Last 50 events)</span></h6>
  <?php if (empty($audit_rows)): ?>
    <div class="text-muted">Belum ada audit log.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-dark table-hover mb-0">
        <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:140px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
        <tbody>
        <?php foreach ($audit_rows as $a): ?>
          <tr><td><?= br_h($a['created_at'] ?? '') ?></td><td><?= br_h($a['action'] ?? '') ?></td><td><?= br_h($a['record_code'] ?? '') ?></td><td><?= br_h($a['username'] ?? '') ?></td><td><?= br_h($a['description'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php rmi_footer(); ?>
