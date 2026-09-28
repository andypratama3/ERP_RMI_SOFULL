<?php
declare(strict_types=1);
/**
 * Rekap GL (Operasional, Beban Gaji, Support, Fee, PPh) - validasi sumber data dashboard
 * Query sama dengan DashboardDetailService::expenseByOffice
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
$category = strtoupper(trim((string)($_GET['category'] ?? '')));
$monthNo = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$yearNo = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$asOf = trim((string)($_GET['as_of'] ?? date('Y-m-d')));

if ($monthNo < 1 || $monthNo > 12) $monthNo = (int)date('m');
if ($yearNo < 2000 || $yearNo > 2100) $yearNo = (int)date('Y');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) $asOf = date('Y-m-d');
$monthStart = sprintf('%04d-%02d-01', $yearNo, $monthNo);
$monthEnd = date('Y-m-t', strtotime($monthStart));
if ($asOf < $monthStart) $asOf = $monthStart;
if ($asOf > $monthEnd) $asOf = $monthEnd;

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
$sumAmount = 0.0;

$categoryLabels = [
    'OPERASIONAL' => 'Operasional',
    'BEBAN_GAJI' => 'Beban Gaji/Pendapatan',
    'SUPPORT' => 'Support',
    'FEE_MGMT' => 'Fee Management Hermina',
    'PPH' => 'PPh 25 / PPh Final',
];

if ($tableExists('kpi_gl_category_map') && $tableExists('gl_journal_headers') && $tableExists('gl_journal_lines')) {
    $officeExpr = "UPPER(
      CASE
        WHEN COALESCE(NULLIF(l.cost_center,''),'') <> '' THEN l.cost_center
        WHEN h.source_ref LIKE '%-BGR-%' THEN 'BGR'
        WHEN h.source_ref LIKE '%-BKS-%' THEN 'BKS'
        WHEN h.source_ref LIKE '%-SLO-%' THEN 'SLO'
        WHEN h.source_ref LIKE '%-BDG-%' THEN 'BDG'
        WHEN h.source_ref LIKE '%-SMG-%' THEN 'SMG'
        WHEN h.source_ref LIKE '%-JGY-%' THEN 'JGY'
        WHEN h.source_ref LIKE '%-KAL-%' THEN 'KAL'
        WHEN h.source_ref LIKE '%-TGR-%' THEN 'TGR'
        ELSE 'ALL'
      END
    )";

    $sql = "SELECT * FROM (
                SELECT h.id AS header_id, h.journal_no, h.journal_date, h.source_ref,
                       l.account_id, l.description, l.dr_amount, l.cr_amount,
                       (l.dr_amount - l.cr_amount) AS amount,
                       {$officeExpr} AS office_code,
                       UPPER(m.category) AS category
                FROM gl_journal_headers h
                JOIN gl_journal_lines l ON l.header_id = h.id
                JOIN kpi_gl_category_map m ON m.gl_account_id = l.account_id
                WHERE h.journal_date BETWEEN ? AND ?
                  AND h.status IN ('POSTED','DRAFT')
            ) sub WHERE 1=1";
    $params = [$monthStart, $asOf];

    if ($office !== '') {
        $sql .= " AND office_code = ?";
        $params[] = $office;
    }
    if ($category !== '') {
        $sql .= " AND category = ?";
        $params[] = $category;
    }

    $sql .= " ORDER BY journal_date DESC, header_id DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
        $sumAmount += (float)($r['amount'] ?? 0);
    }
}

$bp = $GLOBALS['BASE_PROJECT'] ?? '';
$backUrl = rtrim($bp, '/') . '/dashboards/finance/dashboard_detail.php?month=' . $monthNo . '&year=' . $yearNo . '&as_of=' . rawurlencode($asOf) . '&tab=finance';

require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/_rekap_styles.php';
$monthName = strtoupper(date('F', strtotime($monthStart)));
$catLabel = $category !== '' ? ($categoryLabels[$category] ?? $category) : 'Semua Kategori';
$subtitle = "Rekap GL • {$catLabel} • Office: " . ($office ?: '-') . " • {$monthName} {$yearNo} • As of " . date('d-M-y', strtotime($asOf));

rmi_header('Rekap GL (Biaya)', 'dashboard', [
    'subtitle' => $subtitle,
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => $bp . '/dashboards/index.php'],
        ['label' => 'Finance', 'url' => $bp . '/dashboards/finance/ar_ap_cash_dashboard.php'],
        ['label' => 'Dashboard Detail', 'url' => $bp . '/dashboards/finance/dashboard_detail.php?month=' . $monthNo . '&year=' . $yearNo . '&tab=finance'],
        ['label' => 'Rekap GL'],
    ],
    'extra_head' => $rekapExtraHead,
    'body_class' => 'rekap-page',
]);
?>
<div class="container-fluid">
<div class="excel-surface p-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h5 class="text-cyan mb-1">Rekap GL (Biaya)</h5>
      <p class="text-muted small mb-0"><?= h($subtitle) ?></p>
    </div>
    <a href="<?= h($backUrl) ?>" class="btn btn-outline-cyan btn-sm">← Kembali ke Dashboard</a>
  </div>

  <div class="excel-card mb-3">
    <div class="excel-title">Filter Periode</div>
    <p class="text-muted small px-3 pt-2 mb-2">Pilih Office dan Kategori. Pola sama dengan sales_do_rekap.</p>
    <form method="get" class="p-3">
      <div class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label small">Office</label>
          <select name="office" class="form-select form-select-sm">
            <option value="">-- Semua Office --</option>
            <?php foreach ($officeList as $oc => $on): ?>
              <option value="<?= h($oc) ?>" <?= $office === $oc ? 'selected' : '' ?>><?= h($on) ?> (<?= h($oc) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small">Kategori</label>
          <select name="category" class="form-select form-select-sm">
            <option value="">-- Semua --</option>
            <?php foreach ($categoryLabels as $k => $l): ?>
              <option value="<?= h($k) ?>" <?= $category === $k ? 'selected' : '' ?>><?= h($l) ?></option>
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

  <div class="excel-card mb-3">
    <div class="excel-title">Total: <?= f_money($sumAmount) ?></div>
    <div class="table-responsive">
      <table class="excel-table">
        <thead>
          <tr>
            <th>Journal No</th>
            <th>Tanggal</th>
            <th>Source Ref</th>
            <th>Office</th>
            <th>Category</th>
            <th>Description</th>
            <th>Dr</th>
            <th>Cr</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $jDate = (string)($r['journal_date'] ?? '');
            $jNo = (string)($r['journal_no'] ?? '');
            $srcRef = (string)($r['source_ref'] ?? '');
            $glFilter = $jDate ? rawurlencode($jDate) : '';
            $glSourceRef = $srcRef ? rawurlencode($srcRef) : ($jNo ? rawurlencode($jNo) : '');
            $glSourceUrl = $glFilter ? (rtrim($bp, '/') . '/purchases/fin_gl_auto.php?view=journal&date_from=' . $glFilter . '&date_to=' . $glFilter . ($glSourceRef ? '&source_ref=' . $glSourceRef : '')) : null;
          ?>
            <tr>
              <td><?= $glSourceUrl ? '<a class="drill-link" href="' . h($glSourceUrl) . '" title="Lihat journal di GL">' . h($jNo ?: '-') . '</a>' : h($jNo ?: '-') ?></td>
              <td><?= $glSourceUrl ? '<a class="drill-link" href="' . h($glSourceUrl) . '">' . h($jDate ?: '-') . '</a>' : h($jDate ?: '-') ?></td>
              <td><?= $glSourceUrl ? '<a class="drill-link" href="' . h($glSourceUrl) . '">' . h($srcRef ?: '-') . '</a>' : h($srcRef ?: '-') ?></td>
              <td><?= h($r['office_code'] ?? '-') ?></td>
              <td><?= h($r['category'] ?? '-') ?></td>
              <td><?= h(mb_substr((string)($r['description'] ?? ''), 0, 50)) ?><?= mb_strlen((string)($r['description'] ?? '')) > 50 ? '...' : '' ?></td>
              <td class="num"><?= $glSourceUrl ? '<a class="drill-link" href="' . h($glSourceUrl) . '">' . f_money($r['dr_amount'] ?? 0) . '</a>' : f_money($r['dr_amount'] ?? 0) ?></td>
              <td class="num"><?= $glSourceUrl ? '<a class="drill-link" href="' . h($glSourceUrl) . '">' . f_money($r['cr_amount'] ?? 0) . '</a>' : f_money($r['cr_amount'] ?? 0) ?></td>
              <td class="num"><?= $glSourceUrl ? '<a class="drill-link" href="' . h($glSourceUrl) . '">' . f_money($r['amount'] ?? 0) . '</a>' : f_money($r['amount'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="9" class="text-center text-muted">Tidak ada data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div>
<?php rmi_footer(); ?>
