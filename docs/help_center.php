<?php
/**
 * Help Center — indeks panduan per file, modul, dan link operasional.
 * Panduan per URL ERP: taruh file di docs/panduan/… lalu buka lewat panduan_view.php (?p= atau ?f=).
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/_token_helper.php';

require_login();
$baseProject = rmi_layout_base_project();

$userCtx = function_exists('auth_user') ? auth_user() : [];
$docsViewToken = '';
if (function_exists('docs_view_token_generate')) {
  $uid = $userCtx['user_id'] ?? $userCtx['username'] ?? '0';
  $docsViewToken = docs_view_token_generate($uid);
}
$tParam = $docsViewToken !== '' ? ('&t=' . rawurlencode($docsViewToken)) : '';

$panduanRoot = __DIR__ . '/panduan';
$panduanFiles = [];
if (is_dir($panduanRoot)) {
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($panduanRoot, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($it as $fileInfo) {
    if (!$fileInfo->isFile()) {
      continue;
    }
    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, ['md', 'html', 'htm', 'svg', 'pdf'], true)) {
      continue;
    }
    $full = $fileInfo->getPathname();
    $rel = ltrim(str_replace('\\', '/', substr($full, strlen($panduanRoot))), '/');
    if ($rel === '' || $rel === 'README.md') {
      continue;
    }
    $panduanFiles[] = $rel;
  }
  sort($panduanFiles, SORT_STRING);
}

function hc_h(string $v): string
{
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$title = 'Help Center';
$subtitle = 'Panduan per halaman • Modul • Ops';
rmi_header($title, ['active' => '', 'subtitle' => $subtitle, 'breadcrumbs' => [
  ['label' => 'MAIN', 'url' => $baseProject . '/dashboards/index.php'],
  'Help Center',
]]);
?>
<div class="row g-3">
  <div class="col-lg-5">
    <?php rmi_ui_card_open('Panduan per file (docs/panduan/)', ['class' => 'h-100']); ?>
      <p class="small rmi-muted mb-2">
        Satu file per route ERP: <code>docs/panduan/&lt;folder&gt;/&lt;script&gt;.md</code> sama dengan URL <code>/folder/script.php</code>.
        Buka lewat <a href="<?= hc_h($baseProject . '/docs/panduan_view.php?p=' . rawurlencode('/sales/sales_do.php') . $tParam) ?>" target="_blank" rel="noopener">contoh ?p=</a> atau tautan di panel <span class="rmi-kbd">F1</span>.
      </p>
      <input id="panduanSearch" class="form-control form-control-sm mb-2" placeholder="Cari nama file…" />
      <div id="panduanList" style="max-height: 60vh; overflow: auto;" class="small">
<?php if ($panduanFiles === []): ?>
        <div class="rmi-muted">Belum ada file di <code>docs/panduan/</code> (selain README).</div>
<?php else: ?>
        <ul class="list-unstyled mb-0" id="panduanUl">
<?php foreach ($panduanFiles as $rel): ?>
          <li class="mb-1 panduan-item" data-name="<?= hc_h(strtolower($rel)) ?>">
            <a href="<?= hc_h($baseProject . '/docs/panduan_view.php?f=' . rawurlencode($rel) . $tParam) ?>" target="_blank" rel="noopener"><?= hc_h($rel) ?></a>
          </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
      </div>
    <?php rmi_ui_card_close(); ?>
  </div>
  <div class="col-lg-7">
    <?php rmi_ui_card_open('Panduan di dalam ERP & ringkasan modul', ['class' => 'h-100']); ?>
      <p class="small rmi-muted mb-3">
        Tombol <span class="rmi-kbd">F1</span> = konteks halaman (langkah/tips dari <code>docs/help_sop_map.json</code>).
        Panduan modul dalam app (contoh Purchases) tetap di halaman <code>panduan.php</code> masing-masing.
      </p>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-sm btn-primary" href="<?= hc_h($baseProject . '/docs/modules_hub.php') ?>">Indeks modul (Markdown)</a>
        <a class="btn btn-sm btn-outline-light" href="<?= hc_h($baseProject . '/master/monitoring_center.php') ?>">Monitoring Center</a>
        <a class="btn btn-sm btn-outline-light" href="<?= hc_h($baseProject . '/docs/monitoring_guide.php') ?>">Panduan monitoring</a>
        <a class="btn btn-sm btn-outline-light" href="<?= hc_h($baseProject . '/docs/docs_view.php?f=' . rawurlencode('SESSION_POLICY.md') . $tParam) ?>" target="_blank" rel="noopener">Kebijakan sesi</a>
      </div>
      <div class="row g-2 small">
        <div class="col-md-6"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/docs/docs_view.php?f=' . rawurlencode('modules/crm_o2c.md') . $tParam) ?>" target="_blank" rel="noopener">Ringkasan — CRM &amp; Sales</a></div>
        <div class="col-md-6"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/docs/docs_view.php?f=' . rawurlencode('modules/wqs.md') . $tParam) ?>" target="_blank" rel="noopener">Ringkasan — WQS</a></div>
        <div class="col-md-6"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/purchases/panduan.php') ?>">PQP — Panduan dalam ERP</a></div>
        <div class="col-md-6"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/docs/docs_view.php?f=' . rawurlencode('modules/pqp_p2p.md') . $tParam) ?>" target="_blank" rel="noopener">PQP — ringkasan Markdown</a></div>
        <div class="col-md-6"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/docs/docs_view.php?f=' . rawurlencode('modules/sys_itc.md') . $tParam) ?>" target="_blank" rel="noopener">SYS / ITC</a></div>
        <div class="col-md-6"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/docs/docs_view.php?f=' . rawurlencode('modules/README.md') . $tParam) ?>" target="_blank" rel="noopener">Cara menambah panduan modul</a></div>
      </div>
    <?php rmi_ui_card_close(); ?>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12">
    <?php rmi_ui_card_open('OPS & GOVERNANCE', ['class' => 'h-100']); ?>
      <div class="row g-2 small">
        <div class="col-md-6 col-lg-4"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/docs/docs_view.php?f=' . rawurlencode('ops/dr/DR_Runbook.md') . $tParam) ?>" target="_blank" rel="noopener">DR Runbook</a></div>
        <div class="col-md-6 col-lg-4"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/tools/perf/perf_budget.php') ?>" target="_blank" rel="noopener">Perf Budget</a></div>
        <div class="col-md-6 col-lg-4"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/tools/compliance/evidence_index.php') ?>" target="_blank" rel="noopener">Evidence Pack</a></div>
        <div class="col-md-6 col-lg-4"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/tools/change/rfc_list.php') ?>" target="_blank" rel="noopener">RFC / Change</a></div>
        <div class="col-md-6 col-lg-4"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/tools/release/release_gate.php') ?>" target="_blank" rel="noopener">Release Gate</a></div>
        <div class="col-md-6 col-lg-4"><a class="rmi-help-link" href="<?= hc_h($baseProject . '/docs/docs_view.php?f=' . rawurlencode('ops/kt/Onboarding_Quickstart.md') . $tParam) ?>" target="_blank" rel="noopener">Onboarding</a></div>
      </div>
    <?php rmi_ui_card_close(); ?>
  </div>
</div>

<script>
(function(){
  var inp = document.getElementById('panduanSearch');
  if (!inp) return;
  inp.addEventListener('input', function(){
    var q = (inp.value || '').toLowerCase().trim();
    document.querySelectorAll('.panduan-item').forEach(function(li){
      var n = li.getAttribute('data-name') || '';
      li.style.display = (!q || n.indexOf(q) >= 0) ? '' : 'none';
    });
  });
})();
</script>
<?php
rmi_footer();
