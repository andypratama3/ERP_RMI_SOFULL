<?php
declare(strict_types=1);

/**
 * Indeks panduan ERP per modul.
 * - Jika registry punya panduan_in_app → tombol utama ke halaman panduan dalam ERP (seperti purchases/panduan.php).
 * - Ringkasan Markdown (docs/modules/*.md) tetap tersedia sebagai link sekunder.
 */
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/_token_helper.php';

require_login();

$base = rmi_layout_base_project();
$registryPath = __DIR__ . '/modules/registry.json';
$registry = ['title' => 'Panduan per modul', 'description' => '', 'modules' => []];
if (is_file($registryPath) && is_readable($registryPath)) {
    $raw = file_get_contents($registryPath);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $registry = array_merge($registry, $decoded);
    }
}
$modules = isset($registry['modules']) && is_array($registry['modules']) ? $registry['modules'] : [];

$docsToken = '';
if (function_exists('docs_view_token_generate')) {
    $u = function_exists('auth_user') ? auth_user() : [];
    $uid = $u['user_id'] ?? $u['username'] ?? '0';
    $docsToken = docs_view_token_generate($uid);
}
$tParam = $docsToken !== '' ? ('&t=' . rawurlencode($docsToken)) : '';

rmi_header($registry['title'] ?? 'Panduan per modul', [
    'active'      => '',
    'subtitle'    => (string) ($registry['description'] ?? ''),
    'breadcrumbs' => [
        ['label' => 'Help Center', 'url' => $base . '/docs/help_center.php'],
        'Panduan per modul',
    ],
    'actions'     => [
        ['label' => '← Help Center', 'url' => $base . '/docs/help_center.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Monitoring', 'url' => $base . '/master/monitoring_center.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Cara pakai struktur ini</div>
  <ul class="small mb-0 ps-3">
    <li><b>Per modul</b> — satu file ringkasan alur + tabel halaman utama (path relatif project).</li>
    <li><b>Bukan</b> satu dokumen per halaman PHP; detail kritis dipisah di bagian <i>Deep-dive</i> atau doc governance terpisah.</li>
    <li>Memperbarui: edit <code>docs/modules/*.md</code> dan/atau <code>docs/modules/registry.json</code> — lihat <code>docs/modules/README.md</code>.</li>
  </ul>
</div>

<div class="row g-3">
  <?php foreach ($modules as $mod): ?>
    <?php
    if (!is_array($mod)) {
        continue;
    }
    $file = (string) ($mod['file'] ?? '');
    $fileOk = $file !== '' && preg_match('/^[A-Za-z0-9_]+\.md$/', $file);
    $inApp = trim((string) ($mod['panduan_in_app'] ?? ''));
    $inAppOk = $inApp !== '' && preg_match('#^/[A-Za-z0-9_]+/[A-Za-z0-9_.-]+\.php$#', $inApp);
    if (!$fileOk && !$inAppOk) {
        continue;
    }
    $title = (string) ($mod['title'] ?? $mod['id'] ?? 'Modul');
    $icon = (string) ($mod['icon'] ?? '📄');
    $summary = (string) ($mod['summary'] ?? '');
    $depts = (string) ($mod['depts'] ?? '');
    $viewUrl = $fileOk
        ? ($base . '/docs/docs_view.php?f=' . rawurlencode('modules/' . $file) . $tParam)
        : '';
    $inAppUrl = $inAppOk ? ($base . $inApp) : '';
    ?>
  <div class="col-md-6 col-xl-4">
    <div class="rmi-card p-3 h-100 d-flex flex-column">
      <div class="fw-semibold mb-1"><?= $icon ?> <?= rmi_h($title) ?></div>
      <?php if ($depts !== ''): ?>
        <div class="small text-muted mb-2">Dept: <?= rmi_h($depts) ?></div>
      <?php endif; ?>
      <?php if ($summary !== ''): ?>
        <div class="small flex-grow-1 mb-3"><?= rmi_h($summary) ?></div>
      <?php endif; ?>
      <div class="d-flex flex-wrap gap-2 mt-auto">
        <?php if ($inAppUrl !== ''): ?>
          <a class="btn btn-sm btn-primary" href="<?= rmi_h($inAppUrl) ?>">Panduan (dalam ERP)</a>
        <?php endif; ?>
        <?php if ($viewUrl !== ''): ?>
          <a class="btn btn-sm <?= $inAppUrl !== '' ? 'btn-outline-light' : 'btn-primary' ?>" href="<?= rmi_h($viewUrl) ?>"<?= $inAppUrl !== '' ? ' target="_blank" rel="noopener"' : '' ?>>Ringkasan Markdown</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="rmi-card p-3 mt-3">
  <div class="fw-semibold mb-2">Pintasan governance &amp; operasi</div>
  <div class="row g-2 small">
    <div class="col-md-4"><a href="<?= rmi_h($base . '/docs/monitoring_guide.php') ?>">Monitoring &amp; Control</a></div>
    <div class="col-md-4"><a href="<?= rmi_h($base . '/docs/docs_view.php?f=' . rawurlencode('SESSION_POLICY.md') . $tParam) ?>" target="_blank" rel="noopener">Kebijakan sesi (SESSION_POLICY)</a></div>
    <div class="col-md-4"><a href="<?= rmi_h($base . '/master/audit_logs.php') ?>">Audit Log</a></div>
    <div class="col-md-4"><a href="<?= rmi_h($base . '/docs/docs_view.php?f=' . rawurlencode('BASELINES.md') . $tParam) ?>" target="_blank" rel="noopener">Baselines deploy</a></div>
    <div class="col-md-4"><a href="<?= rmi_h($base . '/docs/docs_view.php?f=' . rawurlencode('governance/INDEX.md') . $tParam) ?>" target="_blank" rel="noopener">Governance index</a></div>
    <div class="col-md-4"><a href="<?= rmi_h($base . '/docs/docs_view.php?f=' . rawurlencode('modules/README.md') . $tParam) ?>" target="_blank" rel="noopener">README penulis modul</a></div>
  </div>
</div>
<?php rmi_footer(); ?>
