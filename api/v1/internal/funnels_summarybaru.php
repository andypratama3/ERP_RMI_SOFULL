<?php
/**
 * api/v1/internal/funnels_summary.php
 * Funnel Summary API — untuk BI / Excel / dashboard eksternal
 *
 * GET ?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&funnel=crm_leads|sales_do|reg_alkes|import
 * - funnel: optional, comma-separated. Jika kosong, return semua.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';

use App\Api\ApiResponse;

require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['DASHBOARD.VIEW', 'DASHBOARD.SALES_VIEW', 'DASHBOARD.OWNER_VIEW', 'DASHBOARD.OWNER_SUMMARY']);
} else {
    require_role(['CRM', 'MPR', 'FIN', 'ACT', 'MANAGER', 'SYS', 'ADMIN', 'SUPERADMIN']);
}

$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo   = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$funnelFilter = array_filter(array_map('trim', explode(',', (string)($_GET['funnel'] ?? ''))));
$allowedFunnels = ['crm_leads', 'sales_do', 'reg_alkes', 'import'];
if (!empty($funnelFilter)) {
    $funnelFilter = array_intersect($funnelFilter, $allowedFunnels);
}
if (empty($funnelFilter)) {
    $funnelFilter = $allowedFunnels;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    ApiResponse::fail('Invalid date format. Use YYYY-MM-DD.', 'ERR_VALIDATION', 422);
}
if ($dateFrom > $dateTo) {
    ApiResponse::fail('date_from must be <= date_to.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();
    $funnels = [];

    // --- CRM Leads ---
    if (in_array('crm_leads', $funnelFilter, true)) {
        $stages = ['DRAFT' => 0, 'SUBMITTED' => 0, 'APPROVED' => 0, 'CLOSED' => 0, 'CANCELLED' => 0];
        $conv = ['DRAFT_to_SUBMITTED' => 0.0, 'SUBMITTED_to_APPROVED' => 0.0, 'APPROVED_to_CLOSED' => 0.0];
        $avgDays = ['DRAFT' => null, 'SUBMITTED' => null, 'APPROVED' => null];

        $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_leads'")->fetch();
        if ($chk) {
            $st = $pdo->prepare("SELECT status, COUNT(*) cnt FROM crm_leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status");
            $st->execute([$dateFrom, $dateTo]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $s = strtoupper((string)($r['status'] ?? ''));
                if (isset($stages[$s])) $stages[$s] = (int)$r['cnt'];
            }
            $total = array_sum($stages);
            $pastDraft = $stages['SUBMITTED'] + $stages['APPROVED'] + $stages['CLOSED'] + $stages['CANCELLED'];
            $conv['DRAFT_to_SUBMITTED'] = $total > 0 ? ($pastDraft / $total) * 100.0 : 0.0;
            $pastSubm = $stages['APPROVED'] + $stages['CLOSED'] + $stages['CANCELLED'];
            $conv['SUBMITTED_to_APPROVED'] = $pastDraft > 0 ? ($pastSubm / $pastDraft) * 100.0 : 0.0;
            $denom = $stages['APPROVED'] + $stages['CLOSED'] + $stages['CANCELLED'];
            $conv['APPROVED_to_CLOSED'] = $denom > 0 ? ($stages['CLOSED'] / $denom) * 100.0 : 0.0;

            $chkCol = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_leads' AND column_name='submitted_at'")->fetch();
            if ($chkCol) {
                $st = $pdo->prepare("SELECT AVG(DATEDIFF(COALESCE(submitted_at, NOW()), created_at)) FROM crm_leads WHERE status IN ('SUBMITTED','APPROVED','CLOSED','CANCELLED') AND submitted_at IS NOT NULL AND DATE(created_at) BETWEEN ? AND ?");
                $st->execute([$dateFrom, $dateTo]);
                $v = $st->fetchColumn();
                $avgDays['DRAFT'] = ($v !== null && $v !== '') ? round((float)$v, 1) : null;
            }
            $chkCol2 = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_leads' AND column_name='approved_at'")->fetch();
            if ($chkCol2) {
                $st = $pdo->prepare("SELECT AVG(DATEDIFF(COALESCE(approved_at, NOW()), submitted_at)) FROM crm_leads WHERE status IN ('APPROVED','CLOSED','CANCELLED') AND submitted_at IS NOT NULL AND approved_at IS NOT NULL AND DATE(created_at) BETWEEN ? AND ?");
                $st->execute([$dateFrom, $dateTo]);
                $v = $st->fetchColumn();
                $avgDays['SUBMITTED'] = ($v !== null && $v !== '') ? round((float)$v, 1) : null;
            }
            $chkCol3 = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_leads' AND column_name='closed_at'")->fetch();
            if ($chkCol3) {
                $st = $pdo->prepare("SELECT AVG(DATEDIFF(COALESCE(closed_at, cancelled_at, NOW()), approved_at)) FROM crm_leads WHERE status IN ('CLOSED','CANCELLED') AND approved_at IS NOT NULL AND DATE(created_at) BETWEEN ? AND ?");
                $st->execute([$dateFrom, $dateTo]);
                $v = $st->fetchColumn();
                $avgDays['APPROVED'] = ($v !== null && $v !== '') ? round((float)$v, 1) : null;
            }
        }

        $funnels['crm_leads'] = [
            'stages' => $stages,
            'conversion_rates' => $conv,
            'avg_days_per_stage' => $avgDays,
        ];
    }

    // --- Sales DO ---
    if (in_array('sales_do', $funnelFilter, true)) {
        $stages = ['CRM' => 0, 'WQS' => 0, 'SCM' => 0, 'ACT' => 0, 'FIN' => 0];
        $total = 0;
        $paid = 0;
        $toPaymentPct = 0.0;

        $chk = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_do' AND column_name='flow_status'")->fetch();
        $stageCol = $chk ? 'flow_status' : 'stage';
        $hasCol = false;
        foreach ($pdo->query("SHOW COLUMNS FROM sales_do") as $c) {
            if (($c['Field'] ?? '') === $stageCol) { $hasCol = true; break; }
        }
        if ($hasCol) {
            $st = $pdo->prepare("SELECT {$stageCol} AS stg, COUNT(*) cnt FROM sales_do WHERE do_date BETWEEN ? AND ? GROUP BY {$stageCol}");
            $st->execute([$dateFrom, $dateTo]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $s = (string)($r['stg'] ?? '');
                if ($s !== '' && isset($stages[$s])) {
                    $stages[$s] = (int)$r['cnt'];
                    $total += (int)$r['cnt'];
                }
            }
            $chkPaid = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_do' AND column_name='fin_paid_at'")->fetch();
            if ($chkPaid && $total > 0) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE do_date BETWEEN ? AND ? AND fin_paid_at IS NOT NULL");
                $st->execute([$dateFrom, $dateTo]);
                $paid = (int)$st->fetchColumn();
                $toPaymentPct = ($paid / $total) * 100.0;
            }
        }

        $funnels['sales_do'] = [
            'stages' => $stages,
            'total' => $total,
            'paid' => $paid,
            'to_payment_pct' => round($toPaymentPct, 1),
        ];
    }

    // --- Reg Alkes ---
    if (in_array('reg_alkes', $funnelFilter, true)) {
        $stages = [];
        for ($i = 1; $i <= 15; $i++) $stages[(string)$i] = 0;
        $total = 0;

        $avgDays = [];
        $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_cases'")->fetch();
        if ($chk) {
            $st = $pdo->query("SELECT stage_no, COUNT(*) cnt FROM hrl_reg_alkes_cases WHERE UPPER(COALESCE(status,'OPEN'))='OPEN' GROUP BY stage_no");
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $sn = (int)($r['stage_no'] ?? 0);
                if ($sn >= 1 && $sn <= 15) {
                    $stages[(string)$sn] = (int)$r['cnt'];
                    $total += (int)$r['cnt'];
                }
            }
            $chkLog = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_case_stage_log'")->fetch();
            if ($chkLog) {
                $stAvg = $pdo->query("SELECT stage_no, AVG(DATEDIFF(COALESCE(exited_at, NOW()), entered_at)) AS avg_days FROM hrl_reg_alkes_case_stage_log WHERE exited_at IS NOT NULL GROUP BY stage_no");
                while ($r = $stAvg->fetch(PDO::FETCH_ASSOC)) {
                    $sn = (int)($r['stage_no'] ?? 0);
                    if ($sn >= 1 && $sn <= 15 && $r['avg_days'] !== null) {
                        $avgDays[(string)$sn] = round((float)$r['avg_days'], 1);
                    }
                }
            }
        }

        $funnels['reg_alkes'] = [
            'stages' => $stages,
            'total' => $total,
            'avg_days_per_stage' => $avgDays,
        ];
    }

    // --- Import/PO ---
    if (in_array('import', $funnelFilter, true)) {
        $po = 0;
        $pib = 0;
        $gr = 0;
        $ap = 0;
        $value = null;

        $safeTable = function (PDO $p, string $t): bool {
            try {
                $st = $p->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
                $st->execute([$t]);
                return (bool)$st->fetch();
            } catch (Throwable $e) { return false; }
        };
        if ($safeTable($pdo, 'purchases_po')) {
            $st = $pdo->query("SELECT COALESCE(SUM(total_amount),0) v, COUNT(*) c FROM purchases_po WHERE deleted_at IS NULL AND status IN ('OPEN','IN_PRODUCTION','READY')");
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $po = (int)$r['c'];
                $value = (float)$r['v'];
            }
            if ($safeTable($pdo, 'purchases_ceisa_pib')) {
                $pib = (int)$pdo->query("SELECT COUNT(*) FROM purchases_ceisa_pib")->fetchColumn();
            }
            if ($safeTable($pdo, 'wqs_incoming')) {
                $gr = (int)$pdo->query("SELECT COUNT(DISTINCT po_code) FROM wqs_incoming")->fetchColumn();
            }
            if ($safeTable($pdo, 'purchases_invoice_ap')) {
                $ap = (int)$pdo->query("SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL")->fetchColumn();
            }
        }

        $funnels['import'] = [
            'stages' => ['PO' => $po, 'PIB' => $pib, 'GR' => $gr, 'AP' => $ap],
            'value' => $value,
        ];
    }

    ApiResponse::ok([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'funnels' => $funnels,
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to load funnels summary.', 'ERR_FUNNELS_SUMMARY', 500);
}
