<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/_lib/rfc_dashboard_lib.php';
require_once __DIR__ . '/_lib/rfc_markdown_parse.php';

tools_require_access('rfc/rfc_view.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$requestId = 'rfc-view-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
$rfcId = strtoupper(trim((string)($_GET['rfc'] ?? '')));
$guardOut = [];
$guardCode = 1;
$guardCmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/dev/verify_clean_room.php');
@exec($guardCmd . ' 2>&1', $guardOut, $guardCode);
if ((int)$guardCode !== 0) {
    rfcdash_append_audit('RFC_VIEW_OPENED', $requestId, ['rfc_id' => $rfcId, 'ok' => false, 'error' => 'CLEAN_ROOM_GUARD_FAIL']);
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>RFC View</title></head><body><h1>ATTENTION</h1><p>Clean-room guard failed.</p></body></html>';
    exit;
}
$index = rfcsf_read_index_last();
$resolved = rfcsf_resolve_rfc_file($rfcId, $index['ok'] ? (array)$index['data'] : null);

rfcdash_append_audit('RFC_VIEW_OPENED', $requestId, ['rfc_id' => $rfcId, 'ok' => (bool)($resolved['ok'] ?? false)]);

$baseProject = rmi_layout_base_project();
rmi_header('RFC View', [
    'active' => 'tools',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], ['label' => 'RFC Dashboard', 'url' => $baseProject . '/tools/rfc/rfc_dashboard.php'], 'View'],
]);
?>
<div class="row g-3">
  <div class="col-12"><a class="btn btn-sm btn-outline-light" href="rfc_dashboard.php">Back to Dashboard</a></div>
  <?php if (!$resolved['ok']): ?>
    <div class="col-12">
      <div class="alert alert-warning py-2 mb-0">
        <?= tools_badge('ATTENTION', 'RFC_VIEW_UNAVAILABLE') ?>
        <?= h((string)($resolved['error'] ?? 'UNKNOWN')) ?>
        <div class="small mt-1">Run: <code>php tools/rfc/rfc_index_scan.php --write-last</code></div>
      </div>
    </div>
  <?php else: ?>
    <?php
      $read = rfcsf_read_file_masked((string)$resolved['abs_path']);
      $rawMasked = (string)($read['content'] ?? '');
      $fm = rfcmd_parse_front_matter($rawMasked);
    ?>
    <div class="col-12">
      <div class="rmi-card p-3">
        <div class="fw-semibold mb-2"><?= h((string)($fm['RFC_ID'] ?? $rfcId)) ?> - <?= h((string)($fm['TITLE'] ?? '')) ?></div>
        <div class="small mb-1">Type: <b><?= h((string)($fm['TYPE'] ?? '')) ?></b> · Status: <b><?= h((string)($fm['STATUS'] ?? '')) ?></b> · Target Env: <b><?= h((string)($fm['TARGET_ENV'] ?? '')) ?></b></div>
        <div class="small mb-2">File: <code><?= h((string)($resolved['rel_path_masked'] ?? '')) ?></code></div>
        <div class="small mb-2">
          <a class="btn btn-sm btn-outline-light" href="rfc_download.php?rfc=<?= urlencode($rfcId) ?>">Download (.md)</a>
        </div>
        <pre style="white-space:pre-wrap;max-height:70vh;overflow:auto;"><?= h($rawMasked) ?></pre>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php rmi_footer(); ?>

