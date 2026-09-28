<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

/**
 * purchases/bank_statement_import.php
 * Bank statement CSV import - preview only (Phase 3)
 * Does not persist; shows parsed rows for validation.
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
require_any_permission([
    'MASTER.COMPANY_BANK_CRUD',
    'PURCHASES.AP_PAYMENT_CRUD',
    'PURCHASES.VIEW',
]);
}

$pdo = db_pdo();
$preview = [];
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if ($fh) {
            $header = fgetcsv($fh);
            $preview[] = $header;
            $max = 50;
            while ($max-- > 0 && ($row = fgetcsv($fh)) !== false) {
                $preview[] = $row;
            }
            fclose($fh);
            $flash = ['type' => 'success', 'msg' => 'Preview only. Import not implemented.'];
        } else {
            $flash = ['type' => 'danger', 'msg' => 'Gagal membaca file.'];
        }
    } else {
        $flash = ['type' => 'warning', 'msg' => 'Pilih file CSV.'];
    }
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('Bank Statement Import (Preview)', ['active' => 'purchases', 'breadcrumbs' => [['label' => 'Purchases', 'url' => 'index.php'], ['label' => 'Bank Statement Import', 'url' => '']]]);
?>
<?php if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v??''), ENT_QUOTES, 'UTF-8'); } } ?>
<?php if ($flash['msg']): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="mb-3">
  <?= csrf_field() ?>
  <input type="file" name="csv_file" accept=".csv" class="form-control mb-2">
  <button type="submit" class="btn btn-primary">Preview</button>
</form>
<?php if (!empty($preview)): ?>
<div class="table-responsive">
  <table class="table table-sm table-dark">
    <thead><tr><?php foreach ($preview[0] as $h): ?><th><?= h($h) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php for ($i = 1; $i < count($preview); $i++): ?>
      <tr><?php foreach ($preview[$i] as $c): ?><td><?= h($c) ?></td><?php endforeach; ?></tr>
      <?php endfor; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php rmi_footer(); ?>
