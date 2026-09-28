<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_exec_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('hardening/erp_hardening_triage_web.php');
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
    $lock = tools_lock_acquire('hardening_triage', 900);
    if (!$lock['ok']) {
        $msg = 'Hardening triage sedang berjalan. Coba lagi sebentar.';
        $msgType = 'warning';
    } else {
        $cmd = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
            escapeshellarg(APP_ROOT . '/tools/hardening/erp_hardening_triage_run.php');
        $run = tools_run_step($cmd, 240, ['idempotent' => true, 'max_retry' => 1]);
        $msg = !empty($run['ok']) ? 'Hardening triage selesai.' : 'Hardening triage selesai dengan warning/fail.';
        $msgType = !empty($run['ok']) ? 'success' : 'warning';
        tools_lock_release('hardening_triage');
    }
}

$state = tools_json_read_safe(APP_ROOT . '/storage/logs/erp_hardening_triage_web.last.json');
$sum = (array)($state['summary'] ?? []);
$status = strtoupper((string)($sum['status'] ?? 'UNKNOWN'));
$baseProject = rmi_layout_base_project();
rmi_header('Hardening Triage', [
    'active' => 'tools',
    'subtitle' => 'Deterministic triage + evidence + anti false alarm.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Hardening Triage'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Run Hardening Triage</button>
        <a class="btn btn-outline-light btn-sm" href="../ops/manual_action_queue.php">Open Manual Action Queue</a>
        <a class="btn btn-outline-light btn-sm" href="auto_normalize_extended.php">Open Auto Normalize Extended</a>
      </form>
      <div class="small text-muted mt-2">State: <?= tools_badge($status !== '' ? $status : 'UNKNOWN') ?> · Request ID: <code><?= h((string)($state['request_id'] ?? '-')) ?></code></div>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
