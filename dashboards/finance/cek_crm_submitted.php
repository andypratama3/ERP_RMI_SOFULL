<?php
declare(strict_types=1);
/**
 * Cek DO yang sudah CRM Submitted - untuk debug rekap kosong.
 * Akses: dashboards/finance/cek_crm_submitted.php
 */
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';

require_login();
if (function_exists('require_admin_critical')) {
    require_admin_critical();
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Database connection required.');
}

$statuses = [
    'crm_to_wqs', 'sent_wqs', 'wqs_processing', 'wqs_done', 'ready_scm', 'scm_done',
    'on_delivery', 'delivered', 'act_done', 'wait_payment', 'paid', 'fin_done', 'paid_done', 'closed',
];
$placeholders = implode(',', array_fill(0, count($statuses), '?'));

$total = 0;
$rows = [];
$allStatuses = [];
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ($placeholders)");
    $st->execute(array_map('strtolower', $statuses));
    $total = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT id, do_code, do_date, status, office_code, customers_code, 
        COALESCE(NULLIF(grand_total,0), total_amount, 0) AS amount 
        FROM sales_do 
        WHERE LOWER(COALESCE(status,'')) IN ($placeholders) 
        ORDER BY do_date DESC, id DESC 
        LIMIT 200");
    $st->execute(array_map('strtolower', $statuses));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $st2 = $pdo->query("SELECT status, COUNT(*) cnt FROM sales_do GROUP BY status ORDER BY cnt DESC");
    while ($r = $st2->fetch(PDO::FETCH_ASSOC)) {
        $allStatuses[] = $r;
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function f_money($v): string { return number_format((float)$v, 0, ',', '.'); }

$bp = $GLOBALS['BASE_PROJECT'] ?? '';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
rmi_header('Cek DO CRM Submitted', 'dashboard', [
    'subtitle' => 'Debug: DO dengan status setelah CRM Submit',
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => $bp . '/dashboards/index.php'],
        ['label' => 'Finance', 'url' => $bp . '/dashboards/finance/ar_ap_cash_dashboard.php'],
        ['label' => 'Cek CRM Submitted'],
    ],
]);
?>
<div class="container-fluid">
<div class="excel-surface p-4">
  <h5 class="text-cyan mb-3">Cek DO CRM Submitted</h5>
  <?php if (isset($err)): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
  <?php else: ?>
    <div class="excel-card mb-3">
      <div class="excel-title">Total DO (status setelah CRM Submit)</div>
      <p class="px-3 py-2 mb-0"><strong><?= $total ?></strong> DO</p>
      <p class="small text-muted px-3">Status: crm_to_wqs, sent_wqs, wqs_done, delivered, wait_payment, paid, fin_done, dll.</p>
    </div>

    <div class="excel-card mb-3">
      <div class="excel-title">Status di sales_do (semua)</div>
      <table class="excel-table">
        <thead><tr><th>Status</th><th>Jumlah</th></tr></thead>
        <tbody>
          <?php foreach ($allStatuses as $s): ?>
            <tr><td><?= h($s['status'] ?: '(kosong)') ?></td><td class="num"><?= (int)$s['cnt'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="excel-card mb-3">
      <div class="excel-title">Daftar DO CRM Submitted (max 200)</div>
      <div class="table-responsive">
        <table class="excel-table">
          <thead>
            <tr>
              <th>ID</th><th>DO Code</th><th>Tanggal</th><th>Status</th><th>Office</th><th>Customer</th><th>Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><a href="<?= h($bp) ?>/sales/sales_do_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['do_code'] ?? '-') ?></a></td>
                <td><?= h($r['do_date'] ?? '-') ?></td>
                <td><?= h($r['status'] ?? '-') ?></td>
                <td><?= h($r['office_code'] ?? '-') ?></td>
                <td><?= h($r['customers_code'] ?? '-') ?></td>
                <td class="num"><?= f_money($r['amount'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted">Tidak ada DO dengan status setelah CRM Submit</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($total > 200): ?>
        <p class="small text-muted px-3">Menampilkan 200 dari <?= $total ?> DO</p>
      <?php endif; ?>
    </div>

    <a href="<?= h($bp) ?>/dashboards/finance/sales_do_rekap.php" class="btn btn-outline-cyan btn-sm">→ Ke Rekap Sales DO</a>
  <?php endif; ?>
</div>
</div>
<?php rmi_footer(); ?>
