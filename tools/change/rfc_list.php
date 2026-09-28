<?php
declare(strict_types=1);
require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
opsgov_require_admin();

$dir = opsgov_root() . '/docs/rfc';
@mkdir($dir, 0775, true);
$rows = [];
foreach (glob($dir . '/*.json') ?: [] as $j) {
    $d = opsgov_read_json($j);
    if ($d) $rows[] = $d;
}
usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? '')));

$baseProject = rmi_layout_base_project();
rmi_header('RFC List', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'RFC List']]);
?>
<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">RFC Registry</div>
    <a class="btn btn-sm btn-rmi" href="rfc_new.php">+ New RFC</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-dark-custom">
      <thead><tr><th>ID</th><th>Title</th><th>Owner</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center muted">Belum ada RFC.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><code><?= opsgov_h((string)($r['id'] ?? '-')) ?></code></td>
          <td><?= opsgov_h((string)($r['title'] ?? '-')) ?></td>
          <td><?= opsgov_h((string)($r['owner'] ?? '-')) ?></td>
          <td><?= tools_badge((string)($r['status'] ?? 'DRAFT')) ?></td>
          <td><a class="btn btn-sm btn-outline-light" href="rfc_view.php?id=<?= urlencode((string)($r['id'] ?? '')) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php rmi_footer(); ?>
