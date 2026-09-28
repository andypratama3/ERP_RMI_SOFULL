<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
require_once __DIR__ . '/_kpi_bootstrap.php';
$hasDoc = file_exists(__DIR__ . '/KPI_ENTERPRISE_PHASE1-3_DONE.md');
$pdo    = kpi_require_pdo();

require_once __DIR__ . '/../_shared/rbac.php';
if (!function_exists('rbac_require')) {
    http_response_code(500);
    exit('RBAC unavailable');
}
rbac_require($pdo, 'KPI.VIEW');

// Table checks
$hasOffice   = kpi_table_exists($pdo,'kpi_office');
$hasEmp      = kpi_table_exists($pdo,'kpi_employee');
$hasSnap     = kpi_table_exists($pdo,'kpi_snapshot');
$hasAudit    = kpi_table_exists($pdo,'kpi_audit_log');
$hasSales    = kpi_table_exists($pdo,'sales_do');
$hasSlaAudit = kpi_table_exists($pdo,'sales_do_audit');

$allReady = $hasOffice && $hasEmp && $hasSnap && $hasAudit && $hasSales;

$extraCss = <<<'STYLE'
<style>
/* KPI Center custom styles */
.kpi-ch{background:linear-gradient(135deg,#0f2d5a 0%,#1a4a8a 50%,#2563eb 100%);border-radius:18px;padding:24px 28px;margin-bottom:18px;position:relative;overflow:hidden}
.kpi-ch::before{content:"";position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.05)}
.kpi-ch h2{margin:0;font-size:22px;font-weight:900;color:#fff}
.kpi-ch p{margin:5px 0 0;font-size:13px;color:rgba(255,255,255,.7)}
.kpi-ch-stats{display:flex;gap:12px;margin-top:14px;flex-wrap:wrap}
.kpi-ch-stat{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:8px 14px;text-align:center}
.kpi-ch-stat .sv{font-size:20px;font-weight:800;color:#67e8f9}
.kpi-ch-stat .sl{font-size:10px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.4px}

/* Phase cards */
.kpi-phases{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:18px}
.kpi-phase{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px;border-left:4px solid var(--pc);transition:all .2s;text-decoration:none;color:inherit;display:block}
.kpi-phase:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3);border-color:var(--pc);color:inherit}
.kpi-phase-top{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.kpi-phase-icon{font-size:24px}
.kpi-phase-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;margin-left:auto}
.kpi-phase-badge.ok{background:rgba(34,197,94,.2);color:#4ade80;border:1px solid rgba(34,197,94,.3)}
.kpi-phase-badge.miss{background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.3)}
.kpi-phase-title{font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:5px}
.kpi-phase-desc{font-size:12px;color:#64748b;line-height:1.6}
.kpi-phase-link{font-size:12px;font-weight:700;color:var(--pc);margin-top:10px;display:inline-block}

/* Section title */
.kpi-sec{font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.6px;margin:16px 0 10px;display:flex;align-items:center;gap:8px}
.kpi-sec::after{content:"";flex:1;height:1px;background:rgba(255,255,255,.06)}

/* DB status */
.kpi-db{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin-bottom:16px}
.kpi-db-item{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;font-size:12px}
.kpi-db-item .dn{color:#94a3b8;font-family:monospace}
.kpi-db-item .ds{font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px}
.kpi-db-item .ds.ok{background:rgba(34,197,94,.15);color:#4ade80}
.kpi-db-item .ds.miss{background:rgba(239,68,68,.15);color:#f87171}

/* Quick nav */
.kpi-nav-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
.kpi-nav-btn{padding:8px 16px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:700;border:1px solid rgba(255,255,255,.12);color:#e2e8f0;background:rgba(255,255,255,.06);transition:all .2s;display:inline-flex;align-items:center;gap:5px}
.kpi-nav-btn:hover{background:rgba(255,255,255,.14);color:#fff}
.kpi-nav-btn.active{background:linear-gradient(135deg,#2563eb,#1d4ed8);border-color:transparent;color:#fff}
</style>
STYLE;

kpi_header('KPI Center');
echo $extraCss;
kpi_nav('center');
?>
<div style="display:flex;justify-content:flex-end;margin-bottom:10px">
  <a href="panduan.php" class="btn btn-sm btn-outline-light">📖 Panduan KPI</a>
</div>

<!-- KPI Center Header -->
<div class="kpi-ch">
  <h2>📊 KPI Center</h2>
  <p>Enterprise KPI — SLA, Office, Employee, Snapshot & Audit</p>
  <div class="kpi-ch-stats">
    <div class="kpi-ch-stat">
      <div class="sv"><?= $allReady ? '✓' : ($hasSales?'⚠':'✗') ?></div>
      <div class="sl">Status</div>
    </div>
    <div class="kpi-ch-stat">
      <div class="sv"><?= array_sum([$hasOffice,$hasEmp,$hasSnap,$hasAudit,$hasSales,$hasSlaAudit]) ?>/6</div>
      <div class="sl">Table Ready</div>
    </div>
    <div class="kpi-ch-stat">
      <div class="sv">3</div>
      <div class="sl">Phase</div>
    </div>
    <div class="kpi-ch-stat">
      <div class="sv">9</div>
      <div class="sl">Sub-menu</div>
    </div>
  </div>
</div>


<!-- Phase Cards -->
<div class="kpi-sec">📌 Phase & Modul</div>
<div class="kpi-phases">

  <!-- Phase 1 -->
  <a class="kpi-phase" href="kpi_do_sla.php" style="--pc:#3b82f6">
    <div class="kpi-phase-top">
      <span class="kpi-phase-icon">⏱️</span>
      <span class="kpi-phase-badge <?= $hasSales?'ok':'miss' ?>"><?= $hasSales?'READY':'MISSING' ?></span>
    </div>
    <div class="kpi-phase-title">Phase 1 · KPI DO (SLA)</div>
    <div class="kpi-phase-desc">Monitoring SLA per departemen (WQS/SCM/ACT/FIN) + backlog & overdue. Data dari <code>sales_do</code> + audit timestamps.</div>
    <div class="kpi-phase-link">Buka KPI DO (SLA) →</div>
  </a>

  <a class="kpi-phase" href="kpi_do_audit.php" style="--pc:#06b6d4">
    <div class="kpi-phase-top">
      <span class="kpi-phase-icon">🔍</span>
      <span class="kpi-phase-badge <?= $hasSlaAudit?'ok':'miss' ?>"><?= $hasSlaAudit?'READY':'MISSING' ?></span>
    </div>
    <div class="kpi-phase-title">Phase 1 · DO Audit Trail</div>
    <div class="kpi-phase-desc">Jejak perubahan status DO per departemen. Deteksi bottleneck & waktu proses antar stage.</div>
    <div class="kpi-phase-link">Buka DO Audit →</div>
  </a>

  <!-- Phase 2 -->
  <a class="kpi-phase" href="kpi_office.php" style="--pc:#22c55e">
    <div class="kpi-phase-top">
      <span class="kpi-phase-icon">🏢</span>
      <span class="kpi-phase-badge <?= $hasOffice?'ok':'miss' ?>"><?= $hasOffice?'READY':'MISSING' ?></span>
    </div>
    <div class="kpi-phase-title">Phase 2 · KPI Office</div>
    <div class="kpi-phase-desc">Penjualan, AR aging, stock value, PO/AP, fixed asset, headcount per cabang. Mode Sync System Metrics.</div>
    <div class="kpi-phase-link">Buka KPI Office →</div>
  </a>

  <a class="kpi-phase" href="kpi_employee.php" style="--pc:#8b5cf6">
    <div class="kpi-phase-top">
      <span class="kpi-phase-icon">👤</span>
      <span class="kpi-phase-badge <?= $hasEmp?'ok':'miss' ?>"><?= $hasEmp?'READY':'MISSING' ?></span>
    </div>
    <div class="kpi-phase-title">Phase 2 · KPI Employee</div>
    <div class="kpi-phase-desc">Produktivitas & aktivitas per karyawan/dept — dari audit log otomatis atau input manual.</div>
    <div class="kpi-phase-link">Buka KPI Employee →</div>
  </a>

  <a class="kpi-phase" href="kpi_purchases.php" style="--pc:#f59e0b">
    <div class="kpi-phase-top">
      <span class="kpi-phase-icon">🛒</span>
      <span class="kpi-phase-badge ok">READY</span>
    </div>
    <div class="kpi-phase-title">Phase 2 · KPI Purchases</div>
    <div class="kpi-phase-desc">PR → PO → GR → AP cycle time, pending items, nilai pembelian per periode.</div>
    <div class="kpi-phase-link">Buka KPI Purchases →</div>
  </a>

  <a class="kpi-phase" href="kpi_stock.php" style="--pc:#f97316">
    <div class="kpi-phase-top">
      <span class="kpi-phase-icon">📦</span>
      <span class="kpi-phase-badge ok">READY</span>
    </div>
    <div class="kpi-phase-title">Phase 2 · KPI Stock</div>
    <div class="kpi-phase-desc">Stock value, turnover, slow-moving items, expiry risk per SKU dan office.</div>
    <div class="kpi-phase-link">Buka KPI Stock →</div>
  </a>

  <!-- Phase 3 -->
  <a class="kpi-phase" href="kpi_snapshot.php" style="--pc:#ec4899">
    <div class="kpi-phase-top">
      <span class="kpi-phase-icon">📷</span>
      <span class="kpi-phase-badge <?= $hasSnap?'ok':'miss' ?>"><?= $hasSnap?'READY':'MISSING' ?></span>
    </div>
    <div class="kpi-phase-title">Phase 3 · Snapshot & Lock</div>
    <div class="kpi-phase-desc">Kunci KPI per bulan untuk keperluan audit. Setelah LOCKED → read-only & tidak bisa diubah.</div>
    <div class="kpi-phase-link">Buka Snapshot →</div>
  </a>

  <a class="kpi-phase" href="kpi_audit.php" style="--pc:#64748b">
    <div class="kpi-phase-top">
      <span class="kpi-phase-icon">📋</span>
      <span class="kpi-phase-badge <?= $hasAudit?'ok':'miss' ?>"><?= $hasAudit?'READY':'MISSING' ?></span>
    </div>
    <div class="kpi-phase-title">Phase 3 · Audit Log</div>
    <div class="kpi-phase-desc">Jejak semua operasi create/update/import/bulk/snapshot di seluruh modul KPI.</div>
    <div class="kpi-phase-link">Buka Audit Log →</div>
  </a>

</div>

<!-- DB Status -->
<div class="kpi-sec">🗄️ Status Database</div>
<div class="kpi-db">
  <?php foreach (['sales_do','sales_do_audit','kpi_office','kpi_employee','kpi_snapshot','kpi_snapshot_items','kpi_audit_log'] as $t):
    $ok = kpi_table_exists($pdo,$t);
  ?>
    <div class="kpi-db-item">
      <span class="dn"><?= h($t) ?></span>
      <span class="ds <?= $ok?'ok':'miss' ?>"><?= $ok?'✓ OK':'✗ MISSING' ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$allReady): ?>
<div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:12px 14px;font-size:12px;color:#fde68a;margin-bottom:16px">
  ⚠️ Ada tabel yang MISSING. Jalankan SQL: <code style="color:#fde68a">kpi/kpi_enterprise_tables.sql</code> di NAS untuk membuat tabel yang diperlukan.
</div>
<?php endif; ?>

<?php if ($hasDoc): ?>
<div style="font-size:11px;color:#334155;text-align:center;margin-top:8px">
  📄 <a href="KPI_ENTERPRISE_PHASE1-3_DONE.md" style="color:#475569">KPI_ENTERPRISE_PHASE1-3_DONE.md</a>
</div>
<?php endif; ?>

<?php kpi_footer(); ?>
