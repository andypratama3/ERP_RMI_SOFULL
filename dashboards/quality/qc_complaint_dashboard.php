<?php

require_once __DIR__ . '/../../_shared/assets.php'; // RMI asset loader

// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 2) . '/master/auth.php';
require_login();
// Dept guard: Quality Dashboard — PQP + WQS + SCM + ACT + BRANCH + SYS
// -------------------------------------------------------------

require_once __DIR__ . '/../_dashboard_bootstrap.php';

// ================================================
// Quality & Compliance Dashboard (Existing Data)
// - Tidak membuat modul baru (complaint/CAPA belum ada di schema)
// - Fokus pada quality/compliance yang SUDAH ADA di repo:
//   * WQS Incoming (lot/serial/exp completeness)
//   * Stock per office (baseline lock + anomaly stock_qty)
//   * Master Products (data completeness dasar)
// ================================================

$pdo = $GLOBALS['pdo'] ?? null;

// ---- small helpers (local) ----
function q1(PDO $pdo, string $sql, array $params = []) {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchColumn();
}

function qall(PDO $pdo, string $sql, array $params = []) : array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function qc_col_exists(PDO $pdo, string $table, string $column) : bool {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn()) > 0;
  } catch (Throwable $e) {
    return false;
  }
}

// ---- feature flags: table existence ----
$has_incoming = ($pdo && safe_table_exists($pdo, 'wqs_incoming') && safe_table_exists($pdo, 'wqs_incoming_items'));
$has_stock    = ($pdo && safe_table_exists($pdo, 'wqs_stock'));
$has_stock_by_office = ($pdo && safe_table_exists($pdo, 'wqs_stock_by_office'));
$has_baseline = ($pdo && safe_table_exists($pdo, 'wqs_stock_baseline_lock'));
$has_products = ($pdo && safe_table_exists($pdo, 'master_products'));

// ---- Stats (default) ----
$stats = [
  'incoming_30' => null,
  'incoming_items_30' => null,
  'missing_exp_30' => null,
  'missing_lot_30' => null,
  'missing_serial_30' => null,
  'stock_total' => null,
  'stock_zero_or_less' => null,
  'stock_negative' => null,
  'stock_source_label' => null,
  'stock_office_issue' => null,
  'baseline_locked' => null,
  'baseline_locked_at' => null,
  'products_total' => null,
  'products_missing_barcode' => null,
  'products_missing_unit' => null,
  'products_issue_unique' => null,
  'barcode_coverage_pct' => null,
  'zero_stock' => null,
  'complaint_source_ready' => false,
  'capa_source_ready' => false,
];

$incoming_issues = [];

if ($pdo) {
  // Incoming (last 30 days)
  if ($has_incoming) {
    // incoming header
    $stats['incoming_30'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_incoming WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");

    // items + missing fields
    $stats['incoming_items_30'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_incoming_items it JOIN wqs_incoming i ON i.id = it.incoming_id WHERE i.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stats['missing_exp_30'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_incoming_items it JOIN wqs_incoming i ON i.id = it.incoming_id WHERE i.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (it.exp_date IS NULL OR CAST(it.exp_date AS CHAR) = '0000-00-00')");
    $stats['missing_lot_30'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_incoming_items it JOIN wqs_incoming i ON i.id = it.incoming_id WHERE i.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (it.lot_number IS NULL OR it.lot_number = '')");
    $stats['missing_serial_30'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_incoming_items it JOIN wqs_incoming i ON i.id = it.incoming_id WHERE i.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (it.serial_number IS NULL OR it.serial_number = '')");

    // Incoming with issues (last 30 days)
    $incoming_issues = qall($pdo, "
      SELECT
        i.id,
        i.incoming_code,
        i.received_date,
        i.po_code,
        i.office_code,
        COUNT(it.id) AS items_total,
        SUM(CASE WHEN it.exp_date IS NULL OR CAST(it.exp_date AS CHAR) = '0000-00-00' THEN 1 ELSE 0 END) AS miss_exp,
        SUM(CASE WHEN it.lot_number IS NULL OR it.lot_number = '' THEN 1 ELSE 0 END) AS miss_lot,
        SUM(CASE WHEN it.serial_number IS NULL OR it.serial_number = '' THEN 1 ELSE 0 END) AS miss_serial
      FROM wqs_incoming i
      LEFT JOIN wqs_incoming_items it ON it.incoming_id = i.id
      WHERE i.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      GROUP BY i.id
      HAVING miss_exp > 0 OR miss_lot > 0 OR miss_serial > 0
      ORDER BY i.received_date DESC, i.id DESC
      LIMIT 25
    ");
  }

  // Stock anomaly: prioritaskan stok per office agar tidak menyesatkan cabang.
  if ($has_stock_by_office) {
    $stats['stock_source_label'] = 'wqs_stock_by_office.stock_qty';
    $stats['stock_total'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_stock_by_office");
    $stats['stock_zero_or_less'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_stock_by_office WHERE stock_qty <= 0");
    $stats['zero_stock'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_stock_by_office WHERE stock_qty = 0");
    $stats['stock_negative'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_stock_by_office WHERE stock_qty < 0");
    $stats['stock_office_issue'] = (int) q1($pdo, "SELECT COUNT(DISTINCT office_code) FROM wqs_stock_by_office WHERE stock_qty <= 0");
  } elseif ($has_stock) {
    $stats['stock_source_label'] = 'wqs_stock.stock_qty';
    $stats['stock_total'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_stock");
    $stats['stock_zero_or_less'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_stock WHERE stock_qty <= 0");
    $stats['zero_stock'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_stock WHERE stock_qty = 0");
    $stats['stock_negative'] = (int) q1($pdo, "SELECT COUNT(*) FROM wqs_stock WHERE stock_qty < 0");
    $stats['stock_office_issue'] = null;
  }

  // Baseline lock
  if ($has_baseline) {
    $locked_at = q1($pdo, "SELECT locked_at FROM wqs_stock_baseline_lock WHERE id = 1");
    $stats['baseline_locked_at'] = $locked_at ?: null;
    $stats['baseline_locked'] = ($locked_at ? 'LOCKED' : 'UNLOCKED');
  }

  // Master Products data completeness: hanya produk ACTIVE agar angka quality tidak tercampur produk lama/nonaktif.
  if ($has_products) {
    $hasStatusCol = qc_col_exists($pdo, 'master_products', 'status');
    $hasBarcodeCol = qc_col_exists($pdo, 'master_products', 'barcode');
    $hasUnitCol = qc_col_exists($pdo, 'master_products', 'unit');
    $activeWhere = $hasStatusCol ? " WHERE LOWER(COALESCE(status,'')) = 'active'" : "";
    $activeAnd = $hasStatusCol ? " AND LOWER(COALESCE(status,'')) = 'active'" : "";

    $stats['products_total'] = (int) q1($pdo, "SELECT COUNT(*) FROM master_products" . $activeWhere);
    $stats['products_missing_barcode'] = $hasBarcodeCol
      ? (int) q1($pdo, "SELECT COUNT(*) FROM master_products WHERE (barcode IS NULL OR TRIM(barcode) = '')" . $activeAnd)
      : 0;
    $stats['products_missing_unit'] = $hasUnitCol
      ? (int) q1($pdo, "SELECT COUNT(*) FROM master_products WHERE (unit IS NULL OR TRIM(unit) = '')" . $activeAnd)
      : 0;

    if ($hasBarcodeCol || $hasUnitCol) {
      $issueParts = [];
      if ($hasBarcodeCol) $issueParts[] = "(barcode IS NULL OR TRIM(barcode) = '')";
      if ($hasUnitCol) $issueParts[] = "(unit IS NULL OR TRIM(unit) = '')";
      $stats['products_issue_unique'] = (int) q1($pdo, "SELECT COUNT(*) FROM master_products WHERE (" . implode(' OR ', $issueParts) . ")" . $activeAnd);
    }
    if (($stats['products_total'] ?? 0) > 0 && $hasBarcodeCol) {
      $withBarcode = max(0, (int)$stats['products_total'] - (int)$stats['products_missing_barcode']);
      $stats['barcode_coverage_pct'] = round(($withBarcode / (int)$stats['products_total']) * 100, 1);
    }
  }

  // Complaint/CAPA modules are not part of the current schema used by this dashboard.
  // Keep explicit source-readiness flags instead of showing false zero KPIs.
  $stats['complaint_source_ready'] = safe_table_exists($pdo, 'qc_complaints') || safe_table_exists($pdo, 'quality_complaints');
  $stats['capa_source_ready'] = safe_table_exists($pdo, 'qc_capa') || safe_table_exists($pdo, 'quality_capa');
}

// Helper to print number or '-'
function n($v) {
  if ($v === null) return '-';
  return is_numeric($v) ? number_format((float)$v, 0, ',', '.') : htmlspecialchars((string)$v);
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';

$extraHead = <<<'STYLE'
<style>
body{background:#0b1220;color:#e8ecf4}
.muted{color:#b8c5d4;font-size:12px}
h1,h2,h4{color:#f3f7ff}
table{color:#e8ecf4}
hr{border-color:rgba(255,255,255,.1)}

/* Header */
.qc-header{background:linear-gradient(135deg,rgba(6,78,59,.8),rgba(5,150,105,.6));border:1px solid rgba(16,185,129,.3);border-radius:18px;padding:20px 24px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.qc-header h2{margin:0;font-size:20px;font-weight:800;color:#fff}
.qc-header p{margin:4px 0 0;font-size:12px;color:rgba(255,255,255,.65)}

/* KPI cards */
.qc-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:16px 0}
.qc-k{padding:14px;border-radius:14px;background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-top:3px solid var(--kc)}
.qc-k-icon{font-size:20px;margin-bottom:6px}
.qc-k-n{font-size:24px;font-weight:800;color:#fff}
.qc-k-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}
.qc-k-sub{font-size:10px;color:#64748b;margin-top:2px}

/* Stat card dark */
.qc-stat{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px;height:100%}
.qc-stat .title{font-weight:700;font-size:14px;color:#f1f5f9;margin-bottom:4px}
.qc-stat .subtitle{font-size:11px;color:#64748b;margin-bottom:12px}
.qc-stat .row-item{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.qc-stat .row-item:last-child{border-bottom:none}
.qc-stat .row-item .label{color:#94a3b8}
.qc-stat .row-item .val{font-weight:700;color:#f1f5f9}
.qc-stat .row-item .val.warn{color:#f87171}
.qc-stat .row-item .val.ok{color:#4ade80}

/* Alert badges */
.qc-alert{border-radius:12px;padding:14px 16px;border:1px solid}
.qc-alert.red{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35);color:#fca5a5}
.qc-alert.yellow{background:rgba(234,179,8,.12);border-color:rgba(234,179,8,.35);color:#fde047}
.qc-alert.green{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.25);color:#86efac}
.qc-alert .al-val{font-size:28px;font-weight:800;margin:4px 0 2px}
.qc-alert .al-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.3px;opacity:.85}
.qc-alert .al-sub{font-size:11px;opacity:.8;margin-top:2px}

/* Quick links */
.qc-links{display:flex;flex-wrap:wrap;gap:7px}
.qc-link{padding:7px 13px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid rgba(255,255,255,.12);color:#e2e8f0;background:rgba(255,255,255,.06);transition:all .2s;display:inline-flex;align-items:center;gap:5px}
.qc-link:hover{background:rgba(255,255,255,.14);color:#fff}
.qc-link.primary{background:linear-gradient(135deg,#059669,#10b981);border-color:transparent;color:#fff}
</style>
STYLE;

$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim(BASE_PROJECT, '/') : '');
$actions = [
  ['label' => '📚 Panduan', 'url' => $bp . '/dashboards/quality/panduan.php', 'class' => 'btn btn-sm btn-outline-light'],
  ['label' => 'WQS Incoming', 'url' => $bp . '/stock/wqs_incoming.php', 'class' => 'btn btn-sm btn-outline-light'],
  ['label' => 'Stock Audit', 'url' => $bp . '/stock/wqs_stock_audit.php', 'class' => 'btn btn-sm btn-outline-light'],
  ['label' => 'Retur / Karantina', 'url' => $bp . '/stock/wqs_quarantine.php', 'class' => 'btn btn-sm btn-outline-light'],
  ['label' => 'Master Products', 'url' => $bp . '/master/master_products.php', 'class' => 'btn btn-sm btn-outline-light'],
  ['label' => 'Warehouse Dashboard', 'url' => $bp . '/dashboards/warehouse/wqs_dashboard.php', 'class' => 'btn btn-sm btn-outline-light'],
  ['label' => 'Regulatory', 'url' => $bp . '/dashboards/regulatory/license_docs_dashboard.php', 'class' => 'btn btn-sm btn-outline-light'],
];

rmi_header('Quality & Compliance', [
  'active' => 'quality',
  'subtitle' => 'Quality KPI & complaint overview.',
  'extra_head' => $extraHead,
  'actions' => $actions,
]);
?>
<div class="container py-4">
  <?php
  $missingTotal = (int)($stats['missing_exp_30']??0)+(int)($stats['missing_lot_30']??0)+(int)($stats['missing_serial_30']??0);
  $qualityExceptions = $missingTotal + (int)($stats['stock_negative']??0) + (int)($stats['products_issue_unique']??0);
  $masterFieldIssues = (int)($stats['products_missing_barcode']??0) + (int)($stats['products_missing_unit']??0);
  ?>

  <!-- Quality Control Summary: khusus monitoring, bukan task approval/transaksi -->
  <div class="qc-kpi">
    <div class="qc-k" style="--kc:#10b981"><div class="qc-k-lbl">Scope</div><div class="qc-k-n" style="font-size:20px">ALL</div><div class="qc-k-sub">Quality monitoring</div></div>
    <div class="qc-k" style="--kc:#38bdf8"><div class="qc-k-lbl">Incoming 30 Hari</div><div class="qc-k-n"><?= n($stats['incoming_30']) ?></div><div class="qc-k-sub">Dokumen masuk</div></div>
    <div class="qc-k" style="--kc:#f59e0b"><div class="qc-k-lbl">Missing Data</div><div class="qc-k-n"><?= n($missingTotal) ?></div><div class="qc-k-sub">EXP/LOT/SERIAL</div></div>
    <div class="qc-k" style="--kc:#ef4444"><div class="qc-k-lbl">Stock Negatif</div><div class="qc-k-n"><?= n($stats['stock_negative']) ?></div><div class="qc-k-sub">Qty &lt; 0</div></div>
    <div class="qc-k" style="--kc:#a78bfa"><div class="qc-k-lbl">Master Product Bermasalah</div><div class="qc-k-n"><?= n($stats['products_issue_unique']) ?></div><div class="qc-k-sub">unik per product id</div></div>
  </div>

  <!-- Header -->
  <div class="qc-header">
    <div>
      <h2>✅ Quality & Compliance Dashboard <span style="font-size:10px;font-weight:700;background:rgba(255,255,255,.12);padding:3px 7px;border-radius:8px;vertical-align:middle">FINAL-QC-V2-2026-09-01</span></h2>
      <p>Monitoring quality data & traceability — WQS Incoming, stock status, dan master-product readiness</p>
    </div>
    <div class="d-flex gap-2">
      <a class="qc-link" href="<?= h($bp) ?>/dashboards/index.php">🏠 Home</a>
      <a class="qc-link" href="<?= h($bp) ?>/dashboards/regulatory/license_docs_dashboard.php">📋 Reg Alkes</a>
    </div>
  </div>

  <!-- Alert Bar -->
  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="qc-alert <?= $missingTotal>0?'red':'green' ?>">
        <div class="al-lbl">Missing Data (30 hari)</div>
        <div class="al-val"><?= $missingTotal ?></div>
        <div class="al-sub">EXP/LOT/SERIAL belum diisi</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="qc-alert <?= ($stats['stock_negative']??0)>0?'red':'green' ?>">
        <div class="al-lbl">Stock Negatif</div>
        <div class="al-val"><?= n($stats['stock_negative']) ?></div>
        <div class="al-sub">SKU qty < 0</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="qc-alert <?= ($stats['products_missing_barcode']??0)>0?'yellow':'green' ?>">
        <div class="al-lbl">Produk Tanpa Barcode</div>
        <div class="al-val"><?= n($stats['products_missing_barcode']) ?></div>
        <div class="al-sub">Coverage barcode: <?= $stats['barcode_coverage_pct']===null?'N/A':number_format((float)$stats['barcode_coverage_pct'],1,',','.') . '%' ?> dari <?= n($stats['products_total']) ?> produk aktif</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="qc-alert <?= ($stats['baseline_locked']??'')!=='LOCKED'?'yellow':'green' ?>">
        <div class="al-lbl">Baseline Lock</div>
        <div class="al-val" style="font-size:20px"><?= htmlspecialchars($stats['baseline_locked']??'-') ?></div>
        <div class="al-sub"><?= $stats['baseline_locked_at'] ? date('d/m/Y',strtotime($stats['baseline_locked_at'])) : 'Belum dikunci' ?></div>
      </div>
    </div>
  </div>

  <!-- KPI Grid -->
  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="qc-stat">
        <div class="title">📥 Incoming (30 hari)</div>
        <div class="subtitle">Dokumen & item masuk</div>
        <div class="row-item"><span class="label">Dokumen</span><span class="val"><?= n($stats['incoming_30']) ?></span></div>
        <div class="row-item"><span class="label">Total Item</span><span class="val"><?= n($stats['incoming_items_30']) ?></span></div>
        <div style="margin-top:10px">
          <a class="qc-link primary" href="<?= h($bp) ?>/stock/wqs_incoming.php">Buka Incoming</a>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="qc-stat">
        <div class="title">⚠️ Data Gap Incoming</div>
        <div class="subtitle">field kosong 30 hari terakhir; requirement per produk belum tersedia</div>
        <div class="row-item"><span class="label">Missing EXP</span><span class="val <?= ($stats['missing_exp_30']??0)>0?'warn':'' ?>"><?= n($stats['missing_exp_30']) ?></span></div>
        <div class="row-item"><span class="label">Missing LOT</span><span class="val <?= ($stats['missing_lot_30']??0)>0?'warn':'' ?>"><?= n($stats['missing_lot_30']) ?></span></div>
        <div class="row-item"><span class="label">Missing SERIAL</span><span class="val <?= ($stats['missing_serial_30']??0)>0?'warn':'' ?>"><?= n($stats['missing_serial_30']) ?></span></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="qc-stat">
        <div class="title">📦 Stock Status</div>
        <div class="subtitle"><?= htmlspecialchars($stats['stock_source_label'] ?? 'stock_qty') ?></div>
        <div class="row-item"><span class="label">Total SKU</span><span class="val"><?= n($stats['stock_total']) ?></span></div>
        <div class="row-item"><span class="label">Zero Stock</span><span class="val"><?= n($stats['zero_stock']) ?></span></div>
        <div class="row-item"><span class="label">Negative Stock</span><span class="val <?= ($stats['stock_negative']??0)>0?'warn':'ok' ?>"><?= n($stats['stock_negative']) ?></span></div>
        <?php if ($has_stock_by_office): ?>
          <div class="row-item"><span class="label">Office dengan zero/negative</span><span class="val <?= ($stats['stock_office_issue']??0)>0?'warn':'' ?>"><?= n($stats['stock_office_issue']) ?></span></div>
        <?php endif; ?>
        <div style="margin-top:10px">
          <a class="qc-link" href="<?= h($bp) ?>/stock/wqs_stock_audit.php">Stock Audit</a>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="qc-stat">
        <div class="title">📦 Master Products</div>
        <div class="subtitle">Kelengkapan data produk aktif</div>
        <div class="row-item"><span class="label">Total Produk</span><span class="val"><?= n($stats['products_total']) ?></span></div>
        <div class="row-item"><span class="label">No Barcode</span><span class="val <?= ($stats['products_missing_barcode']??0)>0?'warn':'' ?>"><?= n($stats['products_missing_barcode']) ?></span></div>
        <div class="row-item"><span class="label">No Unit</span><span class="val <?= ($stats['products_missing_unit']??0)>0?'warn':'' ?>"><?= n($stats['products_missing_unit']) ?></span></div>
        <div class="row-item"><span class="label">Produk issue unik</span><span class="val <?= ($stats['products_issue_unique']??0)>0?'warn':'' ?>"><?= n($stats['products_issue_unique']) ?></span></div>
        <div class="row-item"><span class="label">Coverage barcode</span><span class="val"><?= $stats['barcode_coverage_pct']===null?'N/A':number_format((float)$stats['barcode_coverage_pct'],1,',','.') . '%' ?></span></div>
        <div style="margin-top:10px">
          <a class="qc-link" href="<?= h($bp) ?>/master/master_products.php">Master Products</a>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="qc-stat">
        <div class="title">🧭 Interpretasi Traceability</div>
        <div class="subtitle">Dashboard hanya menandai field EXP/LOT/SERIAL yang kosong.</div>
        <div style="font-size:12px;color:#cbd5e1;line-height:1.7">Belum ada master requirement per produk yang menyatakan apakah setiap SKU wajib EXP, LOT, SERIAL, atau kombinasi tertentu. Karena itu angka missing adalah <b>data gap</b>, bukan otomatis defect/QC failure.</div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="qc-stat">
        <div class="title">📋 Complaint / CAPA Source</div>
        <div class="row-item"><span class="label">Complaint Register</span><span class="val"><?= !empty($stats['complaint_source_ready']) ? 'AVAILABLE' : 'N/A' ?></span></div>
        <div class="row-item"><span class="label">CAPA Register</span><span class="val"><?= !empty($stats['capa_source_ready']) ? 'AVAILABLE' : 'N/A' ?></span></div>
        <div class="subtitle" style="margin-top:8px">N/A berarti source/schema belum tersedia; bukan berarti jumlah complaint/CAPA = 0.</div>
      </div>
    </div>
  </div>

  <!-- Quick Links -->
  <div style="background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px;margin-bottom:16px">
    <div class="fw-semibold mb-3" style="font-size:14px">⚡ Quick Links</div>
    <div class="qc-links">
      <a class="qc-link primary" href="<?= h($bp) ?>/stock/wqs_incoming.php">📥 WQS Incoming</a>
      <a class="qc-link primary" href="<?= h($bp) ?>/stock/wqs_stock_audit.php">🔍 Stock Audit</a>
      <a class="qc-link" href="<?= h($bp) ?>/stock/wqs_stock.php">📊 Stock WQS</a>
      <a class="qc-link" href="<?= h($bp) ?>/master/master_products.php">📦 Master Products</a>
      <a class="qc-link" href="<?= h($bp) ?>/dashboards/warehouse/wqs_dashboard.php">🏭 Warehouse Dashboard</a>
      <a class="qc-link" href="<?= h($bp) ?>/dashboards/regulatory/license_docs_dashboard.php">📋 Reg Alkes</a>
      <a class="qc-link" href="<?= h($bp) ?>/stock/wqs_quarantine.php">🚧 Retur / Karantina</a>
      <a class="qc-link" href="<?= h($bp) ?>/stock/wqs_stock_audit.php">📑 Stock Opname / Audit</a>
    </div>
  </div>

  <div class="mt-4">
    <div style="background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px">
      <div class="fw-semibold mb-1" style="font-size:14px;color:#f1f5f9">📋 Incoming QC – Daftar Issue <span style="font-size:11px;color:#64748b;font-weight:400">(30 hari terakhir)</span></div>
      <?php if (!$has_incoming): ?>
        <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:8px;padding:10px 14px;font-size:12px;color:#fde68a;margin-top:10px">
          ⚠️ Tabel <code style="color:#fde68a">wqs_incoming</code> / <code style="color:#fde68a">wqs_incoming_items</code> belum terdeteksi.
          Biasanya tabel ini dibuat otomatis saat halaman <a href="<?= h($bp) ?>/stock/wqs_incoming.php" style="color:#fde68a">WQS Incoming</a> pertama kali dibuka.
        </div>
      <?php elseif (empty($incoming_issues)): ?>
        <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:8px;padding:10px 14px;font-size:12px;color:#86efac;margin-top:10px">
          ✅ Tidak ada issue kelengkapan incoming (missing EXP/LOT/SERIAL) untuk 30 hari terakhir.
        </div>
      <?php else: ?>
        <div class="table-responsive mt-2" style="border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,.08)">
          <table style="width:100%;border-collapse:collapse;min-width:680px">
            <thead>
              <tr style="background:rgba(15,23,42,.95)">
                <th style="padding:9px 12px;font-size:11px;color:#94a3b8;font-weight:600;text-align:left;white-space:nowrap;border-bottom:1px solid rgba(255,255,255,.08)">Incoming</th>
                <th style="padding:9px 12px;font-size:11px;color:#94a3b8;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">Received</th>
                <th style="padding:9px 12px;font-size:11px;color:#94a3b8;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">PO</th>
                <th style="padding:9px 12px;font-size:11px;color:#94a3b8;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">Office</th>
                <th style="padding:9px 12px;font-size:11px;color:#94a3b8;font-weight:600;text-align:right;border-bottom:1px solid rgba(255,255,255,.08)">Items</th>
                <th style="padding:9px 12px;font-size:11px;color:#f87171;font-weight:600;text-align:right;border-bottom:1px solid rgba(255,255,255,.08)">EXP</th>
                <th style="padding:9px 12px;font-size:11px;color:#f87171;font-weight:600;text-align:right;border-bottom:1px solid rgba(255,255,255,.08)">LOT</th>
                <th style="padding:9px 12px;font-size:11px;color:#f87171;font-weight:600;text-align:right;border-bottom:1px solid rgba(255,255,255,.08)">SERIAL</th>
                <th style="padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.08)"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($incoming_issues as $idx => $r):
                $rowBg = $idx % 2 === 0 ? 'rgba(17,24,39,.85)' : 'rgba(15,23,42,.7)';
                $hasIssue = (int)($r['miss_exp']??0)>0 || (int)($r['miss_lot']??0)>0 || (int)($r['miss_serial']??0)>0;
              ?>
                <tr style="background:<?= $rowBg ?>">
                  <td style="padding:9px 12px;font-size:12px;font-weight:600;color:#e2e8f0;border-bottom:1px solid rgba(255,255,255,.05)"><?= htmlspecialchars($r['incoming_code'] ?? '') ?></td>
                  <td style="padding:9px 12px;font-size:12px;color:#94a3b8;border-bottom:1px solid rgba(255,255,255,.05)"><?= htmlspecialchars($r['received_date'] ?? '') ?></td>
                  <td style="padding:9px 12px;font-size:12px;color:#94a3b8;border-bottom:1px solid rgba(255,255,255,.05)"><?= htmlspecialchars($r['po_code'] ?? '-') ?></td>
                  <td style="padding:9px 12px;font-size:12px;border-bottom:1px solid rgba(255,255,255,.05)">
                    <span style="background:rgba(59,130,246,.15);color:#93c5fd;font-size:10px;font-weight:700;padding:1px 7px;border-radius:6px"><?= htmlspecialchars($r['office_code'] ?? '-') ?></span>
                  </td>
                  <td style="padding:9px 12px;font-size:12px;color:#cbd5e1;text-align:right;border-bottom:1px solid rgba(255,255,255,.05)"><?= n($r['items_total'] ?? 0) ?></td>
                  <td style="padding:9px 12px;font-size:12px;text-align:right;font-weight:700;border-bottom:1px solid rgba(255,255,255,.05);color:<?= (int)($r['miss_exp']??0)>0?'#f87171':'#334155' ?>">
                    <?= (int)($r['miss_exp']??0)>0 ? n($r['miss_exp']) : '—' ?>
                  </td>
                  <td style="padding:9px 12px;font-size:12px;text-align:right;font-weight:700;border-bottom:1px solid rgba(255,255,255,.05);color:<?= (int)($r['miss_lot']??0)>0?'#f87171':'#334155' ?>">
                    <?= (int)($r['miss_lot']??0)>0 ? n($r['miss_lot']) : '—' ?>
                  </td>
                  <td style="padding:9px 12px;font-size:12px;text-align:right;font-weight:700;border-bottom:1px solid rgba(255,255,255,.05);color:<?= (int)($r['miss_serial']??0)>0?'#f87171':'#334155' ?>">
                    <?= (int)($r['miss_serial']??0)>0 ? n($r['miss_serial']) : '—' ?>
                  </td>
                  <td style="padding:9px 12px;text-align:right;border-bottom:1px solid rgba(255,255,255,.05)">
                    <a href="<?= h($bp) ?>/stock/wqs_incoming.php?view=<?= (int)($r['id'] ?? 0) ?>"
                       style="padding:4px 10px;border-radius:7px;text-decoration:none;font-size:11px;font-weight:600;border:1px solid rgba(255,255,255,.15);color:#e2e8f0;background:rgba(255,255,255,.06)">
                      Open →
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="font-size:11px;color:#475569;margin-top:8px">💡 Review field EXP/LOT/SERIAL yang kosong di Incoming. Perlakukan sebagai gap data sampai requirement traceability per SKU tersedia.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="mt-4">
    <h4 class="mb-2" style="color:#f1f5f9">📌 Tujuan Halaman Ini</h4>
    <div class="qc-stat">
      <ol class="mb-0" style="padding-left:18px;color:#e2e8f0;font-size:13px;line-height:1.8">
        <li><b style="color:#4ade80">Quality (operasional)</b>: memastikan data Incoming cukup untuk traceability dan menandai field EXP/LOT/SERIAL yang masih kosong.</li>
        <li><b style="color:#60a5fa">Compliance (data)</b>: memisahkan zero stock dari negative stock per office serta memantau baseline lock agar perubahan stok terkontrol.</li>
        <li><b style="color:#fbbf24">Master data readiness</b>: cek kelengkapan barcode/unit, menghitung produk bermasalah secara unik, dan menampilkan coverage barcode.</li>
      </ol>
      <div class="mt-2" style="font-size:11px;color:#475569">
        Dashboard ini hanya monitoring — tidak mengubah data. Detail proses tetap di menu masing-masing modul.
      </div>
    </div>
  </div>

  <?php
  $auditModules = ['STOCK', 'WQS_INCOMING', 'STOCK_ADJUSTMENT', 'STOCK_OPNAME', 'QC'];
  $auditLimit   = 10;
  require __DIR__ . '/../_audit_log_widget.php';
  ?>

</div>
<?php rmi_footer(); ?>
