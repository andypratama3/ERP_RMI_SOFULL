<?php
/**
 * hrl/hr_report_center.php
 * HRL Report Center — agregasi report dokumen, ack, dan Reg Alkes.
 */
declare(strict_types=1);

$pageTitle = 'HRL Report Center';
$pageSubtitle = 'Ringkasan report dokumen, acknowledgement, dan registrasi alkes';
require_once __DIR__ . '/_layout_top.php';
hrl_require_manage();

$bp = rtrim(base_project(), '/');
$urlRegAlkes = $bp . '/hrl_reg_alkes/reg_alkes_export_compliance.php';

// Reg Alkes Summary (case per status)
$regAlkesByStatus = [];
$regAlkesTotal = 0;
try {
    if (function_exists('db_col_exists') && db_col_exists($pdo, 'hrl_reg_alkes_cases', 'status')) {
        $st = $pdo->query("
            SELECT status, COUNT(*) AS cnt
            FROM hrl_reg_alkes_cases
            GROUP BY status
            ORDER BY cnt DESC
        ");
        $regAlkesByStatus = $st->fetchAll(PDO::FETCH_ASSOC);
        $regAlkesTotal = (int)$pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases")->fetchColumn();
    }
} catch (Throwable $e) {
    // table may not exist
}

// Kepatuhan per Dept (ack count per department untuk dokumen aktif)
$kepatuhanDept = [];
try {
    if (function_exists('db_col_exists') && db_col_exists($pdo, 'hrl_doc_acks', 'department')) {
        $st = $pdo->query("
            SELECT COALESCE(NULLIF(TRIM(a.department), ''), '-') AS dept, COUNT(*) AS ack_count
            FROM hrl_doc_acks a
            INNER JOIN hrl_docs d ON d.id = a.doc_id AND d.current_version = a.version_no AND d.deleted_at IS NULL
            WHERE d.status = 'ACTIVE'
            GROUP BY dept
            ORDER BY ack_count DESC
            LIMIT 20
        ");
        $kepatuhanDept = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // fallback
}
?>

<div class="rmi-card mb-3">
  <div class="rmi-card-header">
    <h5>HRL Report Center</h5>
    <div class="sub">Ringkasan report dokumen, acknowledgement, dan registrasi alkes</div>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <div class="card border">
          <div class="card-header bg-transparent">Report Tersedia</div>
          <div class="card-body">
            <ul class="list-unstyled mb-0">
              <li class="mb-2">
                <a class="btn btn-outline-primary btn-sm w-100 text-start" href="<?= e(url_hrl('hrl_ack_report.php')) ?>">
                  📋 Acknowledgement Report
                </a>
                <div class="small text-muted mt-1">Ringkasan ack per dokumen (versi aktif)</div>
              </li>
              <li class="mb-2">
                <a class="btn btn-outline-primary btn-sm w-100 text-start" href="<?= e($urlRegAlkes) ?>">
                  📥 Reg Alkes Export Compliance
                </a>
                <div class="small text-muted mt-1">Export CSV/Excel data compliance registrasi alkes</div>
              </li>
            </ul>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card border">
          <div class="card-header bg-transparent">Reg Alkes Summary</div>
          <div class="card-body">
            <?php if ($regAlkesByStatus): ?>
              <p class="small text-muted">Total case: <strong><?= (int) $regAlkesTotal ?></strong></p>
              <table class="table table-sm table-bordered mb-0">
                <thead><tr><th>Status</th><th class="text-end">Jumlah</th></tr></thead>
                <tbody>
                  <?php foreach ($regAlkesByStatus as $r): ?>
                    <tr>
                      <td><?= e((string) $r['status']) ?></td>
                      <td class="text-end"><?= (int) $r['cnt'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <div class="mt-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e($bp . '/hrl_reg_alkes/reg_alkes_control_tower.php') ?>">Buka Control Tower</a>
              </div>
            <?php else: ?>
              <p class="text-muted small mb-0">Belum ada data atau tabel belum tersedia.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($kepatuhanDept): ?>
    <div class="mt-3">
      <div class="card border">
        <div class="card-header bg-transparent">Kepatuhan per Dept (Ack)</div>
        <div class="card-body">
          <table class="table table-sm table-bordered mb-0">
            <thead><tr><th>Dept</th><th class="text-end">Jumlah Ack</th></tr></thead>
            <tbody>
              <?php foreach ($kepatuhanDept as $r): ?>
                <tr>
                  <td><?= e((string) $r['dept']) ?></td>
                  <td class="text-end"><?= (int) $r['ack_count'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
