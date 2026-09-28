<?php
declare(strict_types=1);
/**
 * Tampilan web: laporan sumber navigasi paralel (sidebar vs registry vs bootstrap vs mod_card).
 * Guard: sama dengan RBAC Center (SYSTEM.RBAC_MANAGE | SYSTEM.RBAC_VIEW).
 */
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';

if (function_exists('auth_require_login')) {
    auth_require_login();
} else {
    require_login();
}
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.RBAC_MANAGE', 'SYSTEM.RBAC_VIEW']);
} else {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../tools/rbac/nav_parallel_sources_lib.php';

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$csrf = function_exists('rmi_csrf_token') ? rmi_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'refresh') {
    if (!function_exists('can') || !can('SYSTEM.RBAC_MANAGE')) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
    @set_time_limit(120);
    $payload = nav_parallel_sources_build($root);
    nav_parallel_sources_write_artifacts($root, $payload);
    $_SESSION['_nav_parallel_flash'] = ['type' => 'success', 'msg' => 'Laporan dibuat ulang dan disimpan ke storage/logs/.'];
    rmi_redirect('nav_parallel_report.php');
}

$flash = null;
if (!empty($_SESSION['_nav_parallel_flash']) && is_array($_SESSION['_nav_parallel_flash'])) {
    $flash = $_SESSION['_nav_parallel_flash'];
    unset($_SESSION['_nav_parallel_flash']);
}

$cacheFile = $root . '/storage/logs/nav_parallel_report.json';
$fromCache = false;
if (is_file($cacheFile)) {
    $raw = file_get_contents($cacheFile);
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['counts'], $decoded['gaps'], $decoded['sources'])) {
            $payload = $decoded;
            $fromCache = true;
        }
    }
}
if (!isset($payload) || !is_array($payload)) {
    @set_time_limit(120);
    $payload = nav_parallel_sources_build($root);
    nav_parallel_sources_write_artifacts($root, $payload);
    $fromCache = false;
}

if (!function_exists('rmi_nav_parallel_render_gap_list')) {
    /**
     * @param list<string> $lines
     */
    function rmi_nav_parallel_render_gap_list(array $lines, array $bootstrapFlat): void
    {
        if ($lines === []) {
            echo '<p class="text-success small mb-0">(kosong — tidak ada gap.)</p>';
            return;
        }
        echo '<ul class="small font-monospace mb-0" style="max-height:420px;overflow:auto;padding-left:1.25rem">';
        foreach ($lines as $line) {
            $files = $bootstrapFlat[$line] ?? [];
            $extra = $files !== [] ? ' <span class="text-muted">← ' . rmi_h(implode(', ', array_slice($files, 0, 4))) . (count($files) > 4 ? '…' : '') . '</span>' : '';
            echo '<li class="mb-1"><code>' . rmi_h($line) . '</code>' . $extra . '</li>';
        }
        echo '</ul>';
    }
}

$counts = $payload['counts'] ?? [];
$gaps = $payload['gaps'] ?? [];
$bootstrapFlat = $payload['sources']['bootstrap_path_to_files'] ?? [];
$modCardPaths = $payload['sources']['mod_card'] ?? [];

require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$canonicalThisPage = ($host !== '' && $script !== '')
    ? ($scheme . '://' . $host . $script)
    : '';

rmi_header('Nav & sidebar — audit sumber', [
    'active' => 'rbac',
    'breadcrumbs' => [
        ['label' => 'RBAC Center', 'url' => $base . '/rbac/index.php'],
        'Nav parallel report',
    ],
    'actions' => [
        ['label' => '🔐 RBAC Center', 'url' => $base . '/rbac/index.php', 'class' => 'btn btn-sm btn-rmi'],
        ['label' => '📖 Panduan RBAC', 'url' => $base . '/rbac/panduan.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

?>
<style>
.npr-hero{border:1px solid rgba(59,130,246,.35);border-radius:14px;padding:18px 22px;margin-bottom:18px;background:linear-gradient(135deg,rgba(59,130,246,.1),rgba(139,92,246,.06))}
.npr-card{border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:16px 18px;margin-bottom:14px;background:var(--rmi-card,#151c2e)}
.npr-card h3{font-size:14px;font-weight:700;margin:0 0 10px}
.npr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-top:8px}
.npr-stat{background:rgba(0,0,0,.2);border-radius:10px;padding:10px 12px;text-align:center}
.npr-stat .n{font-size:22px;font-weight:800;color:#93c5fd}
.npr-stat .l{font-size:10px;text-transform:uppercase;opacity:.75;letter-spacing:.04em;margin-top:4px}
</style>

<div class="npr-hero">
  <h4 class="mb-2" style="font-size:17px">🧭 Audit sumber navigasi (paralel)</h4>
  <?php if ($canonicalThisPage !== ''): ?>
  <div class="alert alert-info py-2 px-3 small mb-3" style="border-radius:10px">
    <strong>URL halaman ini (bookmark):</strong>
    <a href="<?= rmi_h($canonicalThisPage) ?>"><?= rmi_h($canonicalThisPage) ?></a>
    <div class="mt-1 opacity-90">Jika akses ke <code>/rbac/...</code> di root host menghasilkan <b>404</b>, biasanya aplikasi dipasang di <b>subfolder</b> (mis. <code>/ERP_RMI_SOFULL/rbac/...</code>). Pakai URL di atas atau samakan dengan cara Anda membuka RBAC Center.</div>
  </div>
  <?php endif; ?>
  <p class="small mb-2 opacity-90" style="line-height:1.55">
    Membandingkan <b>sidebar / Nav Manager</b> (<code>nav_config</code> + <code>$u</code> di <code>rmi_layout</code>),
    <b>Page Registry</b>, tautan di <b><code>*_bootstrap.php</code></b> (scan semua folder modul tingkat-1 repo, dengan whitelist path), dan kartu <b>Master Hub</b> (<code>mod_card</code>).
    Banyak selisih registry ↔ sidebar adalah wajar (halaman detail/admin); yang penting direviu secara bertahap.
  </p>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <form method="post" class="m-0">
      <input type="hidden" name="action" value="refresh">
      <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
      <button type="submit" class="btn btn-primary btn-sm">↻ Generate ulang + simpan ke storage/logs</button>
    </form>
    <span class="small opacity-75">Data: <code><?= rmi_h((string)($payload['generated_at'] ?? '')) ?></code>
      <?php if ($fromCache): ?><span class="badge bg-secondary ms-1">dari berkas cache</span><?php endif; ?>
    </span>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'info' ?> small py-2">
    <?= rmi_h((string)($flash['msg'] ?? '')) ?>
  </div>
<?php endif; ?>

<div class="npr-grid mb-3">
  <div class="npr-stat"><div class="n"><?= (int)($counts['nav_distinct_paths'] ?? 0) ?></div><div class="l">Path sidebar unik</div></div>
  <div class="npr-stat"><div class="n"><?= (int)($counts['page_registry_paths'] ?? 0) ?></div><div class="l">Page registry</div></div>
  <div class="npr-stat"><div class="n"><?= (int)($counts['rmi_layout_u_keys'] ?? 0) ?></div><div class="l">Keys $u rmi_layout</div></div>
  <div class="npr-stat"><div class="n"><?= (int)($counts['bootstrap_scan_root_folders'] ?? 0) ?></div><div class="l">Folder scan bootstrap</div></div>
  <div class="npr-stat"><div class="n"><?= (int)($counts['bootstrap_distinct_paths'] ?? 0) ?></div><div class="l">Path dari bootstrap</div></div>
  <div class="npr-stat"><div class="n"><?= (int)($counts['mod_card_paths'] ?? 0) ?></div><div class="l">mod_card</div></div>
  <div class="npr-stat"><div class="n"><?= (int)($counts['php_files_app_tree_excl_vendor_exports'] ?? 0) ?></div><div class="l">File .php (pohon app*)</div></div>
</div>
<p class="small text-muted">* Tanpa <code>vendor/</code>, <code>exports/</code>, <code>android_app/</code>, <code>storage/</code>, <code>tests/</code>, dll. Lihat <code>sources.bootstrap_scan_roots</code> di JSON.</p>

<div class="npr-card">
  <h3>1. Page Registry ⊄ sidebar path <span class="badge bg-secondary"><?= count($gaps['in_page_registry_not_in_sidebar_nav'] ?? []) ?></span></h3>
  <p class="small text-muted mb-2">Ada di <code>config/page_registry.php</code>, tidak sebagai URL path item sidebar.</p>
  <?php rmi_nav_parallel_render_gap_list($gaps['in_page_registry_not_in_sidebar_nav'] ?? [], []); ?>
</div>

<div class="npr-card">
  <h3>2. Sidebar path ⊄ Page Registry <span class="badge bg-secondary"><?= count($gaps['in_sidebar_nav_not_in_page_registry'] ?? []) ?></span></h3>
  <p class="small text-muted mb-2">Menu kiri punya path tapi tidak tercatat di registry — prioritas untuk dilengkapi jika RBAC jadi pusat.</p>
  <?php rmi_nav_parallel_render_gap_list($gaps['in_sidebar_nav_not_in_page_registry'] ?? [], []); ?>
</div>

<div class="npr-card">
  <h3>3. Bootstrap toolbar ⊄ sidebar path <span class="badge bg-secondary"><?= count($gaps['in_bootstrap_toolbar_not_in_sidebar_nav'] ?? []) ?></span></h3>
  <p class="small text-muted mb-2">URL diekstrak dari <code>*_bootstrap.php</code> (scan folder modul); sumber file ditampilkan di tiap baris jika ada.</p>
  <?php rmi_nav_parallel_render_gap_list($gaps['in_bootstrap_toolbar_not_in_sidebar_nav'] ?? [], is_array($bootstrapFlat) ? $bootstrapFlat : []); ?>
</div>

<div class="npr-card">
  <h3>4. mod_card (Master Hub) ⊄ sidebar path <span class="badge bg-secondary"><?= count($gaps['in_master_mod_card_not_in_sidebar_nav'] ?? []) ?></span></h3>
  <?php
  $m = $gaps['in_master_mod_card_not_in_sidebar_nav'] ?? [];
  if ($m === []) {
      echo '<p class="text-success small mb-0">(kosong)</p>';
  } else {
      echo '<ul class="small font-monospace mb-0" style="max-height:320px;overflow:auto;padding-left:1.25rem">';
      foreach ($m as $line) {
          $meta = is_array($modCardPaths[$line] ?? null) ? $modCardPaths[$line] : [];
          echo '<li class="mb-1"><code>' . rmi_h($line) . '</code> <span class="text-muted">href ' . rmi_h((string)($meta['href_raw'] ?? '')) . '</span></li>';
      }
      echo '</ul>';
  }
  ?>
</div>

<div class="small opacity-75 mt-3 mb-4">
  Arsip file: <code>storage/logs/nav_parallel_report.json</code> · <code>nav_parallel_report.md</code> —
  diperbarui saat tombol generate atau CLI <code>php tools/rbac/nav_parallel_sources_report.php</code>.
</div>

<?php rmi_footer(); ?>
