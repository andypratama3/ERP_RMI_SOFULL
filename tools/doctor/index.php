<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_exec_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('doctor/index.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$msg = '';
$msgType = 'info';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $lock = tools_lock_acquire('doctor', 900);
    if (!$lock['ok']) {
        $msg = 'Doctor sedang berjalan (IN_PROGRESS).';
        $msgType = 'warning';
    } else {
        tools_lock_release('doctor');
        $cmd = escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' .
            escapeshellarg(APP_ROOT . '/tools/doctor/run_doctor.php');
        $run = tools_run_step($cmd, 300, ['idempotent' => true, 'max_retry' => 0]);
        $msg = $run['ok'] ? 'Doctor selesai dijalankan.' : 'Doctor selesai dengan warning/fail.';
        $msgType = $run['ok'] ? 'success' : 'warning';
    }
}

$state = tools_json_read_safe(APP_ROOT . '/storage/logs/doctor_last.json');

$baseProject = rmi_layout_base_project();
rmi_header('Doctor One Click', [
    'active' => 'tools',
    'subtitle' => 'Safe pipeline: preflight, health, contract, smoke, triage, checklist.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Doctor'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Run Doctor (Safe)</button>
      </form>
      <div class="small text-muted mt-2">Anti overlap lock 15 menit, timeout/retry per step, write state only.</div>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">doctor_last.json</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
