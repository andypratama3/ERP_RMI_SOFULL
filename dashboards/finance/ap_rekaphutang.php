<?php
declare(strict_types=1);
/**
 * Rekap Hutang (AP Outstanding) - validasi sumber data dashboard
 * Query sama dengan DashboardDetailService::apOutstandingByOffice
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

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function f_money($v): string { return number_format((float)$v, 0, ',', '.'); }

$office = strtoupper(trim((string)($_GET['office'] ?? '')));
$asOf = trim((string)($_GET['as_of'] ?? date('Y-m-d')));
$monthNo = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$yearNo = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($monthNo < 1 || $monthNo > 12) $monthNo = (int)date('m');
if ($yearNo < 2000 || $yearNo > 2100) $yearNo = (int)date('Y');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) $asOf = date('Y-m-d');

$officeList = [];
try {
    $st = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active = 1 ORDER BY office_name");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $oc = strtoupper(trim((string)($r['office_code'] ?? '')));
        if ($oc !== '') $officeList[$oc] = (string)($r['office_name'] ?? $oc);
    }
} catch (Throwable $e) {}

$tableExists = static function (string $t) use ($pdo): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
};

$rows = [];
$sumOutstanding = 0.0;

if ($tableExists('purchases_invoice_ap') && $tableExists('purchases_payment_ap') && $office !== '') {
    $sql = "SELECT ap.id, ap.ap_code, ap.invoice_date, ap.invoice_number, ap.status, ap.total_amount,
                   COALESCE(paid.paid_amount, 0) AS paid_amount,
                   (ap.total_amount - COALESCE(paid.paid_amount, 0)) AS outstanding
            FROM purchases_invoice_ap ap
            LEFT JOIN (
              SELECT ap_id, SUM(amount) AS paid_amount
              FROM purchases_payment_ap
              WHERE deleted_at IS NULL AND pay_date <= ?
              GROUP BY ap_id
            ) paid ON paid.ap_id = ap.id
            WHERE UPPER(COALESCE(ap.office_code,'SYS')) = ?
              AND ap.deleted_at IS NULL
              AND ap.invoice_date <= ?
              AND ap.status IN ('UNPAID','PARTIAL')
            ORDER BY ap.invoice_date DESC, ap.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute([$asOf, $office, $asOf]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
        $sumOutstanding += (float)($r['outstanding'] ?? 0);
    }
}

$bp = $GLOBALS['BASE_PROJECT'] ?? '';
$backUrl = rtrim($bp, '/') . '/dashboards/finance/dashboard_detail.php?month=' . $monthNo . '&year=' . $yearNo . '&as_of=' . rawurlencode($asOf) . '&tab=finance';

require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/_rekap_styles.php';
$subtitle = "Rekap Hutang (AP Outstanding) • Office: " . ($office ?: '-') . " • As of " . date('d-M-y', strtotime($asOf));

rmi_header('Rekap Hutang (AP)', 'dashboard', [
    'subtitle' => $subtitle,
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => $bp . '/dashboards/index.php'],
        ['label' => 'Finance', 'url' => $bp . '/dashboards/finance/ar_ap_cash_dashboard.php'],
        ['label' => 'Dashboard Detail', 'url' => $bp . '/dashboards/finance/dashboard_detail.php?month=' . $monthNo . '&year=' . $yearNo . '&tab=finance'],
        ['label' => 'Rekap Hutang'],
    ],
    'extra_head' => $rekapExtraHead,
    'body_class' => 'rekap-page',
]);
?>
<div class="container-fluid">
<div class="excel-surface p-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h5 class="text-cyan mb-1">Rekap Hutang (AP)</h5>
      <p class="text-muted small mb-0"><?= h($subtitle) ?></p>
    </div>
    <a href="<?= h($backUrl) ?>" class="btn btn-outline-cyan btn-sm">← Kembali ke Dashboard</a>
  </div>

  <div class="excel-card mb-3">
    <div class="excel-title">Filter Periode</div>
    <p class="text-muted small px-3 pt-2 mb-2">Pilih Office dan periode. Pola sama dengan sales_do_rekap.</p>
    <form method="get" class="p-3">
      <div class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label small">Office</label>
          <select name="office" class="form-select form-select-sm" required>
            <option value="">-- Pilih Office --</option>
            <?php foreach ($officeList as $oc => $on): ?>
              <option value="<?= h($oc) ?>" <?= $office === $oc ? 'selected' : '' ?>><?= h($on) ?> (<?= h($oc) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small">Bulan</label>
          <select name="month" class="form-select form-select-sm">
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= $m === $monthNo ? 'selected' : '' ?>><?= str_pad((string)$m, 2, '0', STR_PAD_LEFT) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small">Tahun</label>
          <input type="number" name="year" class="form-control form-control-sm" value="<?= $yearNo ?>" min="2020" max="2030">
        </div>
        <div class="col-md-2">
          <label class="form-label small">As of Date</label>
          <input type="date" name="as_of" class="form-control form-control-sm" value="<?= h($asOf) ?>">
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-sm btn-outline-cyan w-100">Tampilkan Rekap</button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($office !== ''): ?>
  <div class="excel-card mb-3">
    <div class="excel-title">Total Outstanding: <?= f_money($sumOutstanding) ?></div>
    <div class="table-responsive">
      <table class="excel-table">
        <thead>
          <tr>
            <th>AP Code</th>
            <th>Invoice Date</th>
            <th>Invoice Number</th>
            <th>Status</th>
            <th>Total Amount</th>
            <th>Paid</th>
            <th>Outstanding</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $apId = (int)($r['id'] ?? 0);
            $apCode = (string)($r['ap_code'] ?? '');
            $apSourceUrl = $apId > 0 ? (rtrim($bp, '/') . '/purchases/purchases_invoice_ap_edit.php?id=' . $apId) : null;
          ?>
            <tr>
              <td><?= $apSourceUrl ? '<a class="drill-link" href="' . h($apSourceUrl) . '" title="Lihat invoice di sumber">' . h($apCode ?: '-') . '</a>' : h($apCode ?: '-') ?></td>
              <td><?= h($r['invoice_date'] ?? '-') ?></td>
              <td><?= $apSourceUrl ? '<a class="drill-link" href="' . h($apSourceUrl) . '">' . h($r['invoice_number'] ?? '-') . '</a>' : h($r['invoice_number'] ?? '-') ?></td>
              <td><?= h($r['status'] ?? '-') ?></td>
              <td class="num"><?= $apSourceUrl ? '<a class="drill-link" href="' . h($apSourceUrl) . '">' . f_money($r['total_amount'] ?? 0) . '</a>' : f_money($r['total_amount'] ?? 0) ?></td>
              <td class="num"><?= $apSourceUrl ? '<a class="drill-link" href="' . h($apSourceUrl) . '">' . f_money($r['paid_amount'] ?? 0) . '</a>' : f_money($r['paid_amount'] ?? 0) ?></td>
              <td class="num"><?= $apSourceUrl ? '<a class="drill-link" href="' . h($apSourceUrl) . '">' . f_money($r['outstanding'] ?? 0) . '</a>' : f_money($r['outstanding'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted">Tidak ada data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="excel-card mb-3">
    <div class="excel-title">Detail</div>
    <p class="text-muted text-center py-4">Pilih Office dan klik Tampilkan Rekap.</p>
  </div>
  <?php endif; ?>
</div>
</div>
<?php rmi_footer(); ?>
