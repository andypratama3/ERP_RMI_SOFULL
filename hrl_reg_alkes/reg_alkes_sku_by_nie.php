<?php
// hrl_reg_alkes/reg_alkes_sku_by_nie.php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['HRL.REG_ALKES_VIEW', 'HRL.VIEW', 'PQP.VIEW']);
} elseif (file_exists(__DIR__ . '/../_shared/enterprise_guard.php')) {
    require_once __DIR__ . '/../_shared/enterprise_guard.php';
    if (function_exists('eg_can_access_reg_alkes') && !eg_can_access_reg_alkes()) {
        http_response_code(403);
        echo "<h3>Akses ditolak</h3><p>Module REG Alkes hanya untuk dept PQP/HRL/ITC atau SYS.</p>";
        exit;
    }
}
$pdo = db_pdo();
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}


$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  rmi_header('SKU by NIE', [
  'active' => 'hrl_reg_alkes',
  'breadcrumbs' => [
    ['label' => 'HRL Reg Alkes', 'url' => $baseProject . '/hrl_reg_alkes/index.php'],
    'SKU by NIE',
  ],
  'extra_head' => '<style>body{font-family:Arial,Helvetica,sans-serif;margin:24px;}</style>',
]);
?>

    <h3>Missing parameter</h3>
    <p>Parameter <code>id</code> wajib ada. Silakan buka dari halaman registrasi.</p>
    <p>
      <a href="reg_alkes.php">Kembali ke Registrasi</a> |
      <a href="reg_alkes_control_tower.php">Control Tower</a> |
      <a href="../master/logout.php">Logout</a>
    </p>
  <?php
  rmi_footer();
  exit;
}

$case = null;
$stmt = $pdo->prepare("SELECT * FROM hrl_reg_alkes_cases WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$case = $stmt->fetch();
if (!$case) { http_response_code(404); exit('Case not found'); }

$nie = (string)($case['nie_no'] ?? '');
if ($nie === '') { http_response_code(400); exit('NIE kosong. Isi NIE dulu di case.'); }

$rows = [];
$stmt = $pdo->prepare("SELECT sku, products_name, general_name, status, created_at, updated_at
                       FROM master_products
                       WHERE manufacture_id=? AND (akl_reg_no=? OR no_akl=?)
                       ORDER BY sku ASC
                       LIMIT 5000");
$stmt->execute([(int)$case['manufacture_id'], $nie, $nie]);
$rows = $stmt->fetchAll() ?: [];
rmi_header('SKU by NIE', [
  'active' => 'hrl_reg_alkes',
  'subtitle' => 'Mapping SKU berdasarkan NIE terdaftar',
  'breadcrumbs' => [
    ['label' => 'HRL Reg Alkes', 'url' => $baseProject . '/hrl_reg_alkes/index.php'],
    ['label' => 'Case', 'url' => $baseProject . '/hrl_reg_alkes/reg_alkes_case.php?id=' . urlencode((string)$id)],
    'SKU by NIE',
  ],
  'extra_head' => '<style>.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }</style>',
]);
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="text-muted small"><a href="<?= rmi_ui_h($baseProject) ?>/hrl_reg_alkes/reg_alkes_case.php?id=<?=h((string)$id)?>">← kembali ke case</a></div>
      <h3 class="mb-0">SKU List</h3>
      <div class="text-muted small">
        Case: <span class="mono"><?=h($case['case_code'])?></span> • NIE: <span class="mono"><?=h($nie)?></span>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary" href="<?= rmi_ui_h($baseProject) ?>/master/master_products.php">Buka Master Products</a>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>SKU</th>
            <th>Nama</th>
            <th>General/Function</th>
            <th>Status</th>
            <th class="text-end">Created</th>
            <th class="text-end">Updated</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="mono fw-semibold"><?=h(strtoupper($r['sku'] ?? ''))?></td>
              <td><?=h(strtoupper($r['products_name'] ?? ''))?></td>
              <td class="text-muted"><?=h((string)$r['general_name'])?></td>
              <td><span class="badge text-bg-<?=((string)$r['status']==='active'?'success':'secondary')?>"><?=h(strtoupper($r['status'] ?? ''))?></span></td>
              <td class="text-end text-muted small"><?=h((string)$r['created_at'])?></td>
              <td class="text-end text-muted small"><?=h((string)$r['updated_at'])?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada SKU untuk NIE ini.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="text-muted small mt-3">Jika butuh edit detail SKU, buka Master Products lalu cari SKU.</div>
</div>
<?php rmi_footer(); ?>
