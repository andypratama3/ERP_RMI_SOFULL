<?php
/**
 * manufacturer_portal/cases.php
 * Daftar case Reg Alkes untuk manufacture.
 */
declare(strict_types=1);
require_once __DIR__ . '/../_shared/rmi_icons.php';

require_once __DIR__ . '/_bootstrap.php';
require_mportal_login();

$pdo = rmi_db_pdo();
$user = mportal_user();
$mc = $user['manufacture_code'];

$pageTitle = mportal_t('reg_alkes_cases');
$base = mportal_base();
$flash = $_SESSION['mportal_flash'] ?? null;
unset($_SESSION['mportal_flash']);

$stages = [
    1 => '1. PQP cari principal',
    2 => '2. PQP quotation',
    3 => '3. PQP PKS/LOA draft',
    4 => '4. Legal LOA/KBRI',
    5 => '5. PQP minta berkas',
    6 => '6. PQP pendukung',
    7 => '7. Legal cek',
    8 => '8. Legal OSS Regalkes',
    9 => '9. Legal berkas ttd',
    10 => '10. Legal submit',
    11 => '11. Revisi diminta',
    12 => '12. PQP revisi',
    13 => '13. Legal submit revisi',
    14 => '14. Legal review NIE',
    15 => '15. NIE terbit',
];

$stmt = $pdo->prepare("
    SELECT id, case_code, product_name, stage_no, stage_code, status, revision_deadline, created_at
    FROM hrl_reg_alkes_cases
    WHERE manufacture_code = ?
    ORDER BY status ASC, stage_no ASC, id DESC
");
$stmt->execute([$mc]);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rows = '';
foreach ($cases as $c) {
    $stageLabel = $stages[(int)$c['stage_no']] ?? (string)$c['stage_code'];
    $isRevisi = (int)$c['stage_no'] >= 11;
    $deadline = $c['revision_deadline'] && $c['revision_deadline'] !== '0000-00-00'
        ? date('d/m/Y', strtotime($c['revision_deadline'])) : '-';
    $rows .= '<tr class="mp-table-row">
        <td><strong class="mp-case-code">' . rmi_h($c['case_code']) . '</strong></td>
        <td>' . rmi_h($c['product_name']) . '</td>
        <td><span class="mp-badge ' . ($isRevisi ? 'mp-badge-warn' : 'mp-badge-info') . '">' . rmi_h($stageLabel) . '</span></td>
        <td><span class="mp-status">' . rmi_h($c['status']) . '</span></td>
        <td>' . rmi_h($deadline) . '</td>
        <td><a href="' . rmi_h($base) . '/manufacturer_portal/case_detail.php?id=' . (int)$c['id'] . '" class="mp-btn mp-btn-sm mp-btn-primary">' . rmi_h(mportal_t('detail_upload')) . '</a></td>
    </tr>';
}

$content = '
<style>
.mp-page-header{background:linear-gradient(135deg,rgba(15,45,90,.9),rgba(29,78,216,.7));border:1px solid rgba(59,130,246,.25);border-radius:16px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.mp-page-title{font-size:18px;font-weight:800;color:#fff;margin:0}
.mp-page-sub{font-size:13px;color:rgba(255,255,255,.85);margin:4px 0 0}
.mp-back{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);transition:all .2s}
.mp-back:hover{background:rgba(255,255,255,.25);color:#fff}
.mp-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden}
.mp-table{width:100%;border-collapse:collapse}
.mp-table th{background:#f8fafc;color:#334155;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:12px 16px;border-bottom:2px solid #e2e8f0;text-align:left}
.mp-table td{padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#334155;vertical-align:middle}
.mp-table-row:hover{background:#f8fafc}
.mp-case-code{color:#1d4ed8;font-weight:700}
.mp-badge{display:inline-block;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:600}
.mp-badge-info{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}
.mp-badge-warn{background:#fef3c7;color:#b45309;border:1px solid #fcd34d}
.mp-status{font-weight:600;color:#475569}
.mp-btn{padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .2s;border:none;cursor:pointer}
.mp-btn-sm{padding:5px 10px;font-size:11px}
.mp-btn-primary{background:linear-gradient(135deg,#0f2d5a,#1d4ed8);color:#fff}
.mp-btn-primary:hover{filter:brightness(1.1);color:#fff}
.mp-alert{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:14px 18px;color:#065f46;font-size:13px;margin-bottom:20px}
.mp-empty{text-align:center;padding:40px 20px;color:#64748b;font-size:14px}
</style>

<a href="' . rmi_h($base) . '/manufacturer_portal/" class="mp-back mb-3">← ' . rmi_h(mportal_t('back_dashboard')) . '</a>

<div class="mp-page-header">
  <div>
    <h1 class="mp-page-title">' . rmi_icon('office') . ' ' . rmi_h(mportal_t('reg_alkes_cases')) . '</h1>
    <p class="mp-page-sub">' . rmi_h(mportal_t('cases_for')) . ' ' . rmi_h($mc) . '</p>
  </div>
</div>

' . ($cases ? '<div class="mp-alert"><strong>' . rmi_icon('question') . ' ' . rmi_h(mportal_t('upload_hint')) . '</strong></div>' : '') . '

<div class="mp-card">
  <div class="table-responsive">
    <table class="mp-table">
      <thead>
        <tr>
          <th>' . rmi_h(mportal_t('case_code')) . '</th>
          <th>' . rmi_h(mportal_t('product')) . '</th>
          <th>' . rmi_h(mportal_t('stage')) . '</th>
          <th>' . rmi_h(mportal_t('status')) . '</th>
          <th>' . rmi_h(mportal_t('deadline')) . '</th>
          <th>' . rmi_h(mportal_t('action')) . '</th>
        </tr>
      </thead>
      <tbody>' . ($rows ?: '<tr><td colspan="6" class="mp-empty">' . rmi_h(mportal_t('no_cases')) . '</td></tr>') . '</tbody>
    </table>
  </div>
</div>
';

require __DIR__ . '/layout.php';
