<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_lib/bootstrap.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.USER_MANAGE', 'SYSTEM.RBAC_MANAGE', 'TOOLS.VIEW']);
} elseif (function_exists('require_role')) {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$pdo = rmi_db_pdo();
$baseProject = (function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '') ?: '';
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$csrfTok = (string)(function_exists('csrf_token') ? csrf_token() : '');
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
    if (empty($_POST['csrf_token']) || !hash_equals($csrfTok, (string)$_POST['csrf_token'])) {
        $flash = ['type' => 'danger', 'msg' => 'CSRF invalid.'];
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $requestId = 'req-' . bin2hex(random_bytes(8));
        $actor = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'unknown');
        try {
            \App\Core\Cache\ApiCache::init();
            $store = \App\Core\Cache\ApiCache::store();
            if ($action === 'purge_all') {
                $n = $store->purgeAll();
                if (function_exists('erp_audit')) {
                    erp_audit($pdo, 'API_CACHE_PURGED', $actor, 'CACHE', 'purge_all', ['request_id' => $requestId, 'count' => $n]);
                }
                $flash = ['type' => 'success', 'msg' => "Purged all cache ({$n} entries)."];
            } elseif ($action === 'purge_prefix' && trim((string)($_POST['prefix'] ?? '')) !== '') {
                $prefix = trim((string)$_POST['prefix']);
                $n = $store->purgeByPrefix($prefix);
                if (function_exists('erp_audit')) {
                    erp_audit($pdo, 'API_CACHE_PURGED', $actor, 'CACHE', 'purge_prefix', ['request_id' => $requestId, 'prefix' => $prefix, 'count' => $n]);
                }
                $flash = ['type' => 'success', 'msg' => "Purged by prefix '{$prefix}' ({$n} entries)."];
            } elseif ($action === 'purge_tag' && trim((string)($_POST['tag'] ?? '')) !== '') {
                $tag = trim((string)$_POST['tag']);
                $n = $store->purgeByTag($tag);
                if (function_exists('erp_audit')) {
                    erp_audit($pdo, 'API_CACHE_PURGED', $actor, 'CACHE', 'purge_tag', ['request_id' => $requestId, 'tag' => $tag, 'count' => $n]);
                }
                $flash = ['type' => 'success', 'msg' => "Purged by tag '{$tag}' ({$n} entries)."];
            } else {
                $flash = ['type' => 'warning', 'msg' => 'Invalid action or missing parameter.'];
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'danger', 'msg' => 'Error: ' . h($e->getMessage())];
        }
    }
}

$enabled = (int)(function_exists('rmi_env') ? rmi_env('API_CACHE_ENABLED', '0') : (getenv('API_CACHE_ENABLED') ?: '0')) === 1;
$dir = (string)(function_exists('rmi_env') ? rmi_env('API_CACHE_DIR', '') : (getenv('API_CACHE_DIR') ?: ''));
if ($dir === '') {
    $dir = (defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: '')) . '/storage/cache/api';
}
$dirWritable = is_dir($dir) && is_writable($dir);
$dirMasked = str_replace((string)(defined('RMI_ROOT') ? RMI_ROOT : ''), '[APP_ROOT]', $dir);
$count = 0;
$sizeBytes = 0;
$lastKeys = [];
try {
    \App\Core\Cache\ApiCache::init();
    $store = \App\Core\Cache\ApiCache::store();
    $count = $store->count();
    $sizeBytes = $store->totalSizeBytes();
    $lastKeys = $store->listKeys(20);
} catch (Throwable $e) {
    $lastKeys = [];
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('API Cache Admin', [
    'active' => 'tools',
    'subtitle' => 'Cache status, purge by prefix/tag, audit log',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], ['label' => 'Cache Admin', 'url' => $baseProject . '/tools/cache_admin.php']],
]);
?>
<div class="container-fluid py-3">
  <?php if ($flash['msg'] !== ''): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="rmi-card p-3 mb-3">
    <h5 class="mb-3">Cache Status</h5>
    <table class="table table-sm table-dark-custom">
      <tr><td>Enabled</td><td><?= $enabled ? 'YES' : 'NO' ?></td></tr>
      <tr><td>Driver</td><td>file</td></tr>
      <tr><td>Dir (masked)</td><td><code><?= h($dirMasked) ?></code></td></tr>
      <tr><td>Dir writable</td><td><?= $dirWritable ? 'OK' : 'FAIL' ?></td></tr>
      <tr><td>Entries</td><td><?= (int)$count ?></td></tr>
      <tr><td>Total size</td><td><?= $sizeBytes >= 1024 ? round($sizeBytes / 1024, 1) . ' KB' : (int)$sizeBytes . ' B' ?></td></tr>
    </table>
  </div>

  <div class="rmi-card p-3 mb-3">
    <h5 class="mb-3">Purge Actions</h5>
    <p class="small text-muted">All mutations require POST + CSRF. Audit log recorded.</p>
    <div class="row g-2">
      <div class="col-md-4">
        <form method="post" onsubmit="return confirm('Purge ALL cache?');">
          <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
          <input type="hidden" name="action" value="purge_all">
          <button type="submit" class="btn btn-danger btn-sm w-100">Purge All</button>
        </form>
      </div>
      <div class="col-md-4">
        <form method="post" class="d-flex gap-1">
          <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
          <input type="hidden" name="action" value="purge_prefix">
          <input type="text" name="prefix" class="form-control form-control-sm" placeholder="Prefix (e.g. api_)" required>
          <button type="submit" class="btn btn-warning btn-sm">Purge by Prefix</button>
        </form>
      </div>
      <div class="col-md-4">
        <form method="post" class="d-flex gap-1">
          <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
          <input type="hidden" name="action" value="purge_tag">
          <input type="text" name="tag" class="form-control form-control-sm" placeholder="Tag (e.g. dashboard)" required>
          <button type="submit" class="btn btn-warning btn-sm">Purge by Tag</button>
        </form>
      </div>
    </div>
  </div>

  <div class="rmi-card p-3">
    <h5 class="mb-3">Last 20 Cache Keys (masked)</h5>
    <table class="table table-sm table-dark-custom">
      <thead><tr><th>Key (masked)</th><th>Created</th><th>Expired</th></tr></thead>
      <tbody>
        <?php foreach ($lastKeys as $k): ?>
          <tr>
            <td><code><?= h((string)($k['key_masked'] ?? '')) ?></code></td>
            <td><?= $k['created_at'] > 0 ? date('Y-m-d H:i', $k['created_at']) : '-' ?></td>
            <td><?= !empty($k['expired']) ? 'Yes' : 'No' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($lastKeys)): ?>
          <tr><td colspan="3" class="text-muted">No cache entries.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-3">
    <a class="btn btn-outline-light btn-sm" href="index.php">← Back to Tools</a>
  </div>
</div>
