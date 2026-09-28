<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// KPI Office (Phase 2) - Enterprise+++ (integrated, no changes to other modules)
require_once __DIR__ . '/_kpi_bootstrap.php';
require_once __DIR__ . '/_kpi_metrics.php';
require_once __DIR__ . '/_kpi_policy.php';
require_once __DIR__ . '/../_shared/rbac.php';
if (!function_exists('rbac_require')) { http_response_code(500); exit('RBAC unavailable'); }

// Ensure PDO exists for RBAC checks (prefer KPI's own PDO helper)
$pdo = $pdo ?? (function_exists('kpi_require_pdo') ? kpi_require_pdo() : null);
if (!$pdo) {
    // fallback to shared helper if available
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : (function_exists('db_pdo') ? db_pdo() : null);
}
if (!$pdo) { http_response_code(500); exit('DB unavailable'); }
kpi_ensure_compat_schema($pdo);

rbac_require($pdo, 'KPI.VIEW');

$table = 'kpi_office';

// --- tiny flash helper (session) ---
if (!isset($_SESSION['_kpi_flash']) || !is_array($_SESSION['_kpi_flash'])) {
    $_SESSION['_kpi_flash'] = [];
}
function kpi_flash_set(string $type, string $msg): void {
    $_SESSION['_kpi_flash'][$type] = $msg;
}
function kpi_flash_get(): array {
    $f = $_SESSION['_kpi_flash'] ?? [];
    $_SESSION['_kpi_flash'] = [];
    return is_array($f) ? $f : [];
}

// If KPI tables not installed yet, render message and stop
if (!kpi_table_exists($pdo, $table)) {
    kpi_header('KPI Office');
    kpi_nav('office');
    echo "<div class='card'><span class='badge danger'>MISSING</span> Tabel <b>{$table}</b> belum ada. Jalankan SQL: <code>kpi/kpi_enterprise_tables.sql</code></div>";
    kpi_footer();
    exit;
}

$cols = kpi_table_columns($pdo, $table);
$pk = kpi_pick_col($cols, ['id','kpi_office_id']) ?: 'id';
$MONTH_COL  = kpi_month_col($pdo, $table);
$OFFICE_COL = kpi_office_col($pdo, $table) ?: 'office_code';
$STATUS_COL = kpi_status_col($pdo, $table);
$DEL_COL    = kpi_deleted_col($pdo, $table);
$JSON_COL   = kpi_json_col($pdo, $table) ?: 'metrics_json';
$NOTE_COL   = kpi_pick_col($cols, ['note','notes']);
$CREATED_BY = kpi_pick_col($cols, ['created_by']);
$CREATED_AT = kpi_pick_col($cols, ['created_at']);
$UPDATED_BY = kpi_pick_col($cols, ['updated_by']);
$UPDATED_AT = kpi_pick_col($cols, ['updated_at']);

function kpi_col_safe(string $c): bool {
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', $c);
}
function kpi_decode_metrics_office($json): array {
    if ($json === null || $json === '') return [];
    $tmp = json_decode((string)$json, true);
    return is_array($tmp) ? $tmp : [];
}

function kpi_office_metric_keys(): array {
    return ['sales_total','do_count','do_paid_count','ar_outstanding','ar_overdue','stock_value','po_total','ap_outstanding','ap_overdue','asset_nbv','asset_cost','headcount'];
}
function kpi_office_actual(PDO $pdo, string $month, string $office): array {
    $sales=kpi_calc_sales_metrics($pdo,$month,$office);
    $stock=kpi_calc_stock_value($pdo,$office);
    $purch=kpi_calc_purchases_metrics($pdo,$month,$office);
    $fa=kpi_calc_fixed_asset_metrics($pdo,$month,$office);
    $hc=kpi_calc_headcount($pdo,$office);
    $actual=[
      'sales_total'=>(float)($sales['sales_total']??0),
      'do_count'=>(int)($sales['do_count']??0),
      'do_paid_count'=>(int)($sales['do_paid_count']??0),
      'ar_outstanding'=>(float)($sales['ar_outstanding']??0),
      'ar_overdue'=>(float)($sales['ar_overdue']??0),
      'ar_aging'=>(string)($sales['ar_aging']??''),
      'stock_value'=>(float)($stock['stock_value']??0),
      'po_total'=>(float)($purch['po_open_value']??$purch['po_total']??0),
      'ap_outstanding'=>(float)($purch['ap_outstanding']??0),
      'ap_overdue'=>(float)($purch['ap_overdue']??0),
      'asset_cost'=>(float)($fa['asset_cost']??0),
      'asset_nbv'=>(float)($fa['asset_nbv']??0),
      'headcount'=>(int)($hc['headcount']??0),
    ];
    $actual['rev_per_employee']=$actual['headcount']>0?$actual['sales_total']/$actual['headcount']:null;
    return [
      'actual'=>$actual,
      'coverage'=>[
        'sales'=>(bool)($sales['available']??false),
        'stock'=>(bool)($stock['available']??false),
        'purchases'=>(bool)($purch['available']??false),
        'fixed_asset'=>(bool)($fa['available']??false),
        'headcount'=>(bool)($hc['available']??false),
      ],
      'sources'=>[
        'sales'=>'sales_do',
        'stock'=>(string)($stock['method']??''),
        'purchases'=>$purch['sources']??[],
        'fixed_asset'=>(string)($fa['method']??''),
        'headcount'=>(string)($hc['method']??''),
      ],
      'do_status_counts'=>is_array($sales['status_counts']??null)?$sales['status_counts']:[],
    ];
}
function kpi_office_apply_adjustment(array $actual,array $adjustment): array {
    $final=$actual;
    foreach(kpi_office_metric_keys() as $key){
        $v=(float)($actual[$key]??0)+(float)($adjustment[$key]??0);
        $final[$key]=in_array($key,['do_count','do_paid_count','headcount'],true)?max(0,(int)round($v)):max(0.0,$v);
    }
    $final['ar_aging']=(string)($actual['ar_aging']??'');
    $final['rev_per_employee']=((int)($final['headcount']??0)>0)?((float)($final['sales_total']??0)/(int)$final['headcount']):null;
    return $final;
}
function kpi_office_payload(array $calc,array $adjustment,string $note,string $actor): array {
    $actual=$calc['actual']??[];
    $final=kpi_office_apply_adjustment($actual,$adjustment);
    $payload=[
      'actual'=>$actual,'adjustment'=>$adjustment,'final'=>$final,
      'coverage'=>$calc['coverage']??[],'system_sources'=>$calc['coverage']??[],
      'source_detail'=>$calc['sources']??[],'source'=>'system_plus_adjustment',
      'note'=>$note,'updated_by'=>$actor,'calc_at'=>date('c'),
      'do_status_counts'=>$calc['do_status_counts']??[],
    ];
    foreach($final as $k=>$v)$payload[$k]=$v;
    return $payload;
}
/**
 * @return array{errors:array<int,string>,warns:array<int,string>}
 */
function kpi_guard_metrics_office(array $metrics, array $prevMetrics, string $status, string $note, string $checker, string $actor): array
{
    $errors = [];
    $warns = [];

    foreach (['do_count','do_paid_count','headcount'] as $k) {
        if ((int)($metrics[$k] ?? 0) < 0) $errors[] = strtoupper($k) . ' tidak boleh negatif.';
    }
    foreach (['sales_total','ar_outstanding','ar_overdue','stock_value','po_total','ap_outstanding','ap_overdue','asset_nbv','asset_cost','rev_per_employee'] as $k) {
        $v = (float)($metrics[$k] ?? 0);
        if ($v < 0) $errors[] = strtoupper($k) . ' tidak boleh negatif.';
        if ($v > 100000000000000.0) $errors[] = strtoupper($k) . ' melewati batas kewajaran sistem.';
    }
    if ((int)($metrics['do_paid_count'] ?? 0) > (int)($metrics['do_count'] ?? 0)) {
        $errors[] = 'DO paid count tidak boleh melebihi DO count.';
    }
    if ((float)($metrics['ar_overdue'] ?? 0) > ((float)($metrics['ar_outstanding'] ?? 0) + 1.0)) {
        $errors[] = 'AR overdue tidak boleh melebihi AR outstanding.';
    }
    if ((float)($metrics['ap_overdue'] ?? 0) > ((float)($metrics['ap_outstanding'] ?? 0) + 1.0)) {
        $errors[] = 'AP overdue tidak boleh melebihi AP outstanding.';
    }

    foreach (['sales_total','ar_outstanding','stock_value','po_total','ap_outstanding','asset_nbv'] as $k) {
        $old = (float)($prevMetrics[$k] ?? 0);
        $new = (float)($metrics[$k] ?? 0);
        if ($old <= 0.0) continue;
        $pct = abs((($new - $old) / $old) * 100.0);
        if ($pct > 50.0) $warns[] = strtoupper($k) . ' berubah ' . number_format($pct, 1, ',', '.') . '%.';
    }

    $isFinal = ($status === 'FINAL');
    if ($isFinal && strlen($note) < 10) $errors[] = 'Catatan minimal 10 karakter untuk FINAL.';
    if ($isFinal) {
        if ($checker === '') $errors[] = 'Checker username wajib untuk FINAL.';
        elseif (strcasecmp($checker, $actor) === 0) $errors[] = 'Checker harus berbeda dari maker (penginput).';
    }
    return ['errors' => $errors, 'warns' => $warns];
}
foreach ([$pk,$MONTH_COL,$OFFICE_COL,$STATUS_COL,$DEL_COL,$JSON_COL,$NOTE_COL,$CREATED_BY,$CREATED_AT,$UPDATED_BY,$UPDATED_AT] as $c) {
    if ($c && !kpi_col_safe($c)) die('Unsafe column name detected');
}

$hasCol = static function(array $allCols, string $target): bool {
    foreach ($allCols as $cc) {
        if (strcasecmp((string)$cc, $target) === 0) return true;
    }
    return false;
};
if (!$hasCol($cols, $pk) || !$hasCol($cols, $MONTH_COL) || !$hasCol($cols, $OFFICE_COL) || !$hasCol($cols, $STATUS_COL) || !$hasCol($cols, $JSON_COL)) {
    kpi_header('KPI Office');
    kpi_nav('office');
    echo "<div class='card'><span class='badge danger'>SCHEMA</span> Kolom wajib KPI Office tidak lengkap (ID/Month/Office/Status/JSON). Jalankan SQL <code>kpi/kpi_enterprise_tables.sql</code> atau migrasikan schema lama.</div>";
    kpi_footer();
    exit;
}

// master data
$offices = kpi_get_master_offices($pdo);


// Flexible schema: office_code VARCHAR OR office_id INT (FK master_office)
$OFFICE_IS_NUM = kpi_is_numeric_column($pdo, $table, $OFFICE_COL);

$officeByCode = [];
$officeById = [];
foreach ($offices as $oRow) {
    $id = trim((string)($oRow['office_id'] ?? ''));
    $code = trim((string)($oRow['office_code'] ?? ''));
    $name = trim((string)($oRow['office_name'] ?? ''));
    if ($code === '') continue;
    if ($id !== '') {
        $officeByCode[strtoupper($code)] = ['office_id'=>$id,'office_code'=>$code,'office_name'=>$name];
        $officeById[$id] = ['office_id'=>$id,'office_code'=>$code,'office_name'=>$name];
    }
}

$officeInputToDb = function(string $officeInput) use ($OFFICE_IS_NUM, $officeByCode): ?string {
    $officeInput = trim($officeInput);
    if ($officeInput === '') return '';
    if (!$OFFICE_IS_NUM) return $officeInput;
    if (ctype_digit($officeInput)) return $officeInput;
    $u = strtoupper($officeInput);
    return $officeByCode[$u]['office_id'] ?? null;
};
$officeDbToCode = function($officeDbVal) use ($OFFICE_IS_NUM, $officeById): string {
    $v = trim((string)$officeDbVal);
    if ($v === '') return '';
    if (!$OFFICE_IS_NUM) return $v;
    return $officeById[$v]['office_code'] ?? $v;
};
$officeDbToLabel = function($officeDbVal) use ($OFFICE_IS_NUM, $officeById, $officeDbToCode): string {
    $code = $officeDbToCode($officeDbVal);
    if ($code === '') return '';
    if (!$OFFICE_IS_NUM) return $code;
    $id = trim((string)$officeDbVal);
    $name = $officeById[$id]['office_name'] ?? '';
    return $name !== '' ? ($code . " • " . $name) : $code;
};

// --- Export CSV (must be before any HTML output) ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fMonth = trim((string)($_GET['month'] ?? ''));
    $fOffice = trim((string)($_GET['office'] ?? ''));
    $fStatus = trim((string)($_GET['status'] ?? ''));

    $where = [];
    $params = [];
    if ($DEL_COL) $where[] = "{$DEL_COL} IS NULL";
    if ($fMonth !== '') { $where[] = "{$MONTH_COL} = :m"; $params[':m'] = $fMonth; }
    if ($fOffice !== '') { $oDb = $officeInputToDb($fOffice); if ($oDb === null) { $where[]='1=0'; } else { $where[] = "{$OFFICE_COL} = :o"; $params[':o'] = $oDb; } }
    if ($fStatus !== '') { $where[] = "{$STATUS_COL} = :s"; $params[':s'] = $fStatus; }

    $sql = "SELECT * FROM {$table}" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY {$MONTH_COL} DESC, {$OFFICE_COL} ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_office', 'export_csv', '', 'filter_month='.(string)$fMonth.';office='.(string)$fOffice.';status='.(string)$fStatus); }

    $out = [];
    foreach ($rows as $r) {
        $m = [];
        $m['month_ym'] = (string)($r[$MONTH_COL] ?? '');
        $m['office_code'] = $officeDbToCode($r[$OFFICE_COL] ?? '');
        $m['status'] = (string)($r[$STATUS_COL] ?? '');
        $m['note'] = $NOTE_COL ? (string)($r[$NOTE_COL] ?? '') : '';

        $metrics = [];
        if (!empty($r[$JSON_COL])) {
            $tmp = json_decode((string)$r[$JSON_COL], true);
            if (is_array($tmp)) $metrics = $tmp;
        }
        foreach (['sales_total','do_count','do_paid_count','ar_outstanding','ar_overdue','ar_aging','stock_value','po_total','ap_outstanding','ap_overdue','asset_nbv','asset_cost','headcount','rev_per_employee'] as $k) {
            if (array_key_exists($k, $metrics)) {
                $m[$k] = is_scalar($metrics[$k]) ? $metrics[$k] : json_encode($metrics[$k], JSON_UNESCAPED_UNICODE);
            }
        }
        $out[] = $m;
    }

    kpi_csv_download('kpi_office_export.csv', $out);
}

// --- Mutations (POST / delete) - must be before HTML output ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
    // Hanya SYS yang boleh mutasi data KPI
    kpi_can_manage_or_die('kpi_office.php');

    $action = (string)$_POST['_action'];

    if ($action === 'delete') {
        if (!kpi_can_manage('SYS')) {
            kpi_flash_set('err', 'Akses ditolak. Hapus data KPI hanya untuk SYS.');
            rmi_redirect('kpi_office.php');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                if ($DEL_COL) {
                    $pdo->prepare("UPDATE {$table} SET {$DEL_COL} = NOW() WHERE {$pk}=:id")->execute([':id'=>$id]);
                } else {
                    $pdo->prepare("DELETE FROM {$table} WHERE {$pk}=:id")->execute([':id'=>$id]);
                }
                if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_office', 'delete', (string)$id, 'delete'); }
                kpi_flash_set('ok', 'Data dihapus.');
            } catch (Throwable $e) {
                kpi_flash_set('err', 'Gagal delete: ' . $e->getMessage());
            }
        }
        rmi_redirect('kpi_office.php');
    }

    // Import CSV
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv'])) {
        kpi_flash_set('err', 'File CSV wajib diupload.');
        rmi_redirect('kpi_office.php');
        }

        $up = $_FILES['csv'];
        $origName = (string)($up['name'] ?? '');
        $safeName = $origName;
        // safe_filename() is preferred; fall back to rmi_safe_filename() if available.
        if (function_exists('safe_filename')) { $safeName = safe_filename($origName); }
        elseif (function_exists('sanitize_filename')) { $safeName = sanitize_filename($origName); }
        elseif (function_exists('rmi_safe_filename')) { $safeName = rmi_safe_filename($origName); }

        $tmp = (string)($up['tmp_name'] ?? '');
        $err = (int)($up['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int)($up['size'] ?? 0);

        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        kpi_flash_set('err', 'Upload CSV gagal.');
        rmi_redirect('kpi_office.php');
        }
        if ($origName === '') {
        kpi_flash_set('err', 'Nama file CSV tidak valid.');
        rmi_redirect('kpi_office.php');
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
        kpi_flash_set('err', 'Format file harus .csv');
        rmi_redirect('kpi_office.php');
        }
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
        kpi_flash_set('err', 'Ukuran CSV terlalu besar (maks 5MB).');
        rmi_redirect('kpi_office.php');
        }

        $mime = '';
        if (function_exists('finfo_open') && is_file($tmp)) {
                $fi = @finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                        $mime = (string)@finfo_file($fi, $tmp);
                        @finfo_close($fi);
                }
        }
        if (function_exists('kpi_audit')) {
                kpi_audit($pdo, 'kpi_office', 'import_csv_start', '', 'filename=' . $safeName . ';size=' . $size . ';mime=' . $mime);
        }

        $fh = fopen($tmp, 'rb');
if (!$fh) {
            kpi_flash_set('err', 'Gagal membaca file.');
            rmi_redirect('kpi_office.php');
        }

        if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_office', 'import_csv_start', '', 'filename='.(string)($_FILES['csv']['name'] ?? '')); }

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            kpi_flash_set('err', 'CSV kosong.');
            rmi_redirect('kpi_office.php');
        }

        $header = array_map(fn($x)=>trim((string)$x), $header);
        $imported = 0;
        $skipped = 0;
        $user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'system';

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
            $data = [];
            foreach ($header as $i=>$key) {
                $data[$key] = $row[$i] ?? '';
            }

            $month = trim((string)($data['month_ym'] ?? ''));
            $office = trim((string)($data['office_code'] ?? ''));
            $officeDb = $officeInputToDb($office);
            if ($officeDb === null) { $skipped++; continue; }
            if ($month === '' || $office === '') { $skipped++; continue; }

            $status = trim((string)($data['status'] ?? 'DRAFT'));
            if (!in_array($status, ['DRAFT','FINAL'], true)) $status = 'DRAFT';

            // CSV hanya membawa adjustment resmi; actual tetap dihitung dari ERP.
            $adjustment = [];
            foreach (kpi_office_metric_keys() as $k) {
                $csvKey = 'adj_' . $k;
                $raw = $data[$csvKey] ?? 0;
                $adjustment[$k] = in_array($k, ['do_count','do_paid_count','headcount'], true)
                    ? kpi_int($raw)
                    : kpi_float($raw);
            }
            $note = trim((string)($data['note'] ?? ($data['notes'] ?? '')));
            $checkerCsv = trim((string)($data['checker_username'] ?? ''));
            if ($status === 'FINAL') {
                if ($checkerCsv === '' || !kpi_user_exists_active($pdo, $checkerCsv)) { $skipped++; continue; }
                if (strcasecmp($checkerCsv, (string)$user) === 0 || strlen($note) < 10) { $skipped++; continue; }
            }
            $calc = kpi_office_actual($pdo, $month, $office);
            $metrics = kpi_office_payload($calc, $adjustment, $note, (string)$user);
            $metrics['imported_at'] = date('c');
            $metrics['checker_username'] = $checkerCsv;
            $payload = json_encode($metrics, JSON_UNESCAPED_UNICODE);

            // Upsert but skip LOCKED
            $existing = null;
            try {
                $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$OFFICE_COL}=:o{$w} LIMIT 1");
                $st->execute([':m'=>$month,':o'=>$officeDb]);
                $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                $existing = null;
            }

            if ($existing && (string)($existing[$STATUS_COL] ?? '') === 'LOCKED') {
                $skipped++;
                continue;
            }

            if ($existing) {
                $id = (int)($existing[$pk] ?? 0);
                $sets = [
                    "{$STATUS_COL} = :s",
                    "{$JSON_COL} = :j",
                ];
                $params = [':s'=>$status, ':j'=>$payload, ':id'=>$id];
                if ($NOTE_COL) { $sets[] = "{$NOTE_COL} = :n"; $params[':n'] = $note; }
                if ($UPDATED_BY) { $sets[] = "{$UPDATED_BY} = :ub"; $params[':ub'] = $user; }
                if ($UPDATED_AT) { $sets[] = "{$UPDATED_AT} = NOW()"; }

                $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$pk}=:id";
                $pdo->prepare($sql)->execute($params);
                $imported++;
            } else {
                $colsIns = [$MONTH_COL,$OFFICE_COL,$STATUS_COL,$JSON_COL];
                $vals = [':m',':o',':s',':j'];
                $params = [':m'=>$month, ':o'=>$officeDb, ':s'=>$status, ':j'=>$payload];

                if ($NOTE_COL) { $colsIns[] = $NOTE_COL; $vals[]=':n'; $params[':n'] = $note; }
                if ($CREATED_BY) { $colsIns[] = $CREATED_BY; $vals[]=':cb'; $params[':cb'] = $user; }
                if ($CREATED_AT) { $colsIns[] = $CREATED_AT; $vals[]='NOW()'; }
                if ($UPDATED_BY) { $colsIns[] = $UPDATED_BY; $vals[]=':ub'; $params[':ub'] = $user; }
                if ($UPDATED_AT) { $colsIns[] = $UPDATED_AT; $vals[]='NOW()'; }

                $sql = "INSERT INTO {$table} (".implode(',', $colsIns).") VALUES (".implode(',', $vals).")";
                $pdo->prepare($sql)->execute($params);
                $imported++;
            }
        }
        fclose($fh);

        kpi_audit($pdo, 'kpi_office', 'import_csv', '', "imported={$imported};skipped={$skipped}");
        kpi_flash_set('ok', "Import selesai. Imported={$imported}, Skipped={$skipped}");
        rmi_redirect('kpi_office.php');
    }

    // Sync system metrics (bulk)
    if ($action === 'sync_system') {
        $month = trim((string)($_POST['month_sync'] ?? ''));
        $onlyOffice = trim((string)($_POST['office_sync'] ?? ''));
        if ($month === '') $month = date('Y-m');

        if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_office', 'sync_system_start', '', 'month='.$month.';office='.(string)$onlyOffice); }

        $targetOffices = [];
        if ($onlyOffice !== '') {
            $targetOffices[] = ['office_code'=>$onlyOffice, 'office_name'=>$onlyOffice];
        } else {
            $targetOffices = $offices ?: [];
            if (!$targetOffices) {
                try {
                    if (kpi_table_exists($pdo, 'sales_do')) {
                        $targetOffices = $pdo->query("SELECT DISTINCT office_code AS office_code, office_code AS office_name FROM sales_do WHERE office_code<>'' ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
                    }
                } catch (Throwable $e) {
                    $targetOffices = [];
                }
            }
        }

        $user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'system';
        $synced = 0;

        foreach ($targetOffices as $o) {
            $officeCode = (string)($o['office_code'] ?? '');
            if ($officeCode === '') continue;
            $calc = kpi_office_actual($pdo, $month, $officeCode);
            $adjustment = [];
            try {
                $officeDbForRead = $officeInputToDb($officeCode);
                if ($officeDbForRead !== null) {
                    $wRead = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                    $stRead = $pdo->prepare("SELECT {$JSON_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$OFFICE_COL}=:o{$wRead} LIMIT 1");
                    $stRead->execute([':m'=>$month, ':o'=>$officeDbForRead]);
                    $oldPayload = kpi_decode_metrics_office((string)($stRead->fetchColumn() ?: ''));
                    if (is_array($oldPayload['adjustment'] ?? null)) $adjustment = $oldPayload['adjustment'];
                }
            } catch (Throwable $e) {
                $adjustment = [];
            }
            $metrics = kpi_office_payload($calc, $adjustment, '', (string)$user);
            $payload = json_encode($metrics, JSON_UNESCAPED_UNICODE);

            $existing = null;
            try {
                $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$OFFICE_COL}=:o{$w} LIMIT 1");
                $officeDb = $officeInputToDb($officeCode);
                if ($officeDb === null) { continue; }
                $st->execute([':m'=>$month,':o'=>$officeDb]);
                $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                $existing = null;
            }

            if ($existing && (string)($existing[$STATUS_COL] ?? '') === 'LOCKED') {
                continue;
            }

            if ($existing) {
                $id = (int)($existing[$pk] ?? 0);
                $sets = ["{$JSON_COL} = :j"];
                $params = [':j'=>$payload, ':id'=>$id];
                if ($UPDATED_BY) { $sets[] = "{$UPDATED_BY} = :ub"; $params[':ub'] = $user; }
                if ($UPDATED_AT) { $sets[] = "{$UPDATED_AT} = NOW()"; }
                $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$pk}=:id";
                $pdo->prepare($sql)->execute($params);
            } else {
                $colsIns = [$MONTH_COL,$OFFICE_COL,$STATUS_COL,$JSON_COL];
                $vals = [':m',':o',':s',':j'];
                $officeDb = $officeInputToDb($officeCode);
                if ($officeDb === null) { continue; }
                $params = [':m'=>$month, ':o'=>$officeDb, ':s'=>'DRAFT', ':j'=>$payload];
                if ($CREATED_BY) { $colsIns[] = $CREATED_BY; $vals[]=':cb'; $params[':cb']=$user; }
                if ($CREATED_AT) { $colsIns[] = $CREATED_AT; $vals[]='NOW()'; }
                if ($UPDATED_BY) { $colsIns[] = $UPDATED_BY; $vals[]=':ub'; $params[':ub']=$user; }
                if ($UPDATED_AT) { $colsIns[] = $UPDATED_AT; $vals[]='NOW()'; }
                $sql = "INSERT INTO {$table} (".implode(',', $colsIns).") VALUES (".implode(',', $vals).")";
                $pdo->prepare($sql)->execute($params);
            }

            $synced++;
        }

        kpi_audit($pdo, 'kpi_office', 'sync_system', $month, "synced={$synced}");
        kpi_flash_set('ok', "Sync selesai. Month={$month} • synced={$synced}");
        rmi_redirect('kpi_office.php');
    }

    // Save single
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $month = trim((string)($_POST['month_ym'] ?? ''));
        $office = trim((string)($_POST['office_code'] ?? ''));
        $officeDb = $officeInputToDb($office);
        if ($officeDb === null) {
            kpi_flash_set('err', 'Office tidak dikenali: '.$office.' (cek master_office).');
            rmi_redirect('kpi_office.php');
        }
        $status = trim((string)($_POST['status'] ?? 'DRAFT'));
        if (!in_array($status, ['DRAFT','FINAL'], true)) $status = 'DRAFT';

        if ($month === '' || $office === '') {
            kpi_flash_set('err', 'Bulan & Office wajib.');
            rmi_redirect('kpi_office.php');
        }

        if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_office', 'save_attempt', '', 'month='.$month.';office='.$office); }

        $prevMetrics = [];
        // Prevent edit if LOCKED
        if ($id > 0) {
            try {
                $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                $st = $pdo->prepare("SELECT {$STATUS_COL}, {$JSON_COL} FROM {$table} WHERE {$pk}=:id{$w} LIMIT 1");
                $st->execute([':id'=>$id]);
                $curRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $cur = (string)($curRow[$STATUS_COL] ?? '');
                $prevMetrics = kpi_decode_metrics_office((string)($curRow[$JSON_COL] ?? ''));
                if ($cur === 'LOCKED') {
                    kpi_flash_set('err', 'Data LOCKED (read-only).');
                    rmi_redirect('kpi_office.php');
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        $adjustment = [
            'sales_total'=>kpi_float($_POST['m_sales_total']??0),
            'do_count'=>kpi_int($_POST['m_do_count']??0),
            'do_paid_count'=>kpi_int($_POST['m_do_paid_count']??0),
            'ar_outstanding'=>kpi_float($_POST['m_ar_outstanding']??0),
            'ar_overdue'=>kpi_float($_POST['m_ar_overdue']??0),
            'stock_value'=>kpi_float($_POST['m_stock_value']??0),
            'po_total'=>kpi_float($_POST['m_po_total']??0),
            'ap_outstanding'=>kpi_float($_POST['m_ap_outstanding']??0),
            'ap_overdue'=>kpi_float($_POST['m_ap_overdue']??0),
            'asset_nbv'=>kpi_float($_POST['m_asset_nbv']??0),
            'asset_cost'=>kpi_float($_POST['m_asset_cost']??0),
            'headcount'=>kpi_int($_POST['m_headcount']??0),
        ];
        $note=trim((string)($_POST['note']??''));
        $user=$_SESSION['username']??$_SESSION['user_name']??$_SESSION['name']??'system';
        $calc=kpi_office_actual($pdo,$month,$office);
        $metrics=kpi_office_payload($calc,$adjustment,$note,(string)$user);
        $payload=json_encode($metrics,JSON_UNESCAPED_UNICODE);
        $checker = trim((string)($_POST['checker_username'] ?? ''));
        if ($checker !== '' && !kpi_user_exists_active($pdo, $checker)) {
            kpi_flash_set('err', 'Checker username tidak ditemukan / non-aktif.');
            rmi_redirect('kpi_office.php');
        }

        // If new record but already exists (month+office) -> update
        if ($id <= 0) {
            try {
                $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL}, {$JSON_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$OFFICE_COL}=:o{$w} LIMIT 1");
                $st->execute([':m'=>$month,':o'=>$officeDb]);
                $ex = $st->fetch(PDO::FETCH_ASSOC);
                if ($ex) {
                    if ((string)($ex[$STATUS_COL] ?? '') === 'LOCKED') {
                        kpi_flash_set('err', 'Data bulan ini sudah LOCKED.');
                        rmi_redirect('kpi_office.php');
                    }
                    $id = (int)($ex[$pk] ?? 0);
                    $prevMetrics = kpi_decode_metrics_office((string)($ex[$JSON_COL] ?? ''));
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        $guard = kpi_guard_metrics_office(
            (array)($metrics['final'] ?? []),
            (array)($prevMetrics['final'] ?? $prevMetrics),
            $status, $note, $checker, (string)$user
        );
        if (count($guard['errors']) > 0) {
            kpi_flash_set('err', implode(' ', $guard['errors']));
            rmi_redirect('kpi_office.php');
        }
        if (count($guard['warns']) > 0 && function_exists('kpi_audit')) {
            kpi_audit($pdo, 'kpi_office', 'guard_warn', (string)$id, implode(' | ', $guard['warns']));
        }

        if ($id > 0) {
            $sets = [
                "{$MONTH_COL} = :m",
                "{$OFFICE_COL} = :o",
                "{$STATUS_COL} = :s",
                "{$JSON_COL} = :j",
            ];
            $params = [':m'=>$month, ':o'=>$officeDb, ':s'=>$status, ':j'=>$payload, ':id'=>$id];
            if ($NOTE_COL) { $sets[] = "{$NOTE_COL} = :n"; $params[':n'] = $note; }
            if ($UPDATED_BY) { $sets[] = "{$UPDATED_BY} = :ub"; $params[':ub'] = $user; }
            if ($UPDATED_AT) { $sets[] = "{$UPDATED_AT} = NOW()"; }

            $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$pk}=:id";
            $pdo->prepare($sql)->execute($params);
            kpi_audit($pdo, 'kpi_office', 'update', (string)$id, "office={$office};month={$month};checker={$checker}");
            if (function_exists('master_audit')) {
                master_audit($pdo, 'kpi_office', 'kpi_office_snapshot', 'UPDATE', (int)$id, $office . '-' . $month, "KPI office update: {$office} {$month}", []);
            }
            kpi_flash_set('ok', 'Data tersimpan.');
        } else {
            $colsIns = [$MONTH_COL,$OFFICE_COL,$STATUS_COL,$JSON_COL];
            $vals = [':m',':o',':s',':j'];
            $params = [':m'=>$month, ':o'=>$officeDb, ':s'=>$status, ':j'=>$payload];

            if ($NOTE_COL) { $colsIns[] = $NOTE_COL; $vals[]=':n'; $params[':n'] = $note; }
            if ($CREATED_BY) { $colsIns[] = $CREATED_BY; $vals[]=':cb'; $params[':cb']=$user; }
            if ($CREATED_AT) { $colsIns[] = $CREATED_AT; $vals[]='NOW()'; }
            if ($UPDATED_BY) { $colsIns[] = $UPDATED_BY; $vals[]=':ub'; $params[':ub']=$user; }
            if ($UPDATED_AT) { $colsIns[] = $UPDATED_AT; $vals[]='NOW()'; }

            $sql = "INSERT INTO {$table} (".implode(',', $colsIns).") VALUES (".implode(',', $vals).")";
            $pdo->prepare($sql)->execute($params);
            $newId = (string)$pdo->lastInsertId();
            kpi_audit($pdo, 'kpi_office', 'create', $newId, "office={$office};month={$month};checker={$checker}");
            if (function_exists('master_audit')) {
                master_audit($pdo, 'kpi_office', 'kpi_office_snapshot', 'CREATE', (int)$newId, $office . '-' . $month, "KPI office create: {$office} {$month}", []);
            }
            kpi_flash_set('ok', 'Data dibuat.');
        }

        rmi_redirect('kpi_office.php');
    }
}

// --- UI (safe to output HTML now) ---

$flash = kpi_flash_get();

// Load edit
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    try {
        $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
        $st = $pdo->prepare("SELECT * FROM {$table} WHERE {$pk}=:id{$w} LIMIT 1");
        $st->execute([':id'=>$editId]);
        $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $editRow = null;
    }
}

// Prefill via system auto
$autoMonth = trim((string)($_GET['auto_month'] ?? ''));
$autoOffice = trim((string)($_GET['auto_office'] ?? ''));
$autoMetrics = null;
if (kpi_can_manage() && ($autoMonth !== '' && $autoOffice !== '') && isset($_GET['auto']) && $_GET['auto'] === '1') {
    $sales = kpi_calc_sales_metrics($pdo, $autoMonth, $autoOffice);
    $stock = kpi_calc_stock_value($pdo, $autoOffice);
    $purch = kpi_calc_purchases_metrics($pdo, $autoMonth, $autoOffice);
    $fa    = kpi_calc_fixed_asset_metrics($pdo, $autoMonth, $autoOffice);
    $hc    = kpi_calc_headcount($pdo, $autoOffice);

    $salesTotal = (float)($sales['sales_total'] ?? 0);
    $headcount = (int)($hc['headcount'] ?? 0);
    $revPerEmp = ($headcount > 0) ? ($salesTotal / $headcount) : 0.0;

    $autoMetrics = [
        'sales_total' => $salesTotal,
        'do_count' => (int)($sales['do_count'] ?? 0),
        'do_paid_count' => (int)($sales['do_paid_count'] ?? 0),
        'ar_outstanding' => (float)($sales['ar_outstanding'] ?? 0),
        'ar_overdue' => (float)($sales['ar_overdue'] ?? 0),
        'ar_aging' => (string)($sales['ar_aging'] ?? ''),
        'stock_value' => (float)($stock['stock_value'] ?? 0),
        'po_total' => (float)($purch['po_total'] ?? 0),
        'ap_outstanding' => (float)($purch['ap_outstanding'] ?? 0),
        'ap_overdue' => (float)($purch['ap_overdue'] ?? 0),
        'asset_cost' => (float)($fa['asset_cost'] ?? 0),
        'asset_nbv' => (float)($fa['asset_nbv'] ?? 0),
        'headcount' => $headcount,
        'rev_per_employee' => $revPerEmp,
        'source' => 'auto_preview',
    ];
}

// Filters
$fMonth  = trim((string)($_GET['month'] ?? ''));
$fOffice = trim((string)($_GET['office'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));

$where = [];
$params = [];
if ($DEL_COL) $where[] = "{$DEL_COL} IS NULL";
if ($fMonth !== '') { $where[] = "{$MONTH_COL} = :m"; $params[':m'] = $fMonth; }
if ($fOffice !== '') { $oDb = $officeInputToDb($fOffice); if ($oDb === null) { $where[]='1=0'; } else { $where[] = "{$OFFICE_COL} = :o"; $params[':o'] = $oDb; } }
if ($fStatus !== '') { $where[] = "{$STATUS_COL} = :s"; $params[':s'] = $fStatus; }

$sql = "SELECT * FROM {$table}" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY {$MONTH_COL} DESC, {$OFFICE_COL} ASC, {$pk} DESC LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Render
kpi_header('KPI Office');
kpi_nav('office');

if (!empty($flash['err'])) {
    echo "<div class='card kpi-flash-err'><b>Error:</b> " . h((string)$flash['err']) . "</div>";
}
if (!empty($flash['ok'])) {
    echo "<div class='card kpi-flash-ok'><b>OK:</b> " . h((string)$flash['ok']) . "</div>";
}

echo "<div class='card'>
  <div class='kpi-header-row'>
    <div>
      <h2>KPI Office</h2>
      <div class='kpi-subtitle'>Actual otomatis dari ERP per office. Form hanya untuk adjustment resmi; FINAL wajib maker-checker; LOCKED hanya melalui Snapshot.</div>
    </div>
    <div>
      <a class='btn secondary' href='kpi_office.php?export=csv&month=".urlencode($fMonth)."&office=".urlencode($fOffice)."&status=".urlencode($fStatus)."'>Export CSV</a>
      <a class='btn secondary' href='kpi_office.php'>Reset</a>
    </div>
  </div>
</div>";

if (!kpi_can_manage()) {
    echo kpi_sys_only_data_notice_html('KPI Office');
}

// Sync system
$defaultMonth = $fMonth ?: date('Y-m');
if (kpi_can_manage()) {
    echo "<div class='card'>
      <h3 class='kpi-section-title'>Sync System Metrics (Bulk)</h3>
      <form method='post' class='kpi-form-row'>
        ".(function_exists('csrf_field') ? csrf_field() : '')."
        <input type='hidden' name='_action' value='sync_system'>
        <div class='kpi-field'><label>Bulan</label><input type='month' name='month_sync' value='".h($defaultMonth)."'></div>
        <div class='kpi-field'><label>Office (optional)</label><input name='office_sync' value='' placeholder='Kosong = semua office'></div>
        <div class='kpi-field'><button class='btn ok' type='submit'>Sync</button></div>
      </form>
      <div class='muted'>Sync akan mengisi <code>metrics_json</code> dari data modul lain jika tabelnya tersedia. Baris LOCKED tidak akan diubah.</div>
    </div>";
}

// Import CSV
if (kpi_can_manage()) {
    echo "<div class='card'>
      <h3 class='kpi-section-title'>Import CSV</h3>
      <form method='post' enctype='multipart/form-data' class='kpi-form-row'>
        ".(function_exists('csrf_field') ? csrf_field() : '')."
        <input type='hidden' name='_action' value='import_csv'>
        <div class='kpi-field'><label>File CSV</label><input type='file' name='csv' accept='.csv,text/csv' required></div>
        <div class='kpi-field'><button class='btn secondary' type='submit'>Import</button></div>
      </form>
      <div class='muted'>Template: <a href='templates/kpi_office_template.csv'>kpi/templates/kpi_office_template.csv</a></div>
    </div>";
}

// Create/Update form
$valMonth = $editRow[$MONTH_COL] ?? ($autoMonth ?: $defaultMonth);
$valOfficeDb = $editRow[$OFFICE_COL] ?? '';
$valOffice = $editRow ? $officeDbToCode($valOfficeDb) : ($autoOffice ?: '');
$valStatus = $editRow[$STATUS_COL] ?? 'DRAFT';

$valMetrics = [
    'sales_total'=>0,'do_count'=>0,'do_paid_count'=>0,
    'ar_outstanding'=>0,'ar_overdue'=>0,'ar_aging'=>'',
    'stock_value'=>0,'po_total'=>0,'ap_outstanding'=>0,'ap_overdue'=>0,
    'asset_nbv'=>0,'asset_cost'=>0,
    'headcount'=>0,'rev_per_employee'=>0,
    'note'=>'',
    'source'=>'system_plus_adjustment'
];
if ($editRow && !empty($editRow[$JSON_COL])) {
    $tmp = json_decode((string)$editRow[$JSON_COL], true);
    if (is_array($tmp)) $valMetrics = array_merge($valMetrics, is_array($tmp['adjustment'] ?? null) ? $tmp['adjustment'] : []);
}

$locked = ($editRow && (string)($editRow[$STATUS_COL] ?? '') === 'LOCKED');
$lockedNotice = $locked ? "<span class='badge danger'>LOCKED</span> <span class='muted'>Data terkunci (read-only).</span>" : '';

$previewActual = [];
$previewFinal = [];
$previewCoverage = [];
if ($valMonth !== '' && $valOffice !== '') {
    try {
        $previewCalc = kpi_office_actual($pdo, (string)$valMonth, (string)$valOffice);
        $previewActual = (array)($previewCalc['actual'] ?? []);
        $previewCoverage = (array)($previewCalc['coverage'] ?? []);
        $previewFinal = kpi_office_apply_adjustment($previewActual, $valMetrics);
    } catch (Throwable $e) {
        $previewActual = [];
        $previewFinal = [];
        $previewCoverage = [];
    }
}

$officeOptions = "<option value=''>-- pilih --</option>";
foreach ($offices as $o) {
    $code = (string)($o['office_code'] ?? '');
    $name = (string)($o['office_name'] ?? $code);
    if ($code === '') continue;
    $sel = ($code === $valOffice) ? 'selected' : '';
    $officeOptions .= "<option {$sel} value='".h($code)."'>".h($code.' • '.$name)."</option>";
}

$autoLink = '';
if (kpi_can_manage() && $valMonth && $valOffice) {
    $autoLink = "<a class='btn secondary' href='kpi_office.php?auto=1&auto_month=".urlencode($valMonth)."&auto_office=".urlencode($valOffice)."'>Auto-Fill dari Sistem</a>";
}

$officeInput = $offices
    ? "<select name='office_code' required>{$officeOptions}</select>"
    : "<input name='office_code' value='".h($valOffice)."' placeholder='OFFICE_CODE' required>";

if (kpi_can_manage()) {
    echo "<div class='card'>
  <div class='kpi-header-row'>
    <h3 class='kpi-section-title'>".($editId ? 'Edit Adjustment KPI Office #'.h((string)$editId) : 'Adjustment KPI Office')."</h3>
    <div>{$lockedNotice}</div>
  </div>
  <form method='post'>
    ".(function_exists('csrf_field') ? csrf_field() : '')."
    <input type='hidden' name='_action' value='save'>
    <input type='hidden' name='id' value='".h((string)($editId ?: 0))."'>

    <div class='kpi-form-row'>
      <div class='kpi-field'><label>Bulan</label><input type='month' name='month_ym' value='".h($valMonth)."' required></div>
      <div class='kpi-field'><label>Office</label>{$officeInput}</div>
      <div class='kpi-field'><label>Status</label><select name='status'>
          <option ".($valStatus==='DRAFT'?'selected':'').">DRAFT</option>
          <option ".($valStatus==='FINAL'?'selected':'').">FINAL</option>
          
      </select></div>
      <div class='kpi-field'>
        <button class='btn ok' type='submit' ".($locked?'disabled':'').">Simpan</button>
        ".($editId?"<a class='btn secondary' href='kpi_office.php'>Batal</a>":"")."
        {$autoLink}
      </div>
    </div>

    <div class='kpi-input-group'>
      <div class='kpi-input-group-title'>Adjustment resmi — isi hanya selisih koreksi (+/-), bukan menyalin actual ERP</div>
      <div class='kpi-form-grid'>
        <div class='kpi-field'><label>Adjustment Sales Total (+/-)</label><input type='number' step='0.01' name='m_sales_total' value='".h((string)($valMetrics['sales_total'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment DO Count (+/-)</label><input type='number' name='m_do_count' value='".h((string)($valMetrics['do_count'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment DO Paid (+/-)</label><input type='number' name='m_do_paid_count' value='".h((string)($valMetrics['do_paid_count'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment AR Outstanding (+/-)</label><input type='number' step='0.01' name='m_ar_outstanding' value='".h((string)($valMetrics['ar_outstanding'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment AR Overdue (+/-)</label><input type='number' step='0.01' name='m_ar_overdue' value='".h((string)($valMetrics['ar_overdue'] ?? 0))."'></div>
        <div class='kpi-field kpi-field-full'><label>AR Aging (otomatis dari ERP)</label><input value='".h((string)($previewActual['ar_aging'] ?? ''))."' placeholder='Belum tersedia' readonly></div>
      </div>
    </div>

    <div class='kpi-input-group'>
      <div class='kpi-input-group-title'>Stock & Purchases</div>
      <div class='kpi-form-grid'>
        <div class='kpi-field'><label>Adjustment Stock Value (+/-)</label><input type='number' step='0.01' name='m_stock_value' value='".h((string)($valMetrics['stock_value'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment PO Total (+/-)</label><input type='number' step='0.01' name='m_po_total' value='".h((string)($valMetrics['po_total'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment AP Outstanding (+/-)</label><input type='number' step='0.01' name='m_ap_outstanding' value='".h((string)($valMetrics['ap_outstanding'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment AP Overdue (+/-)</label><input type='number' step='0.01' name='m_ap_overdue' value='".h((string)($valMetrics['ap_overdue'] ?? 0))."'></div>
      </div>
    </div>

    <div class='kpi-input-group'>
      <div class='kpi-input-group-title'>Asset & HR</div>
      <div class='kpi-form-grid'>
        <div class='kpi-field'><label>Adjustment Asset Cost (+/-)</label><input type='number' step='0.01' name='m_asset_cost' value='".h((string)($valMetrics['asset_cost'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment Asset NBV (+/-)</label><input type='number' step='0.01' name='m_asset_nbv' value='".h((string)($valMetrics['asset_nbv'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment Headcount (+/-)</label><input type='number' name='m_headcount' value='".h((string)($valMetrics['headcount'] ?? 0))."'></div>
        <div class='kpi-field'><label>Revenue / Employee (otomatis)</label><input type='text' value='".h(number_format((float)($previewFinal['rev_per_employee'] ?? 0), 0, ',', '.'))."' readonly></div>
        <div class='kpi-field'><label>Source</label><input value='system_plus_adjustment' readonly></div>
        <div class='kpi-field'><label>Checker Username</label><input name='checker_username' value='' placeholder='wajib saat FINAL'></div>
      </div>
    </div>

    <div class='kpi-field kpi-field-full' style='margin-top:12px'>
      <label>Catatan</label>
      <textarea name='note' rows='2'>".h((string)($valMetrics['note'] ?? ''))."</textarea>
    </div>
  </form>
</div>";
} elseif ($editId > 0) {
    if ($editRow) {
        $noteCol = $NOTE_COL ? (string)($editRow[$NOTE_COL] ?? '') : '';
        $jsonPretty = h(json_encode($valMetrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "<div class='card'>
  <div class='kpi-header-row'>
    <h3 class='kpi-section-title'>Detail KPI Office #".h((string)$editId)." <span class='muted'>(baca saja)</span></h3>
    <div>{$lockedNotice}</div>
  </div>
  <p class='muted'>Bulan: <b>".h($valMonth)."</b> • Office: <b>".h($valOffice)."</b> • Status: <b>".h($valStatus)."</b></p>
  ".($noteCol !== '' ? "<p class='muted'>Catatan kolom: ".h($noteCol)."</p>" : "")."
  <pre class='muted' style='white-space:pre-wrap;font-size:12px;max-height:420px;overflow:auto'>{$jsonPretty}</pre>
  <a class='btn secondary' href='kpi_office.php'>← Kembali ke daftar</a>
</div>";
    } else {
        echo "<div class='card'><span class='badge danger'>Tidak ditemukan</span> <span class='muted'>ID #".h((string)$editId)."</span> — <a href='kpi_office.php'>Kembali</a></div>";
    }
}

// Filters
$officeFilterOptions = "<option value=''>-- Semua Office --</option>";
foreach ($offices as $o) {
    $code = (string)($o['office_code'] ?? '');
    $name = (string)($o['office_name'] ?? $code);
    if ($code === '') continue;
    $sel = ($code === $fOffice) ? 'selected' : '';
    $officeFilterOptions .= "<option {$sel} value='".h($code)."'>".h($code.' • '.$name)."</option>";
}

$officeFilterInput = $offices
    ? "<select name='office'>{$officeFilterOptions}</select>"
    : "<input name='office' value='".h($fOffice)."'>";

echo "<div class='card'>
  <h3 class='kpi-section-title'>Filter</h3>
  <form method='get' class='kpi-form-row'>
    <div class='kpi-field'><label>Bulan</label><input type='month' name='month' value='".h($fMonth)."'></div>
    <div class='kpi-field'><label>Office</label>{$officeFilterInput}</div>
    <div class='kpi-field'><label>Status</label><input name='status' value='".h($fStatus)."' placeholder='DRAFT/FINAL/LOCKED'></div>
    <div class='kpi-field'>
      <button class='btn secondary' type='submit'>Apply</button>
      <a class='btn secondary' href='kpi_office.php'>Reset</a>
    </div>
  </form>
</div>";

// List
$fmt = fn($v) => number_format((float)$v, 0, ',', '.');

echo "<div class='card'>
  <h3 class='kpi-section-title'>Data KPI Office</h3>
  <div class='table-wrap'>
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Bulan</th><th>Office</th><th>Status</th>
        <th>Sales Final</th><th>DO Final</th><th>AR Out Final</th><th>Stock Final</th><th>PO Final</th><th>AP Out Final</th><th>Asset NBV Final</th><th>HC Final</th><th>Coverage</th><th>Aksi</th>
      </tr>
    </thead>
    <tbody>";

foreach ($rows as $r) {
    $id = (int)($r[$pk] ?? 0);
    $m = (string)($r[$MONTH_COL] ?? '');
    $o = $officeDbToLabel($r[$OFFICE_COL] ?? '');
    $s = (string)($r[$STATUS_COL] ?? '');

    $metrics = [];
    if (!empty($r[$JSON_COL])) {
        $tmp = json_decode((string)$r[$JSON_COL], true);
        if (is_array($tmp)) $metrics = $tmp;
    }
    $coverageMap = is_array($metrics['coverage'] ?? null) ? $metrics['coverage'] : [];
    $coverageReady = 0;
    $coverageTotal = 5;
    foreach (['sales','stock','purchases','fixed_asset','headcount'] as $ck) {
        if (!empty($coverageMap[$ck])) $coverageReady++;
    }
    $coverageText = $coverageReady . '/' . $coverageTotal;

    $badgeCls = ($s === 'LOCKED') ? 'pill danger' : 'pill';

    echo "<tr>
      <td>".h((string)$id)."</td>
      <td>".h($m)."</td>
      <td>".h($o)."</td>
      <td><span class='".h($badgeCls)."'>".h($s)."</span></td>
      <td style='text-align:right'>".h($fmt($metrics['sales_total'] ?? 0))."</td>
      <td style='text-align:right'>".h((string)($metrics['do_count'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt($metrics['ar_outstanding'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt($metrics['stock_value'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt($metrics['po_total'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt($metrics['ap_outstanding'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt($metrics['asset_nbv'] ?? 0))."</td>
      <td style='text-align:right'>".h((string)($metrics['headcount'] ?? 0))."</td>
      <td><span class='pill'>".h($coverageText)." READY</span></td>
      <td>
        ".(kpi_can_manage() ? "<a class='btn tiny' href='kpi_office.php?edit=".h((string)$id)."'>Edit</a> " : "<a class='btn tiny secondary' href='kpi_office.php?edit=".h((string)$id)."'>Lihat</a> ")."
        ".(kpi_can_manage('SYS') ? "<form method='post' style='display:inline;margin:0;padding:0' onsubmit='return confirm(\"Hapus data ini?\")'>
          ".(function_exists('csrf_field') ? csrf_field() : '')."
          <input type='hidden' name='_action' value='delete'>
          <input type='hidden' name='id' value='".h((string)$id)."'>
          <button class='btn tiny danger' type='submit'>Del</button>
        </form>" : "")."
      </td>
    </tr>";
}

echo "</tbody></table></div>
  <div class='muted'>Total rows: ".h((string)count($rows))." (limit 500)</div>
</div>";

kpi_footer();

