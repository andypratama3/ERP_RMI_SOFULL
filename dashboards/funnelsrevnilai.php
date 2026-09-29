<?php
/**
 * dashboards/funnels.php
 * Funnel Overview — Ringkasan read-only lintas modul.
 *
 * Prinsip:
 * - Tidak mengubah transaksi sumber.
 * - Periode diterapkan konsisten sejauh kolom tanggal tersedia.
 * - Status teknis dinormalisasi ke tahapan bisnis.
 * - Status yang tidak dikenali tetap dihitung sebagai OTHER agar total tidak hilang.
 */
declare(strict_types=1);

require_once __DIR__ . '/_dashboard_bootstrap.php';
require_login();

if (function_exists('require_any_permission')) {
    require_any_permission([
        'DASHBOARD.VIEW',
        'DASHBOARD.SALES_VIEW',
        'DASHBOARD.OWNER_VIEW',
        'DASHBOARD.OWNER_SUMMARY',
    ]);
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo && function_exists('kpi_require_pdo')) {
    $pdo = kpi_require_pdo();
}

function rmi_h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
if (!function_exists('h')) {
    function h($v): string { return rmi_h($v); }
}

require_once __DIR__ . '/_funnels_data.php';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$date_from = rmi_funnel_valid_date($_GET['date_from'] ?? null, $monthStart);
$date_to = rmi_funnel_valid_date($_GET['date_to'] ?? null, $today);
if ($date_from > $date_to) { [$date_from, $date_to] = [$date_to, $date_from]; }

$data = $pdo instanceof PDO ? rmi_funnel_read($pdo, $date_from, $date_to) : null;
$warnings = $data['warnings'] ?? ['Koneksi database tidak tersedia.'];
$crm = $data['crm_leads'] ?? ['stages'=>['DRAFT'=>0,'SUBMITTED'=>0,'APPROVED'=>0,'CLOSED'=>0,'CANCELLED'=>0,'OTHER'=>0],'total'=>0,'closed_pct'=>0,'progressed_pct'=>0];
$salesDo = $data['sales_do'] ?? ['stages'=>['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0,'CANCELLED'=>0,'OTHER'=>0],'total'=>0,'paid'=>0,'paid_pct'=>0];
$regAlkes = $data['reg_alkes'] ?? ['stages'=>array_fill(1,15,0),'total'=>0,'avg_days'=>[],'active_age_days'=>[]];
$importPo = $data['import'] ?? ['stages'=>['PO'=>0,'PIB'=>0,'GR'=>0,'AP'=>0],'value'=>null];

$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
require_once __DIR__ . '/../_shared/rmi_layout.php';

rmi_header('Funnel Overview', [
    'active' => 'dashboard',
    'breadcrumbs' => [
        ['label' => 'Dashboard', 'url' => $bp . '/dashboards/index.php'],
        'Funnel Overview',
    ],
    'actions' => [
        ['label' => rmi_icon('books').' Panduan', 'url' => $bp . '/dashboards/panduan_funnels.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'API Funnels JSON', 'url' => $bp . '/api/v1/internal/funnels_summary.php?date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to), 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'CRM Dashboard', 'url' => $bp . '/sales/sales_dashboard.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Reg Alkes Tower', 'url' => $bp . '/hrl_reg_alkes/reg_alkes_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Exec Summary', 'url' => $bp . '/dashboards/owner/exec_summary.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<div class="container py-4">
  <div class="card mb-3">
    <div class="card-body py-3">
      <form class="row g-2 align-items-end" method="get" action="funnels.php">
        <div class="col-auto">
          <label class="form-label small">Date From</label>
          <input class="form-control form-control-sm" type="date" name="date_from" value="<?= h($date_from) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label small">Date To</label>
          <input class="form-control form-control-sm" type="date" name="date_to" value="<?= h($date_to) ?>">
        </div>
        <div class="col-auto">
          <button class="btn btn-primary btn-sm">Apply</button>
        </div>
        <div class="col-auto">
          <a class="btn btn-outline-secondary btn-sm" href="funnels.php">Reset</a>
        </div>
      </form>
      <div class="small text-muted mt-2">Periode aktif: <b><?= h($date_from) ?></b> s.d. <b><?= h($date_to) ?></b>. Halaman ini read-only dan tidak mengubah transaksi sumber.</div>
    </div>
  </div>

  <?php if ($warnings): ?>
    <div class="alert alert-warning small">
      <strong>Catatan integritas data:</strong>
      <ul class="mb-0 mt-1">
        <?php foreach (array_unique($warnings) as $warning): ?>
          <li><?= h($warning) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">
          <strong>CRM Leads</strong>
          <span class="badge bg-secondary ms-2"><?= (int)$crm['total'] ?> leads</span>
          <?php if ($crm['stages']['OTHER'] > 0): ?><span class="badge bg-warning text-dark ms-1"><?= (int)$crm['stages']['OTHER'] ?> other</span><?php endif; ?>
        </div>
        <div class="card-body py-2 small">
          <div class="mb-2">DRAFT <b><?= (int)$crm['stages']['DRAFT'] ?></b> → SUBMITTED <b><?= (int)$crm['stages']['SUBMITTED'] ?></b> → APPROVED <b><?= (int)$crm['stages']['APPROVED'] ?></b> → CLOSED <b><?= (int)$crm['stages']['CLOSED'] ?></b> | CANCELLED <b><?= (int)$crm['stages']['CANCELLED'] ?></b></div>
          <div class="text-muted">Closed / total: <b><?= number_format($crm['closed_pct'], 1, ',', '.') ?>%</b> | Sudah melewati draft: <b><?= number_format($crm['progressed_pct'], 1, ',', '.') ?>%</b></div>
          <div class="text-muted mt-1">Angka ini adalah distribusi status saat ini, bukan conversion cohort historis.</div>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?= h($bp) ?>/sales/crm_leads.php">Open CRM Leads</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">
          <strong>Sales DO</strong>
          <span class="badge bg-secondary ms-2"><?= (int)$salesDo['total'] ?> DO</span>
          <?php if ($salesDo['total'] > 0): ?>
            <span class="badge bg-success ms-1"><?= (int)$salesDo['paid'] ?> paid</span>
            <span class="badge bg-info ms-1"><?= number_format($salesDo['paid_pct'], 1, ',', '.') ?>% paid</span>
          <?php endif; ?>
          <?php if ($salesDo['stages']['OTHER'] > 0): ?><span class="badge bg-warning text-dark ms-1"><?= (int)$salesDo['stages']['OTHER'] ?> unmapped</span><?php endif; ?>
        </div>
        <div class="card-body py-2 small">
          <div class="mb-2">CRM <b><?= (int)$salesDo['stages']['CRM'] ?></b> → WQS <b><?= (int)$salesDo['stages']['WQS'] ?></b> → SCM <b><?= (int)$salesDo['stages']['SCM'] ?></b> → ACT <b><?= (int)$salesDo['stages']['ACT'] ?></b> → FIN <b><?= (int)$salesDo['stages']['FIN'] ?></b> | CANCELLED <b><?= (int)$salesDo['stages']['CANCELLED'] ?></b></div>
          <div class="text-muted">Status teknis dinormalisasi ke departemen proses. Unmapped tetap dihitung agar total tidak hilang.</div>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?= h($bp) ?>/sales/sales_dashboard.php">Open Sales Dashboard</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">
          <strong>Reg Alkes Case</strong>
          <span class="badge bg-secondary ms-2"><?= (int)$regAlkes['total'] ?> OPEN</span>
        </div>
        <div class="card-body py-2 small">
          <?php if ($regAlkes['stages']): ?>
            <div class="mb-2">Stage 1–15: <?= h(implode(' | ', array_map(static fn($n, $c) => "S{$n}:{$c}", array_keys($regAlkes['stages']), $regAlkes['stages']))) ?></div>
            <?php if ($regAlkes['avg_days']): ?>
              <div class="text-muted">Rata-rata selesai per tahap: <?= h(implode(' | ', array_map(static fn($n, $d) => "S{$n}:{$d} hari", array_keys($regAlkes['avg_days']), $regAlkes['avg_days']))) ?></div>
            <?php endif; ?>
            <?php if ($regAlkes['active_age_days']): ?>
              <div class="text-muted">Rata-rata umur backlog aktif: <?= h(implode(' | ', array_map(static fn($n, $d) => "S{$n}:{$d} hari", array_keys($regAlkes['active_age_days']), $regAlkes['active_age_days']))) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted">Tidak ada case OPEN.</div>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?= h($bp) ?>/hrl_reg_alkes/reg_alkes_control_tower.php">Open Control Tower</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">
          <strong>Import/PO Pipeline</strong>
          <?php if ($importPo['value'] !== null): ?>
            <span class="badge bg-secondary ms-2">Rp <?= number_format((float)$importPo['value'], 0, ',', '.') ?></span>
          <?php endif; ?>
        </div>
        <div class="card-body py-2 small">
          <div class="mb-2">PO <b><?= (int)$importPo['stages']['PO'] ?></b> → PIB <b><?= (int)$importPo['stages']['PIB'] ?></b> → GR <b><?= (int)$importPo['stages']['GR'] ?></b> → AP <b><?= (int)$importPo['stages']['AP'] ?></b></div>
          <div class="text-muted">Semua tahap memakai periode filter bila kolom tanggal tersedia. Angka adalah ringkasan tahap, bukan jaminan satu cohort PO yang identik.</div>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?= h($bp) ?>/purchases/purchases_import_control_tower.php">Open Import Tower</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
