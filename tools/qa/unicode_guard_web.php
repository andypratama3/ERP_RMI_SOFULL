<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/unicode_guard_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h($v) { return is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : ''; }
}

$jsonPath = ts_storage_logs_dir() . '/unicode_guard_last.json';
$data = [];
if (is_file($jsonPath)) {
    $raw = @file_get_contents($jsonPath);
    if ($raw !== false) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $data = $dec;
        }
    }
}

$baseProject = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';
rmi_header('Unicode Guard (Latest)', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        ['label' => 'QA', 'url' => $baseProject . '/tools/index.php#qa'],
        'Unicode Guard',
    ],
]);
?>
<div class="card p-3">
  <div class="fw-semibold mb-2">Unicode Guard — Latest Scan</div>
  <?php if (empty($data)): ?>
    <div class="text-muted">Belum ada hasil scan. Jalankan: <code>php tools/qa/unicode_guard.php --scan --strict --write-last</code></div>
  <?php else: ?>
    <div class="mb-2">
      <span class="badge bg-<?= !empty($data['ok']) ? 'success' : 'danger' ?>"><?= !empty($data['ok']) ? 'PASS' : 'FAIL' ?></span>
      <span class="ms-2 small text-muted"><?= h($data['generated_at'] ?? '') ?></span>
    </div>
    <div class="small mb-2">
      <strong>Counts:</strong>
      path_cyrillic=<?= (int)($data['counts']['path_cyrillic'] ?? 0) ?>,
      content_cyrillic_path_ctx=<?= (int)($data['counts']['content_cyrillic_path_ctx'] ?? 0) ?>,
      content_cyrillic_general=<?= (int)($data['counts']['content_cyrillic_general'] ?? 0) ?>
    </div>
    <?php
    $findings = $data['findings'] ?? [];
    if (!empty($findings)):
      $masked = array_map(static function ($f) {
          $p = $f['path'] ?? '';
          return tools_mask_sensitive($p);
      }, $findings);
    ?>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>Type</th><th>Path (masked)</th><th>Line</th><th>Snippet</th></tr></thead>
        <tbody>
        <?php foreach ($findings as $i => $f): ?>
          <tr>
            <td><?= h($f['type'] ?? '') ?></td>
            <td><code><?= h($masked[$i] ?? '') ?></code></td>
            <td><?= (int)($f['line'] ?? 0) ?></td>
            <td class="small text-muted"><?= h(mb_substr((string)($f['snippet'] ?? ''), 0, 80)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-success small">Tidak ada temuan Cyrillic.</div>
    <?php endif; ?>
  <?php endif; ?>
</div>
