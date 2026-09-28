<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.MFA_BYPASS_MANAGE', 'SYSTEM.USER_MANAGE']);
} else {
    require_admin_critical();
}

$pdo = db_pdo();
require_once __DIR__ . '/schema_mfa.php';
require_once __DIR__ . '/_audit_master.php';
schema_ensure_auth_mfa_bypass_tickets($pdo);
erp_audit_ensure($pdo);

function mb_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$flash = ['type'=>'','msg'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'create') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            $expiresAt = trim((string)($_POST['expires_at'] ?? ''));
            if ($userId <= 0 || $reason === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiresAt)) {
                throw new Exception('Invalid bypass input.');
            }
            $requester = (int)($_SESSION['user_id'] ?? 0) ?: null;
            $pdo->prepare(
                "INSERT INTO auth_mfa_bypass_tickets (user_id, reason, expires_at, status, is_active, created_by, requested_by, created_at, updated_at)
                 VALUES (?, ?, ?, 'PENDING', 0, ?, ?, NOW(), NOW())"
            )->execute([$userId, $reason, $expiresAt, $requester, $requester]);
            $id = (int)$pdo->lastInsertId();
            erp_audit($pdo, 'MFA_BYPASS', 'BYPASS#'.$id, 'CREATE_REQUEST', ['user_id'=>$userId,'expires_at'=>$expiresAt,'reason'=>$reason,'requested_by'=>$requester]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'mfa_bypass', 'auth_mfa_bypass_tickets', 'CREATE_REQUEST', $id, 'BYPASS#'.$id, "MFA bypass request created for user_id {$userId}", ['user_id' => $userId, 'expires_at' => $expiresAt]);
            }
            $flash = ['type'=>'success','msg'=>'Bypass request created (status: PENDING).'];
        } elseif ($action === 'approve') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid bypass id.');
            $st = $pdo->prepare("SELECT requested_by, expires_at, status FROM auth_mfa_bypass_tickets WHERE id=? LIMIT 1");
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new Exception('Ticket not found.');
            if (strtoupper((string)($cur['status'] ?? '')) !== 'PENDING') throw new Exception('Only PENDING ticket can be approved.');
            if (strtotime((string)$cur['expires_at']) <= time()) throw new Exception('Cannot approve expired ticket.');
            $approver = (int)($_SESSION['user_id'] ?? 0);
            if ((int)($cur['requested_by'] ?? 0) === $approver) throw new Exception('Maker-checker: requester cannot approve own ticket.');
            $pdo->prepare(
              "UPDATE auth_mfa_bypass_tickets
               SET status='APPROVED', is_active=1, approved_by=?, approved_at=NOW(), updated_at=NOW()
               WHERE id=?"
            )            ->execute([$approver ?: null, $id]);
            erp_audit($pdo, 'MFA_BYPASS', 'BYPASS#'.$id, 'APPROVE', ['approved_by'=>$approver]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'mfa_bypass', 'auth_mfa_bypass_tickets', 'APPROVE', $id, 'BYPASS#'.$id, "MFA bypass approved: #{$id}", ['approved_by' => $approver]);
            }
            $flash = ['type'=>'success','msg'=>'Bypass approved and active.'];
        } elseif ($action === 'reject') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid bypass id.');
            $approver = (int)($_SESSION['user_id'] ?? 0);
            $pdo->prepare(
              "UPDATE auth_mfa_bypass_tickets
               SET status='REJECTED', is_active=0, approved_by=?, approved_at=NOW(), updated_at=NOW()
               WHERE id=? AND status='PENDING'"
            )->execute([$approver ?: null, $id]);
            erp_audit($pdo, 'MFA_BYPASS', 'BYPASS#'.$id, 'REJECT', ['approved_by'=>$approver]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'mfa_bypass', 'auth_mfa_bypass_tickets', 'REJECT', $id, 'BYPASS#'.$id, "MFA bypass rejected: #{$id}", ['approved_by' => $approver]);
            }
            $flash = ['type'=>'success','msg'=>'Bypass rejected.'];
        } elseif ($action === 'revoke') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid bypass id.');
            $pdo->prepare("UPDATE auth_mfa_bypass_tickets SET is_active=0, status='REVOKED', updated_at=NOW() WHERE id=?")->execute([$id]);
            erp_audit($pdo, 'MFA_BYPASS', 'BYPASS#'.$id, 'REVOKE', []);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'mfa_bypass', 'auth_mfa_bypass_tickets', 'REVOKE', $id, 'BYPASS#'.$id, "MFA bypass revoked: #{$id}", []);
            }
            $flash = ['type'=>'success','msg'=>'Bypass revoked.'];
        }
    } catch (Throwable $e) {
        $flash = ['type'=>'danger','msg'=>$e->getMessage()];
    }
}

$users = [];
$rows = [];
try {
    $users = $pdo->query("SELECT id, username, full_name, role, department, status FROM master_system_login ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $rows = $pdo->query(
      "SELECT b.*, u.username, u.full_name, u.role, u.department
       FROM auth_mfa_bypass_tickets b
       LEFT JOIN master_system_login u ON u.id=b.user_id
       ORDER BY b.id DESC
       LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $flash = ['type'=>'danger','msg'=>'Load failed: '.$e->getMessage()];
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();
rmi_header('MFA Bypass Tickets', [
  'active' => 'master',
  'subtitle' => 'Temporary bypass with expiry and audit trail',
  'breadcrumbs' => [
    ['label' => 'Master', 'url' => $base . '/master/index.php'],
    'MFA Bypass Tickets'
  ]
]);
?>

<?php if ($flash['msg'] !== ''): ?>
  <div class="alert alert-<?= mb_h($flash['type'] !== '' ? $flash['type'] : 'info') ?>"><?= mb_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="rmi-card p-3">
      <h6>Create Bypass</h6>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-12">
          <label class="form-label">User</label>
          <select class="form-select form-select-sm" name="user_id" required>
            <option value="">-- choose --</option>
            <?php foreach($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= mb_h((string)$u['username'] . ' | ' . (string)($u['role'] ?? '') . ' | ' . (string)($u['department'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Reason</label>
          <textarea class="form-control form-control-sm" name="reason" rows="2" required></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Expires At (YYYY-MM-DD HH:MM:SS)</label>
          <input class="form-control form-control-sm" name="expires_at" value="<?= mb_h(date('Y-m-d H:i:s', time()+3600)) ?>" required>
        </div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Create</button></div>
      </form>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="rmi-card p-3">
      <h6>Bypass List (Maker-Checker)</h6>
      <div class="table-responsive">
        <table class="table table-sm table-dark table-hover">
          <thead><tr><th>ID</th><th>User</th><th>Role/Dept</th><th>Reason</th><th>Expires</th><th>Status</th><th>Requested/Approved</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="8" class="text-muted">No bypass tickets.</td></tr><?php endif; ?>
          <?php foreach($rows as $r): ?>
            <?php $expired = strtotime((string)$r['expires_at']) <= time(); ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= mb_h((string)($r['username'] ?? '')) ?></td>
              <td><?= mb_h((string)($r['role'] ?? '') . '/' . (string)($r['department'] ?? '')) ?></td>
              <td><?= mb_h((string)$r['reason']) ?></td>
              <td><?= mb_h((string)$r['expires_at']) ?></td>
              <td>
                <span class="badge text-bg-secondary"><?= mb_h((string)($r['status'] ?? ((int)$r['is_active']===1?'APPROVED':'PENDING'))) ?></span>
                <?php if ($expired): ?><span class="badge text-bg-warning">EXPIRED</span><?php endif; ?>
              </td>
              <td>
                <div class="small text-muted">req: <?= (int)($r['requested_by'] ?? 0) ?></div>
                <div class="small text-muted">app: <?= (int)($r['approved_by'] ?? 0) ?></div>
              </td>
              <td>
                <?php if (strtoupper((string)($r['status'] ?? '')) === 'PENDING'): ?>
                  <div class="d-flex gap-1">
                    <form method="post" onsubmit="return confirm('Approve bypass?')">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-success">Approve</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Reject bypass?')">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-warning">Reject</button>
                    </form>
                  </div>
                <?php endif; ?>
                <?php if ((int)$r['is_active']===1): ?>
                  <form method="post" onsubmit="return confirm('Revoke bypass?')">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Revoke</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($audit_rows)): ?>
<div class="rmi-card p-3 mt-3">
  <h6>Audit Log (Last 50 events)</h6>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover">
      <thead><tr><th>Time</th><th>Action</th><th>Code</th><th>User</th><th>Description</th></tr></thead>
      <tbody>
        <?php foreach ($audit_rows as $a): ?>
          <tr>
            <td><?= mb_h($a['created_at'] ?? '') ?></td>
            <td><?= mb_h($a['action'] ?? '') ?></td>
            <td><?= mb_h($a['record_code'] ?? '') ?></td>
            <td><?= mb_h($a['username'] ?? '') ?></td>
            <td><?= mb_h($a['description'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
