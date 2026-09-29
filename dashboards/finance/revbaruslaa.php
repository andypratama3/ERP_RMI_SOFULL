<?php
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
if (function_exists('require_login')) { require_login(); }

// Akses: require_rbac + _dashboard_bootstrap + RBAC overlay (DASHBOARD.FINANCE_VIEW).

$scopeCtx        = function_exists('ds_scope_ctx') ? ds_scope_ctx() : ['is_admin' => true, 'office_code' => ''];
$scopeOffice     = (string)($scopeCtx['office_code'] ?? '');

/*
 * Finance dashboard scope policy:
 * - SYS/admin: all offices (existing behaviour).
 * - FIN Manager: all offices for Finance Dashboard analytics/monitoring.
 * - FIN Staff / other non-admin users: keep their office scope.
 *
 * IMPORTANT: this only widens READ scope on this dashboard. It does NOT change
 * approval/payment guards. FIN cash-out approval remains protected by the
 * existing MgrFIN_BGR + SYS business rule in the transaction handlers.
 */
$finCurrentUser = [];
if (function_exists('auth_user')) {
    try { $finCurrentUser = (array)(auth_user() ?: []); } catch (Throwable $e) { $finCurrentUser = []; }
}
if (!$finCurrentUser && isset($_SESSION) && is_array($_SESSION)) {
    $finCurrentUser = (array)($_SESSION['user'] ?? $_SESSION['auth_user'] ?? $_SESSION);
}
$finUserDept = strtoupper(trim((string)(
    $finCurrentUser['department'] ?? $finCurrentUser['dept_code'] ?? $finCurrentUser['dept'] ?? ''
)));
$finUserRole = strtoupper(trim((string)(
    $finCurrentUser['role'] ?? $finCurrentUser['role_code'] ?? $finCurrentUser['level'] ?? ''
)));
$finIsManager = in_array($finUserRole, ['MANAGER','MGR','HEAD','DIRECTOR','DIREKSI'], true)
    || str_contains($finUserRole, 'MANAGER');
$finManagerFullDashboard = in_array($finUserDept, ['FIN','FINANCE'], true) && $finIsManager;

$scopeOfficeFilter = (!$scopeCtx['is_admin'] && !$finManagerFullDashboard && $scopeOffice !== '');

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('normalize_code')) {
    function normalize_code(string $s): string { return preg_replace('/[^A-Z0-9_\-\.]/', '', strtoupper($s)); }
}

$pdo          = $GLOBALS['pdo'] ?? null;
$BASE_PROJECT = $GLOBALS['BASE_PROJECT'] ?? '';

function fin_u($path){ $bp = $GLOBALS['BASE_PROJECT'] ?? ''; return rtrim($bp,'/') . '/' . ltrim((string)$path,'/'); }
function fin_scalar($pdo, string $sql, array $params=[]){
    if (!$pdo) return 0;
    try { $st=$pdo->prepare($sql); $st->execute($params); $v=$st->fetchColumn(); return ($v===false||$v===null)?0:$v; } catch(Throwable $e){ return 0; }
}
function fin_t($pdo, string $t): bool {
    if (!$pdo) return false;
    if (function_exists('kpi_table_exists')) return kpi_table_exists($pdo, $t);
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}
function fin_cols($pdo, string $t): array {
    if (!$pdo || !fin_t($pdo,$t)) return [];
    try { $out=[]; foreach($pdo->query("SHOW COLUMNS FROM `{$t}`") as $r){ $f=(string)($r['Field']??''); if($f!=='') $out[$f]=true; } return $out; }
    catch(Throwable $e){ return []; }
}
function fin_has_col(array $cols, string $c): bool { return isset($cols[$c]) || in_array($c,$cols,true); }
function fin_pick(array $cols, array $candidates): ?string { foreach($candidates as $c) if(fin_has_col($cols,$c)) return $c; return null; }
function fin_money(float $n): string { return 'Rp ' . number_format($n, 0, ',', '.'); }
function fin_money_short(float $n): string {
    if ($n >= 1e9) return 'Rp ' . number_format($n/1e9, 1, ',', '.') . 'M';
    if ($n >= 1e6) return 'Rp ' . number_format($n/1e6, 1, ',', '.') . 'jt';
    if ($n >= 1e3) return 'Rp ' . number_format($n/1e3, 0, ',', '.') . 'rb';
    return 'Rp ' . number_format($n, 0, ',', '.');
}

function fin_duration(?float $sec): string {
    if ($sec === null || $sec < 0) return '—';
    $s = (int)round($sec);
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    if ($d > 0) return $d . ' hari ' . $h . ' jam';
    if ($h > 0) return $h . ' jam ' . $m . ' menit';
    if ($m > 0) return $m . ' menit';
    return $s . ' detik';
}

// ── Period filter ─────────────────────────────────────────────────────────
$period = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$mStart = $period . '-01';
$mEnd   = date('Y-m-t', strtotime($mStart));
$today  = date('Y-m-d');
$prevPeriod = date('Y-m', strtotime($mStart . ' -1 month'));

// ── Cash/Burn/OPEX ────────────────────────────────────────────────────────
$cash = $burn = $opex = 0.0;
$cashSourceAvailable = false;
$cashSourceLabel = 'Belum ada source saldo';
if ($pdo && fin_t($pdo,'company_bank_accounts')) {
    $bankCols = fin_cols($pdo,'company_bank_accounts');
    $bankBalCol = fin_pick($bankCols,['current_balance','balance','saldo','book_balance','ending_balance','available_balance']);
    if ($bankBalCol !== null) {
        $cash = (float)fin_scalar($pdo,"SELECT COALESCE(SUM(COALESCE(`{$bankBalCol}`,0)),0) FROM company_bank_accounts");
        $cashSourceAvailable = true;
        $cashSourceLabel = 'Rekening perusahaan';
    }
}
if ($pdo && function_exists('kpi_policy_get')) {
    $policyCash = (float)kpi_policy_get($pdo,'KPI_FIN','CASH_BALANCE_TOTAL',null,0);
    $burn = (float)kpi_policy_get($pdo,'KPI_FIN','WEEKLY_BURN',null,0);
    $opex = (float)kpi_policy_get($pdo,'KPI_FIN',$period.'|OPEX',null,0);
    if (!$cashSourceAvailable && $policyCash != 0.0) { $cash=$policyCash; $cashSourceAvailable=true; $cashSourceLabel='KPI Finance manual'; }
}
$runwayWeeks = ($cashSourceAvailable && $burn > 0) ? $cash / $burn : 0;

// ── AR config ─────────────────────────────────────────────────────────────
$salesCols = ($pdo && fin_t($pdo,'sales_do')) ? fin_cols($pdo,'sales_do') : [];
$dueCol = fin_pick($salesCols,['act_due_date','fin_due_date','due_date']);
$amtCol = fin_pick($salesCols,['act_amount','grand_total','total_amount']) ?? 'grand_total';

$arStatusesCsv = ($pdo && function_exists('kpi_policy_get')) ? (string)kpi_policy_get($pdo,'KPI_EXEC','AR_UNPAID_STATUSES', null, 'wait_payment') : 'wait_payment';
$arStatuses = array_values(array_filter(array_map('trim', explode(',', $arStatusesCsv))));
if (!$arStatuses) $arStatuses = ['wait_payment'];
$in = implode(',', array_fill(0, count($arStatuses), '?'));

// ── AR Queries ────────────────────────────────────────────────────────────
$arOutstanding = $arOverdue = 0.0;
$arCountTotal  = $arCountOverdue = 0;
$arAging       = ['0_30'=>0.0,'31_60'=>0.0,'61_90'=>0.0,'90p'=>0.0];
$topOverdue    = [];
$arCollectedMTD = 0.0;
$arCollectedCount = 0;

if ($pdo && fin_t($pdo,'sales_do')) {
    $oSql = $scopeOfficeFilter ? " AND UPPER(COALESCE(office_code,'')) = UPPER(?)" : '';
    $oP   = $scopeOfficeFilter ? [$scopeOffice] : [];

    // Outstanding
    $arOutstanding = (float)fin_scalar($pdo,
        "SELECT COALESCE(SUM(COALESCE($amtCol,0)),0) FROM sales_do WHERE status IN ($in){$oSql}",
        array_merge($arStatuses, $oP));
    $arCountTotal = (int)fin_scalar($pdo,
        "SELECT COUNT(*) FROM sales_do WHERE status IN ($in){$oSql}",
        array_merge($arStatuses, $oP));

    // Collected MTD (paid this month)
    $paidStatuses = ['paid','fin_done','closed'];
    $paidIn = implode(',', array_fill(0, count($paidStatuses), '?'));
    try {
        $paidDateCol = fin_pick($salesCols,['fin_paid_at','fin_paid_date','payment_date','paid_at','updated_at']) ?? 'updated_at';
        $st = $pdo->prepare("
            SELECT COUNT(*), COALESCE(SUM(COALESCE($amtCol,0)),0)
            FROM sales_do
            WHERE LOWER(COALESCE(status,'')) IN ($paidIn)
              AND DATE_FORMAT(COALESCE($paidDateCol,do_date),'%Y-%m')=?
              {$oSql}
        ");
        $st->execute(array_merge($paidStatuses, [$period], $oP));
        [$arCollectedCount, $arCollectedMTD] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $arCollectedCount = (int)$arCollectedCount;
        $arCollectedMTD   = (float)$arCollectedMTD;
    } catch (Throwable $e) {}

    // Overdue + Aging
    if ($dueCol && fin_has_col($salesCols,$dueCol)) {
        $arOverdue = (float)fin_scalar($pdo,
            "SELECT COALESCE(SUM(COALESCE($amtCol,0)),0) FROM sales_do WHERE status IN ($in) AND $dueCol IS NOT NULL AND $dueCol < CURDATE(){$oSql}",
            array_merge($arStatuses, $oP));
        $arCountOverdue = (int)fin_scalar($pdo,
            "SELECT COUNT(*) FROM sales_do WHERE status IN ($in) AND $dueCol IS NOT NULL AND $dueCol < CURDATE(){$oSql}",
            array_merge($arStatuses, $oP));

        try {
            $st = $pdo->prepare("
                SELECT
                  SUM(CASE WHEN DATEDIFF(CURDATE(),$dueCol) BETWEEN 1 AND 30  THEN COALESCE($amtCol,0) ELSE 0 END) AS b0_30,
                  SUM(CASE WHEN DATEDIFF(CURDATE(),$dueCol) BETWEEN 31 AND 60 THEN COALESCE($amtCol,0) ELSE 0 END) AS b31_60,
                  SUM(CASE WHEN DATEDIFF(CURDATE(),$dueCol) BETWEEN 61 AND 90 THEN COALESCE($amtCol,0) ELSE 0 END) AS b61_90,
                  SUM(CASE WHEN DATEDIFF(CURDATE(),$dueCol) > 90              THEN COALESCE($amtCol,0) ELSE 0 END) AS b90p
                FROM sales_do
                WHERE status IN ($in) AND $dueCol IS NOT NULL AND $dueCol < CURDATE() {$oSql}
            ");
            $st->execute(array_merge($arStatuses, $oP));
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['0_30','31_60','61_90','90p'] as $k) {
                $arAging[$k] = (float)($r['b'.$k] ?? 0);
            }
        } catch (Throwable $e) {}

        // Top AR Overdue — gunakan relasi yang sama dengan Rekap Piutang yang sudah berjalan:
        // sales_do.customers_code -> master_customers.customers_code.
        // Query ini sengaja sederhana agar tidak gagal diam-diam karena variasi schema.
        try {
            $officeClauseTop = '';
            $topParams = $arStatuses;
            if ($scopeOfficeFilter && fin_has_col($salesCols,'office_code')) {
                $officeClauseTop = " AND UPPER(COALESCE(d.office_code,'')) = UPPER(?)";
                $topParams[] = $scopeOffice;
            }

            // master_customers pada ERP menggunakan customers_code + customers_name.
            // Jika tabel master tidak tersedia, kode customer tetap ditampilkan.
            $hasMasterCustomer = fin_t($pdo,'master_customers');
            $custJoin = $hasMasterCustomer
                ? "LEFT JOIN (SELECT customers_code, MAX(customers_name) AS customers_name FROM master_customers GROUP BY customers_code) c ON c.customers_code = d.customers_code"
                : "";
            $custNameExpr = $hasMasterCustomer
                ? "COALESCE(NULLIF(TRIM(c.customers_name),''), NULLIF(TRIM(d.customers_code),''), 'UNKNOWN')"
                : "COALESCE(NULLIF(TRIM(d.customers_code),''), 'UNKNOWN')";

            $st = $pdo->prepare("
                SELECT
                    COALESCE(NULLIF(TRIM(d.customers_code),''),'UNKNOWN') AS customers_code,
                    {$custNameExpr} AS customer_name,
                    COUNT(*) AS cnt_do,
                    MIN(d.`{$dueCol}`) AS oldest_due,
                    SUM(COALESCE(d.`{$amtCol}`,0)) AS amount
                FROM sales_do d
                {$custJoin}
                WHERE LOWER(TRIM(COALESCE(d.status,''))) IN ($in)
                  AND d.`{$dueCol}` IS NOT NULL
                  AND d.`{$dueCol}` < CURDATE()
                  {$officeClauseTop}
                GROUP BY d.customers_code, customer_name
                ORDER BY amount DESC
                LIMIT 30
            ");
            $st->execute($topParams);
            $topOverdue = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Fallback tanpa master customer: tetap tampilkan kode customer, jangan membuat false-empty.
            try {
                $officeClauseTop = '';
                $topParams = $arStatuses;
                if ($scopeOfficeFilter && fin_has_col($salesCols,'office_code')) {
                    $officeClauseTop = " AND UPPER(COALESCE(d.office_code,'')) = UPPER(?)";
                    $topParams[] = $scopeOffice;
                }
                $st = $pdo->prepare("
                    SELECT
                        COALESCE(NULLIF(TRIM(d.customers_code),''),'UNKNOWN') AS customers_code,
                        COALESCE(NULLIF(TRIM(d.customers_code),''),'UNKNOWN') AS customer_name,
                        COUNT(*) AS cnt_do,
                        MIN(d.`{$dueCol}`) AS oldest_due,
                        SUM(COALESCE(d.`{$amtCol}`,0)) AS amount
                    FROM sales_do d
                    WHERE LOWER(TRIM(COALESCE(d.status,''))) IN ($in)
                      AND d.`{$dueCol}` IS NOT NULL
                      AND d.`{$dueCol}` < CURDATE()
                      {$officeClauseTop}
                    GROUP BY d.customers_code
                    ORDER BY amount DESC
                    LIMIT 30
                ");
                $st->execute($topParams);
                $topOverdue = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e2) {
                $topOverdue = [];
            }
        }
    }
}

// ── Opening AR / piutang lama ─────────────────────────────────────────────
if ($pdo && fin_t($pdo,'fin_ar_opening')) {
    $oc=fin_cols($pdo,'fin_ar_opening');
    $oSql=($scopeOfficeFilter && fin_has_col($oc,'office_code')) ? " AND UPPER(COALESCE(o.office_code,''))=UPPER(?)" : '';
    $oParams=$oSql!=='' ? [$scopeOffice] : [];
    $pc=fin_t($pdo,'fin_ar_opening_payments') ? fin_cols($pdo,'fin_ar_opening_payments') : [];
    $fk=fin_pick($pc,['opening_id','opening_ar_id']);
    $pj=$fk!==null ? "LEFT JOIN (SELECT `{$fk}` opening_id,SUM(amount) paid_extra FROM fin_ar_opening_payments GROUP BY `{$fk}`) px ON px.opening_id=o.id" : "LEFT JOIN (SELECT NULL opening_id,0 paid_extra) px ON 1=0";
    $oe="GREATEST(COALESCE(o.original_amount,0)-COALESCE(o.paid_amount,0)-COALESCE(px.paid_extra,0),0)";
    try { $st=$pdo->prepare("SELECT COUNT(*) cnt,COALESCE(SUM($oe),0) amt FROM fin_ar_opening o $pj WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED' AND $oe>0.01 $oSql"); $st->execute($oParams); $r=$st->fetch(PDO::FETCH_ASSOC)?:[]; $arCountTotal+=(int)($r['cnt']??0); $arOutstanding+=(float)($r['amt']??0); } catch(Throwable $e) {}
    if (fin_has_col($oc,'due_date')) {
        try { $st=$pdo->prepare("SELECT COUNT(*) cnt,COALESCE(SUM($oe),0) amt FROM fin_ar_opening o $pj WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED' AND $oe>0.01 AND o.due_date IS NOT NULL AND o.due_date<CURDATE() $oSql"); $st->execute($oParams); $r=$st->fetch(PDO::FETCH_ASSOC)?:[]; $arCountOverdue+=(int)($r['cnt']??0); $arOverdue+=(float)($r['amt']??0); } catch(Throwable $e) {}
        try { $st=$pdo->prepare("SELECT CASE WHEN DATEDIFF(CURDATE(),o.due_date) BETWEEN 1 AND 30 THEN '0_30' WHEN DATEDIFF(CURDATE(),o.due_date) BETWEEN 31 AND 60 THEN '31_60' WHEN DATEDIFF(CURDATE(),o.due_date) BETWEEN 61 AND 90 THEN '61_90' ELSE '90p' END bucket,COALESCE(SUM($oe),0) amt FROM fin_ar_opening o $pj WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED' AND $oe>0.01 AND o.due_date IS NOT NULL AND o.due_date<CURDATE() $oSql GROUP BY bucket"); $st->execute($oParams); foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$b=$r['bucket']??''; if(isset($arAging[$b])) $arAging[$b]+=(float)($r['amt']??0);} } catch(Throwable $e) {}

        // Gabungkan piutang lama ke Top AR Overdue agar panel konsisten dengan total AR overdue.
        try {
            $st=$pdo->prepare("
                SELECT
                    COALESCE(NULLIF(TRIM(o.customer_code),''),'LEGACY') AS customers_code,
                    COALESCE(NULLIF(TRIM(o.customer_name),''), NULLIF(TRIM(o.customer_code),''), 'Piutang Lama') AS customer_name,
                    COUNT(*) AS cnt_do,
                    MIN(o.due_date) AS oldest_due,
                    COALESCE(SUM($oe),0) AS amount
                FROM fin_ar_opening o
                $pj
                WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED'
                  AND $oe>0.01
                  AND o.due_date IS NOT NULL
                  AND o.due_date<CURDATE()
                  $oSql
                GROUP BY o.customer_code, o.customer_name
                ORDER BY amount DESC
                LIMIT 30
            ");
            $st->execute($oParams);
            $legacyTop=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($legacyTop) $topOverdue=array_merge($topOverdue,$legacyTop);
        } catch(Throwable $e) {}
    }
}

// Finalisasi Top AR Overdue: gabungkan ERP + opening AR per customer, urutkan nilai terbesar.
if ($topOverdue) {
    $mergedTop=[];
    foreach ($topOverdue as $r) {
        $code=trim((string)($r['customers_code']??''));
        if ($code==='') $code='UNKNOWN';
        $key=strtoupper($code);
        if (!isset($mergedTop[$key])) {
            $mergedTop[$key]=[
                'customers_code'=>$code,
                'customer_name'=>trim((string)($r['customer_name']??'')) ?: $code,
                'cnt_do'=>0,
                'oldest_due'=>null,
                'amount'=>0.0,
            ];
        }
        $mergedTop[$key]['cnt_do'] += (int)($r['cnt_do']??0);
        $mergedTop[$key]['amount'] += (float)($r['amount']??0);
        $od=(string)($r['oldest_due']??'');
        if ($od!=='' && ($mergedTop[$key]['oldest_due']===null || $od<$mergedTop[$key]['oldest_due'])) $mergedTop[$key]['oldest_due']=$od;
        $nm=trim((string)($r['customer_name']??''));
        if ($nm!=='' && $nm!==$code && ($mergedTop[$key]['customer_name']===$code || $mergedTop[$key]['customer_name']==='UNKNOWN')) $mergedTop[$key]['customer_name']=$nm;
    }
    $topOverdue=array_values($mergedTop);
    usort($topOverdue, fn($a,$b)=>(float)($b['amount']??0) <=> (float)($a['amount']??0));
    $topOverdue=array_slice($topOverdue,0,10);
}

// ── AP Queries ────────────────────────────────────────────────────────────
$apOutstanding = $apOverdue = 0.0;
$apCount = $apOverdueCount = 0;
$apDueWeek = [];
$apAgingBuckets = ['overdue'=>0.0,'1_7'=>0.0,'8_30'=>0.0,'30plus'=>0.0];

if ($pdo) {
    $pLib = ($GLOBALS['ERP_ROOT'] ?? dirname(dirname(__DIR__))) . '/purchases/_purchases_lib.php';
    if (is_file($pLib)) { require_once $pLib; if (function_exists('p_ensure_schema')) { @p_ensure_schema($pdo); } }

    if (fin_t($pdo,'purchases_invoice_ap')) {
        // Outstanding with correct subtraction
        $apCount = (int)fin_scalar($pdo, "SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL AND status IN ('UNPAID','PARTIAL')");
        $apOutstanding = (float)fin_scalar($pdo, "
            SELECT COALESCE(SUM(ap.total_amount - COALESCE(paid.paid_sum,0)),0)
            FROM purchases_invoice_ap ap
            LEFT JOIN (SELECT ap_id, SUM(amount) AS paid_sum FROM purchases_payment_ap WHERE deleted_at IS NULL GROUP BY ap_id) paid ON paid.ap_id = ap.id
            WHERE ap.deleted_at IS NULL AND ap.status IN ('UNPAID','PARTIAL')
        ");

        // AP Overdue
        $apOverdueCount = (int)fin_scalar($pdo, "SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL AND status IN ('UNPAID','PARTIAL') AND due_date IS NOT NULL AND due_date < CURDATE()");
        $apOverdue = (float)fin_scalar($pdo, "
            SELECT COALESCE(SUM(ap.total_amount - COALESCE(paid.paid_sum,0)),0)
            FROM purchases_invoice_ap ap
            LEFT JOIN (SELECT ap_id, SUM(amount) AS paid_sum FROM purchases_payment_ap WHERE deleted_at IS NULL GROUP BY ap_id) paid ON paid.ap_id = ap.id
            WHERE ap.deleted_at IS NULL AND ap.status IN ('UNPAID','PARTIAL') AND ap.due_date IS NOT NULL AND ap.due_date < CURDATE()
        ");

        // AP Aging buckets
        try {
            $st = $pdo->query("
                SELECT CASE
                    WHEN due_date IS NULL THEN '30plus'
                    WHEN due_date < CURDATE() THEN 'overdue'
                    WHEN DATEDIFF(due_date,CURDATE()) <= 7  THEN '1_7'
                    WHEN DATEDIFF(due_date,CURDATE()) <= 30 THEN '8_30'
                    ELSE '30plus'
                END AS bucket,
                COALESCE(SUM(ap.total_amount - COALESCE(paid.paid_sum,0)),0) AS amt
                FROM purchases_invoice_ap ap
                LEFT JOIN (SELECT ap_id, SUM(amount) AS paid_sum FROM purchases_payment_ap WHERE deleted_at IS NULL GROUP BY ap_id) paid ON paid.ap_id = ap.id
                WHERE ap.deleted_at IS NULL AND ap.status IN ('UNPAID','PARTIAL')
                GROUP BY bucket
            ");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $b = $r['bucket'] ?? 'overdue';
                if (isset($apAgingBuckets[$b])) $apAgingBuckets[$b] = (float)$r['amt'];
            }
        } catch (Throwable $e) {}

        // AP Due this week with supplier name
        try {
            $hasMfr = fin_t($pdo, 'master_manufactures');
            $mfrCol  = $hasMfr ? "COALESCE(NULLIF(m.manufacture_name,''), ap.ap_code)" : "ap.ap_code";
            $mfrJoin = $hasMfr ? "LEFT JOIN master_manufactures m ON m.id = ap.manufacture_id" : "";
            $st = $pdo->query("
                SELECT ap.ap_code, ap.invoice_number, ap.due_date, ap.currency,
                       ap.total_amount, ap.status, {$mfrCol} AS supplier_name
                FROM purchases_invoice_ap ap {$mfrJoin}
                WHERE ap.deleted_at IS NULL AND ap.status IN ('UNPAID','PARTIAL')
                  AND ap.due_date IS NOT NULL
                  AND ap.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                ORDER BY ap.due_date ASC LIMIT 15
            ");
            $apDueWeek = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $apDueWeek = []; }
    }

    // Opening AP / hutang lama hasil import. Ditambahkan ke KPI tanpa mengubah purchases_invoice_ap.
    if (fin_t($pdo,'fin_ap_opening')) {
        $hasOpeningPay = fin_t($pdo,'fin_ap_opening_payments');
        $openPayJoin = $hasOpeningPay
            ? "LEFT JOIN (SELECT opening_ap_id,SUM(amount) paid_sum FROM fin_ap_opening_payments WHERE deleted_at IS NULL GROUP BY opening_ap_id) op ON op.opening_ap_id=o.id"
            : "LEFT JOIN (SELECT NULL opening_ap_id,0 paid_sum) op ON 1=0";
        $openOfficeSql = $scopeOfficeFilter ? " AND UPPER(COALESCE(o.office_code,''))=UPPER(?)" : '';
        $openOfficeParams = $scopeOfficeFilter ? [$scopeOffice] : [];
        $outExpr = "GREATEST(o.original_amount-COALESCE(o.paid_amount,0)-COALESCE(op.paid_sum,0),0)";

        try {
            $st=$pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM($outExpr),0) amt FROM fin_ap_opening o $openPayJoin WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED' AND $outExpr>0.01 $openOfficeSql");
            $st->execute($openOfficeParams); $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
            $apCount += (int)($r['cnt']??0); $apOutstanding += (float)($r['amt']??0);
        } catch(Throwable $e) {}

        try {
            $st=$pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM($outExpr),0) amt FROM fin_ap_opening o $openPayJoin WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED' AND $outExpr>0.01 AND o.due_date IS NOT NULL AND o.due_date<CURDATE() $openOfficeSql");
            $st->execute($openOfficeParams); $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
            $apOverdueCount += (int)($r['cnt']??0); $apOverdue += (float)($r['amt']??0);
        } catch(Throwable $e) {}

        try {
            $st=$pdo->prepare("SELECT CASE WHEN o.due_date IS NULL THEN '30plus' WHEN o.due_date<CURDATE() THEN 'overdue' WHEN DATEDIFF(o.due_date,CURDATE())<=7 THEN '1_7' WHEN DATEDIFF(o.due_date,CURDATE())<=30 THEN '8_30' ELSE '30plus' END bucket, COALESCE(SUM($outExpr),0) amt FROM fin_ap_opening o $openPayJoin WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED' AND $outExpr>0.01 $openOfficeSql GROUP BY bucket");
            $st->execute($openOfficeParams);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $b=$r['bucket']??'30plus'; if(isset($apAgingBuckets[$b])) $apAgingBuckets[$b]+=(float)($r['amt']??0); }
        } catch(Throwable $e) {}

        try {
            $st=$pdo->prepare("SELECT o.legacy_document_no ap_code,o.legacy_document_no invoice_number,o.due_date,COALESCE(NULLIF(o.currency,''),'IDR') currency,$outExpr total_amount,CASE WHEN (COALESCE(o.paid_amount,0)+COALESCE(op.paid_sum,0))>0 THEN 'PARTIAL' ELSE 'UNPAID' END status,o.supplier_name FROM fin_ap_opening o $openPayJoin WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED' AND $outExpr>0.01 AND o.due_date IS NOT NULL AND o.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY) $openOfficeSql ORDER BY o.due_date ASC LIMIT 15");
            $st->execute($openOfficeParams); $apDueWeek=array_merge($apDueWeek,$st->fetchAll(PDO::FETCH_ASSOC) ?: []);
            usort($apDueWeek,fn($a,$b)=>strcmp((string)($a['due_date']??''),(string)($b['due_date']??''))); $apDueWeek=array_slice($apDueWeek,0,15);
        } catch(Throwable $e) {}
    }
}


// ── REG Alkes Registration Payments (FIN) ────────────────────────────────
$regPayPendingCount = 0;
$regPayPendingAmount = 0.0;
$regPayOverdueCount = 0;
$regPayDueSoonCount = 0;
$regPayPending = [];

if ($pdo && fin_t($pdo, 'hrl_reg_alkes_payments')) {
    try {
        $hasCase = fin_t($pdo, 'hrl_reg_alkes_cases');
        $caseJoin = $hasCase ? "LEFT JOIN hrl_reg_alkes_cases c ON c.id = p.case_id" : "";
        $caseCols = $hasCase
            ? "c.manufacture_name, c.product_name, c.stage_no, c.status AS case_status"
            : "NULL AS manufacture_name, NULL AS product_name, NULL AS stage_no, NULL AS case_status";

        $openPayWhere = "UPPER(COALESCE(p.payment_status,'UNPAID')) NOT IN ('PAID','CANCELLED')";

        $regPayPendingCount = (int)fin_scalar($pdo, "
            SELECT COUNT(*)
            FROM hrl_reg_alkes_payments p
            WHERE {$openPayWhere}
        ");

        $regPayPendingAmount = (float)fin_scalar($pdo, "
            SELECT COALESCE(SUM(COALESCE(p.amount,0)),0)
            FROM hrl_reg_alkes_payments p
            WHERE {$openPayWhere}
        ");

        $regPayOverdueCount = (int)fin_scalar($pdo, "
            SELECT COUNT(*)
            FROM hrl_reg_alkes_payments p
            WHERE {$openPayWhere}
              AND p.due_date IS NOT NULL
              AND p.due_date < CURDATE()
        ");

        $regPayDueSoonCount = (int)fin_scalar($pdo, "
            SELECT COUNT(*)
            FROM hrl_reg_alkes_payments p
            WHERE {$openPayWhere}
              AND p.due_date IS NOT NULL
              AND p.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        ");

        $st = $pdo->prepare("
            SELECT
                p.id,
                p.case_id,
                p.case_code,
                p.billing_no,
                p.invoice_no,
                p.vendor_name,
                p.amount,
                p.payment_status,
                p.invoice_date,
                p.due_date,
                p.paid_date,
                p.proof_file_rel,
                p.updated_at,
                {$caseCols}
            FROM hrl_reg_alkes_payments p
            {$caseJoin}
            WHERE {$openPayWhere}
            ORDER BY
                CASE
                    WHEN p.due_date IS NOT NULL AND p.due_date < CURDATE() THEN 0
                    WHEN p.due_date IS NOT NULL THEN 1
                    ELSE 2
                END,
                p.due_date ASC,
                p.id DESC
            LIMIT 12
        ");
        $st->execute();
        $regPayPending = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $regPayPendingCount = 0;
        $regPayPendingAmount = 0.0;
        $regPayOverdueCount = 0;
        $regPayDueSoonCount = 0;
        $regPayPending = [];
    }
}

// ── FIN DO Tasks ──────────────────────────────────────────────────────────
$finDoTasks = 0;
$finDoAmount = 0.0;

// Avg Durasi FIN:
// start  = ACT handoff ke FIN (wait_payment / act_done)
// finish = FIN menyelesaikan pembayaran (paid / fin_done / closed)
// periode mengikuti waktu finish, agar histori tidak terus berjalan setelah FIN selesai.
$finAvgDurationSec = null;
$finDurationMeasured = 0;
if ($pdo && fin_t($pdo,'sales_do')) {
    $finStatuses = ['wait_payment','act_done'];
    $finPh = implode(',', array_fill(0, count($finStatuses), '?'));
    $finOfficeSql = ($scopeOfficeFilter && fin_has_col($salesCols,'office_code')) ? " AND UPPER(COALESCE(office_code,''))=UPPER(?)" : '';
    $finOfficeParams = $finOfficeSql!=='' ? [$scopeOffice] : [];
    $finDoTasks  = (int)fin_scalar($pdo, "SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ($finPh){$finOfficeSql}", array_merge($finStatuses,$finOfficeParams));
    $finDoAmount = (float)fin_scalar($pdo, "SELECT COALESCE(SUM(COALESCE($amtCol,0)),0) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ($finPh){$finOfficeSql}", array_merge($finStatuses,$finOfficeParams));
}


// ── Avg Durasi SLA FIN ─────────────────────────────────────────────────────
// Mengukur durasi kerja FIN saja, bukan total umur DO.
// Prioritas audit immutable:
//   start  = status masuk FIN: wait_payment / act_done
//   finish = status selesai FIN: paid / fin_done / closed
// Fallback ke kolom sales_do dipakai hanya jika audit start tidak tersedia.
if ($pdo && fin_t($pdo,'sales_do')) {
    try {
        $hasAudit = fin_t($pdo,'sales_do_audit');
        $auditCols = $hasAudit ? fin_cols($pdo,'sales_do_audit') : [];
        $hasAuditCore = $hasAudit
            && fin_has_col($auditCols,'do_id')
            && fin_has_col($auditCols,'status_to')
            && fin_has_col($auditCols,'created_at');

        if ($hasAuditCore) {
            $fallbackStartExpr = fin_has_col($salesCols,'act_ready_fin_at')
                ? 'sd.act_ready_fin_at'
                : (fin_has_col($salesCols,'updated_at') ? 'sd.updated_at' : 'NULL');

            $fallbackFinishExpr = fin_has_col($salesCols,'fin_paid_at')
                ? 'sd.fin_paid_at'
                : (fin_has_col($salesCols,'fin_paid_date') ? 'sd.fin_paid_date' : 'NULL');

            $officeJoinFilter = '';
            $durationParams = [$mStart, $mEnd];
            if ($scopeOfficeFilter && fin_has_col($salesCols,'office_code')) {
                $officeJoinFilter = " AND UPPER(COALESCE(sd.office_code,''))=UPPER(?)";
                $durationParams[] = $scopeOffice;
            }

            $sqlFinDuration = "
                SELECT COUNT(*) AS measured_count,
                       AVG(TIMESTAMPDIFF(SECOND, start_at, finish_at)) AS avg_sec
                FROM (
                    SELECT
                        f.do_id,
                        COALESCE(
                            (
                                SELECT MAX(a1.created_at)
                                FROM sales_do_audit a1
                                WHERE a1.do_id=f.do_id
                                  AND LOWER(COALESCE(a1.status_to,'')) IN ('wait_payment','act_done')
                                  AND a1.created_at<=f.finish_at
                            ),
                            {$fallbackStartExpr}
                        ) AS start_at,
                        COALESCE(f.finish_at, {$fallbackFinishExpr}) AS finish_at
                    FROM (
                        SELECT do_id, MIN(created_at) AS finish_at
                        FROM sales_do_audit
                        WHERE LOWER(COALESCE(status_to,'')) IN ('paid','fin_done','closed')
                          AND created_at>=?
                          AND created_at<DATE_ADD(?, INTERVAL 1 DAY)
                        GROUP BY do_id
                    ) f
                    LEFT JOIN sales_do sd ON sd.id=f.do_id
                    WHERE 1=1 {$officeJoinFilter}
                ) x
                WHERE start_at IS NOT NULL
                  AND finish_at IS NOT NULL
                  AND finish_at>=start_at
            ";
            $st = $pdo->prepare($sqlFinDuration);
            $st->execute($durationParams);
            $dur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $finDurationMeasured = (int)($dur['measured_count'] ?? 0);
            $finAvgDurationSec = $dur['avg_sec'] !== null ? (float)$dur['avg_sec'] : null;
        } else {
            // Fallback aman untuk instalasi lama tanpa audit lengkap:
            // act_ready_fin_at -> fin_paid_at/fin_paid_date.
            $startCol = fin_pick($salesCols,['act_ready_fin_at']);
            $finishCol = fin_pick($salesCols,['fin_paid_at','fin_paid_date']);
            if ($startCol && $finishCol) {
                $sql = "
                    SELECT COUNT(*) AS measured_count,
                           AVG(TIMESTAMPDIFF(SECOND, `{$startCol}`, `{$finishCol}`)) AS avg_sec
                    FROM sales_do
                    WHERE `{$startCol}` IS NOT NULL
                      AND `{$finishCol}` IS NOT NULL
                      AND `{$finishCol}`>=`{$startCol}`
                      AND DATE(`{$finishCol}`) BETWEEN ? AND ?
                ";
                $params = [$mStart,$mEnd];
                if ($scopeOfficeFilter && fin_has_col($salesCols,'office_code')) {
                    $sql .= " AND UPPER(COALESCE(office_code,''))=UPPER(?)";
                    $params[] = $scopeOffice;
                }
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $dur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $finDurationMeasured = (int)($dur['measured_count'] ?? 0);
                $finAvgDurationSec = $dur['avg_sec'] !== null ? (float)$dur['avg_sec'] : null;
            }
        }
    } catch (Throwable $e) {
        $finDurationMeasured = 0;
        $finAvgDurationSec = null;
    }
}

// ── Payroll status ────────────────────────────────────────────────────────
$payrollRun = null;
if ($pdo && fin_t($pdo,'payroll_runs')) {
    try {
        $st = $pdo->prepare("SELECT id, period_ym, status, posted_at, paid_at, payment_reference,
            (SELECT COALESCE(SUM(net_pay),0) FROM payroll_run_items WHERE run_id=payroll_runs.id) AS total_net,
            (SELECT COALESCE(SUM(amount),0) FROM payroll_payments WHERE run_id=payroll_runs.id AND status IN ('RECORDED','PAID')) AS total_paid,
            (SELECT COUNT(*) FROM payroll_run_items WHERE run_id=payroll_runs.id) AS emp_count
            FROM payroll_runs WHERE period_ym=? ORDER BY id DESC LIMIT 1");
        $st->execute([$period]);
        $payrollRun = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
}

// ── Net position ──────────────────────────────────────────────────────────
$netPosition = $arOutstanding - $apOutstanding;

require_once __DIR__ . '/../../_shared/rmi_layout.php';

// FIX: active was 'payroll' — wrong
rmi_header('Finance Dashboard', [
    'active'     => 'dashboard',
    'subtitle'   => 'AR · AP · Cash · Payroll · Periode ' . h($period),
    'extra_head' => '<style>
body{background:#0b1220;color:#e5e7eb}
.card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);border-radius:16px}
.muted{color:#9ca3af;font-size:12px}
table{color:#e5e7eb}
.fin-hdr{background:linear-gradient(135deg,rgba(2,68,20,.8),rgba(4,120,87,.6));border:1px solid rgba(16,185,129,.3);border-radius:16px;padding:18px 22px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.fin-hdr h2{margin:0;font-size:19px;font-weight:800;color:#fff}
.fin-hdr p{margin:3px 0 0;font-size:12px;color:rgba(255,255,255,.65)}
/* KPI */
.fin-kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px;margin-bottom:14px}
.fin-k{padding:13px;border-radius:12px;background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.09);border-top:3px solid var(--kc,#64748b);transition:.2s}
.fin-k:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.3)}
.fin-k-icon{font-size:18px;margin-bottom:5px}
.fin-k-val{font-size:16px;font-weight:800;color:#fff;line-height:1.2;font-variant-numeric:tabular-nums}
.fin-k-val.lg{font-size:24px}
.fin-k-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-top:3px}
.fin-k-sub{font-size:10px;color:var(--rmi-muted);margin-top:2px}
/* Section heading */
.fin-sh{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--rmi-muted);margin:14px 0 8px;display:flex;align-items:center;gap:8px}
.fin-sh::after{content:"";flex:1;height:1px;background:rgba(255,255,255,.08)}
/* Aging bar */
.aging-row{margin-bottom:10px}
.aging-bar{height:8px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;margin-top:3px}
.aging-fill{height:8px;border-radius:4px;transition:.3s}
/* Mini table */
.fin-tbl{width:100%;border-collapse:collapse;font-size:12px}
.fin-tbl th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted);padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;white-space:nowrap}
.fin-tbl td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
.fin-tbl tr:hover td{background:rgba(255,255,255,.02)}
.fin-tbl tr:last-child td{border-bottom:none}
/* Quick links */
.fin-links{display:flex;flex-wrap:wrap;gap:6px}
.fin-link{padding:7px 13px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid rgba(255,255,255,.12);color:#e2e8f0;background:rgba(255,255,255,.05);transition:.15s;display:inline-flex;align-items:center;gap:5px}
.fin-link:hover{background:rgba(255,255,255,.12);color:#fff}
.fin-link.primary{background:linear-gradient(135deg,#059669,#10b981);border-color:transparent;color:#fff}
/* Period buttons */
.fin-pb{padding:4px 12px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:#94a3b8;font-size:11px;font-weight:600;text-decoration:none;transition:.12s}
.fin-pb:hover,.fin-pb.act{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.4);color:#34d399}
</style>',
    'actions' => [
        ['label'=>rmi_icon('books').' Panduan',    'url'=>fin_u('/dashboards/finance/panduan.php'),   'class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'FIN Tasks',      'url'=>fin_u('/sales/fin_do_tasks.php'),          'class'=>'btn btn-sm btn-rmi'],
        ['label'=>'AP Invoice',     'url'=>fin_u('/purchases/purchases_invoice_ap.php'),'class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'AP Payment',     'url'=>fin_u('/purchases/purchases_payment_ap.php'),'class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'Local Purchase',  'url'=>fin_u('/purchases/purchases_control_tower.php'),'class'=>'btn btn-sm btn-outline-info'],
        ['label'=>'Import Tower',    'url'=>fin_u('/purchases/purchases_import_control_tower.php'),'class'=>'btn btn-sm btn-outline-warning'],
        ['label'=>'Rekap Hutang',    'url'=>fin_u('/dashboards/finance/ap_rekap.php'),'class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'Import Hutang',   'url'=>fin_u('/purchases/purchases_ap_import.php'),'class'=>'btn btn-sm btn-outline-warning'],
        ['label'=>'Reg Alkes Pay',  'url'=>fin_u('/dashboards/finance/reg_alkes_payments.php'),'class'=>'btn btn-sm btn-outline-warning'],
        ['label'=>'Tax Invoice',    'url'=>fin_u('/sales/tax_invoices.php'),           'class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'Payroll',        'url'=>fin_u('/payroll/index.php'),                'class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'Target Finance', 'url'=>fin_u('/dashboards/finance/target_rekap.php'), 'class'=>'btn btn-sm btn-outline-info'],
        ['label'=>'Adjustment',     'url'=>fin_u('/dashboards/finance/adjustment_manage.php'), 'class'=>'btn btn-sm btn-outline-warning'],
        ['label'=>'KPI Center',     'url'=>fin_u('/kpi/kpi_center.php'),               'class'=>'btn btn-sm btn-outline-light'],
    ],
]);
?>
<div class="container py-3" style="max-width:1240px">
  <?php ds_manager_section($pdo, [
      'backlog_table'         => 'sales_do',
      'backlog_status_col'    => 'status',
      'backlog_open_statuses' => ['wait_payment','act_done'],
      'backlog_office_col'    => 'office_code',
      'exceptions_count'      => $arCountOverdue + $apOverdueCount + $regPayOverdueCount,
      'extra_metrics'         => [
          ['label'=>'AR Overdue','value'=>$arCountOverdue],
          ['label'=>'AP Overdue','value'=>$apOverdueCount],
          ['label'=>'Reg Alkes OD','value'=>$regPayOverdueCount],
          ['label'=>'Avg Durasi FIN','value'=>fin_duration($finAvgDurationSec)],
      ],
  ]); ?>

  <!-- Header -->
  <div class="fin-hdr">
    <div>
      <h2><?=rmi_icon('money')?> Finance Dashboard</h2>
      <p>AR · AP · Cash · Payroll — <?= h($period) ?><?= $scopeOfficeFilter ? ' · Office: '.h($scopeOffice) : ' · All Office' ?><?= $finManagerFullDashboard ? ' · FIN Manager Full Scope' : '' ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <!-- Quick period buttons -->
      <?php
      $pBtns = [];
      for ($i = 2; $i >= 0; $i--) { $m = date('Y-m', strtotime("-{$i} month")); $pBtns[$m] = date('M Y', strtotime("-{$i} month")); }
      foreach ($pBtns as $val => $lbl):
      ?>
        <a class="fin-pb <?= $period===$val?'act':'' ?>" href="?m=<?= urlencode($val) ?>"><?= h($lbl) ?></a>
      <?php endforeach; ?>
      <input type="month" class="form-control form-control-sm" style="max-width:130px;background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.12);color:#e2e8f0"
             value="<?= h($period) ?>" onchange="location.href='?m='+this.value">
      <a href="<?= h(fin_u('/dashboards/index.php')) ?>" class="fin-link" style="font-size:12px"><?=rmi_icon('home')?> Home</a>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($finDoTasks > 0): ?>
  <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
    <span style="font-size:18px"><?=rmi_icon('money')?></span>
    <span><strong style="color:#34d399"><?= $finDoTasks ?> DO menunggu payment</strong>
    <span style="color:#94a3b8"> — Total <?= fin_money_short($finDoAmount) ?></span></span>
    <a href="<?= h(fin_u('/sales/fin_do_tasks.php')) ?>" style="margin-left:auto;color:#34d399;font-size:11px;text-decoration:none">Collect sekarang →</a>
  </div>
  <?php endif; ?>
  <?php if ($arCountOverdue > 0): ?>
  <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
    <span style="font-size:18px"><?=rmi_icon('warn')?></span>
    <span><strong style="color:#f87171"><?= $arCountOverdue ?> AR Overdue</strong>
    <span style="color:#94a3b8"> — <?= fin_money_short($arOverdue) ?> piutang melewati jatuh tempo</span></span>
    <a href="<?= h(fin_u('/sales/fin_do_tasks.php')) ?>" style="margin-left:auto;color:#f87171;font-size:11px;text-decoration:none">Lihat →</a>
  </div>
  <?php endif; ?>
  <?php if ($apOverdueCount > 0): ?>
  <div style="background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.3);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
    <span style="font-size:18px"><?=rmi_icon('money')?></span>
    <span><strong style="color:#fb923c"><?= $apOverdueCount ?> AP Overdue</strong>
    <span style="color:#94a3b8"> — <?= fin_money_short($apOverdue) ?> hutang melewati due date</span></span>
    <a href="<?= h(fin_u('/purchases/purchases_invoice_ap.php')) ?>" style="margin-left:auto;color:#fb923c;font-size:11px;text-decoration:none">Bayar →</a>
  </div>
  <?php endif; ?>


  <?php if ($regPayPendingCount > 0): ?>
  <div style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.3);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
    <span style="font-size:18px"><?=rmi_icon('receipt')?></span>
    <span><strong style="color:#93c5fd"><?= $regPayPendingCount ?> pembayaran registrasi alkes menunggu FIN</strong>
    <span style="color:#94a3b8"> — Total <?= fin_money_short($regPayPendingAmount) ?><?= $regPayOverdueCount>0 ? ' · '.rmi_icon('warn').' '.$regPayOverdueCount.' overdue' : '' ?></span></span>
    <a href="<?= h(fin_u('/dashboards/finance/reg_alkes_payments.php')) ?>" style="margin-left:auto;color:#93c5fd;font-size:11px;text-decoration:none">Review →</a>
  </div>
  <?php endif; ?>

  <!-- KPI Tiles -->
  <div class="fin-kpi">

    <!-- FIN DO Tasks — NEW CRITICAL -->
    <a class="fin-k" href="<?= h(fin_u('/sales/fin_do_tasks.php')) ?>" style="--kc:<?= $finDoTasks>0?'#10b981':'#64748b' ?>">
      <div class="fin-k-icon"><?=rmi_icon('money')?></div>
      <div class="fin-k-val lg" style="color:<?= $finDoTasks>0?'#34d399':'#fff' ?>"><?= number_format($finDoTasks) ?></div>
      <div class="fin-k-lbl">FIN DO Tasks</div>
      <div class="fin-k-sub"><?= $finDoTasks>0 ? fin_money_short($finDoAmount) : rmi_icon('tick').' Clear' ?></div>
    </a>


    <!-- Avg Durasi SLA FIN -->
    <div class="fin-k" style="--kc:#8b5cf6">
      <div class="fin-k-icon"><?=rmi_icon('calendar')?></div>
      <div class="fin-k-val" style="color:#c4b5fd"><?= h(fin_duration($finAvgDurationSec)) ?></div>
      <div class="fin-k-lbl">Avg Durasi FIN</div>
      <div class="fin-k-sub">ACT handoff → FIN selesai · <?= number_format($finDurationMeasured) ?> DO terukur</div>
    </div>

    <!-- REG Alkes Registration Payments -->
    <a class="fin-k" href="<?= h(fin_u('/dashboards/finance/reg_alkes_payments.php')) ?>" style="--kc:<?= $regPayPendingCount>0?'#60a5fa':'#64748b' ?>">
      <div class="fin-k-icon"><?=rmi_icon('receipt')?></div>
      <div class="fin-k-val lg" style="color:<?= $regPayPendingCount>0?'#93c5fd':'#fff' ?>"><?= number_format($regPayPendingCount) ?></div>
      <div class="fin-k-lbl">Reg Alkes Pay</div>
      <div class="fin-k-sub"><?= $regPayPendingCount>0 ? fin_money_short($regPayPendingAmount) . ($regPayOverdueCount>0 ? ' · '.$regPayOverdueCount.' OD' : '') : rmi_icon('tick').' Clear' ?></div>
    </a>

    <!-- Collected MTD — NEW -->
    <div class="fin-k" style="--kc:#22c55e">
      <div class="fin-k-icon"><?=rmi_icon('check')?></div>
      <div class="fin-k-val"><?= fin_money_short($arCollectedMTD) ?></div>
      <div class="fin-k-lbl">Collected MTD</div>
      <div class="fin-k-sub"><?= number_format($arCollectedCount) ?> DO paid <?= date('M Y', strtotime($mStart)) ?></div>
    </div>

    <!-- AR Outstanding -->
    <div class="fin-k" style="--kc:#3b82f6">
      <div class="fin-k-icon"><?=rmi_icon('doc')?></div>
      <div class="fin-k-val"><?= fin_money_short($arOutstanding) ?></div>
      <div class="fin-k-lbl">AR Outstanding</div>
      <div class="fin-k-sub"><?= number_format($arCountTotal) ?> DO belum lunas</div>
    </div>

    <!-- AR Overdue -->
    <div class="fin-k" style="--kc:<?= $arOverdue>0?'#ef4444':'#64748b' ?>">
      <div class="fin-k-icon"><?=rmi_icon('warn')?></div>
      <div class="fin-k-val" style="color:<?= $arOverdue>0?'#f87171':'#fff' ?>"><?= fin_money_short($arOverdue) ?></div>
      <div class="fin-k-lbl">AR Overdue</div>
      <div class="fin-k-sub"><?= $arCountOverdue ?> DO jatuh tempo</div>
    </div>

    <!-- Net Position — NEW -->
    <div class="fin-k" style="--kc:<?= $netPosition>=0?'#22c55e':'#ef4444' ?>">
      <div class="fin-k-icon"><?= $netPosition>=0?rmi_icon('trend'):rmi_icon('trend') ?></div>
      <div class="fin-k-val" style="color:<?= $netPosition>=0?'#4ade80':'#f87171' ?>"><?= fin_money_short(abs($netPosition)) ?></div>
      <div class="fin-k-lbl">Net AR - AP</div>
      <div class="fin-k-sub"><?= $netPosition>=0?'Positif (piutang > hutang)':'Negatif (hutang > piutang)' ?></div>
    </div>

    <!-- AP Outstanding -->
    <div class="fin-k" style="--kc:<?= $apOverdue>0?'#f97316':'#f59e0b' ?>">
      <div class="fin-k-icon"><?=rmi_icon('money')?></div>
      <div class="fin-k-val"><?= fin_money_short($apOutstanding) ?></div>
      <div class="fin-k-lbl">AP Outstanding</div>
      <div class="fin-k-sub"><?= number_format($apCount) ?> invoice · <?= $apOverdueCount>0?rmi_icon('warn').' '.$apOverdueCount.' OD':rmi_icon('tick').' Tidak ada OD' ?></div>
    </div>

    <!-- Cash Balance -->
    <div class="fin-k" style="--kc:#10b981">
      <div class="fin-k-icon"><?=rmi_icon('money')?></div>
      <div class="fin-k-val"><?= $cashSourceAvailable ? fin_money_short($cash) : 'N/A' ?></div>
      <div class="fin-k-lbl">Cash Balance</div>
      <div class="fin-k-sub"><?= h($cashSourceLabel) ?><?= $runwayWeeks>0 ? ' · Runway '.number_format($runwayWeeks,1,',','.').'w' : '' ?></div>
    </div>

    <!-- OPEX / Payroll -->
    <?php if ($payrollRun): ?>
    <a class="fin-k" href="<?= h(fin_u('/payroll/index.php')) ?>" style="--kc:<?= strtoupper($payrollRun['status']??'')==='PAID'?'#22c55e':(strtoupper($payrollRun['status']??'')==='POSTED'?'#3b82f6':'#f59e0b') ?>">
      <div class="fin-k-icon"><?=rmi_icon('users')?></div>
      <div class="fin-k-val"><?= fin_money_short((float)($payrollRun['total_net']??0)) ?></div>
      <div class="fin-k-lbl">Payroll <?= h($period) ?></div>
      <div class="fin-k-sub"><?= h($payrollRun['emp_count']??0) ?> karyawan · <span style="font-weight:700;color:<?= strtoupper($payrollRun['status']??'')==='PAID'?'#4ade80':(strtoupper($payrollRun['status']??'')==='POSTED'?'#60a5fa':'#fbbf24') ?>"><?= h($payrollRun['status']??'DRAFT') ?></span></div>
    </a>
    <?php else: ?>
    <a class="fin-k" href="<?= h(fin_u('/payroll/index.php')) ?>" style="--kc:#94a3b8;opacity:.7">
      <div class="fin-k-icon"><?=rmi_icon('users')?></div>
      <div class="fin-k-val" style="font-size:13px;color:#64748b">Belum ada run</div>
      <div class="fin-k-lbl">Payroll <?= h($period) ?></div>
      <div class="fin-k-sub"><span style="color:#f97316">Buat run →</span></div>
    </a>
    <?php endif; ?>

  </div>

  <!-- AR Aging + AP Aging -->
  <div class="row g-3 mb-3">

    <!-- AR Aging — visual bars -->
    <div class="col-lg-4">
      <div class="rmi-card p-3">
        <div class="fin-sh" style="margin-top:0"><?=rmi_icon('doc')?> AR Aging (Overdue)</div>
        <?php
        $agingTotal = array_sum($arAging);
        if ($agingTotal > 0):
          $agingDef = [
            '0_30'  => ['label'=>'1–30 hari',  'color'=>'#fbbf24'],
            '31_60' => ['label'=>'31–60 hari', 'color'=>'#f97316'],
            '61_90' => ['label'=>'61–90 hari', 'color'=>'#ef4444'],
            '90p'   => ['label'=>'>90 hari',   'color'=>'#dc2626'],
          ];
        ?>
          <?php foreach ($agingDef as $key => $meta):
            $amt = $arAging[$key];
            if ($amt <= 0) continue;
            $pct = round($amt / $agingTotal * 100);
          ?>
            <div class="aging-row">
              <div style="display:flex;justify-content:space-between;margin-bottom:2px">
                <span style="font-size:12px;font-weight:600;color:#e2e8f0"><?= $meta['label'] ?></span>
                <span style="font-size:11px;font-weight:700;color:<?= $meta['color'] ?>"><?= fin_money_short($amt) ?></span>
              </div>
              <div class="aging-bar">
                <div class="aging-fill" style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>"></div>
              </div>
              <div style="font-size:10px;color:var(--rmi-muted);margin-top:1px"><?= $pct ?>% dari total overdue</div>
            </div>
          <?php endforeach; ?>
          <div style="border-top:1px solid rgba(255,255,255,.08);padding-top:8px;margin-top:6px;font-size:12px;color:#64748b">
            Total Overdue AR: <span style="color:#f87171;font-weight:700"><?= fin_money_short($agingTotal) ?></span>
          </div>
        <?php else: ?>
          <div style="color:var(--rmi-muted);padding:16px 0;text-align:center;font-size:13px"><?=rmi_icon('check')?> Tidak ada AR overdue.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- AP Aging — visual bars -->
    <div class="col-lg-4">
      <div class="rmi-card p-3">
        <div class="fin-sh" style="margin-top:0"><?=rmi_icon('money')?> AP Aging (Outstanding)</div>
        <?php
        $apAgingTotal = array_sum($apAgingBuckets);
        if ($apAgingTotal > 0):
          $apAgingDef = [
            'overdue' => ['label'=>'Overdue',    'color'=>'#ef4444'],
            '1_7'     => ['label'=>'1–7 hari',   'color'=>'#f97316'],
            '8_30'    => ['label'=>'8–30 hari',  'color'=>'#f59e0b'],
            '30plus'  => ['label'=>'30+ hari / No Due','color'=>'#64748b'],
          ];
        ?>
          <?php foreach ($apAgingDef as $key => $meta):
            $amt = $apAgingBuckets[$key];
            if ($amt <= 0) continue;
            $pct = round($amt / $apAgingTotal * 100);
          ?>
            <div class="aging-row">
              <div style="display:flex;justify-content:space-between;margin-bottom:2px">
                <span style="font-size:12px;font-weight:600;color:#e2e8f0"><?= $meta['label'] ?></span>
                <span style="font-size:11px;font-weight:700;color:<?= $meta['color'] ?>"><?= fin_money_short($amt) ?></span>
              </div>
              <div class="aging-bar">
                <div class="aging-fill" style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>"></div>
              </div>
              <div style="font-size:10px;color:var(--rmi-muted);margin-top:1px"><?= $pct ?>% dari total AP</div>
            </div>
          <?php endforeach; ?>
          <div style="border-top:1px solid rgba(255,255,255,.08);padding-top:8px;margin-top:6px;font-size:12px;color:#64748b">
            Total AP Outstanding: <span style="color:#fb923c;font-weight:700"><?= fin_money_short($apAgingTotal) ?></span>
          </div>
        <?php else: ?>
          <div style="color:var(--rmi-muted);padding:16px 0;text-align:center;font-size:13px"><?=rmi_icon('check')?> Tidak ada AP outstanding.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Top Overdue Customers — with name -->
    <div class="col-lg-4">
      <div class="rmi-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fin-sh" style="margin:0"><?=rmi_icon('users')?> Top AR Overdue</div>
          <a href="<?= h(fin_u('/sales/fin_do_tasks.php')) ?>" style="font-size:11px;color:#34d399;text-decoration:none">Collect →</a>
        </div>
        <?php if (empty($topOverdue)): ?>
          <div style="color:var(--rmi-muted);padding:16px 0;text-align:center;font-size:13px"><?= $arCountOverdue>0 ? rmi_icon('warn').' AR overdue terdeteksi, tetapi customer belum dapat dipetakan.' : rmi_icon('check').' Tidak ada customer overdue.' ?></div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="fin-tbl">
            <thead><tr><th>Customer</th><th style="text-align:right">DO</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($topOverdue as $r):
              $daysSince = $r['oldest_due'] ? (int)round((time()-strtotime($r['oldest_due']))/86400) : 0;
              $overdueColor = $daysSince > 90 ? '#f87171' : ($daysSince > 30 ? '#fbbf24' : '#fb923c');
            ?>
              <tr>
                <td>
                  <?php
                    $custLabel = trim((string)($r['customer_name'] ?? $r['customers_code'] ?? ''));
                    $custCode  = trim((string)($r['customers_code'] ?? ''));
                  ?>
                  <div title="<?= h($custLabel) ?>" style="font-weight:600;font-size:12px;color:#e2e8f0;white-space:normal;line-height:1.25;overflow-wrap:anywhere"><?= h($custLabel) ?></div>
                  <?php if ($custCode !== '' && strcasecmp($custCode, $custLabel) !== 0): ?>
                    <div style="font-size:9px;color:#64748b;margin-top:2px"><?= h($custCode) ?></div>
                  <?php endif; ?>
                  <?php if ($r['oldest_due']): ?>
                    <div style="font-size:10px;color:<?= $overdueColor ?>">OD <?= $daysSince ?>h</div>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:700"><?= (int)($r['cnt_do']??0) ?></td>
                <td style="text-align:right;font-weight:700;color:#f87171;font-size:11px"><?= fin_money_short((float)($r['amount']??0)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>


  <!-- REG Alkes registration payments for FIN -->
  <div class="row g-3 mb-3" id="reg-alkes-payments">
    <div class="col-12">
      <div class="rmi-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fin-sh" style="margin:0"><?=rmi_icon('receipt')?> Pembayaran Registrasi Alkes</div>
          <div style="display:flex;gap:8px"><a href="<?= h(fin_u('/dashboards/finance/reg_alkes_payments.php')) ?>" style="font-size:11px;color:#93c5fd;text-decoration:none">Buka Halaman FIN →</a><a href="<?= h(fin_u('/hrl_reg_alkes/reg_alkes_control_tower.php')) ?>" style="font-size:11px;color:#93c5fd;text-decoration:none">Control Tower →</a></div>
        </div>
        <?php if (!$pdo || !fin_t($pdo, 'hrl_reg_alkes_payments')): ?>
          <div style="color:#64748b;font-size:13px;padding:12px 0;text-align:center">
            Belum ada tabel pembayaran registrasi. Tabel akan dibuat dari halaman REG Alkes Case setelah file pembayaran dipasang.
          </div>
        <?php elseif (empty($regPayPending)): ?>
          <div style="color:var(--rmi-muted);font-size:13px;padding:12px 0;text-align:center"><?=rmi_icon('check')?> Tidak ada pembayaran registrasi alkes yang menunggu FIN.</div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="fin-tbl">
            <thead>
              <tr>
                <th>Case</th>
                <th>Manufacture / Produk</th>
                <th>Billing / Invoice</th>
                <th>Status</th>
                <th>Due Date</th>
                <th style="text-align:right">Nominal</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($regPayPending as $r):
              $due = (string)($r['due_date'] ?? '');
              $daysLeft = $due !== '' ? (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400) : null;
              $dueColor = $daysLeft === null ? '#64748b' : ($daysLeft < 0 ? '#f87171' : ($daysLeft <= 7 ? '#fbbf24' : '#94a3b8'));
              $payStatus = strtoupper((string)($r['payment_status'] ?? 'UNPAID'));
              $statusColor = $payStatus === 'SUBMITTED' || $payStatus === 'SUBMITTED_TO_FIN' ? '#fbbf24' : '#93c5fd';
              $caseUrl = fin_u('/hrl_reg_alkes/reg_alkes_case.php?id=' . (int)($r['case_id'] ?? 0));
            ?>
              <tr>
                <td>
                  <a href="<?= h($caseUrl) ?>" style="font-weight:700;color:#93c5fd;text-decoration:none"><?= h($r['case_code'] ?? '') ?></a>
                  <div style="font-size:10px;color:#64748b">Stage <?= h((string)($r['stage_no'] ?? '-')) ?> · <?= h((string)($r['case_status'] ?? '-')) ?></div>
                </td>
                <td>
                  <div style="font-weight:600;font-size:12px;color:#e2e8f0"><?= h(mb_strimwidth((string)($r['manufacture_name'] ?? $r['vendor_name'] ?? '-'), 0, 34, '…')) ?></div>
                  <div style="font-size:10px;color:#64748b"><?= h(mb_strimwidth((string)($r['product_name'] ?? '-'), 0, 46, '…')) ?></div>
                </td>
                <td style="font-size:11px;color:#94a3b8">
                  <div>Billing: <strong><?= h((string)($r['billing_no'] ?: '-')) ?></strong></div>
                  <div>Invoice: <strong><?= h((string)($r['invoice_no'] ?: '-')) ?></strong></div>
                </td>
                <td><span style="font-size:10px;font-weight:800;background:rgba(255,255,255,.06);padding:2px 7px;border-radius:6px;color:<?= $statusColor ?>"><?= h($payStatus) ?></span></td>
                <td style="white-space:nowrap">
                  <span style="font-weight:700;color:<?= $dueColor ?>"><?= h($due !== '' ? $due : '-') ?></span>
                  <?php if ($daysLeft !== null): ?>
                    <span style="font-size:10px;color:var(--rmi-muted)">(<?= $daysLeft < 0 ? 'OD '.abs($daysLeft).'h' : $daysLeft.'h lagi' ?>)</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:800;color:#93c5fd"><?= fin_money_short((float)($r['amount'] ?? 0)) ?></td>
                <td style="white-space:nowrap">
                  <a href="<?= h(fin_u('/dashboards/finance/reg_alkes_payments.php?q=' . urlencode((string)($r['case_code'] ?? '')))) ?>" class="btn btn-sm btn-outline-light" style="font-size:11px;padding:3px 8px">Detail / Bayar</a>
                  <?php if (!empty($r['proof_file_rel'])): ?>
                    <a href="<?= h(fin_u((string)$r['proof_file_rel'])) ?>" target="_blank" class="btn btn-sm btn-outline-success" style="font-size:11px;padding:3px 8px">Bukti</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- AP Due this 2 weeks — with supplier name -->
  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="rmi-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fin-sh" style="margin:0"><?=rmi_icon('money')?> AP Jatuh Tempo 14 Hari ke Depan</div>
          <a href="<?= h(fin_u('/purchases/purchases_payment_ap.php')) ?>" style="font-size:11px;color:#fb923c;text-decoration:none">Bayar →</a>
        </div>
        <?php if (empty($apDueWeek)): ?>
          <div style="color:var(--rmi-muted);font-size:13px;padding:12px 0;text-align:center"><?=rmi_icon('check')?> Tidak ada AP jatuh tempo 14 hari ke depan.</div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="fin-tbl">
            <thead><tr><th>Supplier</th><th>AP Code</th><th>Invoice No.</th><th>Due Date</th><th>Status</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($apDueWeek as $r):
              $daysLeft = $r['due_date'] ? (int)round((strtotime($r['due_date'])-time())/86400) : 99;
              $dueColor = $daysLeft <= 3 ? '#f87171' : ($daysLeft <= 7 ? '#fbbf24' : '#94a3b8');
            ?>
              <tr>
                <td style="font-weight:600;font-size:12px"><?= h(mb_strimwidth((string)($r['supplier_name']??'—'),0,22,'…')) ?></td>
                <td style="font-size:11px;color:#60a5fa"><code><?= h($r['ap_code']??'') ?></code></td>
                <td style="font-size:11px;color:#64748b"><?= h($r['invoice_number']??'—') ?></td>
                <td style="white-space:nowrap">
                  <span style="font-weight:700;color:<?= $dueColor ?>"><?= h($r['due_date']??'') ?></span>
                  <span style="font-size:10px;color:var(--rmi-muted)"> (<?= $daysLeft ?>h)</span>
                </td>
                <td><span style="font-size:10px;font-weight:700;background:rgba(255,255,255,.06);padding:2px 7px;border-radius:6px;color:#94a3b8"><?= h($r['status']??'') ?></span></td>
                <td style="text-align:right;font-weight:700;color:#fb923c;font-size:11px"><?= fin_money_short((float)($r['total_amount']??0)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="rmi-card p-3">
    <div class="fin-sh" style="margin-top:0"><?=rmi_icon('zap')?> Quick Links</div>
    <div class="fin-links">
      <a class="fin-link primary" href="<?= h(fin_u('/sales/fin_do_tasks.php')) ?>"><?=rmi_icon('money')?> FIN Task DO</a>
      <a class="fin-link primary" href="<?= h(fin_u('/purchases/purchases_invoice_ap.php')) ?>"><?=rmi_icon('money')?> AP Invoice</a>
      <a class="fin-link primary" href="<?= h(fin_u('/purchases/purchases_payment_ap.php')) ?>"><?=rmi_icon('money')?> AP Payment</a>
      <a class="fin-link" href="<?= h(fin_u('/dashboards/finance/ap_rekap.php')) ?>"><?=rmi_icon('clipboard')?> Rekap Hutang</a>
      <a class="fin-link" href="<?= h(fin_u('/purchases/purchases_ap_import.php')) ?>"><?=rmi_icon('outbox')?> Import Hutang Lama</a>
      <a class="fin-link primary" href="<?= h(fin_u('/dashboards/finance/reg_alkes_payments.php')) ?>"><?=rmi_icon('receipt')?> Reg Alkes Payment</a>
      <a class="fin-link" href="<?= h(fin_u('/sales/tax_invoices.php')) ?>"><?=rmi_icon('receipt')?> Tax Invoice</a>
      <a class="fin-link" href="<?= h(fin_u('/purchases/bank_recon.php')) ?>"><?=rmi_icon('money')?> Bank Rekon</a>
      <a class="fin-link" href="<?= h(fin_u('/purchases/gl_reversal_approvals.php')) ?>"><?=rmi_icon('refresh')?> GL Reversal</a>
      <a class="fin-link" href="<?= h(fin_u('/master/company_bank_accounts.php')) ?>"><?=rmi_icon('money')?> Rekening Perusahaan</a>
      <a class="fin-link" href="<?= h(fin_u('/payroll/index.php')) ?>"><?=rmi_icon('users')?> Payroll</a>
      <a class="fin-link primary" href="<?= h(fin_u('/purchases/purchases_control_tower.php')) ?>"><?=rmi_icon('tower')?> Local Purchase Control Tower</a>
      <a class="fin-link" href="<?= h(fin_u('/purchases/purchases_import_control_tower.php')) ?>"><?=rmi_icon('box')?> Import Control Tower</a>
      <a class="fin-link" href="<?= h(fin_u('/sales/sales_control_tower.php')) ?>"><?=rmi_icon('tower')?> Sales Control Tower</a>
      <a class="fin-link" href="<?= h(fin_u('/Fixed_Asset/index.php')) ?>"><?=rmi_icon('office')?> Fixed Asset</a>
      <a class="fin-link" href="<?= h(fin_u('/dashboards/finance/dashboard_detail.php')) ?>"><?=rmi_icon('chart')?> Finance Detail</a>
      <a class="fin-link" href="<?= h(fin_u('/dashboards/finance/target_rekap.php')) ?>"><?=rmi_icon('target')?> Target Finance</a>
      <a class="fin-link" href="<?= h(fin_u('/dashboards/finance/adjustment_manage.php')) ?>"><?=rmi_icon('money')?> Adjustment</a>
      <a class="fin-link" href="<?= h(fin_u('/kpi/kpi_center.php')) ?>"><?=rmi_icon('trend')?> KPI Center</a>
      <a class="fin-link" href="<?= h(fin_u('/absensi/index.php')) ?>"><?=rmi_icon('calendar')?> Absensi</a>
      <a class="fin-link" href="<?= h(fin_u('/mpr/mpr_budget_fin.php')) ?>">
  <?=rmi_icon('money')?> MPR Budget Approval
</a>

<a class="fin-link" href="<?= h(fin_u('/mpr/mpr_ops_daily_fin.php')) ?>">
  <?=rmi_icon('calendar')?> MPR Ops Payment
</a>
    </div>
  </div>

  <?php
  $auditModules = ['FIN', 'AP_PAYMENT', 'AP_INVOICE', 'AR_RECEIPT', 'GL', 'BANK_RECON'];
  $auditLimit   = 10;
  require __DIR__ . '/../_audit_log_widget.php';
  ?>

</div>
<?php rmi_footer(); ?>
