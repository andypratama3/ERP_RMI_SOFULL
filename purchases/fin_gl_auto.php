<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login();
require_any_permission([
  'PURCHASES.REPORTS_VIEW',
  'PURCHASES.AP_INVOICE_CRUD',
  'PURCHASES.AP_PAYMENT_CRUD',
  'PURCHASES.VIEW',
]);

$pdo = db_pdo();
erp_audit_ensure($pdo);
$report = new \App\Accounting\GLReportService();
$poster = new \App\Accounting\GLPostingService();

function gl_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$view = trim((string)($_GET['view'] ?? 'journal'));
if (!in_array($view, ['journal','trial_balance','ledger'], true)) $view = 'journal';
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$sourceModule = trim((string)($_GET['source_module'] ?? ''));
$sourceRef = trim((string)($_GET['source_ref'] ?? ''));
$accountId = (int)($_GET['account_id'] ?? 0);
$export = trim((string)($_GET['export'] ?? ''));
$flash = ['type'=>'','msg'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
  $action = trim((string)($_POST['action'] ?? ''));
  try {
    if ($action === 'reverse_journal') {
      $headerId = (int)($_POST['header_id'] ?? 0);
      $reason = trim((string)($_POST['reason'] ?? 'Manual reversal'));
      if ($headerId <= 0) throw new Exception('Header id invalid.');
      $requestedBy = (int)($_SESSION['user_id'] ?? 0) ?: null;
      $dup = $pdo->prepare("SELECT id FROM gl_reversal_requests WHERE header_id=? AND status='PENDING' LIMIT 1");
      $dup->execute([$headerId]);
      if ($dup->fetchColumn()) {
        throw new Exception('Reversal request already pending for this journal.');
      }
      $pdo->prepare(
        "INSERT INTO gl_reversal_requests
        (header_id, reason, status, requested_by, requested_at, created_at, updated_at)
        VALUES (?, ?, 'PENDING', ?, NOW(), NOW(), NOW())"
      )->execute([$headerId, $reason !== '' ? $reason : 'Manual reversal request', $requestedBy]);
      $reqId = (int)$pdo->lastInsertId();
      erp_audit($pdo, 'GL', 'REVREQ#'.$reqId, 'REVERSE_REQUEST', ['header_id'=>$headerId,'reason'=>$reason,'requested_by'=>$requestedBy]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'fin_gl_auto', 'gl_reversal_requests', 'REVERSE_REQUEST', $reqId, 'REVREQ#'.$reqId, "GL reversal request submitted: #{$reqId}", ['header_id' => $headerId, 'reason' => $reason]);
      }
      $flash = ['type'=>'success','msg'=>'Reversal request submitted (dual-control): #' . $reqId];
    }
  } catch (Throwable $e) {
    $flash = ['type'=>'danger','msg'=>$e->getMessage()];
  }
}

$accounts = [];
$journalRows = [];
$tbRows = [];
$ledgerRows = [];
try {
  $accounts = $pdo->query("SELECT id, code, name FROM gl_accounts WHERE status='ACTIVE' ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
  if ($view === 'journal') {
    $journalRows = $report->journalListing($pdo, $dateFrom, $dateTo, $sourceModule, $sourceRef);
  } elseif ($view === 'trial_balance') {
    $tbRows = $report->trialBalance($pdo, $dateFrom, $dateTo);
  } else {
    if ($accountId > 0) {
      $ledgerRows = $report->generalLedger($pdo, $accountId, $dateFrom, $dateTo);
    }
  }
} catch (Throwable $e) {
  $flash = ['type'=>'danger','msg'=>'Load report error: ' . $e->getMessage()];
}

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'fin_gl_auto' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if ($export === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="gl_' . $view . '_' . date('Ymd_His') . '.csv"');
  $out = fopen('php://output', 'w');
  if ($view === 'journal') {
    fputcsv($out, ['journal_date','journal_no','source_module','source_event','source_ref','status','total_dr','total_cr']);
    foreach ($journalRows as $r) {
      fputcsv($out, [
        $r['journal_date'] ?? '',
        $r['journal_no'] ?? '',
        $r['source_module'] ?? '',
        $r['source_event'] ?? '',
        $r['source_ref'] ?? '',
        $r['status'] ?? '',
        $r['total_dr'] ?? 0,
        $r['total_cr'] ?? 0,
      ]);
    }
  } elseif ($view === 'trial_balance') {
    fputcsv($out, ['code','name','account_type','total_dr','total_cr','balance']);
    foreach ($tbRows as $r) {
      fputcsv($out, [
        $r['code'] ?? '',
        $r['name'] ?? '',
        $r['account_type'] ?? '',
        $r['total_dr'] ?? 0,
        $r['total_cr'] ?? 0,
        $r['balance'] ?? 0,
      ]);
    }
  } else {
    fputcsv($out, ['journal_date','journal_no','source_module','source_event','source_ref','line_no','memo','dr_amount','cr_amount','running_balance']);
    foreach ($ledgerRows as $r) {
      fputcsv($out, [
        $r['journal_date'] ?? '',
        $r['journal_no'] ?? '',
        $r['source_module'] ?? '',
        $r['source_event'] ?? '',
        $r['source_ref'] ?? '',
        $r['line_no'] ?? '',
        $r['memo'] ?? '',
        $r['dr_amount'] ?? 0,
        $r['cr_amount'] ?? 0,
        $r['running_balance'] ?? 0,
      ]);
    }
  }
  fclose($out);
  exit;
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('FIN GL Auto', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'GL Console',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:#0b1220;color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:16px}
    .num{text-align:right}
    </style>',
]);
?>

<div class="card">
  <div class="card-body">
    <h4>GL Console (Journal, Trial Balance, Ledger)</h4>
    <?php if ($flash['msg'] !== ''): ?>
      <div class="alert alert-<?= gl_h($flash['type']) ?>"><?= gl_h($flash['msg']) ?></div>
    <?php endif; ?>
    <form method="get" class="row g-2 mb-3">
      <div class="col-md-2">
        <label class="form-label">View</label>
        <select class="form-select form-select-sm" name="view">
          <option value="journal" <?= $view==='journal'?'selected':'' ?>>Journal Listing</option>
          <option value="trial_balance" <?= $view==='trial_balance'?'selected':'' ?>>Trial Balance</option>
          <option value="ledger" <?= $view==='ledger'?'selected':'' ?>>General Ledger</option>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">Date from</label><input class="form-control form-control-sm" type="date" name="date_from" value="<?= gl_h($dateFrom) ?>"></div>
      <div class="col-md-2"><label class="form-label">Date to</label><input class="form-control form-control-sm" type="date" name="date_to" value="<?= gl_h($dateTo) ?>"></div>
      <div class="col-md-2"><label class="form-label">Module</label><input class="form-control form-control-sm" name="source_module" value="<?= gl_h($sourceModule) ?>"></div>
      <div class="col-md-2"><label class="form-label">Source ref</label><input class="form-control form-control-sm" name="source_ref" value="<?= gl_h($sourceRef) ?>"></div>
      <div class="col-md-2">
        <label class="form-label">Account (ledger)</label>
        <select class="form-select form-select-sm" name="account_id">
          <option value="0">--choose--</option>
          <?php foreach($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $accountId===(int)$a['id']?'selected':'' ?>><?= gl_h($a['code'].' - '.$a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <button class="btn btn-primary btn-sm">Apply</button>
        <a class="btn btn-outline-light btn-sm" href="?<?= http_build_query(['view'=>$view,'date_from'=>$dateFrom,'date_to'=>$dateTo,'source_module'=>$sourceModule,'source_ref'=>$sourceRef,'account_id'=>$accountId,'export'=>'csv']) ?>">Export CSV</a>
        <a class="btn btn-outline-warning btn-sm" href="gl_reversal_approvals.php">Reversal Approvals</a>
        <a class="btn btn-secondary btn-sm" href="purchases_dashboard.php">Back</a>
      </div>
    </form>

    <?php if ($view === 'journal'): ?>
      <div class="table-responsive">
        <table class="table table-sm table-dark table-hover">
          <thead><tr><th>Date</th><th>Journal No</th><th>Source</th><th>Status</th><th class="num">DR</th><th class="num">CR</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$journalRows): ?><tr><td colspan="7" class="text-muted">No journal data.</td></tr><?php endif; ?>
          <?php foreach($journalRows as $r): ?>
            <tr>
              <td><?= gl_h($r['journal_date']) ?></td>
              <td><?= gl_h($r['journal_no']) ?></td>
              <td><?= gl_h($r['source_module'].'/'.$r['source_event'].'/'.$r['source_ref']) ?></td>
              <td><?= gl_h($r['status']) ?></td>
              <td class="num"><?= gl_h(number_format((float)$r['total_dr'],2,'.',',')) ?></td>
              <td class="num"><?= gl_h(number_format((float)$r['total_cr'],2,'.',',')) ?></td>
              <td>
                <?php if (strtoupper((string)$r['status']) !== 'VOIDED'): ?>
                  <form method="post" class="d-flex gap-1">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="reverse_journal">
                    <input type="hidden" name="header_id" value="<?= (int)$r['id'] ?>">
                    <input class="form-control form-control-sm" name="reason" placeholder="reason" style="width:160px">
                    <button class="btn btn-sm btn-outline-warning">Request Reverse</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($view === 'trial_balance'): ?>
      <div class="table-responsive">
        <table class="table table-sm table-dark table-hover">
          <thead><tr><th>Code</th><th>Name</th><th>Type</th><th class="num">DR</th><th class="num">CR</th><th class="num">Balance</th></tr></thead>
          <tbody>
          <?php if (!$tbRows): ?><tr><td colspan="6" class="text-muted">No trial balance data.</td></tr><?php endif; ?>
          <?php foreach($tbRows as $r): ?>
            <tr>
              <td><?= gl_h($r['code']) ?></td>
              <td><?= gl_h($r['name']) ?></td>
              <td><?= gl_h($r['account_type']) ?></td>
              <td class="num"><?= gl_h(number_format((float)$r['total_dr'],2,'.',',')) ?></td>
              <td class="num"><?= gl_h(number_format((float)$r['total_cr'],2,'.',',')) ?></td>
              <td class="num"><?= gl_h(number_format((float)$r['balance'],2,'.',',')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-dark table-hover">
          <thead><tr><th>Date</th><th>Journal No</th><th>Source</th><th>Memo</th><th class="num">DR</th><th class="num">CR</th><th class="num">Running</th></tr></thead>
          <tbody>
          <?php if (!$ledgerRows): ?><tr><td colspan="7" class="text-muted">No ledger data.</td></tr><?php endif; ?>
          <?php foreach($ledgerRows as $r): ?>
            <tr>
              <td><?= gl_h($r['journal_date']) ?></td>
              <td><?= gl_h($r['journal_no']) ?></td>
              <td><?= gl_h($r['source_module'].'/'.$r['source_event'].'/'.$r['source_ref']) ?></td>
              <td><?= gl_h((string)$r['memo']) ?></td>
              <td class="num"><?= gl_h(number_format((float)$r['dr_amount'],2,'.',',')) ?></td>
              <td class="num"><?= gl_h(number_format((float)$r['cr_amount'],2,'.',',')) ?></td>
              <td class="num"><?= gl_h(number_format((float)$r['running_balance'],2,'.',',')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="card p-3 mt-3">
      <h6>Audit Log <span class="text-muted">(Last 50 events)</span></h6>
      <?php if (empty($audit_rows)): ?>
        <div class="text-muted">Belum ada audit log.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-dark table-hover mb-0">
            <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:140px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($audit_rows as $a): ?>
              <tr><td><?= gl_h($a['created_at'] ?? '') ?></td><td><?= gl_h($a['action'] ?? '') ?></td><td><?= gl_h($a['record_code'] ?? '') ?></td><td><?= gl_h($a['username'] ?? '') ?></td><td><?= gl_h($a['description'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
