<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_exec_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('hardening/auto_normalize_extended.php');
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
    $mode = (string)($_POST['mode'] ?? 'preview');
    $isApply = ($mode === 'apply');
    $cmd = escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' .
        escapeshellarg(APP_ROOT . '/tools/hardening/auto_normalize_extended_run.php');
    if ($isApply) {
        require_role(['SUPERADMIN']);
        $phrase = trim((string)($_POST['confirm_phrase'] ?? ''));
        if ($phrase !== 'I_UNDERSTAND_AUTO_NORMALIZE') {
            $msg = 'Confirm phrase tidak valid.';
            $msgType = 'warning';
        } else {
            $cmd .= ' --apply --i-understand';
        }
    }
    if ($msg === '') {
        $run = tools_run_step($cmd, 240, ['idempotent' => true, 'max_retry' => 0]);
        $msg = !empty($run['ok']) ? 'Auto normalize selesai.' : 'Auto normalize selesai dengan warning/fail.';
        $msgType = !empty($run['ok']) ? 'success' : 'warning';
    }
}

$state = tools_json_read_safe(APP_ROOT . '/storage/logs/auto_normalize_last.json');
$diff = is_file(APP_ROOT . '/storage/logs/auto_normalize_last.diff')
    ? (string)@file_get_contents(APP_ROOT . '/storage/logs/auto_normalize_last.diff')
    : '';
$baseProject = rmi_layout_base_project();
rmi_header('Auto Normalize Extended', [
    'active' => 'tools',
    'subtitle' => 'Preview default. Apply hanya whitelist + backup.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Auto Normalize Extended'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 flex-wrap align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="mode" value="preview">
        <button class="btn btn-rmi btn-sm" type="submit">Preview Only</button>
      </form>
      <form method="post" class="d-flex gap-2 flex-wrap align-items-center mt-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="mode" value="apply">
        <input class="form-control form-control-sm" style="max-width:260px" name="confirm_phrase" placeholder="I_UNDERSTAND_AUTO_NORMALIZE">
        <button class="btn btn-warning btn-sm" type="submit">Apply (Superadmin only)</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6"><div class="rmi-card p-3 h-100"><pre class="small mb-0" style="white-space:pre-wrap"><?= h(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div></div>
  <div class="col-lg-6"><div class="rmi-card p-3 h-100"><pre class="small mb-0" style="white-space:pre-wrap"><?= h($diff !== '' ? $diff : 'No diff yet.') ?></pre></div></div>
</div>
<?php rmi_footer(); ?>
