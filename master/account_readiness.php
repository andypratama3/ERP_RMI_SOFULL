<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.ACCOUNT_READINESS', 'SYSTEM.USER_MANAGE']);
} else {
    require_admin_critical();
}
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/_audit_master.php';

$pdo = db_pdo();
erp_audit_ensure($pdo);
$base = rmi_layout_base_project();
$flash = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'set_inactive') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid user id.');
            }
            $st = $pdo->prepare(
                "UPDATE master_system_login
                 SET status='inactive', updated_at=NOW()
                 WHERE id=?"
            );
            $st->execute([$id]);
            erp_audit($pdo, 'ACCOUNT_READINESS', 'USER#' . $id, 'SET_INACTIVE', [
                'by' => (int)($_SESSION['user_id'] ?? 0),
            ]);
            if (function_exists('master_audit')) {
                $st = $pdo->prepare("SELECT username FROM master_system_login WHERE id=? LIMIT 1");
                $st->execute([$id]);
                $uname = (string)($st->fetchColumn() ?: '');
                master_audit($pdo, 'account_readiness', 'master_system_login', 'SET_INACTIVE', $id, 'USER#' . $id, "User set inactive: {$uname}", ['by' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            $flash = 'User di-set INACTIVE.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$rows = [];
try {
    $st = $pdo->query(
        "SELECT id, username, full_name, role, level, department, office_code, status, updated_at
         FROM master_system_login
         WHERE LOWER(COALESCE(status,'')) = 'active'
           AND (
                TRIM(COALESCE(department,'')) = ''
                OR TRIM(COALESCE(office_code,'')) = ''
           )
         ORDER BY id DESC
         LIMIT 500"
    );
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $error = 'Load failed: ' . $e->getMessage();
}

$audit_rows = [];
if (function_exists('master_audit')) {
    try {
        $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='account_readiness' ORDER BY created_at DESC LIMIT 50");
        $st->execute();
        $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

rmi_header('Account Readiness Check', [
    'active' => 'master',
    'subtitle' => 'Validasi akun ACTIVE wajib punya department dan office_code',
    'breadcrumbs' => [
        ['label' => 'Master Data', 'url' => $base . '/master/index.php'],
        'Account Readiness Check',
    ],
]);
?>
<?php if ($flash !== ''): ?><div class="alert alert-success py-2"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Akun ACTIVE belum lengkap</div>
      <div class="small rmi-muted">Jumlah: <b><?= (int)count($rows) ?></b>. Lengkapi department/office_code, atau set inactive sementara.</div>
    </div>
    <a class="btn btn-sm btn-outline-light" href="<?= htmlspecialchars($base . '/master/master_system_login.php', ENT_QUOTES, 'UTF-8') ?>">Buka Master System Login</a>
  </div>
</div>

<div class="rmi-card p-3">
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover mb-0">
      <thead>
        <tr>
          <th>ID</th>
          <th>User</th>
          <th>Role/Level</th>
          <th>Department</th>
          <th>Office</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-3">Tidak ada issue. Semua akun ACTIVE sudah lengkap.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)($r['id'] ?? 0) ?></td>
          <td>
            <div><?= htmlspecialchars((string)($r['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <small class="text-secondary"><?= htmlspecialchars((string)($r['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
          </td>
          <td><?= htmlspecialchars((string)($r['role'] ?? '') . ' / ' . (string)($r['level'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($r['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($r['office_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="badge text-bg-danger">ACTIVE (INVALID)</span></td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <a class="btn btn-sm btn-outline-light" href="<?= htmlspecialchars($base . '/master/master_system_login.php?edit_id=' . (int)($r['id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">Edit</a>
              <form method="post" onsubmit="return confirm('Set akun ini ke INACTIVE?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="set_inactive">
                <input type="hidden" name="id" value="<?= (int)($r['id'] ?? 0) ?>">
                <button class="btn btn-sm btn-outline-danger">Set INACTIVE</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
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
            <td><?= htmlspecialchars($a['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($a['action'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($a['record_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($a['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($a['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
