<?php
declare(strict_types=1);

/**
 * Central Audit Logs — view system_audit_logs across all modules
 * Filter by module, date range, username, action
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();

// Akses dikendalikan via SYSTEM.AUDIT_LOG_VIEW — bisa dikonfigurasi di RBAC Center per Dept+Role.
// SYS selalu bypass (auth_is_admin). Permission lama tetap diterima sebagai backward-compat.
if (function_exists('require_any_permission')) {
    require_any_permission([
        'SYSTEM.AUDIT_LOG_VIEW',    // permission utama — configurable via RBAC Center
        'SYSTEM.JOBS_MONITOR',      // backward-compat
        'SYSTEM.USER_MANAGE',       // backward-compat
        'SYSTEM.CONFIG_MANAGE',     // backward-compat
        'MASTER.ADMIN_CENTER',      // backward-compat
    ]);
} else {
    // Fallback minimal jika RBAC belum siap — hanya SYS
    if (function_exists('auth_is_admin') && !auth_is_admin()) {
        http_response_code(403);
        echo '<h3>Akses Ditolak</h3><p>Audit Log hanya untuk SYS atau yang memiliki permission <b>SYSTEM.AUDIT_LOG_VIEW</b>.</p>';
        exit;
    }
}

require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

$pdo = db_pdo();
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
}

$base = rmi_layout_base_project();
$moduleF = trim((string)($_GET['module'] ?? ''));
$usernameF = trim((string)($_GET['username'] ?? ''));
$actionF = trim((string)($_GET['action'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$limit = min(500, max(50, (int)($_GET['limit'] ?? 200)));

$where = [];
$params = [];
if ($moduleF !== '') {
    $where[] = 'module = ?';
    $params[] = $moduleF;
}
if ($usernameF !== '') {
    $where[] = 'username LIKE ?';
    $params[] = '%' . $usernameF . '%';
}
if ($actionF !== '') {
    $where[] = 'action = ?';
    $params[] = $actionF;
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}
$ipF = trim((string)($_GET['ip'] ?? ''));
if ($ipF !== '') {
    $where[] = 'ip LIKE ?';
    $params[] = '%' . $ipF . '%';
}

$sql = "SELECT id, module, action, record_code, username, ip, description, created_at
        FROM system_audit_logs";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;

$rows = [];
try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}

// Distinct modules for filter dropdown
$modules = [];
try {
    $modSt = $pdo->query("SELECT DISTINCT module FROM system_audit_logs ORDER BY module");
    $modules = $modSt ? $modSt->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Throwable $e) {}

function al_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

rmi_header('Audit Logs', [
    'active' => 'master',
    'subtitle' => 'Central view of system_audit_logs across all modules',
    'breadcrumbs' => [
        ['label' => 'Master Data', 'url' => $base . '/master/index.php'],
        'Audit Logs',
    ],
    'actions' => [
        ['label' => 'Master Data', 'url' => $base . '/master/index.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<div class="rmi-card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label mb-0 small">Module</label>
      <select class="form-select form-select-sm" name="module" style="width:180px">
        <option value="">All</option>
        <?php foreach ($modules as $m): ?>
          <option value="<?= al_h($m) ?>" <?= $moduleF === $m ? 'selected' : '' ?>><?= al_h($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label mb-0 small">Action</label>
      <input class="form-control form-control-sm" type="text" name="action" value="<?= al_h($actionF) ?>" placeholder="e.g. CREATE" style="width:120px">
    </div>
    <div class="col-auto">
      <label class="form-label mb-0 small">Username</label>
      <input class="form-control form-control-sm" type="text" name="username" value="<?= al_h($usernameF) ?>" placeholder="Filter user" style="width:120px">
    </div>
    <div class="col-auto">
      <label class="form-label mb-0 small">Date From</label>
      <input class="form-control form-control-sm" type="date" name="date_from" value="<?= al_h($dateFrom) ?>" style="width:140px">
    </div>
    <div class="col-auto">
      <label class="form-label mb-0 small">Date To</label>
      <input class="form-control form-control-sm" type="date" name="date_to" value="<?= al_h($dateTo) ?>" style="width:140px">
    </div>
    <div class="col-auto">
      <label class="form-label mb-0 small">Limit</label>
      <select class="form-select form-select-sm" name="limit" style="width:80px">
        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
        <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
        <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label mb-0 small">IP Address</label>
      <input class="form-control form-control-sm" type="text" name="ip" value="<?= al_h(trim((string)($_GET['ip'] ?? ''))) ?>" placeholder="e.g. 10.10.60" style="width:130px">
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary">Filter</button>
    </div>
  </form>
  <div class="small text-muted mt-2">
    Module <code>auth</code> = login denied/success. Filter by module atau IP untuk melihat per-sumber.
  </div>
</div>

<div class="rmi-card p-3">
  <div class="fw-semibold mb-2">Audit Log <span class="text-muted">(<?= count($rows) ?> rows)</span></div>
  <?php if (empty($rows)): ?>
    <div class="text-muted">Tidak ada data. Coba ubah filter.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-dark table-hover mb-0">
        <thead>
          <tr>
            <th style="width:145px">Time</th>
            <th style="width:80px">Module</th>
            <th style="width:160px">Action</th>
            <th style="width:130px">Code / User</th>
            <th style="width:115px">IP</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $action = (string)($r['action'] ?? '');
          $isLoginOk  = $action === 'LOGIN_SUCCESS';
          $isLoginFail = in_array($action, ['LOGIN_DENIED','SESSION_EXPIRED'], true);
          $actionStyle = $isLoginOk ? 'color:#4ade80;font-weight:600' : ($isLoginFail ? 'color:#f87171;font-weight:600' : '');
        ?>
          <tr>
            <td style="white-space:nowrap;color:#94a3b8;font-size:12px"><?= al_h(substr((string)($r['created_at'] ?? ''), 0, 19)) ?></td>
            <td><code style="font-size:11px"><?= al_h($r['module'] ?? '') ?></code></td>
            <td style="font-size:12px;<?= $actionStyle ?>">
              <?php if ($isLoginOk): ?><?= rmi_icon('check') ?> <?php elseif ($isLoginFail): ?><?= rmi_icon('cross') ?> <?php endif; ?>
              <?= al_h($action) ?>
            </td>
            <td style="font-size:12px">
              <span style="color:#e2e8f0"><?= al_h($r['username'] ?? '') ?></span>
              <?php if (!empty($r['record_code']) && $r['record_code'] !== $r['username']): ?>
                <div style="color:#64748b;font-size:10px"><?= al_h($r['record_code'] ?? '') ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:11px;color:#64748b;font-family:monospace"><?= al_h($r['ip'] ?? '') ?></td>
            <td style="font-size:12px"><?= al_h($r['description'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php rmi_footer(); ?>
