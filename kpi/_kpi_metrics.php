<?php
// Block direct access (helper file)
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker


// KPI Metrics Engine (Enterprise+++)
// - Read-only computations from existing ERP modules
// - Safe: all queries guarded by table/column existence
// - No changes to other modules required

require_once __DIR__ . '/_kpi_bootstrap.php';

/**
 * Parse YYYY-MM and return [startDate,endDate] in Y-m-d
 */
function kpi_month_range(string $monthYm): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $monthYm)) {
        $monthYm = date('Y-m');
    }
    $start = $monthYm . '-01';
    $end = date('Y-m-t', strtotime($start));
    return [$start, $end];
}

function kpi_dt(string $ymd, string $time='00:00:00'): string {
    return $ymd . ' ' . $time;
}

function kpi_up(string $s): string {
    return strtoupper(trim((string)$s));
}

function kpi_float($v): float {
    if ($v === null || $v === '') return 0.0;
    return (float)$v;
}

function kpi_int($v): int {
    if ($v === null || $v === '') return 0;
    return (int)$v;
}

function kpi_ensure_sales_do_audit(PDO $pdo): void {
    // Keep compatible with sales/* modules (same schema)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            do_id INT NOT NULL,
            status_from VARCHAR(50) NULL,
            status_to VARCHAR(50) NULL,
            actor_dept VARCHAR(50) NULL,
            actor_name VARCHAR(100) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_do_id (do_id),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
        // fail-soft
    }
}


function kpi_get_master_offices(PDO $pdo): array {
    // Returns: office_id (if available), office_code, office_name
    $table = null;
    if (kpi_table_exists($pdo, 'master_office')) $table = 'master_office';
    if (!$table) return [];
    try {
        $cols = kpi_table_columns($pdo, $table);
        $id   = kpi_pick_col($cols, ['id','office_id']);
        $code = kpi_pick_col($cols, ['office_code','code']) ?: 'office_code';
        $name = kpi_pick_col($cols, ['office_name','name']) ?: 'office_name';
        $isActive = kpi_pick_col($cols, ['is_active','active','status']);

        $sql = "SELECT ".
               ($id ? "{$id} AS office_id, " : "NULL AS office_id, ").
               "{$code} AS office_code, {$name} AS office_name
               FROM {$table}";
        $where = [];
        if ($isActive) {
            if (strtolower($isActive) === 'status') {
                $where[] = "({$isActive}='active' OR {$isActive}='ACTIVE' OR {$isActive}=1 OR {$isActive} IS NULL)";
            } else {
                $where[] = "({$isActive}=1 OR {$isActive} IS NULL)";
            }
        }
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY {$name} ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}


function kpi_get_master_departements(PDO $pdo): array {
    // Returns: dept_id (PK), dept_code, dept_name
    $table = null;
    if (kpi_table_exists($pdo, 'master_departements')) $table = 'master_departements';
    elseif (kpi_table_exists($pdo, 'master_departments')) $table = 'master_departments';
    elseif (kpi_table_exists($pdo, 'master_department')) $table = 'master_department';
    if (!$table) return [];

    try {
        $cols = kpi_table_columns($pdo, $table);
        $id   = kpi_pick_col($cols, ['id','dept_id','department_id','departement_id']) ?: 'id';
        $code = kpi_pick_col($cols, ['dept_code','department_code','dept','department','code']) ?: 'dept_code';
        $name = kpi_pick_col($cols, ['dept_name','department_name','departement_name','name']) ?: 'dept_name';
        $status = kpi_pick_col($cols, ['status','is_active','active']);

        $sql = "SELECT {$id} AS dept_id, {$code} AS dept_code, {$name} AS dept_name FROM {$table}";
        $where = [];
        if ($status) {
            if (strtolower($status) === 'status') {
                $where[] = "({$status}='active' OR {$status}='ACTIVE' OR {$status}=1 OR {$status} IS NULL)";
            } else {
                $where[] = "({$status}=1 OR {$status} IS NULL)";
            }
        }
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY {$code} ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}


function kpi_get_master_employees(PDO $pdo, ?string $deptCode=null, ?string $officeCode=null): array {
    // Returns: employee_pk (if available), employee_id (code/string), employee_name, dept_code, office_code
    if (!kpi_table_exists($pdo, 'master_employees')) return [];
    try {
        $cols = kpi_table_columns($pdo, 'master_employees');

        // PK (numeric) if present
        $pkCol   = kpi_pick_col($cols, ['id','employee_pk','employee_id','employees_id']);

        // Code (string) used across modules
        $codeCol = kpi_pick_col($cols, ['employees_code','employee_code','emp_code','employees_code','employee_id','employee_code']);
        if (!$codeCol) $codeCol = $pkCol ?: 'id';

        $nameCol = kpi_pick_col($cols, ['employee_name','name','full_name']) ?: 'employee_name';
        $deptCol = kpi_pick_col($cols, ['dept_code','department_code','department','departement','dept']) ?: 'dept_code';
        $offCol  = kpi_pick_col($cols, ['office_code','office']) ?: 'office_code';
        $statusCol = kpi_pick_col($cols, ['status','is_active','active']) ?: 'status';

        $where = [];
        // status filter (best-effort)
        if ($statusCol) {
            if (strtolower($statusCol) === 'status') {
                $where[] = "({$statusCol}='active' OR {$statusCol}='ACTIVE' OR {$statusCol}=1 OR {$statusCol} IS NULL)";
            } else {
                $where[] = "({$statusCol}=1 OR {$statusCol} IS NULL)";
            }
        }
        $params = [];

        if ($deptCode !== null && $deptCode !== '') {
            $where[] = "{$deptCol} = :d";
            $params[':d'] = $deptCode;
        }
        if ($officeCode !== null && $officeCode !== '') {
            $where[] = "{$offCol} = :o";
            $params[':o'] = $officeCode;
        }

        $sql = "SELECT ".
               ($pkCol ? "{$pkCol} AS employee_pk, " : "NULL AS employee_pk, ").
               "{$codeCol} AS employee_id,
               {$nameCol} AS employee_name,
               {$deptCol} AS dept_code,
               {$offCol} AS office_code
               FROM master_employees";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY {$nameCol} ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Sales + AR metrics by office for a month.
 * Returns:
 * - sales_total
 * - do_count
 * - do_paid_count
 * - ar_outstanding
 * - ar_overdue
 * - ar_aging (string)
 * - status_counts (assoc)
 */
function kpi_calc_sales_metrics(PDO $pdo, string $monthYm, ?string $officeCode=null): array {
    if (!kpi_table_exists($pdo, 'sales_do')) {
        return [
            'available' => false,
            'reason' => 'sales_do table not found',
        ];
    }

    [$d1,$d2] = kpi_month_range($monthYm);

    $cols = kpi_table_columns($pdo, 'sales_do');
    $colDate   = kpi_pick_col($cols, ['do_date','date','created_date']) ?: 'do_date';
    $colOffice = kpi_pick_col($cols, ['office_code','office']) ?: 'office_code';
    $colTotal  = kpi_pick_col($cols, ['grand_total','total_amount','total']) ?: 'grand_total';
    $colStatus = kpi_pick_col($cols, ['status','status_flow']) ?: 'status';

    // optional financial columns
    $colDue    = kpi_pick_col($cols, ['act_due_date','fin_due_date','due_date']);
    $colPaidDt = kpi_pick_col($cols, ['fin_paid_date','fin_paid_at']);
    $colPaidAmt= kpi_pick_col($cols, ['fin_paid_amount']);
    $colActAmt = kpi_pick_col($cols, ['act_amount']);

    $where = ["{$colDate} BETWEEN :d1 AND :d2"];
    $params = [':d1'=>$d1, ':d2'=>$d2];
    if ($officeCode !== null && $officeCode !== '') {
        $where[] = "{$colOffice} = :o";
        $params[':o'] = $officeCode;
    }

    // main aggregation
    $sql = "SELECT
              COUNT(*) AS do_count,
              COALESCE(SUM({$colTotal}),0) AS sales_total,
              COALESCE(SUM(CASE WHEN LOWER({$colStatus}) IN ('paid','fin_done') THEN 1 ELSE 0 END),0) AS do_paid_by_status
            FROM sales_do
            WHERE " . implode(' AND ', $where);

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $agg = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    // status distribution
    $statusCounts = [];
    try {
        $sql2 = "SELECT {$colStatus} AS st, COUNT(*) c FROM sales_do WHERE " . implode(' AND ', $where) . " GROUP BY {$colStatus}";
        $st2 = $pdo->prepare($sql2);
        $st2->execute($params);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)($r['st'] ?? '');
            if ($k === '') $k = '(blank)';
            $statusCounts[$k] = (int)($r['c'] ?? 0);
        }
    } catch (Throwable $e) {
        $statusCounts = [];
    }

    // AR aging/outstanding (best-effort)
    $ar = [
        'ar_outstanding' => 0.0,
        'ar_overdue' => 0.0,
        'aging' => [
            '0-30' => 0.0,
            '31-60' => 0.0,
            '61-90' => 0.0,
            '91+' => 0.0,
        ],
    ];

    // If we don't have any due date, we still can compute outstanding totals by unpaid DO
    try {
        $asOf = $d2; // end-of-month aging
        $asOfTs = strtotime($asOf . ' 23:59:59') ?: time();

        $selectCols = [
            "{$colTotal} AS grand_total",
            "{$colStatus} AS status",
            "{$colDate} AS do_date",
        ];
        if ($colDue) $selectCols[] = "{$colDue} AS due_date";
        if ($colPaidDt) $selectCols[] = "{$colPaidDt} AS paid_date";
        if ($colPaidAmt) $selectCols[] = "{$colPaidAmt} AS paid_amount";
        if ($colActAmt) $selectCols[] = "{$colActAmt} AS act_amount";

        $sqlAr = "SELECT " . implode(',', $selectCols) . " FROM sales_do WHERE " . implode(' AND ', $where);
        $stAr = $pdo->prepare($sqlAr);
        $stAr->execute($params);
        while ($r = $stAr->fetch(PDO::FETCH_ASSOC)) {
            $status = strtolower((string)($r['status'] ?? ''));
            $isPaid = false;
            if (in_array($status, ['paid','fin_done'], true)) $isPaid = true;
            if (!$isPaid && !empty($r['paid_date'])) $isPaid = true;

            $invoiceAmount = isset($r['act_amount']) && $r['act_amount'] !== null && $r['act_amount'] !== '' ? (float)$r['act_amount'] : (float)($r['grand_total'] ?? 0);
            $paidAmount = isset($r['paid_amount']) ? (float)$r['paid_amount'] : 0.0;
            if ($isPaid) {
                $out = 0.0;
            } else {
                // If partial payment exists
                $out = max($invoiceAmount - $paidAmount, 0.0);
            }
            if ($out <= 0.00001) continue;

            $ar['ar_outstanding'] += $out;

            $due = (string)($r['due_date'] ?? '');
            if ($due === '' || $due === '0000-00-00') {
                $due = (string)($r['do_date'] ?? $d1);
            }
            $dueTs = strtotime($due . ' 00:00:00') ?: $asOfTs;
            $days = (int)floor(($asOfTs - $dueTs) / 86400);

            if ($days <= 30) $bucket = '0-30';
            elseif ($days <= 60) $bucket = '31-60';
            elseif ($days <= 90) $bucket = '61-90';
            else $bucket = '91+';
            $ar['aging'][$bucket] += $out;

            if ($days > 0) {
                $ar['ar_overdue'] += $out;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $agingStrParts = [];
    foreach ($ar['aging'] as $k=>$v) {
        $agingStrParts[] = $k . ':' . number_format((float)$v, 0, ',', '.');
    }

    // paid count (best-effort): status paid/fin_done OR paid_date exists
    $paidCount = 0;
    try {
        $paidWhere = $where;
        $paidWhere[] = "(LOWER({$colStatus}) IN ('paid','fin_done')" . ($colPaidDt ? " OR {$colPaidDt} IS NOT NULL" : "") . ")";
        $stPaid = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE " . implode(' AND ', $paidWhere));
        $stPaid->execute($params);
        $paidCount = (int)$stPaid->fetchColumn();
    } catch (Throwable $e) {
        $paidCount = (int)($agg['do_paid_by_status'] ?? 0);
    }

    return [
        'available' => true,
        'sales_total' => (float)($agg['sales_total'] ?? 0),
        'do_count' => (int)($agg['do_count'] ?? 0),
        'do_paid_count' => $paidCount,
        'ar_outstanding' => (float)$ar['ar_outstanding'],
        'ar_overdue' => (float)$ar['ar_overdue'],
        'ar_aging' => implode('; ', $agingStrParts),
        'status_counts' => $statusCounts,
    ];
}

/**
 * Stock value by office (best-effort)
 */
function kpi_calc_stock_value(PDO $pdo, ?string $officeCode=null): array {
    $out = [
        'available' => false,
        'stock_value' => 0.0,
        'method' => '',
    ];

    // master_products price
    $priceCol = null;
    $prodIdCol = null;
    if (kpi_table_exists($pdo, 'master_products')) {
        $mpCols = kpi_table_columns($pdo, 'master_products');
        $prodIdCol = kpi_pick_col($mpCols, ['id','product_id','products_id']) ?: 'id';
        $priceCol = kpi_pick_col($mpCols, ['price','sell_price','sales_price','unit_price','max_price','min_price']);
        if (!$priceCol) $priceCol = 'price';
    }

    // Prefer wqs_allocations (has office_code)
    if (kpi_table_exists($pdo, 'wqs_allocations') && $priceCol && $prodIdCol) {
        try {
            $where = [];
            $params = [];
            if ($officeCode !== null && $officeCode !== '') {
                $where[] = "a.office_code = :o";
                $params[':o'] = $officeCode;
            }
            $sql = "SELECT COALESCE(SUM(a.qty * COALESCE(p.{$priceCol},0)),0) AS v
                    FROM wqs_allocations a
                    LEFT JOIN master_products p ON p.{$prodIdCol} = a.product_id";
            if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $val = (float)$st->fetchColumn();
            $out['available'] = true;
            $out['stock_value'] = $val;
            $out['method'] = 'wqs_allocations.qty * master_products.price';
            return $out;
        } catch (Throwable $e) {
            // fallback
        }
    }

    // Fallback: wqs_stock global
    if (kpi_table_exists($pdo, 'wqs_stock') && $priceCol && $prodIdCol) {
        try {
            $wsCols = kpi_table_columns($pdo, 'wqs_stock');
            $pid = kpi_pick_col($wsCols, ['product_id','products_id']) ?: 'product_id';
            $qty = kpi_pick_col($wsCols, ['stock_qty','qty']) ?: 'stock_qty';
            $sql = "SELECT COALESCE(SUM(ws.{$qty} * COALESCE(p.{$priceCol},0)),0) AS v
                    FROM wqs_stock ws
                    LEFT JOIN master_products p ON p.{$prodIdCol} = ws.{$pid}";
            $val = (float)$pdo->query($sql)->fetchColumn();
            $out['available'] = true;
            $out['stock_value'] = $val;
            $out['method'] = 'wqs_stock.stock_qty * master_products.price (global)';
            return $out;
        } catch (Throwable $e) {
            // ignore
        }
    }

    $out['reason'] = 'stock tables not found (wqs_allocations / wqs_stock) or master_products.price missing';
    return $out;
}

/**
 * Purchases metrics by office for a month (PO + AP outstanding)
 */
function kpi_calc_purchases_metrics(PDO $pdo, string $monthYm, ?string $officeCode=null): array {
    [$d1,$d2] = kpi_month_range($monthYm);
    $out = [
        'available'=>false,
        'pr_submitted_count'=>0,
        'pr_submitted_age_median_days'=>0.0,
        'po_open_count'=>0,
        'po_open_value'=>0.0,
        'po_to_received_avg_days'=>0.0,
        'eta_ontime_pct'=>0.0,
        'forwarder_pending_count'=>0,
        'docs_missing_count'=>0,
        'ceisa_pending_count'=>0,
        'ap_outstanding'=>0.0,
        'ap_overdue'=>0.0,
        'pqp_sla_available'=>false,
        'pqp_sla_completed_count'=>0,
        'pqp_sla_ontime_count'=>0,
        'pqp_sla_ontime_pct'=>0.0,
        'pqp_sla_avg_minutes'=>0.0,
        'pqp_sla_median_minutes'=>0.0,
        'pqp_sla_running_count'=>0,
        'metric_available'=>[],
        'sources'=>[],
        'warnings'=>[],
    ];
    $metricKeys = ['pr_submitted_count','pr_submitted_age_median_days','po_open_count','po_open_value',
        'po_to_received_avg_days','eta_ontime_pct','forwarder_pending_count','docs_missing_count',
        'ceisa_pending_count','ap_outstanding','ap_overdue'];
    foreach ($metricKeys as $k) $out['metric_available'][$k] = false;

    $policy = function_exists('kpi_purchases_policy_load')
        ? kpi_purchases_policy_load($pdo, $officeCode)
        : ['pqp_pr_to_po_sla_minutes'=>1440];
    $slaTarget = max(1, (int)($policy['pqp_pr_to_po_sla_minutes'] ?? 1440));
    $out['pqp_sla_target_minutes'] = $slaTarget;

    // PR waiting PQP / SLA PR→PO. submitted_at is the only canonical START.
    if (kpi_table_exists($pdo,'wqs_pr')) {
        try {
            $prCols = kpi_table_columns($pdo,'wqs_pr');
            $hasSubmittedAt = in_array('submitted_at',$prCols,true);
            if ($hasSubmittedAt) {
                $where = ["pr.deleted_at IS NULL", "pr.submitted_at IS NOT NULL",
                          "DATE(pr.submitted_at) BETWEEN :d1 AND :d2"];
                $params = [':d1'=>$d1, ':d2'=>$d2];
                if ($officeCode !== null && $officeCode !== '') {
                    $where[] = "pr.office_code=:o"; $params[':o']=$officeCode;
                }
                $sql = "SELECT pr.id,pr.status,pr.submitted_at,
                               MIN(CASE WHEN po.deleted_at IS NULL
                                         AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')
                                        THEN po.created_at END) AS po_created_at
                        FROM wqs_pr pr
                        LEFT JOIN purchases_po po ON po.pr_id=pr.id
                        WHERE ".implode(' AND ',$where)."
                        GROUP BY pr.id,pr.status,pr.submitted_at";
                $st=$pdo->prepare($sql); $st->execute($params);
                $dur=[]; $runningAges=[]; $on=0; $running=0; $submittedCount=0;
                $asOf = min(time(), strtotime($d2.' 23:59:59') ?: time());
                while($r=$st->fetch(PDO::FETCH_ASSOC)){
                    $status=strtoupper(trim((string)($r['status']??'')));
                    if (in_array($status,['CANCELLED','CANCELED','VOID'],true)) continue;
                    $submittedCount++;
                    $s=strtotime((string)$r['submitted_at']);
                    if (!$s) continue;
                    if (!empty($r['po_created_at'])) {
                        $e=strtotime((string)$r['po_created_at']);
                        if ($e && $e >= $s) {
                            $m=($e-$s)/60.0; $dur[]=$m;
                            if ($m <= $slaTarget) $on++;
                        }
                    } elseif ($status === 'SUBMITTED') {
                        $running++;
                        $runningAges[] = max(0, ($asOf-$s)/86400.0);
                    }
                    // REVISION_WQS intentionally paused/excluded from running SLA.
                }
                sort($dur); sort($runningAges);
                $median = function(array $a): float {
                    $n=count($a); if(!$n) return 0.0;
                    $m=intdiv($n,2);
                    return $n%2 ? (float)$a[$m] : ((float)$a[$m-1]+(float)$a[$m])/2.0;
                };
                $out['pr_submitted_count']=$submittedCount;
                $out['pr_submitted_age_median_days']=$median($runningAges);
                $out['pqp_sla_completed_count']=count($dur);
                $out['pqp_sla_ontime_count']=$on;
                $out['pqp_sla_ontime_pct']=count($dur)?($on/count($dur)*100.0):0.0;
                $out['pqp_sla_avg_minutes']=count($dur)?array_sum($dur)/count($dur):0.0;
                $out['pqp_sla_median_minutes']=$median($dur);
                $out['pqp_sla_running_count']=$running;
                $out['pqp_sla_available']=true;
                $out['metric_available']['pr_submitted_count']=true;
                $out['metric_available']['pr_submitted_age_median_days']=true;
                $out['sources'][]='wqs_pr.submitted_at → purchases_po.created_at';
                $out['available']=true;
            } else {
                $out['warnings'][]='wqs_pr.submitted_at belum tersedia; SLA PQP tidak dihitung agar tidak memakai created_at PR secara keliru.';
            }
        } catch(Throwable $e) {
            $out['warnings'][]='PQP SLA: '.$e->getMessage();
        }
    }

    // PO open metrics.
    if (kpi_table_exists($pdo,'purchases_po')) {
        try {
            $where=["po.deleted_at IS NULL","po.po_date BETWEEN :d1 AND :d2",
                    "UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID','CLOSED')"];
            $params=[':d1'=>$d1,':d2'=>$d2];
            if ($officeCode) {$where[]="po.office_code=:o";$params[':o']=$officeCode;}
            $st=$pdo->prepare("SELECT COUNT(*) c,COALESCE(SUM(po.total_amount),0) v FROM purchases_po po WHERE ".implode(' AND ',$where));
            $st->execute($params); $r=$st->fetch(PDO::FETCH_ASSOC)?:[];
            $out['po_open_count']=(int)($r['c']??0); $out['po_open_value']=(float)($r['v']??0);
            $out['metric_available']['po_open_count']=true; $out['metric_available']['po_open_value']=true;
            $out['sources'][]='purchases_po'; $out['available']=true;
        } catch(Throwable $e){$out['warnings'][]='PO: '.$e->getMessage();}
    }

    // AP outstanding / overdue.
    if (kpi_table_exists($pdo,'purchases_invoice_ap')) {
        try {
            $apCols=kpi_table_columns($pdo,'purchases_invoice_ap');
            $invDate=kpi_pick_col($apCols,['invoice_date','created_at'])?:'invoice_date';
            $dueDate=kpi_pick_col($apCols,['due_date']);
            $totalCol=kpi_pick_col($apCols,['total_amount','amount_total','grand_total'])?:'total_amount';
            $where=["ap.{$invDate} BETWEEN :d1 AND :d2"];
            $params=[':d1'=>$d1,':d2'=>$d2];
            if (in_array('deleted_at',$apCols,true)) $where[]='ap.deleted_at IS NULL';
            if ($officeCode && in_array('office_code',$apCols,true)) {$where[]='ap.office_code=:o';$params[':o']=$officeCode;}
            $paidJoin="LEFT JOIN (SELECT ap_id,COALESCE(SUM(amount),0) paid_amount FROM purchases_payment_ap GROUP BY ap_id) p ON p.ap_id=ap.id";
            if (!kpi_table_exists($pdo,'purchases_payment_ap')) $paidJoin="LEFT JOIN (SELECT NULL ap_id,0 paid_amount) p ON 1=0";
            $sql="SELECT ap.{$totalCol} total_amount,".($dueDate?"ap.{$dueDate} due_date":"NULL due_date").",COALESCE(p.paid_amount,0) paid_amount
                  FROM purchases_invoice_ap ap {$paidJoin} WHERE ".implode(' AND ',$where);
            $st=$pdo->prepare($sql);$st->execute($params);
            $asOf=strtotime($d2.' 23:59:59')?:time();$os=0.0;$od=0.0;
            while($r=$st->fetch(PDO::FETCH_ASSOC)){
                $open=max((float)($r['total_amount']??0)-(float)($r['paid_amount']??0),0);
                $os+=$open;
                if($open>0&&!empty($r['due_date'])&&(strtotime((string)$r['due_date'])?:PHP_INT_MAX)<$asOf)$od+=$open;
            }
            $out['ap_outstanding']=$os;$out['ap_overdue']=$od;
            $out['metric_available']['ap_outstanding']=true;$out['metric_available']['ap_overdue']=true;
            $out['sources'][]='purchases_invoice_ap';$out['available']=true;
        } catch(Throwable $e){$out['warnings'][]='AP: '.$e->getMessage();}
    }
    return $out;
}

/**
 * Fixed Asset metrics by office (NBV/cost). Month used if depreciation lines exist.
 */
function kpi_calc_fixed_asset_metrics(PDO $pdo, string $monthYm, ?string $officeCode=null): array {
    $out = [
        'available' => false,
        'asset_cost' => 0.0,
        'asset_nbv' => 0.0,
        'asset_count' => 0,
        'method' => '',
    ];

    if (!kpi_table_exists($pdo, 'fa_assets') || !kpi_table_exists($pdo, 'master_office')) {
        $out['reason'] = 'fa_assets or master_office not found';
        return $out;
    }

    try {
        // master_office id to code
        $moCols = kpi_table_columns($pdo, 'master_office');
        $moId = kpi_pick_col($moCols, ['id','office_id']) ?: 'id';
        $moCode = kpi_pick_col($moCols, ['office_code','code']) ?: 'office_code';

        $where = ["a.deleted_at IS NULL"];
        $params = [];
        if ($officeCode !== null && $officeCode !== '') {
            $where[] = "o.{$moCode} = :o";
            $params[':o'] = $officeCode;
        }

        $sql = "SELECT COUNT(*) cnt, COALESCE(SUM(a.cost),0) cost
                FROM fa_assets a
                LEFT JOIN master_office o ON o.{$moId} = a.office_id
                WHERE " . implode(' AND ', $where);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'cost'=>0];

        $out['asset_count'] = (int)($r['cnt'] ?? 0);
        $out['asset_cost'] = (float)($r['cost'] ?? 0);
        $out['asset_nbv'] = $out['asset_cost'];
        $out['available'] = true;
        $out['method'] = 'fa_assets.cost';

        // NBV from depreciation lines if available
        if (kpi_table_exists($pdo, 'fa_depreciation_lines')) {
            $sql2 = "SELECT COALESCE(SUM(l.nbv),0) nbv
                     FROM fa_depreciation_lines l
                     JOIN fa_assets a ON a.id = l.asset_id
                     LEFT JOIN master_office o ON o.{$moId} = a.office_id
                     WHERE l.period = :p AND a.deleted_at IS NULL" . ($officeCode ? " AND o.{$moCode} = :o" : "");
            $params2 = [':p'=>$monthYm];
            if ($officeCode) $params2[':o'] = $officeCode;
            $st2 = $pdo->prepare($sql2);
            $st2->execute($params2);
            $nbv = (float)$st2->fetchColumn();
            if ($nbv > 0.00001) {
                $out['asset_nbv'] = $nbv;
                $out['method'] .= ' + fa_depreciation_lines.nbv';
            }
        }

        return $out;
    } catch (Throwable $e) {
        $out['reason'] = $e->getMessage();
        return $out;
    }
}

/**
 * Headcount by office
 */
function kpi_calc_headcount(PDO $pdo, ?string $officeCode=null): array {
    $out = ['available'=>false,'headcount'=>0,'method'=>''];
    if (!kpi_table_exists($pdo, 'master_employees')) return $out;
    try {
        $cols = kpi_table_columns($pdo, 'master_employees');
        $offCol = kpi_pick_col($cols, ['office_code','office']) ?: 'office_code';
        $statusCol = kpi_pick_col($cols, ['status']) ?: 'status';
        $where = ["({$statusCol}='active' OR {$statusCol}='ACTIVE' OR {$statusCol} IS NULL)"];
        $params = [];
        if ($officeCode !== null && $officeCode !== '') {
            $where[] = "{$offCol} = :o";
            $params[':o'] = $officeCode;
        }
        $sql = "SELECT COUNT(*) FROM master_employees" . ($where ? " WHERE " . implode(' AND ', $where) : '');
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out['headcount'] = (int)$st->fetchColumn();
        $out['available'] = true;
        $out['method'] = 'master_employees (active)';
        return $out;
    } catch (Throwable $e) {
        return $out;
    }
}

/**
 * DO SLA metrics by office in a date range.
 * Uses best available timestamps and/or sales_do_audit.
 */
function kpi_calc_do_sla(PDO $pdo, string $d1, string $d2, ?string $officeCode=null, array $slaMinutes = []): array {
    if (!kpi_table_exists($pdo, 'sales_do')) {
        return ['available'=>false,'reason'=>'sales_do not found'];
    }

    // SLA input in MINUTES (to support sub-hour SLA)
    // Defaults: CRM 30m / WQS 4h / SCM 24h / ACT 48h / FIN 7 hari
    $defaultSla = [
        'CRM' => 30,
        'WQS' => 240,
        'SCM' => 1440,
        'ACT' => 2880,
        'FIN' => 10080,
    ];
    foreach ($defaultSla as $k=>$v) {
        if (!isset($slaMinutes[$k])) $slaMinutes[$k] = $v;
    }

    $cols = kpi_table_columns($pdo, 'sales_do');
    $colId = kpi_pick_col($cols, ['id']) ?: 'id';
    $colDate = kpi_pick_col($cols, ['do_date']) ?: 'do_date';
    $colOffice = kpi_pick_col($cols, ['office_code']) ?: 'office_code';
    $colStatus = kpi_pick_col($cols, ['status']) ?: 'status';
    $colCreated = kpi_pick_col($cols, ['created_at','created_on','created_date']);

    // CRM manual order time from sales_do.php.
    // This is the business start for CRM SLA: RS/customer order received -> CRM sends DO to WQS.
    // Keep fallback to created_at for old DO rows that do not have manual order time yet.
    $tCrmOrder = kpi_pick_col($cols, ['crm_order_received_at','order_received_at','customer_order_received_at','po_received_at']);
    $tCrmEnd   = kpi_pick_col($cols, ['crm_to_wqs_at','crm_sent_wqs_at','crm_completed_at']);
    $tCrmDur   = kpi_pick_col($cols, ['crm_duration_sec','crm_duration_seconds']);

    // timestamps (best-effort)
   $tWqsStart = kpi_pick_col($cols, ['wqs_started_at','wqs_picked_at']);
$tWqsEnd   = kpi_pick_col($cols, ['wqs_ready_at']);

// SCM start tidak boleh dari scm_on_delivery_at.
// Dalam alur RMI, ready_scm = mulai SCM, on_delivery = selesai SCM.
$tScmStart = null;

// SCM end bisa dari scm_delivered_at jika tersedia.
// Jika tidak ada, fallback ke scm_on_delivery_at.
$tScmEnd   = kpi_pick_col($cols, ['scm_delivered_at', 'scm_on_delivery_at']);

$tActEnd   = kpi_pick_col($cols, ['act_ready_fin_at','act_invoiced_at','act_updated_at']);
$tFinEnd   = kpi_pick_col($cols, ['fin_paid_at','fin_paid_date','paid_at','paid_date']);
$tFinUpdated = kpi_pick_col($cols, ['fin_updated_at','fin_paid_updated_at']);

    // if audit exists, we can enrich WQS/ACT/FIN
    $hasAudit = kpi_table_exists($pdo, 'sales_do_audit');
    if ($hasAudit) kpi_ensure_sales_do_audit($pdo);

    $where = ["d.{$colDate} BETWEEN :d1 AND :d2"];
    $params = [':d1'=>$d1, ':d2'=>$d2];
    if ($officeCode !== null && $officeCode !== '') {
        $where[] = "d.{$colOffice} = :o";
        $params[':o'] = $officeCode;
    }

    // Load DO list (limit 5000 for performance)
    $sql = "SELECT d.* FROM sales_do d WHERE " . implode(' AND ', $where) . " ORDER BY d.{$colDate} DESC, d.{$colId} DESC LIMIT 5000";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $doRows = $st->fetchAll(PDO::FETCH_ASSOC);

    $auditByDo = [];
    if ($hasAudit && $doRows) {
        try {
            $ids = array_values(array_filter(array_map(fn($r)=> (int)($r[$colId] ?? 0), $doRows)));
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st2 = $pdo->prepare("SELECT do_id, status_to, created_at FROM sales_do_audit WHERE do_id IN ($in) ORDER BY created_at ASC, id ASC");
                $st2->execute($ids);
                while ($r = $st2->fetch(PDO::FETCH_ASSOC)) {
                    $did = (int)($r['do_id'] ?? 0);
                    if (!$did) continue;
                    $auditByDo[$did][] = $r;
                }
            }
        } catch (Throwable $e) {
            $auditByDo = [];
        }
    }

    $stats = [];
    foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) {
        $stats[$dep] = ['ok'=>0,'over'=>0,'open'=>0,'avg_sec'=>0,'n_avg'=>0,'sum_sec'=>0];
    }

    $now = time();

    $toTs = function($dt): int {
        if (!$dt) return 0;
        $t = strtotime((string)$dt);
        return $t ? (int)$t : 0;
    };

    foreach ($doRows as $d) {
        $id = (int)($d[$colId] ?? 0);
        $status = strtolower((string)($d[$colStatus] ?? ''));

        // Determine stage from status (supports old and new)
        $stage = 'CRM';

        if (in_array($status, ['crm_to_wqs','wqs_processing'], true)) {
            $stage = 'WQS';
        }

if (in_array($status, ['ready_scm'], true)) {
    $stage = 'SCM';
}

if (in_array($status, ['on_delivery', 'delivered'], true)) {
    $stage = 'ACT';
}

if (in_array($status, ['wait_payment'], true)) {
    $stage = 'FIN';
}

if (in_array($status, ['paid', 'fin_done'], true)) {
    $stage = 'DONE';
}

        // Stage timestamps (best-effort)
        // CRM SLA start priority:
        // 1) crm_order_received_at (manual jam order RS/customer)
        // 2) created_at (fallback DO lama)
        // 3) do_date 00:00 fallback later
        $crmManualStart = $tCrmOrder ? $toTs($d[$tCrmOrder] ?? null) : 0;
        $crmCreatedStart = $colCreated ? $toTs($d[$colCreated] ?? null) : 0;
        $crmStart = $crmManualStart ?: $crmCreatedStart;
        $crmEnd = $tCrmEnd ? $toTs($d[$tCrmEnd] ?? null) : 0;
        $crmDurationStored = ($tCrmDur && isset($d[$tCrmDur])) ? max(0, (int)$d[$tCrmDur]) : 0;
     $wqsStart = $tWqsStart ? $toTs($d[$tWqsStart] ?? null) : 0;
$wqsEnd   = $tWqsEnd   ? $toTs($d[$tWqsEnd] ?? null)   : 0;

// SCM start wajib dari ready_scm / wqsEnd, bukan dari scm_on_delivery_at.
$scmStart = 0;

$scmEnd   = $tScmEnd   ? $toTs($d[$tScmEnd] ?? null)   : 0;
$actEnd   = $tActEnd   ? $toTs($d[$tActEnd] ?? null)   : 0;
$finEnd   = $tFinEnd   ? $toTs($d[$tFinEnd] ?? null)   : 0;
$finUpdated = $tFinUpdated ? $toTs($d[$tFinUpdated] ?? null) : 0;

        // Enrich with audit if needed
        $audit = $auditByDo[$id] ?? [];
        $auditFirst = [];
        if ($audit) {
            foreach ($audit as $l) {
                $to = strtolower(trim((string)($l['status_to'] ?? '')));
if ($to === '') continue;

if (!isset($auditFirst[$to])) {
    $auditFirst[$to] = $toTs($l['created_at'] ?? null);
}
            }
        }
        // CRM selesai saat DO dikirim ke WQS.
        // Jangan overwrite jika sudah ada kolom timestamp CRM yang valid.
if (!$crmEnd) {
    $crmEnd = $auditFirst['crm_to_wqs']
        ?? $auditFirst['wqs_processing']
        ?? 0;
}

// Jika tidak ada timestamp end tetapi sales_do sudah menyimpan durasi CRM,
// bentuk end virtual agar ringkasan departemen sama dengan nilai per staff.
if (!$crmEnd && $crmStart && $crmDurationStored > 0) {
    $crmEnd = $crmStart + $crmDurationStored;
}

        // Ambil timestamp dari audit jika kolom sales_do masih NULL
if (!$wqsStart) {
    $wqsStart = $auditFirst['wqs_processing']
        ?? $auditFirst['crm_to_wqs']
        ?? 0;
}

if (!$wqsEnd) {
    $wqsEnd = $auditFirst['ready_scm'] ?? 0;
}

// SCM mulai dihitung saat WQS selesai / ready_scm
if (!$scmStart) {
    $scmStart = $auditFirst['ready_scm'] ?? $wqsEnd ?? 0;
}

// SCM selesai dihitung saat barang mulai delivery / on_delivery
if (!$scmEnd) {
    $scmEnd = $auditFirst['on_delivery']
        ?? $auditFirst['delivered']
        ?? 0;
}

// ACT mulai setelah SCM masuk on_delivery / delivered
$actStart = $scmEnd ?: 0;

// ACT selesai saat DO masuk wait_payment
if (!$actEnd) {
    $actEnd = $auditFirst['wait_payment']
        ?? $auditFirst['act_ready_fin']
        ?? $auditFirst['act_invoiced']
        ?? 0;
}

// FIN mulai saat wait_payment
$finStart = $actEnd ?: 0;

// FIN selesai saat paid. Prioritas fin_paid_at; fallback fin_paid_date/audit untuk data lama.
if (!$finEnd) {
    $finEnd = $auditFirst['paid']
        ?? $auditFirst['fin_done']
        ?? 0;
}
if (!$finEnd && in_array($status, ['paid','fin_done'], true) && $finUpdated) {
    $finEnd = $finUpdated;
}

// Fallback start ke tanggal DO
$doDateTs = $toTs(($d[$colDate] ?? $d1) . ' 00:00:00');
if (!$crmStart) {
    $crmStart = $doDateTs;
}
if (!$wqsStart) {
    $wqsStart = $doDateTs;
}

        // Completed durations
        $durations = [
            // CRM uses stored crm_duration_sec when available; otherwise calculate from manual order time to WQS handoff.
            'CRM' => ($crmDurationStored > 0) ? $crmDurationStored : (($crmStart && $crmEnd && $crmEnd >= $crmStart) ? ($crmEnd - $crmStart) : 0),
            'WQS' => ($wqsStart && $wqsEnd && $wqsEnd >= $wqsStart) ? ($wqsEnd - $wqsStart) : 0,
            'SCM' => ($scmStart && $scmEnd && $scmEnd >= $scmStart) ? ($scmEnd - $scmStart) : 0,
            'ACT' => ($actStart && $actEnd && $actEnd >= $actStart) ? ($actEnd - $actStart) : 0,
            // FIN: pembayaran yang secara bisnis terjadi sebelum Tukar Faktur adalah early payment.
            // Jangan menghasilkan durasi negatif; transaksi selesai tersebut bernilai SLA 0 detik.
            'FIN' => ($finStart && $finEnd) ? max(0, $finEnd - $finStart) : 0,
        ];

        foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) {
            // Umumnya durasi 0 berarti timestamp stage belum lengkap sehingga tidak dihitung.
            // Khusus FIN, start+end yang sama/early payment adalah completion sah dengan SLA 0.
            $completedForKpi = $durations[$dep] > 0;
            if ($dep === 'FIN' && $finStart && $finEnd) {
                $completedForKpi = true;
            }
            if ($completedForKpi) {
                $stats[$dep]['sum_sec'] += $durations[$dep];
                $stats[$dep]['n_avg']++;
                $slaSec = max(1, (int)$slaMinutes[$dep]) * 60;
                if ($durations[$dep] > $slaSec) $stats[$dep]['over']++; else $stats[$dep]['ok']++;
            }
        }

        // Open stage overdue
        if ($stage !== 'DONE') {
            $stats[$stage]['open']++;
            $startTs = 0;
            if ($stage === 'CRM') $startTs = $crmStart;
            if ($stage === 'WQS') $startTs = $wqsStart;
            if ($stage === 'SCM') $startTs = $scmStart;
            if ($stage === 'ACT') $startTs = $actStart;
            if ($stage === 'FIN') $startTs = $finStart;
            if (!$startTs) $startTs = $doDateTs;
            $elapsed = $now - $startTs;
            $slaSec = max(1, (int)$slaMinutes[$stage]) * 60;
            if ($elapsed > $slaSec) $stats[$stage]['over']++; // treat open overdue as over
        }
    }

    foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) {
        $n = (int)$stats[$dep]['n_avg'];
        $stats[$dep]['avg_sec'] = $n ? (int)round($stats[$dep]['sum_sec']/$n) : 0;
    }

    $slaHoursEquiv = [];
    foreach ($slaMinutes as $k=>$v) { $slaHoursEquiv[$k] = round(((int)$v)/60, 2); }

    return [
        'available' => true,
        'sla_minutes' => $slaMinutes,
        'sla_hours' => $slaHoursEquiv,
        'stats' => $stats,
    ];
}

/**
 * Employee productivity from audit logs (sales_do_audit + purchases + fixed assets) for a month.
 * Returns array of rows:
 * - dept_code
 * - employee_id (actor)
 * - tasks_done
 * - on_time
 * - overdue
 * - avg_sec
 * - source
 */
function kpi_calc_employee_from_audits(PDO $pdo, string $monthYm, ?string $deptCode=null): array {
    [$d1,$d2] = kpi_month_range($monthYm);
    $d1dt = $d1 . ' 00:00:00';
    $d2dt = $d2 . ' 23:59:59';

    $normalizeDept = static function($value): string {
        $v = kpi_up(trim((string)$value));
        $map = [
            'SALES'=>'CRM','MARKETING'=>'CRM','CUSTOMER RELATION'=>'CRM','CUSTOMER RELATION MANAGEMENT'=>'CRM',
            'WAREHOUSE'=>'WQS','GUDANG'=>'WQS','WAREHOUSE & QUANTITY'=>'WQS',
            'SUPPLY CHAIN'=>'SCM','SUPPLY & CHAIN'=>'SCM','LOGISTIC'=>'SCM','LOGISTICS'=>'SCM',
            'ACCOUNTING'=>'ACT','ACCOUNTING & TAX'=>'ACT','TAX'=>'ACT',
            'FINANCE'=>'FIN',
            'PURCHASING'=>'PQP','PURCHASE'=>'PQP','PROCUREMENT'=>'PQP','QUALITY & PURCHASING'=>'PQP',
            'HR'=>'HRL','HRD'=>'HRL','HUMAN RESOURCE'=>'HRL','HR & LEGAL'=>'HRL',
            'IT'=>'ITC','INFORMATION TECHNOLOGY'=>'ITC','IT & CLOUD'=>'ITC',
            'PRODUCT'=>'PRD','MARKETING & PROJECT'=>'MPR',
        ];
        return $map[$v] ?? $v;
    };

    $deptFilter = $deptCode !== null ? $normalizeDept($deptCode) : '';

    // Master employee adalah sumber utama departemen actor. Audit process_dept hanya fallback.
    $actorDeptMap = [];
    try {
        foreach (kpi_get_master_employees($pdo) as $emp) {
            $dept = $normalizeDept($emp['dept_code'] ?? '');
            if ($dept === '') continue;
            foreach ([$emp['employee_id'] ?? '', $emp['employee_name'] ?? ''] as $identity) {
                $key = strtoupper(trim((string)$identity));
                if ($key !== '') $actorDeptMap[$key] = $dept;
            }
        }
    } catch (Throwable $e) {
        $actorDeptMap = [];
    }

    $resolveActorDept = static function(string $employee, string $fallback='') use ($actorDeptMap, $normalizeDept): string {
        $key = strtoupper(trim($employee));
        if ($key !== '' && isset($actorDeptMap[$key])) return $actorDeptMap[$key];

        // Fallback aman dari pola username yang eksplisit. Jangan memetakan STAFFBRANCH secara paksa.
        $compact = preg_replace('/[^A-Z0-9]/', '', $key) ?? $key;
        $prefixMap = [
            'MGRCRM'=>'CRM','STAFFCRM'=>'CRM',
            'MGRWQS'=>'WQS','STAFFWQS'=>'WQS',
            'MGRSCM'=>'SCM','STAFFSCM'=>'SCM',
            'MGRACT'=>'ACT','STAFFACT'=>'ACT',
            'MGRFIN'=>'FIN','STAFFFIN'=>'FIN',
            'MGRPQP'=>'PQP','STAFFPQP'=>'PQP',
            'MGRHRL'=>'HRL','STAFFHRL'=>'HRL',
            'MGRMPR'=>'MPR','STAFFMPR'=>'MPR',
            'MGRPRD'=>'PRD','STAFFPRD'=>'PRD',
            'MGRITC'=>'ITC','STAFFITC'=>'ITC',
        ];
        foreach ($prefixMap as $prefix=>$dept) {
            if (str_starts_with($compact, $prefix)) return $dept;
        }
        return $normalizeDept($fallback);
    };

    $rows = [];
    $append = static function(array &$rows, string $dept, string $employee, int $tasks, string $source, ?int $errors=null) use ($normalizeDept, $deptFilter): void {
        $dept = $normalizeDept($dept);
        $employee = trim($employee);
        if ($dept === '' || $employee === '' || strtoupper($employee) === 'SYSTEM') return;
        if ($deptFilter !== '' && $dept !== $deptFilter) return;
        if ($tasks <= 0) return;
        $row = [
            'dept_code' => $dept,
            'employee_id' => $employee,
            'tasks_done' => $tasks,
            'source' => $source,
        ];
        if ($errors !== null) {
            $row['errors'] = max(0, $errors);
            $row['quality'] = round(max(0, (($tasks-max(0,$errors))/$tasks)*100), 2);
        }
        $rows[] = $row;
    };

    // 1) Sales DO audit: satu baris transisi nyata = satu task.
    if (kpi_table_exists($pdo, 'sales_do_audit')) {
        try {
            kpi_ensure_sales_do_audit($pdo);
            $cols = kpi_table_columns($pdo, 'sales_do_audit');
            $timeCol = kpi_pick_col($cols, ['created_at','event_at','logged_at','timestamp']);
            $deptCol = kpi_pick_col($cols, ['actor_dept','process_dept','dept_code','department']);
            $actorCol = kpi_pick_col($cols, ['actor_name','actor','username','user_name','created_by']);
            $fromCol = kpi_pick_col($cols, ['status_from','from_status']);
            $toCol = kpi_pick_col($cols, ['status_to','to_status']);
            $noteCol = kpi_pick_col($cols, ['note','action','event_type']);

            if ($timeCol && $actorCol) {
                $sql = "SELECT ".($deptCol ? "COALESCE({$deptCol},'')" : "''")." dept_code,
                               COALESCE({$actorCol},'') employee_id,
                               ".($fromCol ? "COALESCE({$fromCol},'')" : "''")." status_from,
                               ".($toCol ? "COALESCE({$toCol},'')" : "''")." status_to,
                               ".($noteCol ? "COALESCE({$noteCol},'')" : "''")." note_text
                        FROM sales_do_audit
                        WHERE {$timeCol} BETWEEN :d1 AND :d2";
                $st = $pdo->prepare($sql);
                $st->execute([':d1'=>$d1dt, ':d2'=>$d2dt]);

                $counts = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $emp = trim((string)($r['employee_id'] ?? ''));
                    $from = strtolower(trim((string)($r['status_from'] ?? '')));
                    $to = strtolower(trim((string)($r['status_to'] ?? '')));
                    $note = strtoupper(trim((string)($r['note_text'] ?? '')));
                    if ($emp === '' || strtoupper($emp) === 'SYSTEM') continue;
                    if ($from === '' && $to === '' && $note === '') continue;

                    $fallbackDept = $normalizeDept($r['dept_code'] ?? '');
                    if ($fallbackDept === '') {
                        if ($from === 'new' || $to === 'crm_to_wqs' || str_contains($note,'CREATE_DO')) $fallbackDept = 'CRM';
                        elseif (str_contains($from,'wqs') || str_contains($to,'wqs') || $to === 'ready_scm') $fallbackDept = 'WQS';
                        elseif (str_contains($from,'scm') || str_contains($to,'delivery') || $to === 'delivered') $fallbackDept = 'SCM';
                        elseif (str_contains($from,'act') || str_contains($to,'act') || $to === 'wait_payment') $fallbackDept = 'ACT';
                        elseif (str_contains($from,'fin') || str_contains($to,'paid') || $to === 'fin_done') $fallbackDept = 'FIN';
                    }
                    $dep = $resolveActorDept($emp, $fallbackDept);
                    if ($dep === '' || ($deptFilter !== '' && $dep !== $deptFilter)) continue;

                    $key = $dep.'|'.$emp;
                    $counts[$key] = $counts[$key] ?? ['tasks'=>0,'errors'=>0];
                    $counts[$key]['tasks']++;
                    $abnormal = str_contains($note,'REVISION') || str_contains($note,'RETURN') || str_contains($note,'CANCEL') ||
                                str_contains($note,'FAILED') || str_contains($note,'ERROR') || str_contains($to,'revision') ||
                                str_contains($to,'return') || str_contains($to,'cancel');
                    if ($abnormal) $counts[$key]['errors']++;
                }
                foreach ($counts as $key=>$cnt) {
                    [$dep,$emp] = explode('|',$key,2);
                    $append($rows, $dep, $emp, (int)$cnt['tasks'], 'sales_do_audit', (int)$cnt['errors']);
                }
            }
        } catch (Throwable $e) {
            // fail-soft untuk skema audit lama
        }
    }

    // 2) Purchases audit. Departemen actor dari master; PQP hanya fallback.
    if (kpi_table_exists($pdo, 'purchases_audit_log')) {
        try {
            $cols = kpi_table_columns($pdo, 'purchases_audit_log');
            $timeCol = kpi_pick_col($cols, ['created_at','event_at','logged_at']);
            $userCol = kpi_pick_col($cols, ['user_name','created_by','actor','username']);
            if ($timeCol && $userCol) {
                $sql = "SELECT COALESCE({$userCol},'') employee_id, COUNT(*) tasks_done
                        FROM purchases_audit_log
                        WHERE {$timeCol} BETWEEN :d1 AND :d2
                        GROUP BY {$userCol}";
                $st = $pdo->prepare($sql);
                $st->execute([':d1'=>$d1dt, ':d2'=>$d2dt]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $emp = trim((string)($r['employee_id'] ?? ''));
                    $dep = $resolveActorDept($emp, 'PQP');
                    $append($rows, $dep, $emp, (int)($r['tasks_done'] ?? 0), 'purchases_audit_log', null);
                }
            }
        } catch (Throwable $e) {}
    }

    // 3) Fixed asset audit. Departemen actor dari master; ACT hanya fallback.
    if (kpi_table_exists($pdo, 'fa_audit_log')) {
        try {
            $cols = kpi_table_columns($pdo, 'fa_audit_log');
            $timeCol = kpi_pick_col($cols, ['created_at','event_at','logged_at']);
            $userCol = kpi_pick_col($cols, ['actor','user_name','created_by','username']);
            if ($timeCol && $userCol) {
                $sql = "SELECT COALESCE({$userCol},'') employee_id, COUNT(*) tasks_done
                        FROM fa_audit_log
                        WHERE {$timeCol} BETWEEN :d1 AND :d2
                        GROUP BY {$userCol}";
                $st = $pdo->prepare($sql);
                $st->execute([':d1'=>$d1dt, ':d2'=>$d2dt]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $emp = trim((string)($r['employee_id'] ?? ''));
                    $dep = $resolveActorDept($emp, 'ACT');
                    $append($rows, $dep, $emp, (int)($r['tasks_done'] ?? 0), 'fa_audit_log', null);
                }
            }
        } catch (Throwable $e) {}
    }

    // Merge actor + departemen yang sama dari beberapa sumber.
    $merged = [];
    foreach ($rows as $r) {
        $dep = $normalizeDept($r['dept_code'] ?? '');
        $emp = trim((string)($r['employee_id'] ?? ''));
        if ($dep === '' || $emp === '') continue;
        $key = $dep . '|' . strtoupper($emp);
        if (!isset($merged[$key])) {
            $merged[$key] = ['dept_code'=>$dep,'employee_id'=>$emp,'tasks_done'=>0,'errors'=>null,'quality'=>null,'sources'=>[]];
        }
        $merged[$key]['tasks_done'] += max(0,(int)($r['tasks_done'] ?? 0));
        if (array_key_exists('errors',$r) && $r['errors'] !== null) {
            $merged[$key]['errors'] = (int)($merged[$key]['errors'] ?? 0) + max(0,(int)$r['errors']);
        }
        $source = trim((string)($r['source'] ?? ''));
        if ($source !== '') $merged[$key]['sources'][] = $source;
    }

    $out = [];
    foreach ($merged as $m) {
        $m['sources'] = array_values(array_unique($m['sources']));
        if ($m['tasks_done'] > 0 && $m['errors'] !== null) {
            $m['quality'] = round(max(0, (($m['tasks_done']-$m['errors'])/$m['tasks_done'])*100),2);
        }
        $out[] = $m;
    }
    usort($out, fn($a,$b)=> strcmp($a['dept_code'],$b['dept_code']) ?: (($b['tasks_done'] <=> $a['tasks_done']) ?: strcmp($a['employee_id'],$b['employee_id'])));
    return $out;
}

?>
