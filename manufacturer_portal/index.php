<?php
/**
 * manufacturer_portal/index.php
 * Dashboard Manufacturer Portal.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_mportal_login();

$pdo = rmi_db_pdo();
$user = mportal_user();
$mc = $user['manufacture_code'];
$mid = $user['manufacture_id'];

$pageTitle = mportal_t('dashboard');
$base = mportal_base();
$flash = $_SESSION['mportal_flash'] ?? null;
unset($_SESSION['mportal_flash']);

// Nama manufacture
$manuName = '';
if ($mid) {
    $st = $pdo->prepare("SELECT manufacture_name FROM master_manufactures WHERE id = ? LIMIT 1");
    $st->execute([$mid]);
    $r = $st->fetch();
    if ($r) $manuName = (string)$r['manufacture_name'];
}
if ($manuName === '') {
    $st = $pdo->prepare("SELECT manufacture_name FROM master_manufactures WHERE manufacture_code = ? LIMIT 1");
    $st->execute([$mc]);
    $r = $st->fetch();
    if ($r) $manuName = (string)$r['manufacture_name'];
}
if ($manuName === '') $manuName = $mc;

// Jumlah case aktif
$stCases = $pdo->prepare("
    SELECT COUNT(*) FROM hrl_reg_alkes_cases
    WHERE manufacture_code = ? AND status = 'OPEN'
");
$stCases->execute([$mc]);
$caseCount = (int)$stCases->fetchColumn();

// Case yang menunggu upload (stage 5, 6, 12)
$stPending = $pdo->prepare("
    SELECT COUNT(*) FROM hrl_reg_alkes_cases
    WHERE manufacture_code = ? AND status = 'OPEN' AND stage_no IN (5, 6, 12)
");
$stPending->execute([$mc]);
$pendingCount = (int)$stPending->fetchColumn();

// Ambil case untuk quick link upload (semua case OPEN)
$stCasesList = $pdo->prepare("
    SELECT id, case_code, product_name, stage_no
    FROM hrl_reg_alkes_cases
    WHERE manufacture_code = ? AND status = 'OPEN'
    ORDER BY stage_no ASC, id DESC
    LIMIT 10
");
$stCasesList->execute([$mc]);
$casesForUpload = $stCasesList->fetchAll(PDO::FETCH_ASSOC);

$upload_links = '';
foreach ($casesForUpload as $c) {
    $stageLabel = (int)$c['stage_no'] >= 11 ? 'Revisi' : 'Stage ' . (int)$c['stage_no'];
    $upload_links .= '<a href="' . rmi_h($base) . '/manufacturer_portal/case_detail.php?id=' . (int)$c['id'] . '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">'
        . rmi_h($c['case_code']) . ' — ' . rmi_h($c['product_name']) . ' <span class="badge bg-primary">Upload</span></a>';
}

$content = '
<style>
.mp-welcome-banner{background:linear-gradient(135deg,rgba(15,45,90,.9),rgba(29,78,216,.7));border:1px solid rgba(59,130,246,.25);border-radius:16px;padding:22px 26px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.mp-welcome-name{font-size:20px;font-weight:800;color:#fff;margin:0}
.mp-welcome-sub{font-size:13px;color:rgba(255,255,255,.85);margin:4px 0 0}
.mp-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.mp-kpi-c{background:linear-gradient(135deg,#0f2d5a,#1a4480);border:1px solid rgba(59,130,246,.3);border-radius:14px;padding:16px;border-top:3px solid var(--mc);transition:all .2s;box-shadow:0 2px 8px rgba(0,0,0,.15)}
.mp-kpi-c:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.25)}
.mp-kpi-icon{font-size:22px;margin-bottom:6px}
.mp-kpi-val{font-size:26px;font-weight:900;color:#fff}
.mp-kpi-lbl{font-size:11px;color:rgba(255,255,255,.9);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}
.mp-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.mp-action-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:700;transition:all .2s;border:none;cursor:pointer}
.mp-action-btn.primary{background:linear-gradient(135deg,#0f2d5a,#1d4ed8);color:#fff;box-shadow:0 4px 12px rgba(29,78,216,.3)}
.mp-action-btn.primary:hover{filter:brightness(1.1);color:#fff}
.mp-action-btn.outline{background:#fff;border:2px solid #1d4ed8;color:#1d4ed8}
.mp-action-btn.outline:hover{background:#1d4ed8;color:#fff}
.mp-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#334155;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.mp-section-title::after{content:"";flex:1;height:1px;background:#cbd5e1}
.mp-case-list{display:flex;flex-direction:column;gap:6px}
.mp-case-item{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;text-decoration:none;color:#334155;font-size:13px;transition:all .2s;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.mp-case-item:hover{background:#f8fafc;border-color:#94a3b8;color:#0f172a}
.mp-case-code{font-weight:700;color:#1d4ed8}
.mp-case-badge{background:#dbeafe;color:#1d4ed8;font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;border:1px solid #93c5fd}
.mp-flow-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.mp-flow-step{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;font-size:13px;color:#334155;line-height:1.5}
.mp-flow-step:last-child{margin-bottom:0}
.mp-flow-num{width:22px;height:22px;border-radius:50%;background:#1d4ed8;border:1px solid #3b82f6;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.mp-no-case{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:16px;color:#065f46;font-size:13px}
</style>

<!-- Welcome Banner -->
<div class="mp-welcome-banner">
  <div>
    <div class="mp-welcome-name">👋 ' . rmi_h(mportal_t('welcome')) . ', ' . rmi_h($user['full_name'] ?: $user['username']) . '</div>
    <div class="mp-welcome-sub">🏭 ' . rmi_h($manuName) . ' &nbsp;·&nbsp; <code style="color:rgba(255,255,255,.8);font-size:11px">' . rmi_h($mc) . '</code></div>
  </div>
  <div style="text-align:right;font-size:12px;color:rgba(255,255,255,.85)">' . date('d M Y') . '</div>
</div>

<!-- KPI -->
<div class="mp-kpi-grid">
  <div class="mp-kpi-c" style="--mc:#3b82f6">
    <div class="mp-kpi-icon">📋</div>
    <div class="mp-kpi-val">' . $caseCount . '</div>
    <div class="mp-kpi-lbl">' . rmi_h(mportal_t('active_cases')) . '</div>
  </div>
  <div class="mp-kpi-c" style="--mc:' . ($pendingCount > 0 ? '#f59e0b' : '#22c55e') . '">
    <div class="mp-kpi-icon">' . ($pendingCount > 0 ? '⏳' : '✅') . '</div>
    <div class="mp-kpi-val">' . $pendingCount . '</div>
    <div class="mp-kpi-lbl">' . rmi_h(mportal_t('pending_upload')) . '</div>
  </div>
</div>

<!-- Quick Actions -->
<div class="mp-section-title">⚡ Quick Actions</div>
<div class="mp-actions">
  <a class="mp-action-btn primary" href="' . rmi_h($base) . '/manufacturer_portal/cases.php">📋 ' . rmi_h(mportal_t('btn_view_cases')) . '</a>
  <a class="mp-action-btn outline" href="' . rmi_h($base) . '/manufacturer_portal/rfq.php">📩 ' . rmi_h(mportal_t('rfq')) . '</a>
  <a class="mp-action-btn outline" href="' . rmi_h($base) . '/manufacturer_portal/manufacture_docs.php">📤 ' . rmi_h(mportal_t('btn_partnership')) . '</a>
</div>

<div class="row g-3">
  <div class="col-md-8">
    ' . ($casesForUpload ? '
    <div class="mp-section-title">📤 ' . rmi_h(mportal_t('upload_per_case')) . '</div>
    <div class="mp-case-list">
      ' . implode('', array_map(function($c) use ($base) {
          $stageLabel = (int)$c['stage_no'] >= 11 ? 'Revisi' : 'Stage ' . (int)$c['stage_no'];
          return '<a href="' . rmi_h($base) . '/manufacturer_portal/case_detail.php?id=' . (int)$c['id'] . '" class="mp-case-item">'
            . '<div><span class="mp-case-code">' . rmi_h($c['case_code']) . '</span> &nbsp;'
            . '<span style="color:#64748b">' . rmi_h($c['product_name']) . '</span></div>'
            . '<span class="mp-case-badge">📤 Upload · ' . rmi_h($stageLabel) . '</span>'
            . '</a>';
      }, $casesForUpload)) . '
    </div>' : '
    <div class="mp-no-case">
      ✅ ' . rmi_h(mportal_t('no_case_msg')) . '
    </div>') . '
  </div>
  <div class="col-md-4">
    <div class="mp-section-title">📖 ' . rmi_h(mportal_t('info_flow')) . '</div>
    <div class="mp-flow-card">
      <div class="mp-flow-step"><div class="mp-flow-num">1</div><div>' . rmi_h(mportal_t('flow_step1')) . '</div></div>
      <div class="mp-flow-step"><div class="mp-flow-num">2</div><div>' . rmi_h(mportal_t('flow_step2')) . '</div></div>
      <div class="mp-flow-step"><div class="mp-flow-num">3</div><div>' . rmi_h(mportal_t('flow_step3')) . '</div></div>
      <div class="mp-flow-step"><div class="mp-flow-num">?</div><div>' . rmi_h(mportal_t('need_help')) . '</div></div>
    </div>
  </div>
</div>
';

require __DIR__ . '/layout.php';
