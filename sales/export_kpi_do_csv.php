<?php
require_once __DIR__ . '/../master/auth.php';
require_login();
require_role(['SUPERADMIN','ADMIN','MANAGER']);
// export_kpi_do_csv.php (V5 - PHP 8.4 safe + robust date parsing + ignore_date option)
// Export CSV KPI (Audit & Overdue) untuk Sales DO
// Read-only. Filter via GET: date_from, date_to, department, status, customer, pic, include_locked
// Extra: ignore_date=1 untuk export semua tanpa filter tanggal. debug=1 untuk cek count (plain text).
//
// Penempatan: /ERP_RMI_SOFULL/sales/export_kpi_do_csv.php

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

if (!ob_get_level()) { ob_start(); }
// --- DB (centralized) ---
$pdo = db_pdo();

// -------------------------
// Helpers
// -------------------------
function get_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        $cols = [];
        foreach ($rows as $r) $cols[] = (string)$r['Field'];
        return $cols;
    } catch (Throwable $e) { return []; }
}

function get_col_types(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        $m = [];
        foreach ($rows as $r) $m[(string)$r['Field']] = (string)$r['Type'];
        return $m;
    } catch (Throwable $e) { return []; }
}

function pick_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
    return null;
}

function is_date_type(?string $type): bool {
    if ($type === null) return false;
    $t = strtolower($type);
    return (strpos($t, 'date') !== false) || (strpos($t, 'time') !== false) || (strpos($t, 'timestamp') !== false);
}

function gstr($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? '1' : '0';
    return (string)$v;
}

function is_truthy($v): bool {
    if ($v === null) return false;
    $s = strtolower(trim((string)$v));
    if ($s === '') return false;
    return in_array($s, ['1','true','yes','y','paid','done','closed','complete','completed','finish','finished','ok','approved','approve'], true);
}

function safe_date($v): ?string {
    $s = trim((string)$v);
    if ($s === '' || $s === '0000-00-00') return null;
    return $s;
}

function dept_norm($v): string {
    $s = strtoupper(trim((string)$v));
    $map = [
        'ACCOUNTING & TAX' => 'ACT',
        'FINANCE'    => 'FIN',
        'WAREHOUSE & QUANTITY'  => 'WQS',

    ];
    return $map[$s] ?? $s;
}

// -------------------------
// Input filters (GET)
// -------------------------
$date_from = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : date('Y-m-01');
$date_to   = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : date('Y-m-t');

$department     = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
$status         = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$customer       = isset($_GET['customer']) ? trim((string)$_GET['customer']) : '';
$pic            = isset($_GET['pic']) ? trim((string)$_GET['pic']) : '';
$include_locked = isset($_GET['include_locked']) ? (int)$_GET['include_locked'] : 0;

$ignore_date = isset($_GET['ignore_date']) ? (int)$_GET['ignore_date'] : 0;
$debug       = isset($_GET['debug']) ? (int)$_GET['debug'] : 0;

// basic validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-t');

// -------------------------
// Detect columns (sales_do)
// -------------------------
$sales_cols = get_cols($pdo, 'sales_do');
if (empty($sales_cols)) {
    http_response_code(500);
    die("Tabel sales_do tidak ditemukan / tidak bisa dibaca.");
}
$sales_types = get_col_types($pdo, 'sales_do');

$customer_table = 'master_customers';
$customer_cols  = get_cols($pdo, $customer_table);
$customer_name_col = pick_col($customer_cols, ['customers_name','customer_name','name']);
$customer_code_col = pick_col($customer_cols, ['customers_code','customer_code','code']);

$emp_table = 'master_employees';
$emp_cols  = get_cols($pdo, $emp_table);
$emp_name_col = pick_col($emp_cols, ['employee_name','emp_name','employees_name','name']);
$emp_code_col = pick_col($emp_cols, ['employees_code','emp_code','employee_code','code']);

$flow_col     = pick_col($sales_cols, ['flow_status']);
$status_col   = pick_col($sales_cols, ['status']);
$do_code_col  = pick_col($sales_cols, ['do_code']);
$do_date_col  = pick_col($sales_cols, ['do_date']);
$customers_code_col = pick_col($sales_cols, ['customers_code']);
$sales_emp_code_col = pick_col($sales_cols, ['sales_emp_code']);
$created_at_col = pick_col($sales_cols, ['created_at','crm_created_at']);
$updated_at_col = pick_col($sales_cols, ['updated_at','last_updated_at']);

$fin_due_col  = pick_col($sales_cols, ['fin_due_date']);
$act_due_col  = pick_col($sales_cols, ['act_due_date']);

$fin_paid_at_col   = pick_col($sales_cols, ['fin_paid_at']);
$fin_paid_date_col = pick_col($sales_cols, ['fin_paid_date']);
$fin_status_col    = pick_col($sales_cols, ['fin_status','status_fin']);
$status_fin_col    = pick_col($sales_cols, ['status_fin']);

$wqs_started_col = pick_col($sales_cols, ['wqs_started_at','wqs_picked_at']);
$scm_done_col    = pick_col($sales_cols, ['scm_delivered_at']);
$act_done_col    = pick_col($sales_cols, ['act_invoiced_at']);
$fin_done_col    = pick_col($sales_cols, ['fin_paid_at','fin_paid_date']);

// -------------------------
// Audit aggregation (PRECISE) - sales_do_audit
// -------------------------
$audit_table = 'sales_do_audit';
$audit_cols  = get_cols($pdo, $audit_table);
$has_audit   = (!empty($audit_cols) && in_array('do_id', $audit_cols, true));

$auditAgg = []; // [do_id] => ['cnt'=>int,'first_at'=>..., 'last_at'=>..., 'depts'=>set]
if ($has_audit) {
    try {
        $pdo->exec("SET SESSION group_concat_max_len = 1024000");
        $sqlAudit = "
            SELECT
                do_id,
                COUNT(*) AS cnt,
                MIN(created_at) AS first_at,
                MAX(created_at) AS last_at,
                GROUP_CONCAT(DISTINCT UPPER(actor_dept) ORDER BY UPPER(actor_dept) SEPARATOR ',') AS dept_list
            FROM `$audit_table`
            GROUP BY do_id
        ";
        foreach ($pdo->query($sqlAudit) as $row) {
            $do_id = (string)$row['do_id'];
            $dept_list = (string)($row['dept_list'] ?? '');
            $dept_set = [];
            if ($dept_list !== '') {
                foreach (explode(',', $dept_list) as $d) {
                    $dn = dept_norm($d);
                    if ($dn !== '') $dept_set[$dn] = true;
                }
            }
            $auditAgg[$do_id] = [
                'cnt'      => (int)$row['cnt'],
                'first_at' => $row['first_at'] ?? null,
                'last_at'  => $row['last_at'] ?? null,
                'depts'    => $dept_set,
            ];
        }
    } catch (Throwable $e) {
        $auditAgg = [];
        $has_audit = false;
    }
}

// -------------------------
// Build main query (sales_do + master joins)
// -------------------------
$where = [];
$params = [];

// Robust effective date expression
// - if created_at/do_date types are DATE/DATETIME: DATE(col)
// - else parse common string formats using STR_TO_DATE
$created_type = $created_at_col ? ($sales_types[$created_at_col] ?? null) : null;
$do_date_type = $do_date_col ? ($sales_types[$do_date_col] ?? null) : null;

$created_expr = 'NULL';
if ($created_at_col) {
    if (is_date_type($created_type)) {
        $created_expr = "DATE(sd.`$created_at_col`)";
    } else {
        $created_expr = "COALESCE(
            STR_TO_DATE(sd.`$created_at_col`,'%Y-%m-%d %H:%i:%s'),
            STR_TO_DATE(sd.`$created_at_col`,'%Y-%m-%d'),
            STR_TO_DATE(sd.`$created_at_col`,'%d/%m/%Y'),
            STR_TO_DATE(sd.`$created_at_col`,'%d-%m-%Y')
        )";
    }
}

$do_expr = 'NULL';
if ($do_date_col) {
    if (is_date_type($do_date_type)) {
        $do_expr = "DATE(sd.`$do_date_col`)";
    } else {
        $do_expr = "COALESCE(
            STR_TO_DATE(sd.`$do_date_col`,'%Y-%m-%d'),
            STR_TO_DATE(sd.`$do_date_col`,'%d/%m/%Y'),
            STR_TO_DATE(sd.`$do_date_col`,'%d-%m-%Y')
        )";
    }
}

$effective_date_expr = "COALESCE($created_expr, $do_expr)";

if ($ignore_date === 0) {
    $where[] = "$effective_date_expr BETWEEN :df AND :dt";
    $params[':df'] = $date_from;
    $params[':dt'] = $date_to;
}

if ($department !== '' && $flow_col) {
    $where[] = "sd.`$flow_col` = :dept";
    $params[':dept'] = $department;
}
if ($status !== '' && $status_col) {
    $where[] = "sd.`$status_col` = :st";
    $params[':st'] = $status;
}
if ($customer !== '' && $customers_code_col) {
    $where[] = "sd.`$customers_code_col` = :cust";
    $params[':cust'] = $customer;
}
if ($pic !== '' && $sales_emp_code_col) {
    $where[] = "sd.`$sales_emp_code_col` = :pic";
    $params[':pic'] = $pic;
}

$sql = "SELECT sd.*";
$joinCustomer = false;
if ($customer_code_col && $customer_name_col && $customers_code_col) {
    $sql .= ", mc.`$customer_name_col` AS customer_name";
    $joinCustomer = true;
}
$joinEmp = false;
if ($emp_code_col && $emp_name_col && $sales_emp_code_col) {
    $sql .= ", me.`$emp_name_col` AS pic_name";
    $joinEmp = true;
}

$sql .= " FROM sales_do sd";
if ($joinCustomer) $sql .= " LEFT JOIN `$customer_table` mc ON mc.`$customer_code_col` = sd.`$customers_code_col`";
if ($joinEmp) $sql .= " LEFT JOIN `$emp_table` me ON me.`$emp_code_col` = sd.`$sales_emp_code_col`";
if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY sd.`" . ($created_at_col ?: $do_date_col ?: 'id') . "` DESC";

// Debug mode: count only
if ($debug === 1) {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: text/plain; charset=utf-8');
    $countSql = "SELECT COUNT(*) AS c FROM (".$sql.") t";
    $st = $pdo->prepare($countSql);
    $st->execute($params);
    $c = (int)$st->fetchColumn();
    echo "DEBUG export_kpi_do_csv.php\n";
    echo "ignore_date={$ignore_date}\n";
    echo "date_from={$date_from} date_to={$date_to}\n";
    echo "effective_date_expr={$effective_date_expr}\n";
    echo "count={$c}\n";
    exit;
}

// clean buffer (avoid corrupting headers/CSV)
if (ob_get_length()) { ob_clean(); }

// -------------------------
// CSV headers
// -------------------------
$filename = "KPI_DO_{$date_from}_{$date_to}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

$csv_delim  = ',';
$csv_encl   = '"';
$csv_escape = '\\';

$csvHeader = [
    'do_no','do_date','created_at','customer_code','customer_name',
    'current_status','current_department','pic_code','pic_name',
    'due_at','overdue_flag','overdue_days',
    'audit_ok','audit_missing_events','audit_count','audit_first_at','audit_last_at',
    'locked_flag','last_update_at'
];
fputcsv($out, $csvHeader, $csv_delim, $csv_encl, $csv_escape);

// -------------------------
// Execute and stream rows
// -------------------------
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$today = new DateTime(date('Y-m-d'));

while ($r = $stmt->fetch()) {
    $do_id = (string)($r['id'] ?? '');

    $do_no   = $do_code_col ? gstr($r[$do_code_col] ?? '') : gstr($r['do_code'] ?? '');
    $do_date = $do_date_col ? gstr($r[$do_date_col] ?? '') : gstr($r['do_date'] ?? '');

    $created_at = $created_at_col ? gstr($r[$created_at_col] ?? '') : gstr($r['created_at'] ?? '');
    $updated_at = $updated_at_col ? gstr($r[$updated_at_col] ?? '') : gstr($r['updated_at'] ?? '');

    $cust_code = $customers_code_col ? gstr($r[$customers_code_col] ?? '') : gstr($r['customers_code'] ?? '');
    $cust_name = gstr($r['customer_name'] ?? '');

    $cur_status = $status_col ? gstr($r[$status_col] ?? '') : gstr($r['status'] ?? '');
    $cur_dept   = $flow_col ? gstr($r[$flow_col] ?? '') : gstr($r['flow_status'] ?? '');
    $cur_dept_n = dept_norm($cur_dept);

    $pic_code = $sales_emp_code_col ? gstr($r[$sales_emp_code_col] ?? '') : gstr($r['sales_emp_code'] ?? '');
    $pic_name = gstr($r['pic_name'] ?? '');

    // locked detection
    $locked = false;
    $paid_at   = $fin_paid_at_col ? ($r[$fin_paid_at_col] ?? null) : null;
    $paid_date = $fin_paid_date_col ? ($r[$fin_paid_date_col] ?? null) : null;
    if (!empty($paid_at) || !empty($paid_date)) $locked = true;
    if (!$locked && $fin_status_col)  $locked = is_truthy($r[$fin_status_col] ?? null);
    if (!$locked && $status_fin_col)  $locked = is_truthy($r[$status_fin_col] ?? null);

    if ($include_locked === 0 && $locked) continue;

    // due_at
    $due_at = null;
    if ($fin_due_col) $due_at = safe_date($r[$fin_due_col] ?? null);
    if (!$due_at && $act_due_col) $due_at = safe_date($r[$act_due_col] ?? null);

    // overdue
    $overdue_flag = 0;
    $overdue_days = 0;
    if ($due_at && !$locked) {
        try {
            $dueDate = new DateTime($due_at);
            if ($today > $dueDate) {
                $overdue_flag = 1;
                $diff = $dueDate->diff($today);
                $overdue_days = (int)$diff->format('%a');
            }
        } catch (Throwable $e) {}
    }

    // stage progression
    $passed_wqs = in_array($cur_dept_n, ['WQS','SCM','ACT','FIN'], true);
    $passed_scm = in_array($cur_dept_n, ['SCM','ACT','FIN'], true);
    $passed_act = in_array($cur_dept_n, ['ACT','FIN'], true);
    $passed_fin = ($cur_dept_n === 'FIN') || $locked;

    if ($wqs_started_col && !empty($r[$wqs_started_col])) $passed_wqs = true;
    if ($scm_done_col && !empty($r[$scm_done_col])) $passed_scm = true;
    if ($act_done_col && !empty($r[$act_done_col])) $passed_act = true;
    if ($fin_done_col && !empty($r[$fin_done_col])) $passed_fin = true;

    // audit check
    $audit_cnt = 0;
    $audit_first_at = '';
    $audit_last_at  = '';
    $dept_set = [];

    if ($has_audit && $do_id !== '' && isset($auditAgg[$do_id])) {
        $audit_cnt = (int)$auditAgg[$do_id]['cnt'];
        $audit_first_at = gstr($auditAgg[$do_id]['first_at'] ?? '');
        $audit_last_at  = gstr($auditAgg[$do_id]['last_at'] ?? '');
        $dept_set       = (array)$auditAgg[$do_id]['depts'];
    }

    $missing = [];
    if (!$has_audit) {
        $missing[] = 'AUDIT_TABLE_MISSING';
    } else {
        if ($audit_cnt <= 0) {
            $missing[] = 'CRM';
        } else {
            if (!isset($dept_set['CRM'])) $missing[] = 'CRM';
            if ($passed_wqs && !isset($dept_set['WQS'])) $missing[] = 'WQS';
            if ($passed_scm && !isset($dept_set['SCM'])) $missing[] = 'SCM';
            if ($passed_act && !isset($dept_set['ACT'])) $missing[] = 'ACT';
            if ($passed_fin && !isset($dept_set['FIN'])) $missing[] = 'FIN';
        }
    }

    $audit_ok = empty($missing) ? 1 : 0;
    $audit_missing_events = implode(',', $missing);

    $row = [
        $do_no,
        $do_date,
        $created_at,
        $cust_code,
        $cust_name,
        $cur_status,
        $cur_dept,
        $pic_code,
        $pic_name,
        $due_at ? $due_at : '',
        (string)$overdue_flag,
        (string)$overdue_days,
        (string)$audit_ok,
        $audit_missing_events,
        (string)$audit_cnt,
        $audit_first_at,
        $audit_last_at,
        $locked ? '1' : '0',
        $updated_at,
    ];

    fputcsv($out, $row, $csv_delim, $csv_encl, $csv_escape);
}

fclose($out);
exit;
