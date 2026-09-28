<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.MFA_POLICY_MANAGE', 'SYSTEM.USER_MANAGE', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_admin_critical();
}

$pdo = db_pdo();
erp_audit_ensure($pdo);
$flash = ['type' => '', 'msg' => ''];

function mp_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function mfa_policy_upsert(PDO $pdo, string $role, string $dept, int $require, int $active): void {
    $role = strtoupper(trim($role));
    $dept = strtoupper(trim($dept));
    if ($role === '') $role = '*';
    if ($dept === '') $dept = '*';
    $pdo->prepare(
        "INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE require_mfa=VALUES(require_mfa), is_active=VALUES(is_active), updated_at=NOW()"
    )->execute([$role, $dept, $require, $active]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_policy') {
            $id = (int)($_POST['id'] ?? 0);
            $role = strtoupper(trim((string)($_POST['role_code'] ?? '*')));
            $dept = strtoupper(trim((string)($_POST['dept_code'] ?? '*')));
            $require = isset($_POST['require_mfa']) ? 1 : 0;
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($role === '') $role = '*';
            if ($dept === '') $dept = '*';

            if ($id > 0) {
                $pdo->prepare(
                    "UPDATE auth_mfa_policies
                     SET role_code=?, dept_code=?, require_mfa=?, is_active=?, updated_at=NOW()
                     WHERE id=?"
                )->execute([$role, $dept, $require, $active, $id]);
                erp_audit($pdo, 'MFA_POLICY', 'POL#'.$id, 'UPDATE', ['role'=>$role,'dept'=>$dept,'require'=>$require,'active'=>$active]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'mfa_policy', 'auth_mfa_policies', 'UPDATE', $id, 'POL#'.$id, "MFA policy updated: {$role}/{$dept}", ['role' => $role, 'dept' => $dept]);
                }
                $flash = ['type' => 'success', 'msg' => 'Policy updated.'];
            } else {
                $pdo->prepare(
                    "INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(), NOW())"
                )->execute([$role, $dept, $require, $active]);
                $newId = (int)$pdo->lastInsertId();
                erp_audit($pdo, 'MFA_POLICY', 'POL#'.$newId, 'CREATE', ['role'=>$role,'dept'=>$dept,'require'=>$require,'active'=>$active]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'mfa_policy', 'auth_mfa_policies', 'CREATE', $newId, 'POL#'.$newId, "MFA policy created: {$role}/{$dept}", ['role' => $role, 'dept' => $dept]);
                }
                $flash = ['type' => 'success', 'msg' => 'Policy created.'];
            }
        }
        if ($action === 'delete_policy') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid policy id.');
            $pdo->prepare("DELETE FROM auth_mfa_policies WHERE id=?")->execute([$id]);
            erp_audit($pdo, 'MFA_POLICY', 'POL#'.$id, 'DELETE', []);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'mfa_policy', 'auth_mfa_policies', 'DELETE', $id, 'POL#'.$id, "MFA policy deleted: #{$id}", []);
            }
            $flash = ['type' => 'success', 'msg' => 'Policy deleted.'];
        }
        if ($action === 'apply_phase') {
            $phase = trim((string)($_POST['phase'] ?? ''));
            if (!in_array($phase, ['phase1','phase2','phase3'], true)) {
                throw new Exception('Invalid phase.');
            }

            if ($phase === 'phase1') {
                // Conservative rollout:
                // - Admin/Superadmin not required (as requested)
                // - Owner + Manager required
                // - Staff only critical dept required (FIN/ACT/SYS)
                mfa_policy_upsert($pdo, 'SYS', '*', 1, 1);
                mfa_policy_upsert($pdo, 'SYS', '*', 0, 1);
                mfa_policy_upsert($pdo, 'MANAGER', '*', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'FIN', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'ACT', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'SYS', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', '*', 0, 1);
                $flash = ['type' => 'success', 'msg' => 'MFA Policy Phase 1 applied.'];
            } elseif ($phase === 'phase2') {
                // Moderate rollout:
                // - Admin/Superadmin enabled
                // - Staff still department-based
                mfa_policy_upsert($pdo, 'SYS', '*', 1, 1);
                mfa_policy_upsert($pdo, 'SYS', '*', 1, 1);
                mfa_policy_upsert($pdo, 'MANAGER', '*', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'FIN', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'ACT', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'SYS', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', '*', 0, 1);
                $flash = ['type' => 'success', 'msg' => 'MFA Policy Phase 2 applied.'];
            } else {
                // Strict rollout:
                // - all roles/departments required
                mfa_policy_upsert($pdo, '*', '*', 1, 1);
                mfa_policy_upsert($pdo, 'SYS', '*', 1, 1);
                mfa_policy_upsert($pdo, 'SYS', '*', 1, 1);
                mfa_policy_upsert($pdo, 'MANAGER', '*', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', '*', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'FIN', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'ACT', 1, 1);
                mfa_policy_upsert($pdo, 'STAFF', 'SYS', 1, 1);
                $flash = ['type' => 'success', 'msg' => 'MFA Policy Phase 3 applied.'];
            }
            erp_audit($pdo, 'MFA_POLICY', strtoupper($phase), 'APPLY_PHASE', ['phase'=>$phase, 'by'=>(int)($_SESSION['user_id'] ?? 0)]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'mfa_policy', 'auth_mfa_policies', 'APPLY_PHASE', null, strtoupper($phase), "MFA policy phase applied: {$phase}", ['phase' => $phase]);
            }
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
}

$rows = [];
$edit = null;
$editId = (int)($_GET['id'] ?? 0);
try {
    $rows = $pdo->query("SELECT * FROM auth_mfa_policies ORDER BY role_code, dept_code, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if ($editId > 0) {
        $st = $pdo->prepare("SELECT * FROM auth_mfa_policies WHERE id=? LIMIT 1");
        $st->execute([$editId]);
        $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    $flash = ['type' => 'danger', 'msg' => 'Load failed: ' . $e->getMessage()];
}

// MFA User Status — siapa ON/OFF
$mfaUsers = [];
$mfaTotal = 0;
$mfaOn = 0;
$mfaOff = 0;
try {
    $mfaUsers = $pdo->query("
        SELECT id, username, full_name, role, department, office_code, status,
               COALESCE(mfa_enabled, 0) AS mfa_enabled,
               mfa_confirmed_at
        FROM master_system_login
        WHERE deleted_at IS NULL
        ORDER BY mfa_enabled DESC, department ASC, username ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $mfaTotal = count($mfaUsers);
    $mfaOn    = count(array_filter($mfaUsers, fn($u) => (int)($u['mfa_enabled'] ?? 0) === 1));
    $mfaOff   = $mfaTotal - $mfaOn;
} catch (Throwable $e) {}

$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
    $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'mfa_policy' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();
rmi_header('MFA Policy', [
  'active' => 'master',
  'subtitle' => 'Require MFA by role and/or department',
  'breadcrumbs' => [
    ['label' => 'Master', 'url' => $base . '/master/index.php'],
    'MFA Policy',
  ],
]);
?>

<?php if ($flash['msg'] !== ''): ?>
  <div class="alert alert-<?= mp_h($flash['type'] !== '' ? $flash['type'] : 'info') ?>"><?= mp_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <h6>Apply MFA Policy Phase</h6>
  <div class="text-muted small mb-2">
    Phase 1 (recommended now): SYS not required (gunakan SYS saja), SYS/MANAGER required, STAFF only FIN/ACT/SYS required.
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <form method="post" onsubmit="return confirm('Apply MFA Policy Phase 1?')">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="apply_phase">
      <input type="hidden" name="phase" value="phase1">
      <button class="btn btn-sm btn-outline-success">Apply Phase 1</button>
    </form>
    <form method="post" onsubmit="return confirm('Apply MFA Policy Phase 2?')">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="apply_phase">
      <input type="hidden" name="phase" value="phase2">
      <button class="btn btn-sm btn-outline-warning">Apply Phase 2</button>
    </form>
    <form method="post" onsubmit="return confirm('Apply MFA Policy Phase 3 (strict/all users)?')">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="apply_phase">
      <input type="hidden" name="phase" value="phase3">
      <button class="btn btn-sm btn-outline-danger">Apply Phase 3</button>
    </form>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="rmi-card p-3">
      <h6><?= $edit ? 'Edit Policy' : 'Create Policy' ?></h6>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_policy">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="col-12">
          <label class="form-label">Role code</label>
          <input class="form-control form-control-sm" name="role_code" value="<?= mp_h((string)($edit['role_code'] ?? '*')) ?>" placeholder="FIN / SYS / *">
        </div>
        <div class="col-12">
          <label class="form-label">Department code</label>
          <input class="form-control form-control-sm" name="dept_code" value="<?= mp_h((string)($edit['dept_code'] ?? '*')) ?>" placeholder="SYS / FIN / *">
        </div>
        <div class="col-12 form-check">
          <input class="form-check-input" type="checkbox" name="require_mfa" id="req_mfa" <?= ((int)($edit['require_mfa'] ?? 1)===1)?'checked':'' ?>>
          <label class="form-check-label" for="req_mfa">Require MFA</label>
        </div>
        <div class="col-12 form-check">
          <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= ((int)($edit['is_active'] ?? 1)===1)?'checked':'' ?>>
          <label class="form-check-label" for="is_active">Active policy</label>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-sm btn-primary">Save</button>
          <?php if ($edit): ?><a class="btn btn-sm btn-outline-light" href="mfa_policy.php">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="rmi-card p-3">
      <h6>Policy List</h6>
      <div class="table-responsive">
        <table class="table table-sm table-dark table-hover">
          <thead><tr><th>ID</th><th>Role</th><th>Dept</th><th>Require</th><th>Active</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">No policy yet.</td></tr><?php endif; ?>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= mp_h((string)$r['role_code']) ?></td>
              <td><?= mp_h((string)$r['dept_code']) ?></td>
              <td><?= (int)$r['require_mfa']===1?'YES':'NO' ?></td>
              <td><?= (int)$r['is_active']===1?'YES':'NO' ?></td>
              <td class="d-flex gap-1">
                <a class="btn btn-sm btn-outline-light" href="?id=<?= (int)$r['id'] ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Delete policy?')">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="delete_policy">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
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

<!-- MFA User Status -->
<div class="rmi-card p-3 mb-3">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <h6 class="mb-0">📋 Status MFA per User</h6>
      <div class="text-muted small">Siapa yang sudah & belum aktifkan MFA</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <span style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3);border-radius:8px;padding:4px 12px;font-size:12px;font-weight:700">
        ✅ ON: <?= $mfaOn ?>
      </span>
      <span style="background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.25);border-radius:8px;padding:4px 12px;font-size:12px;font-weight:700">
        ✗ OFF: <?= $mfaOff ?>
      </span>
      <span style="background:rgba(255,255,255,.06);color:#94a3b8;border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:4px 12px;font-size:12px">
        Total: <?= $mfaTotal ?>
      </span>
    </div>
  </div>
  <?php if (empty($mfaUsers)): ?>
    <div class="text-muted small">Tidak ada data user.</div>
  <?php else: ?>
  <div class="table-responsive" style="border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,.08)">
    <table style="width:100%;border-collapse:collapse;min-width:600px">
      <thead>
        <tr style="background:rgba(15,23,42,.95)">
          <th style="padding:8px 12px;font-size:11px;color:#64748b;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">Username</th>
          <th style="padding:8px 12px;font-size:11px;color:#64748b;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">Nama</th>
          <th style="padding:8px 12px;font-size:11px;color:#64748b;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">Role / Dept</th>
          <th style="padding:8px 12px;font-size:11px;color:#64748b;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">Office</th>
          <th style="padding:8px 12px;font-size:11px;color:#64748b;font-weight:600;text-align:center;border-bottom:1px solid rgba(255,255,255,.08)">MFA Status</th>
          <th style="padding:8px 12px;font-size:11px;color:#64748b;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">Aktif Sejak</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($mfaUsers as $idx => $u):
          $on = (int)($u['mfa_enabled'] ?? 0) === 1;
          $rowBg = $idx % 2 === 0 ? 'rgba(17,24,39,.85)' : 'rgba(15,23,42,.7)';
          $dept = strtoupper((string)($u['department'] ?? ''));
          $role = strtoupper((string)($u['role'] ?? ''));
          $isFinMgr = ($dept === 'FIN' && $role === 'MANAGER');
          $isSys    = in_array($role, ['SYS','ADMIN','SUPERADMIN'], true);
        ?>
        <tr style="background:<?= $rowBg ?>">
          <td style="padding:8px 12px;font-size:12px;font-weight:600;color:#e2e8f0;border-bottom:1px solid rgba(255,255,255,.05)">
            <?= mp_h((string)($u['username'] ?? '')) ?>
            <?php if ($isFinMgr): ?>
              <span style="margin-left:4px;font-size:10px;background:rgba(251,191,36,.2);color:#fbbf24;border:1px solid rgba(251,191,36,.4);border-radius:4px;padding:1px 5px">💰 FIN</span>
            <?php elseif ($isSys): ?>
              <span style="margin-left:4px;font-size:10px;background:rgba(139,92,246,.2);color:#c4b5fd;border:1px solid rgba(139,92,246,.4);border-radius:4px;padding:1px 5px">🔐 SYS</span>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px;font-size:12px;color:#94a3b8;border-bottom:1px solid rgba(255,255,255,.05)"><?= mp_h((string)($u['full_name'] ?? '—')) ?></td>
          <td style="padding:8px 12px;font-size:11px;border-bottom:1px solid rgba(255,255,255,.05)">
            <span style="color:#93c5fd"><?= mp_h($role) ?></span>
            <span style="color:#475569"> / </span>
            <span style="color:#94a3b8"><?= mp_h($dept ?: '—') ?></span>
          </td>
          <td style="padding:8px 12px;font-size:11px;color:#64748b;border-bottom:1px solid rgba(255,255,255,.05)"><?= mp_h(strtoupper((string)($u['office_code'] ?? '—'))) ?></td>
          <td style="padding:8px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.05)">
            <?php if ($on): ?>
              <span style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3);border-radius:6px;padding:2px 10px;font-size:11px;font-weight:700">✅ ON</span>
            <?php else: ?>
              <span style="background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.25);border-radius:6px;padding:2px 10px;font-size:11px;font-weight:700">✗ OFF</span>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px;font-size:11px;color:#64748b;border-bottom:1px solid rgba(255,255,255,.05)">
            <?= $on && !empty($u['mfa_confirmed_at']) ? mp_h(substr((string)$u['mfa_confirmed_at'], 0, 16)) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
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
          <tr><td><?= mp_h($a['created_at'] ?? '') ?></td><td><?= mp_h($a['action'] ?? '') ?></td><td><?= mp_h($a['record_code'] ?? '') ?></td><td><?= mp_h($a['username'] ?? '') ?></td><td><?= mp_h($a['description'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php rmi_footer(); ?>
