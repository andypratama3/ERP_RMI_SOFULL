<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
if (is_file($root . '/_shared/env.php')) {
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
}
require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

opsgov_require_admin();

if (!function_exists('ra_h')) {
    function ra_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$root = opsgov_root();
$statePath = $root . '/storage/state/release_verify_all_last.json';
$msg = '';
$env = strtolower((string)(getenv('APP_ENV') ?: 'staging'));
if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['verify_quick', 'verify_full'], true)) {
        $mode = $action === 'verify_full' ? 'full' : 'quick';
        if ($mode === 'full' && $env === 'production') {
            $typed = trim((string)($_POST['confirm_text'] ?? ''));
            if ($typed !== 'VERIFY_FULL') {
                $msg = 'Confirm text invalid. Use VERIFY_FULL.';
            }
        }
        if ($msg === '') {
            $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: (defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php'));
            $cmd = 'ERP_PHP_BIN=' . escapeshellarg($phpBin) . ' ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/release/verify_all_release_packs.php')
                . ' --env=' . escapeshellarg($env)
                . ' --mode=' . escapeshellarg($mode)
                . ' --write-last';
            if ($env === 'production') $cmd .= ' --strict';
            $out = [];
            $code = 1;
            @exec($cmd . ' 2>&1', $out, $code);
            $msg = $code === 0 ? 'Verification completed.' : 'Verification finished with issues.';
        }
    }
}

$stateRaw = is_file($statePath) ? (string)@file_get_contents($statePath) : '';
$state = json_decode($stateRaw, true);
$stateOk = is_array($state) && isset($state['summary']) && is_array($state['summary']);
$overall = $stateOk ? (bool)($state['overall_ok'] ?? false) : false;
$badge = $overall ? 'OK' : ($stateOk ? 'FAIL' : 'WARN');
$summary = $stateOk ? (array)$state['summary'] : ['bundle_total' => 0, 'bundle_ok' => 0, 'bundle_warn' => 0, 'bundle_fail' => 0, 'pack_total' => 0, 'pack_ok' => 0, 'pack_warn' => 0, 'pack_fail' => 0];
$bundles = $stateOk ? (array)($state['bundles'] ?? []) : [];

$baseProject = rmi_layout_base_project();
rmi_header('Release Artifacts', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Release Artifacts']]);
?>
<div class="row g-3">
  <div class="col-12">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div class="fw-semibold">Release Packs Verification</div>
          <div class="small text-muted">Last run: <?= ra_h((string)($state['generated_at'] ?? '-')) ?> | mode: <?= ra_h((string)($state['mode'] ?? '-')) ?> | env: <?= ra_h((string)($state['env'] ?? $env)) ?></div>
        </div>
        <span class="badge <?= $badge === 'OK' ? 'text-bg-success' : ($badge === 'FAIL' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= ra_h($badge) ?></span>
      </div>
      <?php if ($msg !== ''): ?><div class="alert alert-info py-2 mt-2 mb-0"><?= ra_h($msg) ?></div><?php endif; ?>
      <?php
        $zipOk = class_exists('ZipArchive');
        $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : 'php';
      ?>
      <?php if (!$zipOk): ?>
        <div class="alert alert-danger py-2 mt-2 mb-0">
          <strong>PHP Zip extension tidak tersedia.</strong> Verify gagal tanpa ZipArchive.
          <br>Web Station → PHP 8.4 → Edit → centang <b>zip</b>. Atau jalankan via CLI: <code>ERP_PHP_BIN=/usr/local/bin/php84 php tools/release/verify_all_release_packs.php --env=staging --mode=quick --write-last</code>
        </div>
      <?php endif; ?>
      <?php if (!$stateOk): ?>
        <div class="alert alert-warning py-2 mt-2 mb-0">Verification state corrupt; rerun verify tool.</div>
      <?php endif; ?>
      <div class="small text-muted">PHP: <?= ra_h(PHP_VERSION) ?> | ZipArchive: <?= $zipOk ? 'OK' : 'MISSING' ?> | CLI: <?= ra_h($phpBin) ?></div>
      <div class="small mt-2">
        bundles <?= (int)$summary['bundle_total'] ?> (ok <?= (int)$summary['bundle_ok'] ?> / warn <?= (int)$summary['bundle_warn'] ?> / fail <?= (int)$summary['bundle_fail'] ?>),
        packs <?= (int)$summary['pack_total'] ?> (ok <?= (int)$summary['pack_ok'] ?> / warn <?= (int)$summary['pack_warn'] ?> / fail <?= (int)$summary['pack_fail'] ?>)
      </div>
      <div class="mt-2 d-flex gap-2 flex-wrap">
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= ra_h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="verify_quick">
          <button class="btn btn-sm btn-rmi">Verify All (Quick)</button>
        </form>
        <form method="post" class="d-inline d-flex gap-2 align-items-center">
          <input type="hidden" name="csrf_token" value="<?= ra_h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="verify_full">
          <?php if ($env === 'production'): ?>
            <input class="form-control form-control-sm" style="max-width:170px" name="confirm_text" placeholder="VERIFY_FULL" required>
          <?php endif; ?>
          <button class="btn btn-sm btn-outline-light">Verify All (Full)</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Bundles</div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead><tr><th>Bundle Key</th><th>Status</th><th>Packs</th></tr></thead>
          <tbody>
            <?php if (!$bundles): ?>
              <tr><td colspan="3">No bundles.</td></tr>
            <?php endif; ?>
            <?php foreach ($bundles as $idx => $b): ?>
              <?php
                $st = strtoupper((string)($b['status'] ?? 'WARN'));
                $packs = (array)($b['packs'] ?? []);
                $showExpand = ($st === 'WARN' || $st === 'FAIL') && $packs !== [];
              ?>
              <tr>
                <td><code><?= ra_h((string)($b['bundle_key'] ?? '-')) ?></code></td>
                <td><span class="badge <?= $st === 'OK' ? 'text-bg-success' : ($st === 'FAIL' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= ra_h($st) ?></span></td>
                <td class="small">
                  <?php foreach ($packs as $p): ?>
                    <div><code><?= ra_h((string)($p['rel_path_zip'] ?? '-')) ?></code> · <?= ra_h((string)($p['status'] ?? 'UNKNOWN')) ?></div>
                  <?php endforeach; ?>
                  <?php if ($showExpand): ?>
                    <details class="mt-1">
                      <summary class="text-primary" style="cursor:pointer">▼ Klik untuk detail WARN/FAIL</summary>
                      <ul class="small mt-1 mb-0 ps-3">
                        <?php $anyShown = false; ?>
                        <?php foreach ($packs as $p): ?>
                          <?php $warns = (array)($p['warnings'] ?? []); $fails = (array)($p['fails'] ?? []); ?>
                          <?php if ($warns !== [] || $fails !== []): ?>
                            <?php $anyShown = true; ?>
                            <li class="mb-1">
                              <code><?= ra_h((string)($p['rel_path_zip'] ?? '-')) ?></code>:
                              <?php foreach ($warns as $w): ?>
                                <span class="text-warning"><?= ra_h(is_array($w) ? (($w['code'] ?? '') . ': ' . ($w['message'] ?? '')) : (string)$w) ?></span>
                              <?php endforeach; ?>
                              <?php foreach ($fails as $f): ?>
                                <span class="text-danger"><?= ra_h(is_array($f) ? (($f['code'] ?? '') . ': ' . ($f['message'] ?? '')) : (string)$f) ?></span>
                              <?php endforeach; ?>
                            </li>
                          <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!$anyShown): ?>
                          <li class="text-muted">Tidak ada breakdown. Jalankan Verify All (Full) untuk detail.</li>
                        <?php endif; ?>
                      </ul>
                    </details>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>

