<?php
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';

if (function_exists('require_login')) {
    require_login();
}
if (function_exists('require_any_permission')) {
    require_any_permission([
        'PQP.VIEW',
        'PQP.REGULATORY_VIEW',
        'HRL.REG_ALKES_VIEW',
        'MASTER.PRODUCT_VIEW',
        'WQS.VIEW',
        'DASHBOARD.OWNER_SUMMARY',
    ]);
}

// Dashboard read-only: sumber data tetap Master Products + Reg Alkes.
if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$pdo = $GLOBALS['pdo'] ?? null;
$BASE_PROJECT = $GLOBALS['BASE_PROJECT'] ?? '';

if (!function_exists('u')) {
    function u(string $path): string
    {
        $base = $GLOBALS['BASE_PROJECT'] ?? '';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

function reg_table_exists($pdo, string $table): bool
{
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function reg_column_exists($pdo, string $table, string $column): bool
{
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
        $st->execute([$table, $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function reg_first_column($pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (reg_column_exists($pdo, $table, $c)) return $c;
    }
    return null;
}

function reg_scalar($pdo, string $sql, array $params = []): ?int
{
    if (!$pdo) return null;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? 0 : (int)$v;
    } catch (Throwable $e) {
        return null;
    }
}

function reg_valid_registration_value($value): bool
{
    $normalized = strtoupper(trim((string)$value));
    return $normalized !== '' && !in_array(
        $normalized,
        ['', '-', '0', 'N/A', 'NA', 'NULL', 'NONE', 'BELUM ADA', 'TIDAK ADA'],
        true
    );
}

function reg_display_date($value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '0000-00-00') return 'Belum diisi';
    if ($raw < '2000-01-01' || $raw > '2100-12-31') return 'Tidak valid';
    $ts = strtotime($raw);
    return $ts ? date('d-m-Y', $ts) : 'Tidak valid';
}

function reg_num($value): string
{
    return $value === null ? '—' : number_format((int)$value, 0, ',', '.');
}

// -----------------------------------------------------------------------------
// 1) MASTER PRODUCTS: product-level regulatory master.
// -----------------------------------------------------------------------------
$kpi = [
    'total' => null,
    'has_reg' => null,
    'missing_reg' => null,
    'missing_expiry' => null,
    'invalid_expiry' => null,
    'exp_180' => null,
    'exp_90' => null,
    'expired' => null,
    'backlog' => null,
    'duplicate_sku' => null,
];

$dossier = [
    'source_ready' => false,
    'open' => null,
    'stage15_open' => null,
    'nie_complete' => null,
    'closed' => null,
];

$sourceWarnings = [];
$sourceInfo = [];
$priorityRows = [];
$nameColumn = 'products_name';
$regCols = [];
$expiryColumn = null;
$expirySourceReady = false;

if (!$pdo) {
    $sourceWarnings[] = 'Koneksi database tidak tersedia.';
} elseif (!reg_table_exists($pdo, 'master_products')) {
    $sourceWarnings[] = 'Tabel master_products tidak tersedia.';
} else {
    $nameColumn = reg_first_column($pdo, 'master_products', ['products_name', 'product_name', 'name']) ?? 'products_name';
    $statusColumn = reg_first_column($pdo, 'master_products', ['status', 'product_status']);
    $skuColumn = reg_first_column($pdo, 'master_products', ['sku', 'products_code', 'product_code']);

    $regCols = array_values(array_filter(
        ['akl_reg_no', 'no_akl', 'licence_number', 'license_number', 'nie_no', 'registration_no'],
        static fn(string $c): bool => reg_column_exists($pdo, 'master_products', $c)
    ));

    // PENTING: exp_date sengaja TIDAK dipakai. Itu legacy/proxy dan dapat tercampur
    // dengan expiry produk/batch. Regulatory hanya memakai kolom expiry izin khusus.
    $expiryColumn = reg_first_column($pdo, 'master_products', [
        'licence_expiry_date',
        'license_expiry_date',
        'registration_expiry_date',
        'nie_expiry_date',
        'nie_exp_date',
        'akl_expiry_date',
        'akd_expiry_date',
    ]);
    $expirySourceReady = $expiryColumn !== null;

    if (!$regCols) {
        $sourceWarnings[] = 'Kolom nomor registrasi produk belum ditemukan pada master_products.';
    }
    if (!$expirySourceReady) {
        $sourceWarnings[] = 'Kolom khusus masa berlaku izin edar belum tersedia. KPI expiry ditampilkan N/A; master_products.exp_date tidak dipakai sebagai pengganti agar expiry izin tidak tercampur dengan expiry produk/batch.';
    }

    $activeWhere = $statusColumn
        ? "LOWER(TRIM(COALESCE(`{$statusColumn}`,'')))='active'"
        : '1=1';
    if (!$statusColumn) {
        $sourceWarnings[] = 'Kolom status produk tidak ditemukan; seluruh master_products dianggap dalam cakupan.';
    }

    $kpi['total'] = reg_scalar($pdo, "SELECT COUNT(*) FROM master_products WHERE {$activeWhere}");

    $regExpr = 'NULL';
    if ($regCols) {
        $parts = [];
        foreach ($regCols as $c) {
            $parts[] = "CASE WHEN UPPER(TRIM(COALESCE(`{$c}`,''))) NOT IN ('','-','0','N/A','NA','NULL','NONE','BELUM ADA','TIDAK ADA') THEN TRIM(`{$c}`) ELSE NULL END";
        }
        $regExpr = 'COALESCE(' . implode(',', $parts) . ')';
        $kpi['has_reg'] = reg_scalar($pdo, "SELECT COUNT(*) FROM master_products WHERE {$activeWhere} AND {$regExpr} IS NOT NULL");
        $kpi['missing_reg'] = reg_scalar($pdo, "SELECT COUNT(*) FROM master_products WHERE {$activeWhere} AND {$regExpr} IS NULL");
    }

    if ($skuColumn) {
        $kpi['duplicate_sku'] = reg_scalar(
            $pdo,
            "SELECT COUNT(*) FROM (SELECT UPPER(TRIM(`{$skuColumn}`)) s FROM master_products WHERE {$activeWhere} AND TRIM(COALESCE(`{$skuColumn}`,''))<>'' GROUP BY UPPER(TRIM(`{$skuColumn}`)) HAVING COUNT(*)>1) x"
        );
    }

    $expiryIssueSql = [];
    $expirySelect = 'NULL AS regulatory_expiry';
    $missingExpiry = '0=1';
    $invalidExpiry = '0=1';
    $validExpiry = '0=1';
    $ec = null;

    if ($expirySourceReady) {
        $ec = "`{$expiryColumn}`";
        $dateText = "TRIM(CAST({$ec} AS CHAR))";
        $missingExpiry = "({$ec} IS NULL OR {$dateText}='' OR {$dateText}='0000-00-00')";
        $validExpiry = "({$ec} IS NOT NULL AND {$dateText}<>'' AND {$dateText}<>'0000-00-00' AND {$ec}>='2000-01-01' AND {$ec}<='2100-12-31')";
        $invalidExpiry = "({$ec} IS NOT NULL AND {$dateText}<>'' AND {$dateText}<>'0000-00-00' AND ({$ec}<'2000-01-01' OR {$ec}>'2100-12-31'))";
        $expirySelect = "{$ec} AS regulatory_expiry";

        $kpi['missing_expiry'] = reg_scalar($pdo, "SELECT COUNT(*) FROM master_products WHERE {$activeWhere} AND {$missingExpiry}");
        $kpi['invalid_expiry'] = reg_scalar($pdo, "SELECT COUNT(*) FROM master_products WHERE {$activeWhere} AND {$invalidExpiry}");
        $kpi['exp_180'] = reg_scalar($pdo, "SELECT COUNT(*) FROM master_products WHERE {$activeWhere} AND {$validExpiry} AND {$ec} BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 180 DAY)");
        $kpi['exp_90'] = reg_scalar($pdo, "SELECT COUNT(*) FROM master_products WHERE {$activeWhere} AND {$validExpiry} AND {$ec} BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)");
        $kpi['expired'] = reg_scalar($pdo, "SELECT COUNT(*) FROM master_products WHERE {$activeWhere} AND {$validExpiry} AND {$ec}<CURDATE()");

        $expiryIssueSql = [
            $missingExpiry,
            $invalidExpiry,
            "({$validExpiry} AND {$ec}<=DATE_ADD(CURDATE(),INTERVAL 90 DAY))",
        ];
    }

    $backlogConditions = $expiryIssueSql;
    if ($regCols) array_unshift($backlogConditions, "{$regExpr} IS NULL");
    if ($backlogConditions) {
        $kpi['backlog'] = reg_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT id) FROM master_products WHERE {$activeWhere} AND (" . implode(' OR ', $backlogConditions) . ')'
        );
    } else {
        $kpi['backlog'] = 0;
    }

    // Priority table. Satu produk dapat memiliki lebih dari satu masalah; issue flags
    // dikirim terpisah agar UI tidak menyembunyikan masalah kedua/ketiga.
    $selectRegColumns = $regCols
        ? implode(', ', array_map(static fn(string $c): string => "`{$c}`", $regCols))
        : "'' AS registration_no";
    $skuSelect = $skuColumn ? "`{$skuColumn}` AS sku" : "'' AS sku";

    $wherePriority = [];
    if ($regCols) $wherePriority[] = "{$regExpr} IS NULL";
    if ($expirySourceReady) {
        $wherePriority[] = $missingExpiry;
        $wherePriority[] = $invalidExpiry;
        $wherePriority[] = "({$validExpiry} AND {$ec}<=DATE_ADD(CURDATE(),INTERVAL 180 DAY))";
    }

    if ($wherePriority) {
        $flagMissingReg = $regCols ? "CASE WHEN {$regExpr} IS NULL THEN 1 ELSE 0 END" : '0';
        $flagMissingExp = $expirySourceReady ? "CASE WHEN {$missingExpiry} THEN 1 ELSE 0 END" : '0';
        $flagInvalidExp = $expirySourceReady ? "CASE WHEN {$invalidExpiry} THEN 1 ELSE 0 END" : '0';
        $flagExpired = $expirySourceReady ? "CASE WHEN {$validExpiry} AND {$ec}<CURDATE() THEN 1 ELSE 0 END" : '0';
        $flag90 = $expirySourceReady ? "CASE WHEN {$validExpiry} AND {$ec}>=CURDATE() AND {$ec}<=DATE_ADD(CURDATE(),INTERVAL 90 DAY) THEN 1 ELSE 0 END" : '0';
        $flag180 = $expirySourceReady ? "CASE WHEN {$validExpiry} AND {$ec}>DATE_ADD(CURDATE(),INTERVAL 90 DAY) AND {$ec}<=DATE_ADD(CURDATE(),INTERVAL 180 DAY) THEN 1 ELSE 0 END" : '0';
        $orderExpiry = $expirySourceReady ? "COALESCE({$ec},'9999-12-31')," : '';

        $sqlPriority = "SELECT id, {$skuSelect}, `{$nameColumn}` AS products_name, {$selectRegColumns}, {$expirySelect},
            {$flagMissingReg} AS issue_missing_reg,
            {$flagMissingExp} AS issue_missing_expiry,
            {$flagInvalidExp} AS issue_invalid_expiry,
            {$flagExpired} AS issue_expired,
            {$flag90} AS issue_expiry_90,
            {$flag180} AS issue_expiry_180
            FROM master_products
            WHERE {$activeWhere} AND (" . implode(' OR ', $wherePriority) . ")
            ORDER BY
              issue_missing_reg DESC,
              issue_missing_expiry DESC,
              issue_invalid_expiry DESC,
              issue_expired DESC,
              issue_expiry_90 DESC,
              {$orderExpiry}
              `{$nameColumn}` ASC
            LIMIT 100";
        try {
            $st = $pdo->query($sqlPriority);
            $priorityRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $sourceWarnings[] = 'Tabel prioritas gagal dibaca; periksa struktur master_products.';
        }
    }

    $sourceInfo[] = 'Nomor registrasi produk: ' . ($regCols ? 'master_products.' . implode(' / ', $regCols) : 'belum dipetakan');
    $sourceInfo[] = 'Masa berlaku izin: ' . ($expirySourceReady ? 'master_products.' . $expiryColumn : 'belum tersedia');
}

// -----------------------------------------------------------------------------
// 2) REG ALKES DOSSIER: workflow-level regulatory source.
// Dipisah dari KPI produk agar 1 case dossier tidak dianggap sama dengan 1 SKU.
// -----------------------------------------------------------------------------
if ($pdo && reg_table_exists($pdo, 'hrl_reg_alkes_cases')) {
    $dossier['source_ready'] = true;
    $statusCol = reg_first_column($pdo, 'hrl_reg_alkes_cases', ['status', 'case_status']);
    $stageCol = reg_first_column($pdo, 'hrl_reg_alkes_cases', ['stage_no', 'current_stage']);
    $nieNoCol = reg_first_column($pdo, 'hrl_reg_alkes_cases', ['nie_no', 'registration_no']);
    $nieTypeCol = reg_first_column($pdo, 'hrl_reg_alkes_cases', ['nie_type', 'registration_type']);
    $nieIssueCol = reg_first_column($pdo, 'hrl_reg_alkes_cases', ['nie_issue_date', 'issue_date']);

    $openWhere = $statusCol
        ? "UPPER(TRIM(COALESCE(`{$statusCol}`,'OPEN')))='OPEN'"
        : '1=1';
    $closedWhere = $statusCol
        ? "UPPER(TRIM(COALESCE(`{$statusCol}`,'')))='CLOSED'"
        : '0=1';

    $dossier['open'] = reg_scalar($pdo, "SELECT COUNT(*) FROM hrl_reg_alkes_cases WHERE {$openWhere}");
    $dossier['closed'] = reg_scalar($pdo, "SELECT COUNT(*) FROM hrl_reg_alkes_cases WHERE {$closedWhere}");
    if ($stageCol) {
        $dossier['stage15_open'] = reg_scalar($pdo, "SELECT COUNT(*) FROM hrl_reg_alkes_cases WHERE {$openWhere} AND `{$stageCol}`>=15");
    }
    if ($nieNoCol) {
        $checks = ["TRIM(COALESCE(`{$nieNoCol}`,''))<>''"];
        if ($nieTypeCol) $checks[] = "TRIM(COALESCE(`{$nieTypeCol}`,''))<>''";
        if ($nieIssueCol) $checks[] = "`{$nieIssueCol}` IS NOT NULL";
        $dossier['nie_complete'] = reg_scalar($pdo, 'SELECT COUNT(*) FROM hrl_reg_alkes_cases WHERE ' . implode(' AND ', $checks));
    }
    $sourceInfo[] = 'Dossier NIE/AKL/AKD: hrl_reg_alkes_cases (workflow/case, tidak dijumlahkan sebagai SKU).';
} else {
    $sourceWarnings[] = 'Tabel hrl_reg_alkes_cases tidak tersedia; ringkasan dossier Reg Alkes tidak dapat ditampilkan.';
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';

$extraHead = <<<'STYLE'
<style>
body{background:#0b1220;color:#e5e7eb}.card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);border-radius:16px}.muted{color:#a7b2c2;font-size:12px}table{color:#e5e7eb}
.reg-header{background:linear-gradient(135deg,rgba(127,29,29,.82),rgba(185,28,28,.62));border:1px solid rgba(239,68,68,.3);border-radius:18px;padding:20px 24px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}.reg-header h2{margin:0;font-size:20px;font-weight:800;color:#fff}.reg-header p{margin:4px 0 0;font-size:12px;color:rgba(255,255,255,.75)}
.reg-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin-bottom:16px}.reg-k{padding:14px;border-radius:14px;background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-top:3px solid var(--kc)}.reg-k-icon{font-size:20px;margin-bottom:6px}.reg-k-n{font-size:24px;font-weight:800;color:#fff}.reg-k-lbl{font-size:10px;color:#93a4b8;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}.reg-k-sub{font-size:10px;color:#7f91a8;margin-top:4px;line-height:1.35}
.reg-links{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:16px}.reg-link{padding:7px 13px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid rgba(255,255,255,.12);color:#e2e8f0;background:rgba(255,255,255,.06);display:inline-flex;align-items:center;gap:5px}.reg-link:hover{background:rgba(255,255,255,.14);color:#fff}.reg-link.primary{background:linear-gradient(135deg,#dc2626,#ef4444);border-color:transparent;color:#fff}
.issue-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap;margin:1px 3px 1px 0}.issue-missing{background:rgba(245,158,11,.18);color:#fbbf24}.issue-invalid{background:rgba(168,85,247,.18);color:#c084fc}.issue-expired{background:rgba(239,68,68,.18);color:#f87171}.issue-warning{background:rgba(249,115,22,.18);color:#fb923c}.issue-watch{background:rgba(59,130,246,.18);color:#60a5fa}.issue-info{background:rgba(34,211,238,.14);color:#67e8f9}
.source-ok{border-color:rgba(34,197,94,.25)!important}.source-warn{border-color:rgba(245,158,11,.35)!important}.mini-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.mini-box{padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(15,23,42,.5)}.mini-box b{display:block;font-size:22px;margin-top:3px}.table td,.table th{vertical-align:middle}
</style>
STYLE;

$actions = [
    ['label' => '📚 Panduan', 'url' => u('/dashboards/regulatory/panduan.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Master Products', 'url' => u('/master/master_products.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Reg Alkes', 'url' => u('/hrl_reg_alkes/reg_alkes.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Warehouse Dashboard', 'url' => u('/dashboards/warehouse/wqs_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Quality & Compliance', 'url' => u('/dashboards/quality/qc_complaint_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'HRL Tower', 'url' => u('/hrl/hrl_tower.php'), 'class' => 'btn btn-sm btn-outline-light'],
];

rmi_header('Regulatory & Compliance', [
    'active' => 'quality',
    'subtitle' => 'NIE/AKL/AKD product master + Reg Alkes dossier compliance overview.',
    'extra_head' => $extraHead,
    'actions' => $actions,
]);
?>
<div class="container py-4" style="max-width:1450px">

    <div class="reg-header">
        <div>
            <h2>🏥 Regulatory & Compliance Dashboard</h2>
            <p>Product-level compliance dari <code style="color:#fecaca">master_products</code> dan workflow dossier dari <code style="color:#fecaca">hrl_reg_alkes_cases</code>. Dashboard read-only.</p>
        </div>
        <a class="reg-link" href="<?= h(u('/dashboards/index.php')) ?>">🏠 Home</a>
    </div>

    <?php if ($sourceWarnings): ?>
        <div class="card source-warn mb-3"><div class="card-body py-3">
            <div class="fw-semibold mb-1">⚠ Data source check</div>
            <ul class="mb-0 muted">
                <?php foreach ($sourceWarnings as $warning): ?><li><?= h($warning) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">Regulatory Control — Product Master</div>
        <div class="mini-grid">
            <div class="mini-box"><span class="muted">Backlog perlu tindak lanjut</span><b><?= h(reg_num($kpi['backlog'])) ?></b><span class="muted">unik per product id</span></div>
            <div class="mini-box"><span class="muted">Izin expired</span><b class="text-danger"><?= h(reg_num($kpi['expired'])) ?></b><span class="muted"><?= $expirySourceReady ? h($expiryColumn) : 'N/A — expiry izin belum tersedia' ?></span></div>
            <div class="mini-box"><span class="muted">Expiry izin ≤90 hari</span><b class="text-warning"><?= h(reg_num($kpi['exp_90'])) ?></b><span class="muted"><?= $expirySourceReady ? 'tanggal izin khusus' : 'N/A — tidak memakai exp_date' ?></span></div>
            <div class="mini-box"><span class="muted">Duplicate SKU aktif</span><b><?= h(reg_num($kpi['duplicate_sku'])) ?></b><span class="muted">kelompok SKU yang duplikat</span></div>
        </div>
    </div></div>

    <div class="reg-kpi">
        <div class="reg-k" style="--kc:#22c55e"><div class="reg-k-icon">📦</div><div class="reg-k-n"><?= h(reg_num($kpi['total'])) ?></div><div class="reg-k-lbl">Produk Aktif</div><div class="reg-k-sub">master_products</div></div>
        <div class="reg-k" style="--kc:#3b82f6"><div class="reg-k-icon">✅</div><div class="reg-k-n"><?= h(reg_num($kpi['has_reg'])) ?></div><div class="reg-k-lbl">Ada No AKL/NIE/AKD</div><div class="reg-k-sub">minimal 1 nomor registrasi valid</div></div>
        <div class="reg-k" style="--kc:#f59e0b"><div class="reg-k-icon">⚠️</div><div class="reg-k-n"><?= h(reg_num($kpi['missing_reg'])) ?></div><div class="reg-k-lbl">Missing Registrasi</div><div class="reg-k-sub">produk aktif tanpa nomor registrasi</div></div>
        <div class="reg-k" style="--kc:#eab308"><div class="reg-k-icon">📅</div><div class="reg-k-n"><?= h(reg_num($kpi['missing_expiry'])) ?></div><div class="reg-k-lbl">Tanggal Izin Belum Diisi</div><div class="reg-k-sub"><?= $expirySourceReady ? h($expiryColumn) : 'N/A — kolom izin belum tersedia' ?></div></div>
        <div class="reg-k" style="--kc:#a855f7"><div class="reg-k-icon">🛠️</div><div class="reg-k-n"><?= h(reg_num($kpi['invalid_expiry'])) ?></div><div class="reg-k-lbl">Tanggal Izin Tidak Valid</div><div class="reg-k-sub">valid: 2000-01-01 s.d. 2100-12-31</div></div>
        <div class="reg-k" style="--kc:#f97316"><div class="reg-k-icon">⏰</div><div class="reg-k-n"><?= h(reg_num($kpi['exp_90'])) ?></div><div class="reg-k-lbl">Expiry Izin ≤90 Hari</div><div class="reg-k-sub">tidak memakai expiry batch/lot</div></div>
        <div class="reg-k" style="--kc:#ef4444"><div class="reg-k-icon">❌</div><div class="reg-k-n"><?= h(reg_num($kpi['expired'])) ?></div><div class="reg-k-lbl">Izin Expired</div><div class="reg-k-sub">tanggal izin khusus &lt; hari ini</div></div>
    </div>

    <div class="card mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">Reg Alkes Dossier — Workflow Status</div>
        <div class="mini-grid">
            <div class="mini-box"><span class="muted">Case OPEN</span><b><?= h(reg_num($dossier['open'])) ?></b><span class="muted">hrl_reg_alkes_cases</span></div>
            <div class="mini-box"><span class="muted">Stage 15 masih OPEN</span><b class="text-warning"><?= h(reg_num($dossier['stage15_open'])) ?></b><span class="muted">final stage, perlu closure review</span></div>
            <div class="mini-box"><span class="muted">NIE data lengkap</span><b><?= h(reg_num($dossier['nie_complete'])) ?></b><span class="muted">No + type + issue date jika tersedia</span></div>
            <div class="mini-box"><span class="muted">Case CLOSED</span><b><?= h(reg_num($dossier['closed'])) ?></b><span class="muted">workflow selesai</span></div>
        </div>
        <div class="muted mt-2">Catatan: angka dossier adalah jumlah case, bukan jumlah SKU. Karena struktur case saat ini tidak memiliki relasi product_id/SKU yang tervalidasi, dashboard tidak memaksakan join berdasarkan nama produk.</div>
    </div></div>

    <div class="reg-links">
        <a class="reg-link primary" href="<?= h(u('/hrl_reg_alkes/reg_alkes.php')) ?>">📋 Reg Alkes (Dossier)</a>
        <a class="reg-link primary" href="<?= h(u('/master/master_products.php')) ?>">📦 Master Products</a>
        <a class="reg-link" href="<?= h(u('/hrl/hrl_tower.php')) ?>">🗼 HRL Tower</a>
        <a class="reg-link" href="<?= h(u('/hrl_process/index.php')) ?>">📋 HRL Process</a>
        <a class="reg-link" href="<?= h(u('/dashboards/quality/qc_complaint_dashboard.php')) ?>">✅ Quality Dashboard</a>
        <a class="reg-link" href="<?= h(u('/stock/wqs_incoming.php')) ?>">📥 WQS Incoming</a>
        <a class="reg-link" href="<?= h(u('/dashboards/warehouse/wqs_dashboard.php')) ?>">📦 Warehouse</a>
        <a class="reg-link" href="<?= h(u('/kpi/kpi_center.php')) ?>">📊 KPI Center</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-9"><div class="card"><div class="card-body">
            <div class="fw-semibold mb-2">Prioritas Regulatory — Product Master</div>
            <?php if (!$priorityRows): ?>
                <div class="muted">Tidak ada baris prioritas yang dapat ditampilkan dari sumber yang tersedia.</div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-sm table-dark table-striped align-middle">
                    <thead><tr><th>SKU</th><th>Nama</th><th>No Registrasi</th><th>Masa Berlaku Izin</th><th>Masalah</th></tr></thead>
                    <tbody>
                    <?php foreach ($priorityRows as $row):
                        $registration = '';
                        foreach (array_merge($regCols, ['registration_no']) as $column) {
                            $candidate = $row[$column] ?? '';
                            if (reg_valid_registration_value($candidate)) { $registration = trim((string)$candidate); break; }
                        }
                        $issues = [];
                        if ((int)($row['issue_missing_reg'] ?? 0) === 1) $issues[] = ['Registrasi belum lengkap','issue-missing'];
                        if ((int)($row['issue_missing_expiry'] ?? 0) === 1) $issues[] = ['Tanggal izin belum diisi','issue-missing'];
                        if ((int)($row['issue_invalid_expiry'] ?? 0) === 1) $issues[] = ['Tanggal izin tidak valid','issue-invalid'];
                        if ((int)($row['issue_expired'] ?? 0) === 1) $issues[] = ['Izin expired','issue-expired'];
                        if ((int)($row['issue_expiry_90'] ?? 0) === 1) $issues[] = ['Expiry ≤90 hari','issue-warning'];
                        if ((int)($row['issue_expiry_180'] ?? 0) === 1) $issues[] = ['Expiry 91–180 hari','issue-watch'];
                    ?>
                        <tr>
                            <td><?= h(function_exists('rmi_sku') ? rmi_sku($row['sku'] ?? '') : ($row['sku'] ?? '')) ?></td>
                            <td><?= h(function_exists('rmi_product_name') ? rmi_product_name($row['products_name'] ?? '') : ($row['products_name'] ?? '')) ?></td>
                            <td><?= h($registration !== '' ? $registration : '-') ?></td>
                            <td><?= $expirySourceReady ? h(reg_display_date($row['regulatory_expiry'] ?? null)) : '<span class="muted">N/A</span>' ?></td>
                            <td><?php foreach ($issues as [$label,$cls]): ?><span class="issue-badge <?= h($cls) ?>"><?= h($label) ?></span><?php endforeach; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div></div></div>

        <div class="col-lg-3"><div class="card"><div class="card-body">
            <div class="fw-semibold mb-2">Sumber & Definisi</div>
            <div class="muted">
                <?php foreach ($sourceInfo as $info): ?><div class="mb-2">• <?= h($info) ?></div><?php endforeach; ?>
                <div class="mb-2">• <strong>master_products.exp_date tidak dipakai</strong> untuk expiry izin.</div>
                <div class="mb-2">• Expiry batch/lot tetap berasal dari WQS Incoming/Allocation.</div>
                <div>• Backlog product = union produk yang registrasinya belum lengkap dan/atau memiliki masalah tanggal izin yang valid untuk dihitung.</div>
            </div>
        </div></div></div>
    </div>

    <div class="card mt-3"><div class="card-body">
        <div class="fw-semibold mb-1">Alur data final</div>
        <div class="muted"><strong>Master Products</strong> → validasi nomor registrasi + tanggal masa berlaku izin khusus → Product Compliance. &nbsp;|&nbsp; <strong>Reg Alkes</strong> → proses dossier → NIE Type/No/Tanggal Terbit → Stage 15 → Close Case. Dashboard hanya membaca kedua sumber dan tidak mengubah stok, lot, serial, incoming, allocation, picking, atau transaksi WQS.</div>
    </div></div>

    <?php
    $auditModules = ['PQP', 'PRODUCTS', 'LICENSE', 'MASTER_PRODUCTS', 'REGULATORY', 'REG_ALKES'];
    $auditLimit = 10;
    require __DIR__ . '/../_audit_log_widget.php';
    ?>
</div>
<?php rmi_footer(); ?>
