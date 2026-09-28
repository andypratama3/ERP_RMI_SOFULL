<?php
declare(strict_types=1);

// Keep tools namespace local-only.
require_once __DIR__ . '/tools_remote_check.php';

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.REVIEW_KIT', 'TOOLS.VIEW']);
} else {
    require_admin_critical();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}
require_once __DIR__ . '/../_shared/rmi_layout.php';

if (!function_exists('rk_h')) {
    function rk_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$export = trim((string)($_GET['export'] ?? ''));
if ($export === 'issue_template_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="review_kit_issue_log_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'domain', 'module', 'severity', 'finding', 'evidence_file', 'evidence_note', 'recommendation', 'owner', 'eta', 'status']);
    fputcsv($out, ['1', 'Security', 'API', 'Critical', 'POST without CSRF', 'api/v1/internal/example.php', 'No verify_csrf() call', 'Add verify_csrf() and tests', 'Security Lead', '2026-02-20', 'OPEN']);
    fclose($out);
    exit;
}

if ($export === 'gap_matrix_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="review_kit_gap_matrix_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['control_id', 'control_name', 'domain', 'status', 'evidence', 'notes']);
    fputcsv($out, ['SEC-001', 'All internal POST must enforce CSRF', 'Security', 'PARTIAL', 'master/*.php', 'Need closure sprint']);
    fputcsv($out, ['INT-002', 'Workflow uses idempotent transitions', 'Data Integrity', 'PASS', 'sales/crm_lead_edit.php', 'FOR UPDATE + rowCount']);
    fclose($out);
    exit;
}

$base = rmi_layout_base_project();
$env = defined('APP_ENV') ? strtoupper((string)APP_ENV) : 'LOCAL';
$viewer = strtoupper(trim((string)($_SESSION['username'] ?? 'unknown')));

$domains = [
    ['name' => 'Security Controls', 'items' => ['CSRF on all POST', 'Escaping on all HTML output', 'Prepared statements', 'Route guard + RBAC']],
    ['name' => 'Workflow Integrity', 'items' => ['Status transition rules', 'Maker-checker', 'No edit after posted/closed', 'Reversal-only flow']],
    ['name' => 'Data Integrity', 'items' => ['Transaction boundaries', 'Idempotent update/post', 'Document number uniqueness', 'No duplicate posting']],
    ['name' => 'Observability', 'items' => ['Audit trail for create/edit/approve/post/cancel', 'Operational logs', 'Error traceability']],
    ['name' => 'Backward Compatibility', 'items' => ['No breaking route changes', 'No regression on existing modules', 'Migration idempotent']],
];

$moduleQuickLinks = [
    ['label' => 'Sales', 'url' => $base . '/sales/sales_dashboard.php'],
    ['label' => 'Purchases', 'url' => $base . '/purchases/index.php'],
    ['label' => 'Stock/WQS', 'url' => $base . '/stock/index.php'],
    ['label' => 'Master', 'url' => $base . '/master/index.php'],
    ['label' => 'Dashboards', 'url' => $base . '/dashboards/index.php'],
    ['label' => 'Tools', 'url' => $base . '/tools/index.php'],
];

rmi_header('Review Kit ERP (Global)', [
    'active' => 'tools',
    'subtitle' => 'Framework audit lintas modul untuk Admin/Superadmin',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $base . '/tools/index.php'],
        'Review Kit ERP',
    ],
    'actions' => [
        ['label' => 'Open Review Workspace', 'url' => 'review_kit_workspace.php', 'class' => 'btn btn-sm btn-primary'],
        ['label' => 'Download Issue Log CSV', 'url' => '?export=issue_template_csv', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Download Gap Matrix CSV', 'url' => '?export=gap_matrix_csv', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Akses Khusus</div>
      <div class="rmi-muted">
        Halaman ini hanya untuk <b>ADMIN</b> dan <b>SUPERADMIN</b>, serta hanya bisa diakses dari localhost (`127.0.0.1` / `::1`).
      </div>
      <div class="mt-2 small">
        Viewer: <b><?= rk_h($viewer) ?></b> &middot; Env: <b><?= rk_h($env) ?></b>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Quick Links Modul</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($moduleQuickLinks as $m): ?>
          <a class="btn btn-sm btn-outline-light" href="<?= rk_h($m['url']) ?>" target="_blank" rel="noopener"><?= rk_h($m['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Cara Melihat & Menggunakan (UI) — Admin/Superadmin</div>
  <ol class="mb-0">
    <li>Buka menu <b>Tools</b> lalu pilih <b>Review Kit ERP (Global)</b>.</li>
    <li>Pilih modul yang ingin diaudit dari <b>Quick Links Modul</b> (Sales/Purchases/Stock/Master/dll).</li>
    <li>Gunakan domain checklist di bawah untuk menilai PASS / PARTIAL / FAIL.</li>
    <li>Catat temuan ke template <b>Issue Log CSV</b> (klik tombol download di top-right).</li>
    <li>Isi <b>Gap Matrix CSV</b> untuk kontrol yang belum memenuhi standar.</li>
    <li>Prioritaskan action berdasarkan severity: Critical -> High -> Medium -> Low.</li>
    <li>Kumpulkan evidence file path + screenshot/error message untuk setiap finding.</li>
    <li>Finalisasi backlog remediation (P0/P1/P2) dan PIC + ETA.</li>
    <li>Gunakan <b>Review Workspace</b> untuk input finding langsung di UI, auto-priority (P0/P1/P2), dan export backlog CSV aktual.</li>
    <li>Untuk baseline, tersedia 2 opsi di Review Workspace: <b>Muat Baseline AI</b> (seed cepat) dan <b>Muat Baseline REAL Scan</b> (berdasarkan scan file ERP saat ini).</li>
    <li>Jika ingin roadmap siap kirim ke tim, klik <b>Generate Roadmap MD</b> di Review Workspace.</li>
  </ol>
</div>

<div class="row g-3">
  <?php foreach ($domains as $d): ?>
    <div class="col-lg-6">
      <div class="rmi-card p-3 h-100">
        <div class="fw-semibold mb-2"><?= rk_h($d['name']) ?></div>
        <ul class="mb-0">
          <?php foreach ($d['items'] as $item): ?>
            <li><?= rk_h($item) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="rmi-card p-3 mt-3">
  <div class="fw-semibold mb-2">Output Review yang Disarankan</div>
  <ul class="mb-0">
    <li><code>AUDIT_METHOD.md</code> (metodologi + scoring).</li>
    <li><code>AUDIT_FINDINGS.md</code> (temuan detail + evidence + rekomendasi).</li>
    <li><code>AUDIT_GAP_MATRIX.csv</code> (kontrol vs status).</li>
    <li><code>REMEDIATION_ROADMAP.md</code> (P0/P1/P2, PIC, ETA).</li>
  </ul>
</div>

<?php rmi_footer(); ?>

