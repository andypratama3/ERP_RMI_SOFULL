<?php
declare(strict_types=1);
/**
 * Rekap Sales DO - validasi sumber data dashboard (Penjualan, Piutang Baru, Piutang Lama)
 * Flow: sales_do (source) → sales_do_rekap (bridge) → dashboards/finance/dashboard_detail (display)
 * By day & real-time: pakai DashboardDetailService::getSalesDoRowsForRekap (SAME logic as salesAggregates)
 */
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../app/Dashboard/DashboardDetailService.php';
use App\Dashboard\DashboardDetailService;

require_login();

/*
 * RBAC Rekap Sales DO
 * - SYS/Admin: pertahankan akses existing.
 * - User yang punya permission Sales Dashboard / Sales View: boleh membaca rekap Sales DO.
 * - Tidak memberikan DASHBOARD.FINANCE_VIEW dan tidak membuka modul Finance lain.
 *
 * Catatan: route registry untuk file ini juga harus dipetakan ke permission Sales
 * (DASHBOARD.SALES_VIEW / SALES.DASHBOARD_VIEW / SALES.VIEW), bukan hanya
 * DASHBOARD.FINANCE_VIEW, karena registry dieksekusi sebelum gate halaman ini.
 */
$canSalesDoRekap = false;

/*
 * Gunakan helper RBAC resmi dari master/auth.php.
 * Urutan:
 * 1) SYS tier tetap mengikuti hak existing.
 * 2) Permission Sales dari RBAC Center menjadi sumber utama.
 * 3) Compatibility khusus MGR CRM hanya untuk READ halaman Rekap Sales DO.
 *
 * Tidak memberikan DASHBOARD.FINANCE_VIEW, tidak membuka route Finance lain,
 * dan tidak mengubah permission write/approve/delete.
 */
if (function_exists('auth_is_sys_tier') && auth_is_sys_tier()) {
    $canSalesDoRekap = true;
}

if (!$canSalesDoRekap && function_exists('can_any')) {
    try {
        $canSalesDoRekap = can_any([
            'DASHBOARD.SALES_VIEW',
            'SALES.DASHBOARD_VIEW',
            'SALES.VIEW',
        ]);
    } catch (Throwable $e) {
        $canSalesDoRekap = false;
    }
}

/*
 * Compatibility sempit untuk Manager CRM.
 * Pakai helper auth resmi agar membaca session legacy/new secara konsisten.
 * Scope hanya halaman ini dan hanya setelah require_login()/route registry lolos.
 */
if (!$canSalesDoRekap) {
    $dept  = function_exists('auth_dept')
        ? auth_up(auth_dept())
        : strtoupper(trim((string)($_SESSION['department'] ?? $_SESSION['dept'] ?? '')));

    $level = function_exists('auth_level')
        ? auth_up(auth_level())
        : strtoupper(trim((string)($_SESSION['level'] ?? '')));

    $role  = function_exists('auth_role')
        ? auth_up(auth_role())
        : strtoupper(trim((string)($_SESSION['role'] ?? '')));

    if ($dept === 'CRM'
        && (in_array($level, ['MANAGER', 'MGR'], true)
            || in_array($role, ['MANAGER', 'MGR'], true))) {
        $canSalesDoRekap = true;
    }
}

if (!$canSalesDoRekap) {
    $route = function_exists('auth_rbac_route')
        ? auth_rbac_route()
        : (string)($_SERVER['REQUEST_URI'] ?? '');

    if (function_exists('auth_rbac_forbidden_exit')) {
        auth_rbac_forbidden_exit(
            'SALES_DO_REKAP_VIEW_BLOCK',
            $route,
            'need Sales view permission or CRM MANAGER/MGR'
        );
    }

    http_response_code(403);
    exit('Forbidden');
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
$segment = strtoupper(trim((string)($_GET['segment'] ?? '')));
$allPeriod = isset($_GET['all_period']) && $_GET['all_period'] === '1';
$revenueOnly = isset($_GET['revenue_only']) && $_GET['revenue_only'] === '1';
$monthNo = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$yearNo = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$asOf = trim((string)($_GET['as_of'] ?? date('Y-m-d')));
$type = strtolower(trim((string)($_GET['type'] ?? 'sales')));

/*
 * FIX V4 — Penjualan MTD harus selalu tunduk pada cutoff As of Date.
 * Link lama/dashboard dapat membawa all_period=1 dan, karena month/year disabled,
 * browser tidak mengirim month/year. Untuk type=sales, jangan biarkan flag itu
 * mengubah MTD menjadi histori. Ambil bulan/tahun langsung dari as_of.
 */
if ($type === 'sales' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $allPeriod = false;
    $monthNo = (int)substr($asOf, 5, 2);
    $yearNo = (int)substr($asOf, 0, 4);
}

if ($monthNo < 1 || $monthNo > 12) $monthNo = (int)date('m');
if ($yearNo < 2000 || $yearNo > 2100) $yearNo = (int)date('Y');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) $asOf = date('Y-m-d');
$monthStart = sprintf('%04d-%02d-01', $yearNo, $monthNo);
$monthEnd = date('Y-m-t', strtotime($monthStart));
if ($allPeriod) {
    // Semua periode = tarik histori dari awal, tetapi tetap hormati cutoff As of Date dari user.
    // Jangan reset $asOf ke hari ini karena membuat filter tanggal selalu kembali ke tanggal sekarang.
    $monthStart = '2020-01-01';
} else {
    if ($asOf < $monthStart) $asOf = $monthStart;
    if ($asOf > $monthEnd) $asOf = $monthEnd;
}

$rows = [];
$sumNet = 0.0;
$sumOutstanding = 0.0;
$byOffice = [];
$bySegment = [];
$byDate = [];

$officeList = [];
try {
    $st = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active = 1 ORDER BY office_name");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $oc = strtoupper(trim((string)($r['office_code'] ?? '')));
        if ($oc !== '') {
            $officeList[$oc] = (string)($r['office_name'] ?? $oc);
        }
    }
} catch (Throwable $e) {}

$runQuery = true; // selalu jalankan query (pakai default month/year bila tanpa params)
if ($runQuery) {
    $service = new DashboardDetailService($pdo);
    $rowsSales = $service->getSalesDoRowsForRekap($monthStart, $asOf, $office, $segment, 'sales', $revenueOnly);
    $rowsPiutangBaru = $service->getSalesDoRowsForRekap($monthStart, $asOf, $office, $segment, 'piutang_baru', $revenueOnly);
    $rowsPiutangLama = $service->getSalesDoRowsForRekap($monthStart, $asOf, $office, $segment, 'piutang_lama', $revenueOnly);

    $rows = $rowsSales; // untuk tab detail default
    foreach ($rowsSales as $r) {
        $sumNet += (float)($r['net_amount'] ?? 0);
        $oc = strtoupper(trim((string)($r['office_code'] ?? 'SYS')));
        $seg = strtoupper(trim((string)($r['segment'] ?? 'NON_HERMINA')));
        $dt = (string)($r['do_date'] ?? '');
        if (!isset($byOffice[$oc])) $byOffice[$oc] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        if (!isset($bySegment[$seg])) $bySegment[$seg] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        if ($dt !== '' && !isset($byDate[$dt])) $byDate[$dt] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        $byOffice[$oc]['penjualan'] += (float)($r['net_amount'] ?? 0);
        $bySegment[$seg]['penjualan'] += (float)($r['net_amount'] ?? 0);
        if ($dt !== '') $byDate[$dt]['penjualan'] += (float)($r['net_amount'] ?? 0);
    }
    foreach ($rowsPiutangBaru as $r) {
        $out = (float)($r['outstanding'] ?? 0);
        $sumOutstanding += $out;
        $oc = strtoupper(trim((string)($r['office_code'] ?? 'SYS')));
        $seg = strtoupper(trim((string)($r['segment'] ?? 'NON_HERMINA')));
        $dt = (string)($r['do_date'] ?? '');
        if (!isset($byOffice[$oc])) $byOffice[$oc] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        if (!isset($bySegment[$seg])) $bySegment[$seg] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        if ($dt !== '' && !isset($byDate[$dt])) $byDate[$dt] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        $byOffice[$oc]['piutang_baru'] += $out;
        $bySegment[$seg]['piutang_baru'] += $out;
        if ($dt !== '') $byDate[$dt]['piutang_baru'] += $out;
    }
    foreach ($rowsPiutangLama as $r) {
        $out = (float)($r['outstanding'] ?? 0);
        $sumOutstanding += $out;
        $oc = strtoupper(trim((string)($r['office_code'] ?? 'SYS')));
        $seg = strtoupper(trim((string)($r['segment'] ?? 'NON_HERMINA')));
        $dt = (string)($r['do_date'] ?? '');
        if (!isset($byOffice[$oc])) $byOffice[$oc] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        if (!isset($bySegment[$seg])) $bySegment[$seg] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        if ($dt !== '' && !isset($byDate[$dt])) $byDate[$dt] = ['penjualan' => 0, 'piutang_baru' => 0, 'piutang_lama' => 0];
        $byOffice[$oc]['piutang_lama'] += $out;
        $bySegment[$seg]['piutang_lama'] += $out;
        if ($dt !== '') $byDate[$dt]['piutang_lama'] += $out;
    }

    ksort($byDate);
    if ($type === 'piutang_baru') {
        $rows = $rowsPiutangBaru;
    } elseif ($type === 'piutang_lama') {
        $rows = $rowsPiutangLama;
    } else {
        $rows = $rowsSales;
    }
    $totalPiutangBaru = array_sum(array_map(function ($r) { return (float)($r['outstanding'] ?? 0); }, $rowsPiutangBaru));
    $totalPiutangLama = array_sum(array_map(function ($r) { return (float)($r['outstanding'] ?? 0); }, $rowsPiutangLama));
    $sumOutstanding = ($type === 'piutang_baru') ? $totalPiutangBaru : (($type === 'piutang_lama') ? $totalPiutangLama : 0);
}

$typeLabel = [
    'sales' => 'Penjualan MTD',
    'piutang_baru' => 'Piutang Baru (DO bulan berjalan)',
    'piutang_lama' => 'Piutang Lama (DO sebelum bulan berjalan)',
][$type] ?? 'Penjualan';
$fromTab = ($segment !== '' && $type === 'sales') ? 'target' : 'finance';
$bp = $GLOBALS['BASE_PROJECT'] ?? '';
$backUrl = rtrim($bp, '/') . '/dashboards/finance/dashboard_detail.php?month=' . $monthNo . '&year=' . $yearNo . '&as_of=' . rawurlencode($asOf) . '&tab=' . $fromTab;

require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/_rekap_styles.php';
$monthName = strtoupper(date('F', strtotime($monthStart)));
$subtitle = "Rekap • Office: " . ($office ?: '-') . ($segment ? " • Segment: {$segment}" : '') . ($allPeriod ? " • Semua periode" : " • {$monthName} {$yearNo} • As of " . date('d-M-y', strtotime($asOf))) . ($revenueOnly ? " • Revenue only (delivered/paid)" : " • DO setelah CRM Submit");

rmi_header('Rekap Sales DO', 'dashboard', [
    'subtitle' => $subtitle,
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => $bp . '/dashboards/index.php'],
        ['label' => 'Finance', 'url' => $bp . '/dashboards/finance/ar_ap_cash_dashboard.php'],
        ['label' => 'Dashboard Detail', 'url' => $bp . '/dashboards/finance/dashboard_detail.php?month=' . $monthNo . '&year=' . $yearNo . '&tab=' . $fromTab],
        ['label' => 'Rekap Sales DO'],
    ],
    'extra_head' => $rekapExtraHead,
    'body_class' => 'rekap-page',
]);
?>
<div class="container-fluid">
<div class="excel-surface p-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h5 class="text-cyan mb-1">Rekap Sales DO</h5>
      <p class="text-muted small mb-0"><?= h($subtitle) ?></p>
      <?php if ($revenueOnly): ?>
        <p class="small text-info mb-0 mt-1">Angka ini sesuai dengan Pencapaian di Dashboard (hanya DO status: delivered, wait_payment, paid, fin_done). <a href="?office=<?= h($office) ?>&segment=<?= h($segment) ?>&month=<?= $monthNo ?>&year=<?= $yearNo ?>&as_of=<?= h($asOf) ?>&type=<?= h($type) ?>">Lihat semua DO setelah CRM Submit</a></p>
      <?php endif; ?>
      <?php if (in_array($type, ['piutang_baru', 'piutang_lama'], true) && ($office || $segment)): ?>
        <?php $otherType = $type === 'piutang_baru' ? 'piutang_lama' : 'piutang_baru'; ?>
        <a href="?office=<?= h($office) ?>&month=<?= $monthNo ?>&year=<?= $yearNo ?>&as_of=<?= h($asOf) ?>&type=<?= $otherType ?>&segment=<?= h($segment) ?>" class="btn btn-outline-cyan btn-sm mt-1">Lihat <?= $otherType === 'piutang_baru' ? 'Piutang Baru' : 'Piutang Lama' ?></a>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= h($backUrl) ?>" class="btn btn-outline-cyan btn-sm">← Kembali ke Dashboard</a>
    </div>
  </div>

  <div class="excel-card mb-3">
    <div class="excel-title">Filter Periode</div>
    <p class="text-muted small px-3 pt-2 mb-2">Pilih Office/Segment (kosongkan = semua) dan periode. Menampilkan DO dengan status setelah CRM Submit (crm_to_wqs, sent_wqs, wqs_done, delivered, dll.).</p>
    <form method="get" class="p-3">
      <?php if ($revenueOnly): ?><input type="hidden" name="revenue_only" value="1"><?php endif; ?>
      <div class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label small">Office</label>
          <select name="office" class="form-select form-select-sm">
            <option value="" <?= $office === '' ? 'selected' : '' ?>>-- Semua Office --</option>
            <?php foreach ($officeList as $oc => $on): ?>
              <option value="<?= h($oc) ?>" <?= $office === $oc ? 'selected' : '' ?>><?= h($on) ?> (<?= h($oc) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small">Segment</label>
          <select name="segment" class="form-select form-select-sm">
            <option value="">-- Semua --</option>
            <option value="HERMINA" <?= $segment === 'HERMINA' ? 'selected' : '' ?>>Hermina</option>
            <option value="NON_HERMINA" <?= $segment === 'NON_HERMINA' ? 'selected' : '' ?>>Non Hermina</option>
            <option value="DEPO_YOGYA" <?= $segment === 'DEPO_YOGYA' ? 'selected' : '' ?>>Depo Yogya</option>
            <option value="DEPO_SAMARINDA" <?= $segment === 'DEPO_SAMARINDA' ? 'selected' : '' ?>>Depo Samarinda</option>
            <option value="ACCUNIT" <?= $segment === 'ACCUNIT' ? 'selected' : '' ?>>ACCUNIT</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small">Bulan</label>
          <select name="month" id="filter_month" class="form-select form-select-sm" <?= $allPeriod ? 'disabled' : '' ?>>
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= $m === $monthNo ? 'selected' : '' ?>><?= str_pad((string)$m, 2, '0', STR_PAD_LEFT) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small">Tahun</label>
          <input type="number" name="year" id="filter_year" class="form-control form-control-sm" value="<?= $yearNo ?>" min="2020" max="2030" <?= $allPeriod ? 'disabled' : '' ?>>
        </div>
        <div class="col-md-2">
          <label class="form-label small">As of Date</label>
          <input type="date" name="as_of" id="filter_as_of" class="form-control form-control-sm" value="<?= h($asOf) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small">Tipe</label>
          <select name="type" class="form-select form-select-sm">
            <option value="sales" <?= $type === 'sales' ? 'selected' : '' ?>>Penjualan MTD</option>
            <option value="piutang_baru" <?= $type === 'piutang_baru' ? 'selected' : '' ?>>Piutang Baru</option>
            <option value="piutang_lama" <?= $type === 'piutang_lama' ? 'selected' : '' ?>>Piutang Lama</option>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <div class="form-check">
            <input type="checkbox" name="all_period" value="1" id="all_period" class="form-check-input" <?= $allPeriod ? 'checked' : '' ?> <?= $type === 'sales' ? 'disabled' : '' ?>>
            <label class="form-check-label small" for="all_period"><?= $type === 'sales' ? 'Penjualan MTD mengikuti Bulan / As of Date' : 'Semua periode (konsisten dengan List DO)' ?></label>
          </div>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-sm btn-outline-cyan w-100">Tampilkan Rekap</button>
        </div>
      </div>
    </form>
    <script>
    (function () {
      var allPeriod = document.getElementById('all_period');
      var month = document.getElementById('filter_month');
      var year = document.getElementById('filter_year');
      var asOf = document.getElementById('filter_as_of');
      if (!allPeriod || !month || !year || !asOf) return;

      function syncPeriodControls() {
        var disabled = allPeriod.checked;
        month.disabled = disabled;
        year.disabled = disabled;
        // As of Date tetap dapat diubah. Saat user mengubah tanggal, mode Semua Periode otomatis dimatikan
        // agar filter kembali menjadi MTD bulan/tahun terpilih.
        asOf.disabled = false;
      }
      allPeriod.addEventListener('change', syncPeriodControls);

      [month, year, asOf].forEach(function (el) {
        el.addEventListener('change', function () {
          if (allPeriod.checked) {
            allPeriod.checked = false;
            syncPeriodControls();
          }
        });
      });
      syncPeriodControls();
    })();
    </script>
  </div>

  <?php if ($runQuery): ?>
  <!-- Detail DO (di atas rekap) -->
  <div class="excel-card mb-3">
    <div class="excel-title d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Detail DO (<?= $typeLabel ?>) — Total: <?= $type === 'sales' ? f_money($sumNet) : f_money($sumOutstanding) ?></span>
      <div class="btn-group btn-group-sm">
        <?php $qp = 'office=' . rawurlencode($office) . '&segment=' . rawurlencode($segment) . '&month=' . $monthNo . '&year=' . $yearNo . '&as_of=' . rawurlencode($asOf) . ($allPeriod ? '&all_period=1' : '') . ($revenueOnly ? '&revenue_only=1' : ''); ?>
        <a href="?<?= $qp ?>&type=sales" class="btn btn-outline-cyan btn-sm <?= $type === 'sales' ? 'active' : '' ?>">Penjualan</a>
        <a href="?<?= $qp ?>&type=piutang_baru" class="btn btn-outline-cyan btn-sm <?= $type === 'piutang_baru' ? 'active' : '' ?>">Piutang Baru</a>
        <a href="?<?= $qp ?>&type=piutang_lama" class="btn btn-outline-cyan btn-sm <?= $type === 'piutang_lama' ? 'active' : '' ?>">Piutang Lama</a>
      </div>
    </div>
    <div class="table-responsive">
      <table class="excel-table">
        <thead>
          <tr>
            <th>DO Code</th>
            <th>Tracking</th>
            <th>Tanggal</th>
            <th>Customer</th>
            <th>Office</th>
            <th class="num">Total</th>
            <th class="num">PPN</th>
            <th class="num">Grand Total</th>
            <?php if ($type !== 'sales'): ?><th class="num">Outstanding</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $doId = (int)($r['id'] ?? 0);
            $doCode = (string)($r['do_code'] ?? '');
            $doSourceUrl = $doId > 0 ? (rtrim($bp, '/') . '/sales/sales_do_view.php?id=' . $doId) : null;
            $totalAmt = (float)($r['total_amount'] ?? 0);
            $taxAmt = (float)($r['tax_amount'] ?? 0);
            $grandTotal = (float)($r['grand_total'] ?? 0);
          ?>
            <tr>
              <td><?= $doSourceUrl ? '<a class="drill-link" href="' . h($doSourceUrl) . '" title="Lihat DO di sumber">' . h($doCode ?: '-') . '</a>' : h($doCode ?: '-') ?></td>
              <td><?= h($r['tracking_code'] ?? '-') ?></td>
              <td><?= h($r['do_date'] ?? '-') ?></td>
              <td><?= h($r['customers_code'] ?? '-') ?></td>
              <td><?= h($r['office_code'] ?? '-') ?></td>
              <td class="num"><?= f_money($totalAmt) ?></td>
              <td class="num"><?= f_money($taxAmt) ?></td>
              <td class="num"><?= $doSourceUrl ? '<a class="drill-link" href="' . h($doSourceUrl) . '">' . f_money($grandTotal) . '</a>' : f_money($grandTotal) ?></td>
              <?php if ($type !== 'sales'): ?>
                <td class="num"><?= $doSourceUrl ? '<a class="drill-link" href="' . h($doSourceUrl) . '">' . f_money($r['outstanding'] ?? 0) . '</a>' : f_money($r['outstanding'] ?? 0) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <?php
            $colspan = 8 + ($type !== 'sales' ? 1 : 0);
            $debugCount = 0;
            try {
                $dcSt = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE do_date >= ? AND do_date <= ?");
                $dcSt->execute([$monthStart, $asOf]);
                $debugCount = (int)$dcSt->fetchColumn();
            } catch (Throwable $e) {}
            ?>
            <tr><td colspan="<?= $colspan ?>" class="text-center text-muted">
              Tidak ada data
              <div class="small mt-2 text-secondary">Filter: Office=<?= h($office ?: 'semua') ?>, Segment=<?= h($segment ?: 'semua') ?>, <?= h($monthStart) ?> s.d. <?= h($asOf) ?>, Tipe=<?= h($type) ?></div>
              <?php if ($debugCount > 0): ?>
                <div class="small mt-1 text-warning">Ada <?= $debugCount ?> DO di periode ini (semua status). Coba <a href="?month=<?= $monthNo ?>&year=<?= $yearNo ?>&as_of=<?= h($asOf) ?>&type=<?= h($type) ?>">tanpa filter Office/Segment</a> atau <a href="?all_period=1&type=<?= h($type) ?>">Semua periode (sama seperti List DO)</a>.</div>
              <?php else: ?>
                <div class="mt-2">
                  <a href="?month=<?= $monthNo ?>&year=<?= $yearNo ?>&as_of=<?= h($asOf) ?>&type=<?= h($type) ?>" class="btn btn-outline-secondary btn-sm">Tanpa filter Office/Segment</a>
                  <a href="?all_period=1&type=<?= h($type) ?>" class="btn btn-outline-cyan btn-sm ms-1">Semua periode (sama seperti List DO)</a>
                </div>
              <?php endif; ?>
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tabel 1: Per Office -->
  <div class="excel-card mb-3">
    <div class="excel-title">Rekap per Office (untuk Dashboard)</div>
    <div class="table-responsive">
      <table class="excel-table">
        <thead><tr><th>Office</th><th class="num">Penjualan MTD</th><th class="num">Piutang Baru</th><th class="num">Piutang Lama</th><th class="num">Total Piutang</th></tr></thead>
        <tbody>
          <?php
          $grandPenjualan = 0; $grandPb = 0; $grandPl = 0;
          foreach ($byOffice as $oc => $v):
            $grandPenjualan += $v['penjualan'];
            $grandPb += $v['piutang_baru'];
            $grandPl += $v['piutang_lama'];
            $totalPiutang = $v['piutang_baru'] + $v['piutang_lama'];
          ?>
            <tr>
              <td><?= h($officeList[$oc] ?? $oc) ?></td>
              <td class="num"><?= f_money($v['penjualan']) ?></td>
              <td class="num"><?= f_money($v['piutang_baru']) ?></td>
              <td class="num"><?= f_money($v['piutang_lama']) ?></td>
              <td class="num"><?= f_money($totalPiutang) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!empty($byOffice)): ?>
            <tr class="total-row">
              <td><strong>Total</strong></td>
              <td class="num"><strong><?= f_money($grandPenjualan) ?></strong></td>
              <td class="num"><strong><?= f_money($grandPb) ?></strong></td>
              <td class="num"><strong><?= f_money($grandPl) ?></strong></td>
              <td class="num"><strong><?= f_money($grandPb + $grandPl) ?></strong></td>
            </tr>
          <?php endif; ?>
          <?php if (empty($byOffice)): ?>
            <tr><td colspan="5" class="text-center text-muted">Tidak ada data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tabel 2: Per Segment -->
  <div class="excel-card mb-3">
    <div class="excel-title">Rekap per Segment (untuk Dashboard)</div>
    <div class="table-responsive">
      <table class="excel-table">
        <thead><tr><th>Segment</th><th class="num">Penjualan MTD</th><th class="num">Piutang Baru</th><th class="num">Piutang Lama</th><th class="num">Total Piutang</th></tr></thead>
        <tbody>
          <?php
          $segGrandPenjualan = 0; $segGrandPb = 0; $segGrandPl = 0;
          foreach ($bySegment as $seg => $v):
            $segGrandPenjualan += $v['penjualan'];
            $segGrandPb += $v['piutang_baru'];
            $segGrandPl += $v['piutang_lama'];
            $segTotalPiutang = $v['piutang_baru'] + $v['piutang_lama'];
          ?>
            <tr>
              <td><?= h($seg) ?></td>
              <td class="num"><?= f_money($v['penjualan']) ?></td>
              <td class="num"><?= f_money($v['piutang_baru']) ?></td>
              <td class="num"><?= f_money($v['piutang_lama']) ?></td>
              <td class="num"><?= f_money($segTotalPiutang) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!empty($bySegment)): ?>
            <tr class="total-row">
              <td><strong>Total</strong></td>
              <td class="num"><strong><?= f_money($segGrandPenjualan) ?></strong></td>
              <td class="num"><strong><?= f_money($segGrandPb) ?></strong></td>
              <td class="num"><strong><?= f_money($segGrandPl) ?></strong></td>
              <td class="num"><strong><?= f_money($segGrandPb + $segGrandPl) ?></strong></td>
            </tr>
          <?php endif; ?>
          <?php if (empty($bySegment)): ?>
            <tr><td colspan="5" class="text-center text-muted">Tidak ada data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tabel 3: Per Tanggal (daily) -->
  <div class="excel-card mb-3">
    <div class="excel-title">Rekap per Tanggal (Penjualan harian)</div>
    <div class="table-responsive">
      <table class="excel-table">
        <thead><tr><th>Tanggal</th><th class="num">Penjualan</th><th class="num">Piutang Baru</th><th class="num">Piutang Lama</th></tr></thead>
        <tbody>
          <?php foreach ($byDate as $dt => $v): ?>
            <tr>
              <td><?= h(date('d-M-Y', strtotime($dt))) ?></td>
              <td class="num"><?= f_money($v['penjualan']) ?></td>
              <td class="num"><?= f_money($v['piutang_baru']) ?></td>
              <td class="num"><?= f_money($v['piutang_lama']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($byDate)): ?>
            <tr><td colspan="4" class="text-center text-muted">Tidak ada data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>
<?php rmi_footer(); ?>
