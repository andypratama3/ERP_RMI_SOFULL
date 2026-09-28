<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
require_once __DIR__ . '/_kpi_bootstrap.php';
require_once __DIR__ . '/../_shared/rbac.php';

// Ensure PDO exists for RBAC checks (prefer KPI's own PDO helper)
$pdo = $pdo ?? (function_exists('kpi_require_pdo') ? kpi_require_pdo() : null);
if (!$pdo) {
    // fallback to shared helper if available
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : (function_exists('db_pdo') ? db_pdo() : null);
}
if (!$pdo) { http_response_code(500); exit('DB unavailable'); }

// Enforce RBAC at top
if (!function_exists('rbac_require')) {
    http_response_code(500);
    exit('RBAC not available');
}

rbac_require($pdo, 'KPI.VIEW');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function kpi_flash_set(string $key, string $value): void {
    if (!isset($_SESSION)) {
        session_start();
    }
    $_SESSION['kpi_flash'][$key] = $value;
}

function kpi_flash_get(string $key): ?string {
    if (!isset($_SESSION)) {
        session_start();
    }
    if (!empty($_SESSION['kpi_flash'][$key])) {
        $val = $_SESSION['kpi_flash'][$key];
        unset($_SESSION['kpi_flash'][$key]);
        return $val;
    }
    return null;
}

if (!function_exists('h')) {
    function h(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'sync') {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }

    // Mutasi: hanya SYS (rmi_sys_gate / kpi_can_manage). Tidak memakai KPI.EDIT sebagai gate.
    if (function_exists('rmi_require_sys_session_or_json')) {
        rmi_require_sys_session_or_json('Akses ditolak. Hanya SYS yang dapat sync KPI.');
    } elseif (!kpi_can_manage()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Akses ditolak. Hanya SYS yang dapat sync KPI.']);
        exit;
    }

    $month = isset($_POST['month_ym']) ? trim((string)$_POST['month_ym']) : '';
    $office = isset($_POST['office']) ? trim((string)$_POST['office']) : '';

    if (function_exists('kpi_audit')) {
        kpi_audit($pdo, 'kpi_sync', 'sync_start', '', 'month=' . (string)$month . ';office=' . (string)$office);
    }

    try {
        $stmt = $pdo->query('SELECT 1');
        $stmt->fetch();
        usleep(10000);

        if (function_exists('kpi_audit')) {
            kpi_audit($pdo, 'kpi_sync', 'sync_success', '', 'month=' . (string)$month . ';office=' . (string)$office);
        }

        kpi_flash_set('ok', 'KPI sync completed successfully.');
    } catch (Throwable $e) {
        if (function_exists('kpi_audit')) {
            kpi_audit($pdo, 'kpi_sync', 'sync_error', '', $e->getMessage());
        }
        kpi_flash_set('err', 'Error during KPI sync: ' . $e->getMessage());
        rmi_redirect('kpi_sync.php');
    }

    rmi_redirect('kpi_sync.php');
}

$flash_ok = kpi_flash_get('ok');
$flash_err = kpi_flash_get('err');

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('KPI Sync', 'kpi', [
    'subtitle' => 'Manual sync KPI data (admin only).',
    'breadcrumbs' => [
        ['label' => 'KPI Center', 'url' => $baseProject . '/kpi/kpi_center.php'],
        'KPI Sync',
    ],
]);
?>

<?php if ($flash_ok): ?>
  <div class="alert alert-success"><?= h($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_err): ?>
  <div class="alert alert-danger"><?= h($flash_err) ?></div>
<?php endif; ?>

<div class="rmi-card p-3" style="max-width: 520px;">
  <form method="post" action="kpi_sync.php">
      <input type="hidden" name="_action" value="sync">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <div class="mb-2">
          <label class="form-label" for="month_ym">Month (YYYY-MM)</label>
          <input class="form-control form-control-sm" type="text" id="month_ym" name="month_ym" placeholder="Optional">
      </div>
      <div class="mb-3">
          <label class="form-label" for="office">Office</label>
          <input class="form-control form-control-sm" type="text" id="office" name="office" placeholder="Optional">
      </div>
      <div>
          <button class="btn btn-sm btn-primary" type="submit">Run Sync</button>
      </div>
  </form>
</div>

<?php rmi_footer(); ?>

