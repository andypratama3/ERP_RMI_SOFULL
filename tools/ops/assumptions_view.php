<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_state.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('ops/assumptions_view.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$path = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/logs/assumptions_last.json';
$res = tools_read_json_safe($path);
$data = $res['ok'] ? (array)$res['data'] : [];

require_once __DIR__ . '/../../_shared/rmi_layout.php';
rmi_header('Assumptions Log (Latest)', ['active' => 'stock', 'subtitle' => 'assumptions_last.json']);
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5>Assumptions Last</h5>
    <a class="btn btn-sm btn-outline-light" href="../index.php">← Tools Index</a>
  </div>
  <?php if (!$res['ok']): ?>
    <div class="alert alert-warning">State file missing or invalid. Run tools from NAS to generate.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-dark">
        <thead><tr><th>Key</th><th>Value (masked)</th></tr></thead>
        <tbody>
          <?php foreach ($data as $k => $v): ?>
          <tr>
            <td><code><?= h((string)$k) ?></code></td>
            <td><?= h(tools_mask_sensitive(is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php rmi_footer(); ?>
