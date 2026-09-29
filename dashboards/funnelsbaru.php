<?php
/**
 * dashboards/funnels.php
 * Funnel Overview — Ringkasan semua funnel: CRM Leads, Sales DO, Reg Alkes, Import/PO
 * Read-only. RBAC: DASHBOARD.VIEW atau permission terkait.
 */
declare(strict_types=1);

require_once __DIR__ . '/_dashboard_bootstrap.php';
require_login();

if (function_exists('require_any_permission')) {
    require_any_permission(['DASHBOARD.VIEW', 'DASHBOARD.SALES_VIEW', 'DASHBOARD.OWNER_VIEW', 'DASHBOARD.OWNER_SUMMARY']);
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo && function_exists('kpi_require_pdo')) {
    $pdo = kpi_require_pdo();
}

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to'] ?? date('Y-m-d');

function rmi_h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
if (!function_exists('h')) { function h($v) { return rmi_h($v); } }

// --- Data defaults ---
$crm = ['total'=>0, 'DRAFT'=>0, 'SUBMITTED'=>0, 'APPROVED'=>0, 'CLOSED'=>0, 'CANCELLED'=>0, 'close_pct'=>0.0, 'conv'=>[]];
$salesDo = ['CRM'=>0, 'WQS'=>0, 'SCM'=>0, 'ACT'=>0, 'FIN'=>0, 'total'=>0, 'paid'=>0, 'to_payment_pct'=>0.0, 'overdue'=>[]];
$regAlkes = ['total'=>0, 'stages'=>[]];
$importPo = ['PO'=>0, 'PIB'=>0, 'GR'=>0, 'AP'=>0, 'value'=>null];

if ($pdo) {
    try {
        // CRM Leads
        $chkCrm = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_leads'")->fetch();
        if ($chkCrm) {
            $st = $pdo->prepare("SELECT status, COUNT(*) cnt FROM crm_leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status");
            $st->execute([$date_from, $date_to]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $s = strtoupper((string)($r['status'] ?? ''));
                if (isset($crm[$s])) $crm[$s] = (int)$r['cnt'];
                $crm['total'] += (int)$r['cnt'];
            }
            if ($crm['APPROVED'] > 0) {
                $crm['close_pct'] = ($crm['CLOSED'] / $crm['APPROVED']) * 100.0;
            }
            $pastDraft = $crm['SUBMITTED'] + $crm['APPROVED'] + $crm['CLOSED'] + $crm['CANCELLED'];
            $crm['conv']['DRAFT_to_SUBM'] = $crm['total'] > 0 ? ($pastDraft / $crm['total']) * 100 : 0;
            $pastSubm = $crm['APPROVED'] + $crm['CLOSED'] + $crm['CANCELLED'];
            $crm['conv']['SUBM_to_APPR'] = $pastDraft > 0 ? ($pastSubm / $pastDraft) * 100 : 0;
            $crm['conv']['APPR_to_CLOSED'] = ($crm['APPROVED'] + $crm['CLOSED'] + $crm['CANCELLED']) > 0
                ? ($crm['CLOSED'] / ($crm['APPROVED'] + $crm['CLOSED'] + $crm['CANCELLED'])) * 100 : 0;
        }

        // Sales DO
        $chkDo = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_do' AND column_name='flow_status'")->fetch();
        $stageCol = $chkDo ? 'flow_status' : 'stage';
        $hasStage = false;
        foreach ($pdo->query("SHOW COLUMNS FROM sales_do") as $col) {
            if (($col['Field'] ?? '') === $stageCol) { $hasStage = true; break; }
        }
        if ($hasStage) {
            $st = $pdo->prepare("SELECT {$stageCol} AS stg, COUNT(*) cnt FROM sales_do WHERE do_date BETWEEN ? AND ? GROUP BY {$stageCol}");
            $st->execute([$date_from, $date_to]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $s = (string)($r['stg'] ?? '');
                if ($s !== '' && isset($salesDo[$s])) {
                    $salesDo[$s] = (int)$r['cnt'];
                    $salesDo['total'] += (int)$r['cnt'];
                }
            }
            $chkPaid = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_do' AND column_name='fin_paid_at'")->fetch();
            if ($chkPaid && $salesDo['total'] > 0) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE do_date BETWEEN ? AND ? AND fin_paid_at IS NOT NULL");
                $st->execute([$date_from, $date_to]);
                $salesDo['paid'] = (int)$st->fetchColumn();
                $salesDo['to_payment_pct'] = ($salesDo['paid'] / $salesDo['total']) * 100.0;
            }
        }

        // Reg Alkes
        $chkReg = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_cases'")->fetch();
        if ($chkReg) {
            $st = $pdo->query("SELECT stage_no, COUNT(*) cnt FROM hrl_reg_alkes_cases WHERE UPPER(COALESCE(status,'OPEN'))='OPEN' GROUP BY stage_no");
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $sn = (int)($r['stage_no'] ?? 0);
                if ($sn >= 1 && $sn <= 15) {
                    $regAlkes['stages'][$sn] = (int)$r['cnt'];
                    $regAlkes['total'] += (int)$r['cnt'];
                }
            }
            $regAlkes['avg_days'] = [];
            $chkLog = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_case_stage_log'")->fetch();
            if ($chkLog) {
                $stAvg = $pdo->query("SELECT stage_no, AVG(DATEDIFF(COALESCE(exited_at, NOW()), entered_at)) AS avg_days FROM hrl_reg_alkes_case_stage_log WHERE exited_at IS NOT NULL GROUP BY stage_no");
                while ($r = $stAvg->fetch(PDO::FETCH_ASSOC)) {
                    $sn = (int)($r['stage_no'] ?? 0);
                    if ($sn >= 1 && $sn <= 15 && $r['avg_days'] !== null) {
                        $regAlkes['avg_days'][$sn] = round((float)$r['avg_days'], 1);
                    }
                }
            }
        }

        // Import/PO
        if (function_exists('kpi_policy_table_exists') && kpi_policy_table_exists($pdo, 'purchases_po')) {
            $st = $pdo->query("SELECT COALESCE(SUM(total_amount),0) v, COUNT(*) c FROM purchases_po WHERE deleted_at IS NULL AND status IN ('OPEN','IN_PRODUCTION','READY')");
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $importPo['PO'] = (int)$r['c'];
                $importPo['value'] = (float)$r['v'];
            }
            if (kpi_policy_table_exists($pdo, 'purchases_ceisa_pib')) {
                $importPo['PIB'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_ceisa_pib")->fetchColumn();
            }
            if (kpi_policy_table_exists($pdo, 'wqs_incoming')) {
                $importPo['GR'] = (int)$pdo->query("SELECT COUNT(DISTINCT po_code) FROM wqs_incoming")->fetchColumn();
            }
            if (kpi_policy_table_exists($pdo, 'purchases_invoice_ap')) {
                $importPo['AP'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL")->fetchColumn();
            }
        }
    } catch (Throwable $e) {
        // fail-soft
    }
}

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
  <div class="mb-3">
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
    </form>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">
          <strong>CRM Leads</strong>
          <span class="badge bg-secondary ms-2"><?= (int)$crm['total'] ?> leads</span>
        </div>
        <div class="card-body py-2 small">
          <div class="mb-2">DRAFT <b><?= (int)$crm['DRAFT'] ?></b> → SUBMITTED <b><?= (int)$crm['SUBMITTED'] ?></b> → APPROVED <b><?= (int)$crm['APPROVED'] ?></b> → CLOSED <b><?= (int)$crm['CLOSED'] ?></b> | CANCELLED <b><?= (int)$crm['CANCELLED'] ?></b></div>
          <div class="text-muted">Close rate: <b><?= number_format($crm['close_pct'], 1) ?>%</b> | Conversion: DRAFT→SUBM <b><?= number_format($crm['conv']['DRAFT_to_SUBM'] ?? 0, 1) ?>%</b> | SUBM→APPR <b><?= number_format($crm['conv']['SUBM_to_APPR'] ?? 0, 1) ?>%</b> | APPR→CLOSED <b><?= number_format($crm['conv']['APPR_to_CLOSED'] ?? 0, 1) ?>%</b></div>
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
          <span class="badge bg-info ms-1"><?= number_format($salesDo['to_payment_pct'], 1) ?>% to payment</span>
          <?php endif; ?>
        </div>
        <div class="card-body py-2 small">
          <div class="mb-2">CRM <b><?= (int)$salesDo['CRM'] ?></b> → WQS <b><?= (int)$salesDo['WQS'] ?></b> → SCM <b><?= (int)$salesDo['SCM'] ?></b> → ACT <b><?= (int)$salesDo['ACT'] ?></b> → FIN <b><?= (int)$salesDo['FIN'] ?></b></div>
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
          <?php if (!empty($regAlkes['stages'])): ?>
          <div class="mb-2">Stage 1–15: <?= implode(' | ', array_map(function($n, $c) { return "S{$n}:{$c}"; }, array_keys($regAlkes['stages']), $regAlkes['stages'])) ?></div>
          <?php if (!empty($regAlkes['avg_days'])): ?>
          <div class="text-muted small">Avg days: <?= implode(' | ', array_map(function($n, $d) { return "S{$n}:{$d}d"; }, array_keys($regAlkes['avg_days']), $regAlkes['avg_days'])) ?></div>
          <?php endif; ?>
          <?php else: ?>
          <div class="text-muted">Tidak ada case OPEN</div>
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
          <span class="badge bg-secondary ms-2">Rp <?= number_format($importPo['value'] / 1e6, 1) ?>M</span>
          <?php endif; ?>
        </div>
        <div class="card-body py-2 small">
          <div class="mb-2">PO <b><?= (int)$importPo['PO'] ?></b> → PIB <b><?= (int)$importPo['PIB'] ?></b> → GR <b><?= (int)$importPo['GR'] ?></b> → AP <b><?= (int)$importPo['AP'] ?></b></div>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?= h($bp) ?>/purchases/purchases_import_control_tower.php">Open Import Tower</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
