<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_login();
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../dashboards/_manager_scope.php';
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.VIEW', 'DASHBOARD.SALES_VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','MANAGER','CRM','ACT','FIN','SCM','WQS','STAFF']);
}

date_default_timezone_set('Asia/Jakarta');

// ── DB ────────────────────────────────────────────────────────────────────
$pdo      = null;
$db_error = '';
try { $pdo = rmi_db_pdo(); } catch (Throwable $e) { $db_error = $e->getMessage(); }

// ── Filters ───────────────────────────────────────────────────────────────
$date_from      = (string)($_GET['date_from']      ?? date('Y-m-01'));
$date_to        = (string)($_GET['date_to']        ?? date('Y-m-d'));
$filter_status  = (string)($_GET['status']         ?? '');
$include_locked = (string)($_GET['include_locked'] ?? '1');

$scopeCtx   = ds_scope_ctx();
$department = (string)($_GET['department'] ?? '');

// CRM Dashboard adalah dashboard manager lintas office untuk flow O2C CRM -> WQS -> SCM -> ACT -> FIN.
// MGR CRM harus membaca populasi KPI yang sama dengan SYS. Scope office/dept otomatis tetap berlaku
// untuk user non-admin lain; filter department eksplisit dari GET tetap dihormati.
$sessionRole = strtoupper(trim((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));
$sessionLevel = strtoupper(trim((string)($_SESSION['level'] ?? $_SESSION['user_level'] ?? '')));
$sessionDept = strtoupper(trim((string)($_SESSION['dept'] ?? $_SESSION['department'] ?? $_SESSION['dept_code'] ?? '')));
$isCrmManager = ($sessionDept === 'CRM' && ($sessionRole === 'MANAGER' || $sessionLevel === 'MANAGER'));
$applyManagerScope = !$scopeCtx['is_admin'] && !$isCrmManager;

if ($applyManagerScope && $department === '' && $scopeCtx['department'] !== '') {
    $department = $scopeCtx['department'];
}

// ── sales_do.status → current operational stage ───────────────────────────
// Source of truth is sales_do.status. flow_status is intentionally NOT used here
// because historical rows currently contain flow_status='CRM' across all stages.
// Mapping is lowercase because DB status values are normalized with strtolower().
$STATUS_TO_STAGE = [
    'draft'          => 'CRM',

    // Once CRM sends the DO, responsibility moves to WQS.
    'crm_to_wqs'     => 'WQS',
    'sent_wqs'       => 'WQS',
    'wqs_processing' => 'WQS',
    'wqs_picked'     => 'WQS',
    'wqs_done'       => 'WQS',

    // READY_SCM and ON_DELIVERY are SCM responsibility.
    'ready_scm'      => 'SCM',
    'on_delivery'    => 'SCM',

    // After SCM completes delivery the next owner is ACT.
    'delivered'      => 'ACT',
    'scm_done'       => 'ACT',

    // ACT_DONE hands the document to FIN; WAIT_PAYMENT stays in FIN.
    'act_done'       => 'FIN',
    'wait_payment'   => 'FIN',
];

// Completed statuses are reported separately and must not inflate "DO Aktif".
$COMPLETED_STATUSES = ['paid','fin_done','closed'];
$CANCELLED_STATUSES = ['cancelled','void'];
$ACTIVE_STATUSES    = array_keys($STATUS_TO_STAGE);

// ── KPI defaults ─────────────────────────────────────────────────────────
$stage_counts   = ['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0];
$stage_revenue  = ['CRM'=>0.0,'WQS'=>0.0,'SCM'=>0.0,'ACT'=>0.0,'FIN'=>0.0];
$stage_overdue  = ['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0];
$salesDoTotal   = 0;
$salesDoPaid    = 0;
$salesDoRevenue = 0.0; // total grand_total of all DOs in period
$salesDoPaidRev = 0.0; // grand_total of paid DOs
$salesDoToPaymentPct    = 0.0;
$salesDoBottleneckStage = '';
$customers_transacted       = 0;
$customers_transacted_month = 0;

// CRM timing KPI. Leads/close-rate live in MPR Pipeline, not this operational DO dashboard.
$crmAvgDurationSec = null;
$crmMeasuredCount  = 0;
$crmMeasuredPct    = 0.0;

$topCustomers    = []; // [customers_code, customer_name, do_count, total_revenue]
$officeBreakdown = []; // [office_code, do_count, revenue, paid_count]
$monthlyTrend    = []; // [{month, label, do_count, revenue}] — 6 months

// ── Queries ───────────────────────────────────────────────────────────────
if ($pdo !== null) {
    try {
        // Schema detect
    $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM sales_do") as $r) {
            $cols[(string)$r['Field']] = true;
        }
        $has = static fn(string $c): bool => isset($cols[$c]);

        // Pipeline source-of-truth: sales_do.status. Never prefer flow_status here.
        $status_col = $has('status') ? 'status' : null;
        $stage_col  = $status_col; // kept as alias for the read-only reporting queries below
        $dep_col    = $has('department') ? 'department' : null;
        $locked_col = $has('locked') ? 'locked' : ($has('is_locked') ? 'is_locked' : null);
        $rev_col    = $has('grand_total') ? 'grand_total' : ($has('total_amount') ? 'total_amount' : null);

        if ($status_col === null) {
            throw new Exception("Kolom status tidak ditemukan di sales_do. Dashboard tidak mengubah workflow dan membutuhkan sales_do.status sebagai source of truth.");
        }

        $where  = "WHERE do_date BETWEEN :df AND :dt";
        $params = [':df' => $date_from, ':dt' => $date_to];

        if ($department !== '' && $dep_col !== null) {
      $where .= " AND {$dep_col} = :dep";
      $params[':dep'] = $department;
    }
    if ($applyManagerScope && $has('office_code') && $scopeCtx['office_code'] !== '') {
      $where .= " AND UPPER(COALESCE(office_code,'')) = :scope_office";
            $params[':scope_office'] = $scopeCtx['office_code'];
    }
        if ($filter_status !== '' && $status_col !== null) {
      $where .= " AND LOWER(COALESCE({$status_col},'')) = :st";
            $params[':st'] = strtolower(trim($filter_status));
    }
        if ($include_locked === '0' && $locked_col !== null) {
      $where .= " AND ({$locked_col} IS NULL OR {$locked_col}=0)";
    }

        // Read actual workflow status and map it to the department currently responsible.
        $revSelect = $rev_col ? ", COALESCE(SUM({$rev_col}),0) AS rev" : ", 0 AS rev";
        $st = $pdo->prepare("SELECT LOWER(TRIM(COALESCE({$status_col},''))) AS stg, COUNT(*) AS cnt {$revSelect} FROM sales_do {$where} GROUP BY LOWER(TRIM(COALESCE({$status_col},'')))");
        $st->execute($params);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rawStg = strtolower(trim((string)($row['stg'] ?? '')));
            $stage  = $STATUS_TO_STAGE[$rawStg] ?? null;
            $cnt    = (int)$row['cnt'];
            $rev    = (float)$row['rev'];

            // Active pipeline counts only statuses that still require operational action.
            if ($stage !== null && in_array($rawStg, $ACTIVE_STATUSES, true)) {
                $stage_counts[$stage]  += $cnt;
                $stage_revenue[$stage] += $rev;
            }

            // Total DO excludes cancelled/void but includes completed documents.
            if (!in_array($rawStg, $CANCELLED_STATUSES, true)) {
                $salesDoTotal   += $cnt;
                $salesDoRevenue += $rev;
            }
            if (in_array($rawStg, $COMPLETED_STATUSES, true)) {
                $salesDoPaid    += $cnt;
                $salesDoPaidRev += $rev;
            }
        }
        if ($salesDoTotal > 0) {
            $salesDoToPaymentPct = round($salesDoPaid / $salesDoTotal * 100.0, 1);
        }

        // Overdue / SLA — use the same operational concept as KPI DO (SLA):
        // current-stage age compared with configured SLA. This is read-only and does not
        // alter sales_do status or task workflow.
        $slaMinutes = [
            'CRM' => 30,
            'WQS' => 240,
            'SCM' => 1440,
            'ACT' => 2880,
            'FIN' => 10080,
        ];
        try {
            if (ds_table_exists($pdo, 'system_config')) {
                $cfgKeys = [
                    'CRM' => 'SLA_CRM_MINUTES',
                    'WQS' => 'SLA_WQS_MINUTES',
                    'SCM' => 'SLA_SCM_MINUTES',
                    'ACT' => 'SLA_ACT_MINUTES',
                    'FIN' => 'SLA_FIN_MINUTES',
                ];
                foreach ($cfgKeys as $dep => $cfgKey) {
                    $qCfg = $pdo->prepare("SELECT config_value FROM system_config WHERE config_group='KPI_DO_SLA' AND config_key=? AND is_active=1 AND office_code IS NULL ORDER BY id DESC LIMIT 1");
                    $qCfg->execute([$cfgKey]);
                    $v = $qCfg->fetchColumn();
                    if ($v !== false && $v !== null && $v !== '' && is_numeric($v) && (int)$v > 0) {
                        $slaMinutes[$dep] = (int)$v;
                    } else {
                        // Legacy KPI_SLA hour-based policy, if still in use.
                        $legacyKey = 'SLA_' . $dep . '_HOURS';
                        $qOld = $pdo->prepare("SELECT config_value FROM system_config WHERE config_group='KPI_SLA' AND config_key=? AND is_active=1 AND office_code IS NULL ORDER BY id DESC LIMIT 1");
                        $qOld->execute([$legacyKey]);
                        $h = $qOld->fetchColumn();
                        if ($h !== false && $h !== null && $h !== '' && is_numeric($h) && (float)$h > 0) {
                            $slaMinutes[$dep] = max(1, (int)round((float)$h * 60));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Keep safe defaults above.
        }

        $toTs = static function ($dt): int {
            if ($dt === null || $dt === '') return 0;
            $t = strtotime((string)$dt);
            return $t ? (int)$t : 0;
        };
        $pickFirstAudit = static function (array $auditFirst, array $statuses): int {
            foreach ($statuses as $st) {
                if (!empty($auditFirst[$st])) return (int)$auditFirst[$st];
            }
            return 0;
        };

        try {
            $activeRowsSql = "SELECT * FROM sales_do {$where} AND LOWER(TRIM(COALESCE({$status_col},''))) IN ("
                . implode(',', array_fill(0, count($ACTIVE_STATUSES), '?')) . ")";
            $activeParams = array_values($params);
            // Named parameters cannot be mixed with positional placeholders, so rebuild safely.
            $activeWhere = "WHERE do_date BETWEEN ? AND ?";
            $activeBind = [$date_from, $date_to];
            if ($department !== '' && $dep_col !== null) {
                $activeWhere .= " AND {$dep_col} = ?";
                $activeBind[] = $department;
            }
            if ($applyManagerScope && $has('office_code') && $scopeCtx['office_code'] !== '') {
                $activeWhere .= " AND UPPER(COALESCE(office_code,'')) = ?";
                $activeBind[] = $scopeCtx['office_code'];
            }
            if ($filter_status !== '') {
                $activeWhere .= " AND LOWER(COALESCE({$status_col},'')) = ?";
                $activeBind[] = strtolower(trim($filter_status));
            }
            if ($include_locked === '0' && $locked_col !== null) {
                $activeWhere .= " AND ({$locked_col} IS NULL OR {$locked_col}=0)";
            }
            $inActive = implode(',', array_fill(0, count($ACTIVE_STATUSES), '?'));
            $qActive = $pdo->prepare("SELECT * FROM sales_do {$activeWhere} AND LOWER(TRIM(COALESCE({$status_col},''))) IN ({$inActive})");
            $qActive->execute(array_merge($activeBind, $ACTIVE_STATUSES));
            $activeRows = $qActive->fetchAll(PDO::FETCH_ASSOC);

            $auditByDo = [];
            if ($activeRows && ds_table_exists($pdo, 'sales_do_audit')) {
                $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $activeRows)));
                if ($ids) {
                    $inIds = implode(',', array_fill(0, count($ids), '?'));
                    $qAudit = $pdo->prepare("SELECT do_id, LOWER(TRIM(COALESCE(status_to,''))) AS status_to, created_at FROM sales_do_audit WHERE do_id IN ({$inIds}) ORDER BY created_at ASC, id ASC");
                    $qAudit->execute($ids);
                    while ($a = $qAudit->fetch(PDO::FETCH_ASSOC)) {
                        $did = (int)($a['do_id'] ?? 0);
                        $stTo = (string)($a['status_to'] ?? '');
                        if (!$did || $stTo === '') continue;
                        if (!isset($auditByDo[$did][$stTo])) {
                            $auditByDo[$did][$stTo] = $toTs($a['created_at'] ?? null);
                        }
                    }
                }
            }

            $nowTs = time();
            foreach ($activeRows as $d) {
                $rawStatus = strtolower(trim((string)($d[$status_col] ?? '')));
                $stage = $STATUS_TO_STAGE[$rawStatus] ?? null;
                if ($stage === null) continue;
                $auditFirst = $auditByDo[(int)($d['id'] ?? 0)] ?? [];

                $doDateTs = $toTs(((string)($d['do_date'] ?? $date_from)) . ' 00:00:00');
                $createdTs = $has('created_at') ? $toTs($d['created_at'] ?? null) : 0;
                $crmStart = $has('crm_order_received_at') ? $toTs($d['crm_order_received_at'] ?? null) : 0;
                if (!$crmStart && $has('crm_start_at')) $crmStart = $toTs($d['crm_start_at'] ?? null);
                if (!$crmStart) $crmStart = $createdTs ?: $doDateTs;

                $wqsStart = 0;
                foreach (['wqs_started_at','wqs_picked_at'] as $c) if (!$wqsStart && $has($c)) $wqsStart = $toTs($d[$c] ?? null);
                if (!$wqsStart) $wqsStart = $pickFirstAudit($auditFirst, ['crm_to_wqs','wqs_processing','wqs_picked']);

                $scmStart = 0;
                if ($has('scm_ready_at')) $scmStart = $toTs($d['scm_ready_at'] ?? null);
                if (!$scmStart) $scmStart = $pickFirstAudit($auditFirst, ['ready_scm','on_delivery']);

                $actStart = 0;
                if ($has('scm_delivered_at')) $actStart = $toTs($d['scm_delivered_at'] ?? null);
                if (!$actStart) $actStart = $pickFirstAudit($auditFirst, ['delivered','scm_done']);

                $finStart = 0;
                foreach (['wait_payment_at','act_ready_fin_at','act_invoiced_at'] as $c) if (!$finStart && $has($c)) $finStart = $toTs($d[$c] ?? null);
                if (!$finStart) $finStart = $pickFirstAudit($auditFirst, ['act_done','wait_payment','act_ready_fin','act_invoiced']);

                $startTs = $crmStart;
                if ($stage === 'WQS') $startTs = $wqsStart ?: $pickFirstAudit($auditFirst, ['crm_to_wqs']) ?: $createdTs ?: $doDateTs;
                if ($stage === 'SCM') $startTs = $scmStart ?: $wqsStart ?: $createdTs ?: $doDateTs;
                if ($stage === 'ACT') $startTs = $actStart ?: $scmStart ?: $createdTs ?: $doDateTs;
                if ($stage === 'FIN') $startTs = $finStart ?: $actStart ?: $createdTs ?: $doDateTs;
                if (!$startTs) $startTs = $doDateTs;

                $ageSec = max(0, $nowTs - $startTs);
                $slaSec = max(1, (int)($slaMinutes[$stage] ?? 1440)) * 60;
                if ($ageSec > $slaSec) $stage_overdue[$stage]++;
            }
        } catch (Throwable $e) {
            // Do not break the dashboard. If SLA history is unavailable, keep overdue at zero.
            $stage_overdue = ['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0];
        }

        // Bottleneck after SLA calculation.
        $maxOd = 0;
        foreach ($stage_overdue as $stg => $od) {
            if ($od > $maxOd) { $maxOd = $od; $salesDoBottleneckStage = $stg; }
        }

        // CRM duration quality KPI (read-only).
        // Prefer stored crm_duration_sec; for legacy rows, fall back to timestamp difference.
        try {
            $crmDurationExpr = null;
            if ($has('crm_duration_sec')) {
                $crmDurationExpr = "CASE WHEN crm_duration_sec > 0 THEN crm_duration_sec END";
            }

            $crmStartCol = $has('crm_order_received_at') ? 'crm_order_received_at'
                         : ($has('crm_start_at') ? 'crm_start_at' : null);
            $crmFinishCol = $has('crm_finish_at') ? 'crm_finish_at'
                          : ($has('crm_created_at') ? 'crm_created_at'
                          : ($has('created_at') ? 'created_at' : null));

            if ($crmStartCol !== null && $crmFinishCol !== null) {
                $fallback = "CASE WHEN {$crmStartCol} IS NOT NULL AND {$crmFinishCol} IS NOT NULL AND {$crmFinishCol} >= {$crmStartCol} THEN TIMESTAMPDIFF(SECOND, {$crmStartCol}, {$crmFinishCol}) END";
                $crmDurationExpr = $crmDurationExpr !== null
                    ? "COALESCE({$crmDurationExpr}, {$fallback})"
                    : $fallback;
            }

            if ($crmDurationExpr !== null) {
                $qCrm = $pdo->prepare("SELECT COUNT(*) AS measured_count, AVG(duration_sec) AS avg_sec FROM (SELECT {$crmDurationExpr} AS duration_sec FROM sales_do {$where}) x WHERE duration_sec IS NOT NULL AND duration_sec > 0");
                $qCrm->execute($params);
                $crmRow = $qCrm->fetch(PDO::FETCH_ASSOC) ?: [];
                $crmMeasuredCount = (int)($crmRow['measured_count'] ?? 0);
                $crmAvgDurationSec = $crmRow['avg_sec'] !== null ? (float)$crmRow['avg_sec'] : null;
                $crmMeasuredPct = $salesDoTotal > 0 ? round(($crmMeasuredCount / $salesDoTotal) * 100.0, 1) : 0.0;
            }
        } catch (Throwable) {
            $crmAvgDurationSec = null;
            $crmMeasuredCount = 0;
            $crmMeasuredPct = 0.0;
        }

        // Customers — source mengikuti populasi DO dashboard yang valid.
        // Hitung customer eksternal unik dari sales_do; cancelled/void dan kode internal *-INT
        // tidak boleh menaikkan KPI Customer.
        try {
            $cust_col = $has('customers_code') ? 'customers_code'
                      : ($has('customer_code') ? 'customer_code'
                      : ($has('customer_id')   ? 'customer_id' : null));
            if ($cust_col !== null) {
                $customerWhere = $where
                    . " AND {$cust_col} IS NOT NULL"
                    . " AND TRIM({$cust_col}) != ''"
                    . " AND UPPER(TRIM({$cust_col})) NOT LIKE '%-INT'"
                    . " AND LOWER(COALESCE({$status_col},'')) NOT IN ('cancelled','void')";

                $stC = $pdo->prepare("SELECT COUNT(DISTINCT TRIM({$cust_col})) FROM sales_do {$customerWhere}");
                $stC->execute($params);
                $customers_transacted = (int)$stC->fetchColumn();

                // Sub-label harus mengikuti bulan dari akhir periode dashboard, bukan CURDATE(),
                // agar tidak menampilkan angka bulan yang berbeda dari filter yang sedang dilihat.
                $monthStart = date('Y-m-01', strtotime($date_to));
                $monthEnd   = date('Y-m-t', strtotime($date_to));
                $monthWhere = "WHERE do_date BETWEEN :mdf AND :mdt"
                    . " AND {$cust_col} IS NOT NULL"
                    . " AND TRIM({$cust_col}) != ''"
                    . " AND UPPER(TRIM({$cust_col})) NOT LIKE '%-INT'"
                    . " AND LOWER(COALESCE({$status_col},'')) NOT IN ('cancelled','void')";

                if ($applyManagerScope && $has('office_code') && $scopeCtx['office_code'] !== '') {
                    $monthWhere .= " AND UPPER(COALESCE(office_code,'')) = :scope_office";
                }

                $monthParams = [':mdf'=>$monthStart, ':mdt'=>$monthEnd];
                if (isset($params[':scope_office'])) $monthParams[':scope_office'] = $params[':scope_office'];

                $stM = $pdo->prepare("SELECT COUNT(DISTINCT TRIM({$cust_col})) FROM sales_do {$monthWhere}");
                $stM->execute($monthParams);
                $customers_transacted_month = (int)$stM->fetchColumn();
            }
        } catch (Throwable) {}

        // ── Top 5 customers by revenue ────────────────────────────────────
        try {
            if ($cust_col !== null && $rev_col !== null) {
                $custNameCol = 'sd.' . $cust_col;
                $custJoin    = '';
                if (function_exists('ds_scalar') && $has('office_code')) {
                    // Try join master_customers for name
                    try {
                        $pdo->query("SELECT 1 FROM master_customers LIMIT 1");
                        $custJoin    = "LEFT JOIN master_customers mc ON mc.customers_code = sd.{$cust_col}";
                        $custNameCol = "COALESCE(NULLIF(mc.customers_name,''), mc.name, sd.{$cust_col})";
                    } catch (Throwable) { $custJoin = ''; $custNameCol = 'sd.' . $cust_col; }
                }
                $topSt = $pdo->prepare("
                    SELECT sd.{$cust_col} AS code,
                           {$custNameCol} AS name,
                           COUNT(*) AS do_count,
                           COALESCE(SUM(sd.{$rev_col}),0) AS total_rev
                    FROM sales_do sd
                    {$custJoin}
                    WHERE sd.do_date BETWEEN :df AND :dt
                      AND sd.{$cust_col} IS NOT NULL AND TRIM(sd.{$cust_col}) <> ''
                      AND LOWER(TRIM(COALESCE(sd.{$status_col},''))) NOT IN ('cancelled','void')
                      /* Canonical family separation: widget Top 5 hanya BMHP.
                         Unit ACC memakai family UNITACC-* dan tidak boleh masuk
                         ke pencapaian/nilai BMHP. Perubahan ini hanya memfilter
                         sumber widget; workflow CRM -> WQS -> SCM -> ACT -> FIN
                         dan KPI dashboard lain tetap tidak berubah. */
                      AND UPPER(TRIM(COALESCE(sd.do_code,''))) LIKE 'BMHP-%'
                      /* Top Customer = customer eksternal saja. */
                      AND UPPER(TRIM(COALESCE(sd.{$cust_col},''))) NOT LIKE '%-INT'
                    GROUP BY sd.{$cust_col}, name
                    ORDER BY total_rev DESC
                    LIMIT 5
                ");
                $topSt->execute([':df'=>$date_from,':dt'=>$date_to]);
                $topCustomers = $topSt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable) {}

        // ── Office breakdown: BMHP external only ──────────────────────────
        try {
            if ($has('office_code') && $rev_col !== null) {
                $paidIn = implode(',', array_map(fn($s) => "'".addslashes($s)."'", ['paid','fin_done','closed']));

                // Samakan populasi Per Office dengan Top 5 Customer:
                // hanya DO family BMHP yang valid dan bukan customer internal *-INT.
                // Filter ini hanya mempengaruhi widget agregasi; workflow/status DO tidak diubah.
                $internalFilter = '';
                if ($cust_col !== null) {
                    $internalFilter = "
                      AND (
                            {$cust_col} IS NULL
                            OR TRIM({$cust_col}) = ''
                            OR UPPER(TRIM({$cust_col})) NOT LIKE '%-INT'
                          )";
                }

                $offSt  = $pdo->prepare("
                    SELECT UPPER(TRIM(office_code)) AS office_code,
                           COUNT(*) AS do_count,
                           COALESCE(SUM({$rev_col}),0) AS revenue,
                           SUM(CASE WHEN LOWER(TRIM(COALESCE({$status_col},''))) IN ({$paidIn}) THEN 1 ELSE 0 END) AS paid_count
                    FROM sales_do
                    WHERE do_date BETWEEN :df AND :dt
                      AND LOWER(TRIM(COALESCE({$status_col},''))) NOT IN ('cancelled','void')
                      AND UPPER(TRIM(COALESCE(do_code,''))) LIKE 'BMHP-%'
                      AND office_code IS NOT NULL
                      AND TRIM(office_code) <> ''
                      {$internalFilter}
                    GROUP BY UPPER(TRIM(office_code))
                    ORDER BY revenue DESC, do_count DESC
                    LIMIT 10
                ");
                $offSt->execute([':df'=>$date_from,':dt'=>$date_to]);
                $officeBreakdown = $offSt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable) {}

        // ── Monthly trend (6 months) ──────────────────────────────────────
        try {
            if ($rev_col !== null) {
                $trendSt = $pdo->prepare("
                    SELECT DATE_FORMAT(do_date,'%Y-%m') AS m,
                           COUNT(*) AS do_count,
                           COALESCE(SUM({$rev_col}),0) AS revenue
                    FROM sales_do
                    WHERE do_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
                      AND LOWER(COALESCE({$status_col},'')) NOT IN ('cancelled','void')
                    GROUP BY DATE_FORMAT(do_date,'%Y-%m')
                    ORDER BY m ASC
                ");
                $trendSt->execute();
                $trendRaw = [];
                while ($tr = $trendSt->fetch(PDO::FETCH_ASSOC)) {
                    $trendRaw[$tr['m']] = $tr;
                }
                // Fill all 6 months (including those with 0)
                for ($i = 5; $i >= 0; $i--) {
                    $m   = date('Y-m', strtotime("-{$i} month"));
                    $lbl = date('M Y', strtotime("-{$i} month"));
                    $monthlyTrend[] = [
                        'month'    => $m,
                        'label'    => $lbl,
                        'do_count' => (int)($trendRaw[$m]['do_count'] ?? 0),
                        'revenue'  => (float)($trendRaw[$m]['revenue'] ?? 0),
                    ];
                }
            }
        } catch (Throwable) {}

        // CRM Leads / conversion intentionally remain in MPR Pipeline.
        // This dashboard reports operational DO performance only.

    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}

// ── Derived values ────────────────────────────────────────────────────────
$totalOverdue    = (int)array_sum($stage_overdue);
$totalActive     = (int)array_sum($stage_counts);
$totalRevActive  = array_sum($stage_revenue);
$export_url      = 'export_kpi_do_csv.php?' . http_build_query(compact('date_from','date_to','include_locked') + ['department'=>$department,'status'=>$filter_status]);

function sd_money(float $v): string {
    if ($v >= 1e9)  return 'Rp ' . number_format($v / 1e9, 1, ',', '.') . 'M';
    if ($v >= 1e6)  return 'Rp ' . number_format($v / 1e6, 1, ',', '.') . 'jt';
    if ($v >= 1000) return 'Rp ' . number_format($v / 1000, 0, ',', '.') . 'rb';
    return 'Rp ' . number_format($v, 0, ',', '.');
}

function sd_duration(?float $sec): string {
    if ($sec === null || $sec <= 0) return '—';
    $s = (int)round($sec);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $r = $s % 60;
    if ($h > 0) return $h . ' jam ' . $m . ' menit';
    if ($m > 0) return $m . ' menit ' . $r . ' detik';
    return $r . ' detik';
}

// ── Stage config ──────────────────────────────────────────────────────────
$stageConfig = [
    'CRM' => ['icon'=>'💼','color'=>'#3b82f6','label'=>'CRM',   'desc'=>'Input & approval DO',         'url'=>'sales_do.php',            'tasks_url'=>null],
    'WQS' => ['icon'=>rmi_icon('box'),'color'=>'#f97316','label'=>'WQS',   'desc'=>'Picking & packing',            'url'=>'../stock/wqs_picking.php', 'tasks_url'=>'../stock/wqs_do_tasks.php'],
    'SCM' => ['icon'=>'🚢','color'=>'#06b6d4','label'=>'SCM',   'desc'=>'Logistik & forwarding',        'url'=>'scm_do_tasks.php',         'tasks_url'=>'scm_do_tasks.php'],
    'ACT' => ['icon'=>rmi_icon('memo'),'color'=>'#f59e0b','label'=>'ACT',   'desc'=>'Accounting & verifikasi',      'url'=>'act_do_tasks.php',         'tasks_url'=>'act_do_tasks.php'],
    'FIN' => ['icon'=>rmi_icon('money'),'color'=>'#22c55e','label'=>'FIN',   'desc'=>'Invoice & payment',            'url'=>'fin_do_tasks.php',         'tasks_url'=>'fin_do_tasks.php'],
];

// ── View ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../_shared/rmi_layout.php';
$bp  = rtrim((string)(rmi_layout_base_project() ?? ''), '/');
$pfx = $bp !== '' ? $bp . '/' : '../';

$actions = [
    ['label' => rmi_icon('books') . ' Panduan',          'url' => 'panduan.php',                 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => rmi_icon('tower') . ' Control Tower',    'url' => 'sales_control_tower.php',    'class' => 'btn btn-sm btn-outline-light'],
    ['label' => rmi_icon('target') . ' MPR Pipeline',     'url' => '../mpr/mpr_pipeline.php',    'class' => 'btn btn-sm btn-outline-light'],
    ['label' => rmi_icon('receipt') . ' Tax Invoice',      'url' => 'tax_invoices.php',           'class' => 'btn btn-sm btn-outline-light'],
    ['label' => rmi_icon('chart') . ' KPI / SLA',        'url' => '../kpi/kpi_do_sla.php',      'class' => 'btn btn-sm btn-outline-light'],
    ['label' => '⬇ Export CSV',        'url' => $export_url,                  'class' => 'btn btn-sm btn-outline-light'],
];

// Chart data (JSON)
$chartLabels  = json_encode(array_column($monthlyTrend, 'label'));
$chartRevenue = json_encode(array_map(fn($r) => round($r['revenue'] / 1e6, 2), $monthlyTrend));
$chartDoCnt   = json_encode(array_column($monthlyTrend, 'do_count'));

rmi_header('Sales Dashboard', [
    'active'      => 'sales',
    'subtitle'    => 'Pipeline: CRM → WQS → SCM → ACT → FIN',
    'breadcrumbs' => [['label' => 'CRM', 'url' => 'index.php'], 'Dashboard'],
    'actions'     => $actions,
    'extra_head'  => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script><style>
/* ── KPI Summary ── */
.sd-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:18px}
.sd-kpi{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;border-top:3px solid var(--kc,#3b82f6);padding:14px 16px;text-decoration:none;display:block;transition:transform .15s,box-shadow .15s}
.sd-kpi:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.3)}
.sd-kpi-icon{font-size:18px;margin-bottom:6px}.sd-kpi-val{font-size:22px;font-weight:800;color:var(--rmi-text);line-height:1}
.sd-kpi-lbl{font-size:10px;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
.sd-kpi-sub{font-size:10px;color:var(--rmi-muted,#9ca3af);margin-top:2px}
.sd-kpi-rev{font-size:11px;font-weight:700;margin-top:3px}
/* ── Filter bar ── */
.sd-filter{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;padding:14px 18px;margin-bottom:18px}
.sd-filter-title{font-size:11px;font-weight:700;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px}
.sd-quick-btn{padding:4px 11px;border-radius:6px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:#94a3b8;font-size:11px;font-weight:600;cursor:pointer;text-decoration:none;transition:.12s;display:inline-block}
.sd-quick-btn:hover,.sd-quick-btn.active{background:rgba(96,165,250,.15);border-color:rgba(96,165,250,.4);color:#60a5fa}
/* ── Pipeline bar ── */
.sd-pipeline{display:flex;align-items:center;gap:4px;flex-wrap:nowrap;overflow-x:auto;background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;padding:14px 20px;margin-bottom:18px;scrollbar-width:thin}
.sd-pipe-step{flex:1;min-width:80px;text-align:center}
.sd-pipe-badge{padding:7px 10px;border-radius:10px;font-size:12px;font-weight:700;white-space:nowrap;display:flex;align-items:center;justify-content:center;gap:5px}
.sd-pipe-count{font-size:11px;color:var(--rmi-muted,#9ca3af);margin-top:5px}
.sd-pipe-rev{font-size:10px;color:#67e8f9;margin-top:2px;font-weight:700}
.sd-pipe-od{font-size:10px;color:#f87171;margin-top:2px}
.sd-pipe-arrow{color:rgba(255,255,255,.2);font-size:14px;flex-shrink:0;padding:0 2px}
/* ── Stage cards ── */
.sd-stage{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;border-top:4px solid var(--sc,#3b82f6);padding:18px;height:100%;transition:transform .15s,box-shadow .15s}
.sd-stage:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3)}
.sd-stage-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:14px}
.sd-stage-icon{font-size:24px;line-height:1}.sd-stage-name{font-size:14px;font-weight:800;color:#f1f5f9}
.sd-stage-desc{font-size:11px;color:var(--rmi-muted,#9ca3af)}
.sd-stage-count{font-size:36px;font-weight:900;line-height:1;margin-bottom:2px}
.sd-stage-lbl{font-size:10px;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.4px}
.sd-stage-rev{font-size:12px;font-weight:700;color:#67e8f9;margin-bottom:4px}
.sd-badge-ok{background:rgba(34,197,94,.15);color:#4ade80;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700}
.sd-badge-od{background:rgba(239,68,68,.15);color:#f87171;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700}
.sd-badge-btn{background:rgba(245,158,11,.15);color:#fbbf24;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700}
/* ── CRM Leads funnel ── */
.sd-funnel-row{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.sd-funnel-label{font-size:12px;font-weight:600;width:80px;flex-shrink:0}
.sd-funnel-bar{flex:1;height:10px;background:rgba(255,255,255,.08);border-radius:20px;overflow:hidden}
.sd-funnel-fill{height:100%;border-radius:20px;transition:width .4s}
.sd-funnel-count{font-size:12px;font-weight:600;min-width:36px;text-align:right;flex-shrink:0}
.sd-funnel-pct{font-size:10px;color:var(--rmi-muted,#9ca3af);min-width:36px;text-align:right;flex-shrink:0}
/* ── Conversion row ── */
.sd-conv{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.sd-conv-item{flex:1;min-width:80px;background:rgba(255,255,255,.04);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:10px;padding:8px 10px;text-align:center}
.sd-conv-val{font-size:16px;font-weight:800;color:var(--rmi-text)}.sd-conv-lbl{font-size:9px;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.3px;margin-top:2px}
/* ── Quick links ── */
.sd-links{display:flex;flex-wrap:wrap;gap:8px}
.sd-link{padding:7px 14px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid var(--rmi-border,rgba(255,255,255,.12));color:#e2e8f0;background:rgba(255,255,255,.05);transition:all .15s;display:inline-flex;align-items:center;gap:5px}
.sd-link:hover{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25)}
.sd-link.primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);border-color:transparent;box-shadow:0 3px 10px rgba(37,99,235,.3)}
.sd-link.primary:hover{filter:brightness(1.1)}
/* ── Chart ── */
.sd-chart-wrap{position:relative;height:200px}
/* ── DO stat ── */
.sd-do-stat{text-align:center;flex:1}.sd-do-stat-val{font-size:22px;font-weight:900;color:var(--rmi-text)}.sd-do-stat-lbl{font-size:10px;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.4px}
/* ── Top customer / office table ── */
.sd-mini-table{width:100%;border-collapse:collapse;font-size:12px}
.sd-mini-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted,#9ca3af);padding:6px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left}
.sd-mini-table td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.04)}
.sd-mini-table tr:last-child td{border-bottom:none}
.sd-mini-table tr:hover td{background:rgba(255,255,255,.02)}
</style>',
]);
?>

<?php
// Manager Controlling Staff sengaja tidak ditampilkan pada CRM Dashboard.
// Tidak ada perubahan pada source KPI, workflow sales_do, RBAC, SLA, atau proses CRM → WQS → SCM → ACT → FIN.
?>

<?php if ($db_error !== ''): ?>
<div class="alert alert-danger mb-3">
  <strong><?= rmi_icon('warn') ?> Database Error</strong><br><small><?= rmi_h($db_error) ?></small>
</div>
<?php endif; ?>

<!-- ══ Filter ══ -->
<div class="sd-filter">
  <div class="sd-filter-title"><?= rmi_icon('search') ?> Filter</div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">

    <!-- Quick period buttons -->
    <div>
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted,#9ca3af);margin-bottom:5px">Periode Cepat</div>
      <div style="display:flex;gap:4px;flex-wrap:wrap">
        <?php
        $periods = [
          'Hari Ini'    => [date('Y-m-d'), date('Y-m-d')],
          'Minggu Ini'  => [date('Y-m-d',strtotime('monday this week')), date('Y-m-d')],
          'Bulan Ini'   => [date('Y-m-01'), date('Y-m-d')],
          'Bulan Lalu'  => [date('Y-m-01',strtotime('first day of last month')), date('Y-m-t',strtotime('last day of last month'))],
          'Tahun Ini'   => [date('Y-01-01'), date('Y-m-d')],
        ];
        foreach ($periods as $lbl => [$pf,$pt]):
          $isActive = ($date_from===$pf && $date_to===$pt);
        ?>
          <a class="sd-quick-btn <?= $isActive?'active':'' ?>"
             href="?date_from=<?= urlencode($pf) ?>&date_to=<?= urlencode($pt) ?>"><?= rmi_h($lbl) ?></a>
        <?php endforeach; ?>
    </div>
    </div>

    <form class="d-flex flex-wrap gap-2 align-items-end" method="get" style="flex:1">
      <div>
        <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted,#9ca3af);display:block;margin-bottom:3px">Dari</label>
        <input class="form-control form-control-sm" type="date" name="date_from" value="<?= rmi_h($date_from) ?>" style="width:130px">
    </div>
      <div>
        <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted,#9ca3af);display:block;margin-bottom:3px">Sampai</label>
        <input class="form-control form-control-sm" type="date" name="date_to" value="<?= rmi_h($date_to) ?>" style="width:130px">
    </div>
      <div>
        <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted,#9ca3af);display:block;margin-bottom:3px">Status</label>
        <select class="form-select form-select-sm" name="status" style="width:130px">
          <option value="">Semua</option>
          <?php foreach (['draft','crm_to_wqs','wqs_processing','wqs_picked','wqs_done','ready_scm','on_delivery','delivered','act_done','wait_payment','paid','fin_done','closed','cancelled'] as $s): ?>
            <option value="<?= rmi_h($s) ?>" <?= $filter_status===$s?'selected':'' ?>><?= rmi_h($s) ?></option>
          <?php endforeach; ?>
      </select>
    </div>
      <div class="d-flex gap-2 align-items-end">
        <button class="btn btn-rmi btn-sm" type="submit">Terapkan</button>
        <a class="btn btn-ghost btn-sm" href="sales_dashboard.php">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- ══ KPI Summary ══ -->
<div class="sd-kpi-grid">
  <a class="sd-kpi" href="sales_do.php" style="--kc:#3b82f6">
    <div class="sd-kpi-icon"><?= rmi_icon('clipboard') ?></div>
    <div class="sd-kpi-val"><?= number_format($totalActive) ?></div>
    <div class="sd-kpi-lbl">DO Aktif</div>
    <?php if ($totalRevActive > 0): ?><div class="sd-kpi-rev" style="color:#67e8f9"><?= sd_money($totalRevActive) ?></div><?php endif; ?>
  </a>
  <a class="sd-kpi" href="sales_do.php" style="--kc:#6366f1">
    <div class="sd-kpi-icon">🗂</div>
    <div class="sd-kpi-val"><?= number_format($salesDoTotal) ?></div>
    <div class="sd-kpi-lbl">Total DO</div>
    <?php if ($salesDoRevenue > 0): ?><div class="sd-kpi-rev" style="color:#a5b4fc"><?= sd_money($salesDoRevenue) ?></div><?php endif; ?>
  </a>
  <a class="sd-kpi" href="sales_do.php?status=paid" style="--kc:#22c55e">
    <div class="sd-kpi-icon"><?= rmi_icon('check') ?></div>
    <div class="sd-kpi-val"><?= number_format($salesDoPaid) ?></div>
    <div class="sd-kpi-lbl">DO Paid</div>
    <?php if ($salesDoPaidRev > 0): ?><div class="sd-kpi-rev" style="color:#4ade80"><?= sd_money($salesDoPaidRev) ?></div><?php endif; ?>
    <?php if ($salesDoToPaymentPct > 0): ?><div class="sd-kpi-sub"><?= number_format($salesDoToPaymentPct,1) ?>% dari total</div><?php endif; ?>
  </a>
  <a class="sd-kpi" href="sales_do.php" style="--kc:#8b5cf6">
    <div class="sd-kpi-icon">⏱</div>
    <div class="sd-kpi-val" style="font-size:18px"><?= rmi_h(sd_duration($crmAvgDurationSec)) ?></div>
    <div class="sd-kpi-lbl">Avg Durasi CRM</div>
    <div class="sd-kpi-sub">Order customer → DO selesai dibuat</div>
  </a>
  <a class="sd-kpi" href="sales_do.php" style="--kc:#f59e0b">
    <div class="sd-kpi-icon">🧭</div>
    <div class="sd-kpi-val"><?= number_format($crmMeasuredCount) ?></div>
    <div class="sd-kpi-lbl">DO Terukur CRM</div>
    <div class="sd-kpi-sub"><?= number_format($crmMeasuredPct,1) ?>% dari Total DO periode</div>
  </a>
  <a class="sd-kpi" href="sales_do.php" style="--kc:<?= $totalOverdue>0?'#ef4444':'#22c55e' ?>">
    <div class="sd-kpi-icon"><?= $totalOverdue>0?rmi_icon('warn'):rmi_icon('tick') ?></div>
    <div class="sd-kpi-val"><?= number_format($totalOverdue) ?></div>
    <div class="sd-kpi-lbl">Overdue</div>
    <?php if ($salesDoBottleneckStage!=='' && $totalOverdue>0): ?>
      <div class="sd-kpi-sub">BN: <?= rmi_h($salesDoBottleneckStage) ?></div>
    <?php endif; ?>
  </a>
  <a class="sd-kpi" href="<?= rmi_h($pfx) ?>master/master_customers.php" style="--kc:#10b981">
    <div class="sd-kpi-icon">🤝</div>
    <div class="sd-kpi-val"><?= number_format($customers_transacted) ?></div>
    <div class="sd-kpi-lbl">Customer</div>
    <div class="sd-kpi-sub">Bln ini: <b><?= number_format($customers_transacted_month) ?></b></div>
  </a>
</div>

<!-- ══ Pipeline Flow Bar ══ -->
<div class="sd-pipeline">
  <?php foreach ($stageConfig as $stg => $cfg):
    $cnt = $stage_counts[$stg];
    $od  = $stage_overdue[$stg];
    $rev = $stage_revenue[$stg];
  ?>
    <div class="sd-pipe-step">
      <a class="sd-pipe-badge" href="<?= rmi_h($cfg['url']) ?>" style="background:<?= $cfg['color'] ?>18;border:1px solid <?= $cfg['color'] ?>40;color:<?= $cfg['color'] ?>;text-decoration:none">
        <?= $cfg['icon'] ?> <?= $stg ?>
      </a>
      <div class="sd-pipe-count"><?= number_format($cnt) ?> DO</div>
      <?php if ($rev > 0): ?><div class="sd-pipe-rev"><?= sd_money($rev) ?></div><?php endif; ?>
      <?php if ($od > 0): ?><div class="sd-pipe-od"><?= rmi_icon('warn') ?> <?= number_format($od) ?> OD</div><?php endif; ?>
    </div>
    <?php if ($stg !== 'FIN'): ?><div class="sd-pipe-arrow">›</div><?php endif; ?>
  <?php endforeach; ?>
</div>

<!-- ══ Main Content Grid ══ -->
<div class="row g-3">

  <!-- Stage cards -->
  <?php foreach ($stageConfig as $stg => $cfg):
    $cnt = $stage_counts[$stg];
    $od  = $stage_overdue[$stg];
    $rev = $stage_revenue[$stg];
    $isBottleneck = ($salesDoBottleneckStage === $stg && $od > 0);
  ?>
  <div class="col-sm-6 col-xl-4">
    <div class="sd-stage" style="--sc:<?= $cfg['color'] ?>">
      <div class="sd-stage-head">
          <div>
          <div class="sd-stage-name"><?= $cfg['icon'] ?> <?= rmi_h($cfg['label']) ?></div>
          <div class="sd-stage-desc"><?= rmi_h($cfg['desc']) ?></div>
          </div>
        <?php if ($od > 0): ?>
          <span class="sd-badge-od"><?= rmi_icon('warn') ?> <?= $od ?><?= $isBottleneck?' · BN':'' ?></span>
            <?php else: ?>
          <span class="sd-badge-ok"><?= rmi_icon('tick') ?> OK</span>
            <?php endif; ?>
          </div>
      <div class="sd-stage-count" style="color:<?= $cfg['color'] ?>"><?= number_format($cnt) ?></div>
      <div class="sd-stage-lbl">items aktif</div>
      <?php if ($rev > 0): ?><div class="sd-stage-rev"><?= sd_money($rev) ?></div><?php endif; ?>
      <div class="d-flex gap-2 flex-wrap mt-3">
        <a class="btn btn-rmi btn-sm" href="<?= rmi_h($cfg['url']) ?>">Buka</a>
        <?php if ($cfg['tasks_url'] !== null): ?><a class="btn btn-ghost btn-sm" href="<?= rmi_h($cfg['tasks_url']) ?>">Tasks</a><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Monthly Trend Chart -->
  <div class="col-md-12 col-xl-8">
    <div class="rmi-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <div class="fw-semibold"><?= rmi_icon('trend') ?> Tren 6 Bulan</div>
          <div class="rmi-muted small">DO count & Revenue (Juta Rp)</div>
        </div>
        <div style="display:flex;gap:8px;font-size:11px">
          <span style="display:flex;align-items:center;gap:4px"><span style="width:12px;height:4px;background:#60a5fa;border-radius:2px;display:inline-block"></span>Revenue (jt)</span>
          <span style="display:flex;align-items:center;gap:4px"><span style="width:12px;height:12px;background:rgba(251,191,36,.4);border-radius:2px;display:inline-block"></span>DO Count</span>
        </div>
      </div>
      <div class="sd-chart-wrap">
        <canvas id="sdTrendChart"></canvas>
      </div>
    </div>
  </div>

  <!-- Sales DO Summary + Top Customers -->
  <div class="col-md-6 col-xl-4">
    <div class="rmi-card p-3 h-100 d-flex flex-column">
      <div class="fw-semibold mb-1"><?= rmi_icon('clipboard') ?> Sales DO Summary</div>
      <div class="rmi-muted small mb-3"><?= rmi_h($date_from) ?> s/d <?= rmi_h($date_to) ?></div>
      <div class="d-flex gap-3 mb-3">
        <div class="sd-do-stat">
          <div class="sd-do-stat-val"><?= number_format($salesDoTotal) ?></div>
          <div class="sd-do-stat-lbl">Total DO</div>
        </div>
        <div class="sd-do-stat">
          <div class="sd-do-stat-val" style="color:#4ade80"><?= number_format($salesDoPaid) ?></div>
          <div class="sd-do-stat-lbl">DO Paid</div>
        </div>
        <?php if ($salesDoToPaymentPct > 0): ?>
        <div class="sd-do-stat">
          <div class="sd-do-stat-val" style="color:#60a5fa"><?= number_format($salesDoToPaymentPct,1) ?>%</div>
          <div class="sd-do-stat-lbl">Paid Rate</div>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($salesDoRevenue > 0): ?>
      <div style="display:flex;gap:12px;margin-bottom:12px">
        <div class="sd-do-stat">
          <div style="font-size:16px;font-weight:800;color:#67e8f9"><?= sd_money($salesDoRevenue) ?></div>
          <div class="sd-do-stat-lbl">Total Revenue</div>
        </div>
        <?php if ($salesDoPaidRev > 0): ?>
        <div class="sd-do-stat">
          <div style="font-size:16px;font-weight:800;color:#4ade80"><?= sd_money($salesDoPaidRev) ?></div>
          <div class="sd-do-stat-lbl">Revenue Paid</div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($salesDoBottleneckStage!=='' && $totalOverdue>0): ?>
        <div class="sd-badge-od mb-2" style="display:inline-block;font-size:11px"><?= rmi_icon('warn') ?> Bottleneck: <?= rmi_h($salesDoBottleneckStage) ?> (<?= $stage_overdue[$salesDoBottleneckStage] ?> OD)</div>
      <?php endif; ?>
      <div class="mt-auto d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="sales_do.php"><?= rmi_icon('clipboard') ?> Buat DO</a>
        <a class="btn btn-ghost btn-sm" href="sales_control_tower.php"><?= rmi_icon('tower') ?> Tower</a>
        <a class="btn btn-ghost btn-sm" href="<?= rmi_h($export_url) ?>">⬇ Export</a>
      </div>
    </div>
  </div>

  <!-- Top 5 Customers -->
  <?php if (!empty($topCustomers)): ?>
  <div class="col-md-6 col-xl-4">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-1">🏆 Top 5 Customer</div>
      <div class="rmi-muted small mb-3">Berdasarkan revenue BMHP periode ini</div>
      <table class="sd-mini-table">
        <thead><tr><th>#</th><th>Customer</th><th class="text-end">DO</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($topCustomers as $i => $c): ?>
          <tr>
            <td style="color:<?= $i===0?'#fbbf24':($i===1?'#94a3b8':($i===2?'#fb923c':'var(--rmi-muted,#64748b)')) ?>;font-weight:700"><?= $i+1 ?></td>
            <td>
              <div style="font-weight:600;font-size:12px"><?= rmi_h(mb_strimwidth((string)($c['name']??$c['code']??''),0,22,'…')) ?></div>
              <?php if ($c['name'] !== $c['code']): ?><div style="font-size:10px;color:var(--rmi-muted,#9ca3af)"><?= rmi_h($c['code']??'') ?></div><?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:600"><?= number_format((int)$c['do_count']) ?></td>
            <td style="text-align:right;font-weight:700;color:#67e8f9"><?= sd_money((float)$c['total_rev']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Office Breakdown -->
  <?php if (!empty($officeBreakdown) && count($officeBreakdown) > 1): ?>
  <div class="col-md-6 col-xl-4">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-1"><?= rmi_icon('office') ?> Per Office</div>
      <div class="rmi-muted small mb-3">Perbandingan DO BMHP & revenue BMHP per kantor</div>
      <?php
      $maxOffRev = max(1.0, ...array_map(fn($o)=>(float)$o['revenue'], $officeBreakdown));
      ?>
      <?php foreach ($officeBreakdown as $o):
        $barPct = round((float)$o['revenue'] / $maxOffRev * 100);
        $paidPct = (int)$o['do_count'] > 0 ? round((int)$o['paid_count'] / (int)$o['do_count'] * 100) : 0;
      ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <span style="font-size:12px;font-weight:700;color:#e2e8f0"><?= rmi_h($o['office_code']??'—') ?></span>
            <span style="font-size:11px;color:#67e8f9;font-weight:700"><?= sd_money((float)$o['revenue']) ?></span>
          </div>
          <div style="height:6px;background:rgba(255,255,255,.08);border-radius:3px;overflow:hidden">
            <div style="height:6px;width:<?= $barPct ?>%;background:linear-gradient(90deg,#3b82f6,#06b6d4);border-radius:3px;transition:.3s"></div>
          </div>
          <div style="font-size:10px;color:var(--rmi-muted,#9ca3af);margin-top:2px">
            <?= number_format((int)$o['do_count']) ?> DO · <?= $paidPct ?>% paid
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- CRM Operational Timing -->
  <div class="col-md-12 col-xl-8">
    <div class="rmi-card p-3">
      <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
          <div class="fw-semibold">⏱ Kinerja Waktu CRM</div>
          <div class="rmi-muted small">Sumber: sales_do.crm_duration_sec; legacy fallback dari Jam Order Customer/RS ke timestamp penyelesaian DO.</div>
        </div>
        <a class="btn btn-ghost btn-sm" href="../mpr/mpr_pipeline.php"><?= rmi_icon('target') ?> Leads ada di MPR Pipeline</a>
      </div>
      <div class="row g-2">
        <div class="col-sm-4">
          <div class="sd-conv-item">
            <div class="sd-conv-val"><?= rmi_h(sd_duration($crmAvgDurationSec)) ?></div>
            <div class="sd-conv-lbl">Rata-rata Durasi CRM</div>
          </div>
        </div>
        <div class="col-sm-4">
          <div class="sd-conv-item">
            <div class="sd-conv-val"><?= number_format($crmMeasuredCount) ?></div>
            <div class="sd-conv-lbl">DO Dengan Timer Valid</div>
          </div>
        </div>
        <div class="col-sm-4">
          <div class="sd-conv-item">
            <div class="sd-conv-val"><?= number_format($crmMeasuredPct,1) ?>%</div>
            <div class="sd-conv-lbl">Kelengkapan Timer</div>
          </div>
        </div>
      </div>
      <div class="rmi-muted small mt-3">
        Dashboard ini tidak menghitung Leads/Close Rate karena sumber prospek resmi sudah berada di MPR Pipeline.
        Pipeline di atas hanya membaca <b>sales_do.status</b> dan tidak mengubah status workflow apa pun.
      </div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-3"><?= rmi_icon('zap') ?> Akses Cepat</div>
      <div class="sd-links">
        <a class="sd-link primary" href="sales_do.php"><?= rmi_icon('clipboard') ?> Buat DO</a>
        <a class="sd-link primary" href="../mpr/mpr_pipeline.php"><?= rmi_icon('target') ?> MPR Pipeline</a>
        <a class="sd-link primary" href="sales_control_tower.php"><?= rmi_icon('tower') ?> Sales Control Tower</a>
        <a class="sd-link" href="<?= rmi_h($pfx) ?>master/master_customers.php"><?= rmi_icon('users') ?> Master Customer</a>
        <a class="sd-link" href="<?= rmi_h($pfx) ?>master/master_user.php">🤝 Master User / PIC Customers</a>
        <a class="sd-link" href="<?= rmi_h($pfx) ?>master/master_pricelist_sell.php">💲 Pricelist Jual</a>
        <a class="sd-link" href="<?= rmi_h($pfx) ?>customer_portal/login.php" target="_blank">🌐 Customer Portal</a>
        <a class="sd-link" href="<?= rmi_h($pfx) ?>kpi/kpi_do_sla.php"><?= rmi_icon('chart') ?> KPI SLA</a>
        <a class="sd-link" href="<?= rmi_h($pfx) ?>kpi/kpi_do_audit.php"><?= rmi_icon('search') ?> KPI Audit</a>
        <a class="sd-link" href="<?= rmi_h($pfx) ?>dashboards/funnels.php">🌊 Funnel Overview</a>
        <a class="sd-link" href="tax_invoices.php"><?= rmi_icon('receipt') ?> Tax Invoice</a>
        <a class="sd-link" href="<?= rmi_h($export_url) ?>">⬇ Export CSV</a>
      </div>
    </div>
  </div>

  <div class="col-12">
    <?php
    $auditModules = ['SALES', 'DO', 'CRM', 'TAX_INVOICE', 'SALES_DO', 'CRM_LEADS'];
    $auditLimit   = 10;
    require __DIR__ . '/../dashboards/_audit_log_widget.php';
    ?>
  </div>

</div><!-- /row -->

<!-- ── Trend Chart Script ──────────────────────────────────────────────── -->
<script>
(function(){
  var ctx = document.getElementById('sdTrendChart');
  if (!ctx || typeof Chart === 'undefined') return;
  var labels  = <?= $chartLabels ?>;
  var revenue = <?= $chartRevenue ?>;
  var counts  = <?= $chartDoCnt ?>;
  var grid    = 'rgba(255,255,255,0.06)';
  new Chart(ctx, {
    data: {
      labels: labels,
      datasets: [
        {
          type: 'bar',
          label: 'DO Count',
          data: counts,
          backgroundColor: 'rgba(251,191,36,0.25)',
          borderColor: '#fbbf24',
          borderWidth: 1.5,
          borderRadius: 4,
          yAxisID: 'y2',
          order: 2,
        },
        {
          type: 'line',
          label: 'Revenue (jt Rp)',
          data: revenue,
          borderColor: '#60a5fa',
          backgroundColor: 'rgba(96,165,250,0.1)',
          borderWidth: 2.5,
          pointRadius: 4,
          pointHoverRadius: 6,
          fill: true,
          tension: 0.35,
          yAxisID: 'y',
          order: 1,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(15,23,42,0.92)',
          borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1,
          titleColor: '#e2e8f0', bodyColor: '#94a3b8', padding: 10,
          callbacks: {
            label: function(c) {
              if (c.dataset.yAxisID === 'y2') return ' DO: ' + c.parsed.y;
              return ' Revenue: Rp ' + c.parsed.y.toLocaleString('id-ID', {minimumFractionDigits:1}) + 'jt';
            }
          }
        }
      },
      scales: {
        x: { grid: { color: grid }, ticks: { color: '#64748b', font: { size: 11 } } },
        y: {
          position: 'left', grid: { color: grid },
          ticks: { color: '#60a5fa', font: { size: 11 }, callback: v => 'Rp'+v+'jt' },
          title: { display: true, text: 'Revenue (jt)', color: '#60a5fa', font: { size: 10 } }
        },
        y2: {
          position: 'right', grid: { drawOnChartArea: false },
          ticks: { color: '#fbbf24', font: { size: 11 } },
          title: { display: true, text: 'DO Count', color: '#fbbf24', font: { size: 10 } },
          beginAtZero: true
        }
      }
    }
  });
})();
</script>

<?php rmi_footer(); ?>
