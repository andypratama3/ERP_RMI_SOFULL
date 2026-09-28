<?php
declare(strict_types=1);

require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login();
// GL Reversal: hanya FIN / ADMIN — BRANCH & dept lain dilarang keras
// JANGAN tambahkan PURCHASES.REPORTS_VIEW di sini (BRANCH punya permission itu)
if (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.AP_PAYMENT_VIEW', 'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.AP_PAYMENT_EDIT', 'PURCHASES.ADMIN_GL_AUTO', 'MASTER.ADMIN_CENTER']);
} else {
    require_role(['FIN','ADMIN','SUPERADMIN','SYS']);
}
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
rmi_block_branch('GL Reversal hanya untuk Dept FIN atau Admin.');

$pdo = db_pdo();
erp_audit_ensure($pdo);
$poster = new \App\Accounting\GLPostingService();

function ga_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function ga_table_exists(PDO $pdo, string $table): bool
{
    try {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $st->execute([$dbName, $table]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
$flash = ['type'=>'','msg'=>''];
$tableReady = ga_table_exists($pdo, 'gl_reversal_requests');
$setupHint = 'Table `gl_reversal_requests` belum tersedia. Jalankan migrasi SQL: `sql/migrations/082_gl_dual_control_reversal.sql`.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    $note = trim((string)($_POST['decision_note'] ?? ''));
    try {
        if (!$tableReady) {
            throw new Exception($setupHint);
        }
        if ($id <= 0) throw new Exception('Invalid request id.');
        $st = $pdo->prepare("SELECT * FROM gl_reversal_requests WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) throw new Exception('Request not found.');
        if (strtoupper((string)$req['status']) !== 'PENDING') throw new Exception('Only PENDING request can be processed.');
        $approver = (int)($_SESSION['user_id'] ?? 0);
        if ((int)($req['requested_by'] ?? 0) === $approver) throw new Exception('Maker-checker: requester cannot approve/reject own request.');

        if ($action === 'approve') {
            // FIN Central Approver guard — GL Reversal approval is a high-risk financial action.
            // Only MgrFIN_BGR (FIN Pusat) + SYS may approve. Throws 403 if not allowed.
            if (function_exists('auth_require_fin_central_approver')) {
                auth_require_fin_central_approver();
            }
            $revId = $poster->reverseJournal($pdo, (int)$req['header_id'], (string)$req['reason'], $approver);
            $pdo->prepare(
              "UPDATE gl_reversal_requests
               SET status='APPROVED', approved_by=?, approved_at=NOW(), decision_note=?, reversal_header_id=?, updated_at=NOW()
               WHERE id=?"
            )->execute([$approver ?: null, $note !== '' ? $note : null, $revId ?: null, $id]);
            erp_audit($pdo, 'GL', 'REVREQ#'.$id, 'APPROVE', ['reversal_header_id'=>$revId,'approver'=>$approver,'note'=>$note]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'fin_gl_auto', 'gl_reversal_requests', 'APPROVE', $id, 'REVREQ#'.$id, "GL reversal approved: #{$id}", ['reversal_header_id' => $revId, 'approver' => $approver]);
            }
            if (function_exists('auth_audit_event')) {
                auth_audit_event('PURCHASES.GL_REVERSAL_APPROVE', 'gl_reversal_requests', (string)$id, [
                    'reversal_header_id' => $revId, 'approver_user_id' => $approver, 'note' => $note,
                ]);
            }
            $flash = ['type'=>'success','msg'=>'Reversal request approved.'];
        } elseif ($action === 'reject') {
            $pdo->prepare(
              "UPDATE gl_reversal_requests
               SET status='REJECTED', rejected_by=?, rejected_at=NOW(), decision_note=?, updated_at=NOW()
               WHERE id=?"
            )->execute([$approver ?: null, $note !== '' ? $note : null, $id]);
            erp_audit($pdo, 'GL', 'REVREQ#'.$id, 'REJECT', ['approver'=>$approver,'note'=>$note]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'fin_gl_auto', 'gl_reversal_requests', 'REJECT', $id, 'REVREQ#'.$id, "GL reversal rejected: #{$id}", ['approver' => $approver]);
            }
            $flash = ['type'=>'success','msg'=>'Reversal request rejected.'];
        }
    } catch (Throwable $e) {
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('gl_reversal_approvals', $e, ['action' => 'GL_REVERSAL_' . strtoupper($action ?? ''), 'id' => $id ?? null]);
        $flash = ['type'=>'danger','msg'=>$e->getMessage()];
    }
}

$rowsPending = [];
$rowsHistory = [];
try {
    if ($tableReady) {
        $rowsPending = $pdo->query(
          "SELECT r.*, h.journal_no, h.journal_date, h.source_module, h.source_event, h.source_ref
           FROM gl_reversal_requests r
           JOIN gl_journal_headers h ON h.id=r.header_id
           WHERE r.status='PENDING'
           ORDER BY r.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $rowsHistory = $pdo->query(
          "SELECT r.*, h.journal_no, h.journal_date, h.source_module, h.source_event, h.source_ref
           FROM gl_reversal_requests r
           JOIN gl_journal_headers h ON h.id=r.header_id
           WHERE r.status IN ('APPROVED','REJECTED')
           ORDER BY r.id DESC
           LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $flash = ['type'=>'warning','msg'=>$setupHint];
    }
} catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('gl_reversal_approvals', $e, ['action' => 'GL_REVERSAL_LOAD']);
    $flash = ['type'=>'danger','msg'=>'Load failed. Please check DB schema/migration and retry.'];
}

$audit_rows = [];
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
}
try {
    $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='fin_gl_auto' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();
rmi_header('GL Reversal Approvals', [
  'active' => 'purchases',
  'subtitle' => 'Dual-control approvals for manual journal reversals',
  'breadcrumbs' => [
    ['label'=>'Purchases', 'url'=>$base.'/purchases/index.php'],
    'GL Reversal Approvals'
  ]
]);
?>

<?php if ($flash['msg'] !== ''): ?>
  <div class="alert alert-<?= ga_h($flash['type'] !== '' ? $flash['type'] : 'info') ?>"><?= ga_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <h6>Pending Requests</h6>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover">
      <thead><tr><th>ID</th><th>Journal</th><th>Source</th><th>Reason</th><th>Requested By</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$rowsPending): ?><tr><td colspan="6" class="text-muted">No pending request.</td></tr><?php endif; ?>
      <?php foreach($rowsPending as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= ga_h((string)$r['journal_no'].' | '.(string)$r['journal_date']) ?></td>
          <td><?= ga_h((string)$r['source_module'].'/'.(string)$r['source_event'].'/'.(string)$r['source_ref']) ?></td>
          <td><?= ga_h((string)$r['reason']) ?></td>
          <td><?= (int)($r['requested_by'] ?? 0) ?></td>
          <td class="d-flex gap-1">
            <form method="post" class="d-flex gap-1">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <input class="form-control form-control-sm" name="decision_note" placeholder="note (optional)" style="width:180px">
              <button class="btn btn-sm btn-outline-success" onclick="return confirm('Approve reversal request?')">Approve</button>
            </form>
            <form method="post" class="d-flex gap-1">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="reject">
              <input class="form-control form-control-sm" name="decision_note" placeholder="reason (optional)" style="width:180px">
              <button class="btn btn-sm btn-outline-warning" onclick="return confirm('Reject reversal request?')">Reject</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="rmi-card p-3">
  <h6>History</h6>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover">
      <thead><tr><th>ID</th><th>Journal</th><th>Status</th><th>Requested</th><th>Approved/Rejected By</th><th>Decision Note</th><th>Reversal Header</th></tr></thead>
      <tbody>
      <?php if (!$rowsHistory): ?><tr><td colspan="7" class="text-muted">No history.</td></tr><?php endif; ?>
      <?php foreach($rowsHistory as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= ga_h((string)$r['journal_no']) ?></td>
          <td><?= ga_h((string)$r['status']) ?></td>
          <td><?= (int)($r['requested_by'] ?? 0) ?></td>
          <td><?= (int)($r['approved_by'] ?? 0) ?: (int)($r['rejected_by'] ?? 0) ?></td>
          <td><?= ga_h((string)($r['decision_note'] ?? '')) ?></td>
          <td><?= (int)($r['reversal_header_id'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
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
          <tr><td><?= ga_h($a['created_at'] ?? '') ?></td><td><?= ga_h($a['action'] ?? '') ?></td><td><?= ga_h($a['record_code'] ?? '') ?></td><td><?= ga_h($a['username'] ?? '') ?></td><td><?= ga_h($a['description'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php rmi_footer(); ?>
