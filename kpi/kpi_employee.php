<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// KPI Employee (Phase 2) - Enterprise+++
// - Integrated (read-only to other modules)
// - Flexible schema: dept_code VARCHAR OR departement_id/department_id INT
// - Flexible schema: employee_id VARCHAR OR employee_id INT (FK to master_employees)
// - Safe: never crashes on schema mismatch; shows actionable message.

require_once __DIR__ . '/_kpi_bootstrap.php';
require_once __DIR__ . '/_kpi_metrics.php';
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

$table = 'kpi_employee';

// --- tiny flash helper (session) ---
if (!isset($_SESSION['_kpi_flash_em']) || !is_array($_SESSION['_kpi_flash_em'])) {
    $_SESSION['_kpi_flash_em'] = [];
}
function kpi_flash_em_set(string $type, string $msg): void {
    $_SESSION['_kpi_flash_em'][$type] = $msg;
}
function kpi_flash_em_get(): array {
    $f = $_SESSION['_kpi_flash_em'] ?? [];
    $_SESSION['_kpi_flash_em'] = [];
    return is_array($f) ? $f : [];
}

// --- KPI Employee metric helpers ---
// Actual berasal dari audit ERP. Form manual hanya menyimpan adjustment/koreksi.
function kpi_emp_nullable_number($value): ?float {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return (float)$value;
}
function kpi_emp_clamp_pct(?float $value): ?float {
    if ($value === null) return null;
    return max(0.0, min(100.0, $value));
}
function kpi_emp_rebuild_metrics(array $metrics): array {
    $keys = ['tasks_done','productivity','quality','on_time_rate','errors'];
    $actual = is_array($metrics['actual'] ?? null) ? $metrics['actual'] : [];
    $adjustment = is_array($metrics['adjustment'] ?? null) ? $metrics['adjustment'] : [];

    $available = 0;
    foreach ($keys as $key) {
        $a = kpi_emp_nullable_number($actual[$key] ?? null);
        $d = kpi_emp_nullable_number($adjustment[$key] ?? null);
        if ($a !== null) $available++;
        $final = null;
        if ($a !== null || $d !== null) $final = ($a ?? 0.0) + ($d ?? 0.0);
        if (in_array($key, ['productivity','quality','on_time_rate'], true)) {
            $final = kpi_emp_clamp_pct($final);
        } elseif ($final !== null) {
            $final = max(0.0, $final);
        }
        $metrics[$key] = $final;
    }
    $metrics['coverage_pct'] = round(($available / count($keys)) * 100, 2);
    $metrics['is_valid'] = $available >= 3;
    return $metrics;
}
function kpi_emp_current_actor(): string {
    foreach (['username','user_name','user','login','employee_id'] as $key) {
        if (!empty($_SESSION[$key]) && is_scalar($_SESSION[$key])) return trim((string)$_SESSION[$key]);
    }
    return '';
}

if (!kpi_table_exists($pdo, $table)) {
    kpi_header('KPI Employee');
    kpi_nav('employee');
    echo "<div class='card'><span class='badge danger'>MISSING</span> Tabel <b>{$table}</b> belum ada. Jalankan SQL: <code>kpi/kpi_enterprise_tables.sql</code></div>";
    kpi_footer();
    exit;
}

$cols = kpi_table_columns($pdo, $table);
$pk = kpi_pick_col($cols, ['id','kpi_employee_id']) ?: 'id';
$MONTH_COL = kpi_month_col($pdo, $table);

// Detect dept/employee columns with compatibility for older schemas
$DEPT_COL  = kpi_dept_col($pdo, $table);
if (!$DEPT_COL) {
    foreach ($cols as $c) {
        $lc = strtolower((string)$c);
        if (strpos($lc,'dept') !== false || strpos($lc,'depart') !== false) { $DEPT_COL = $c; break; }
    }
}
$EMP_COL   = kpi_employee_col($pdo, $table);
if (!$EMP_COL) {
    foreach ($cols as $c) {
        $lc = strtolower((string)$c);
        if (strpos($lc,'emp') !== false || strpos($lc,'user') !== false) { $EMP_COL = $c; break; }
    }
}

$STATUS_COL= kpi_status_col($pdo, $table);
$DEL_COL   = kpi_deleted_col($pdo, $table);
$JSON_COL  = kpi_json_col($pdo, $table) ?: 'metrics_json';
$NOTE_COL  = kpi_pick_col($cols, ['note','notes']);
$CREATED_AT = kpi_pick_col($cols, ['created_at']);
$UPDATED_AT = kpi_pick_col($cols, ['updated_at']);

function kpi_col_safe2(string $c): bool { return (bool)preg_match('/^[A-Za-z0-9_]+$/', $c); }
foreach ([$pk,$MONTH_COL,$DEPT_COL,$EMP_COL,$STATUS_COL,$DEL_COL,$JSON_COL,$NOTE_COL,$CREATED_AT,$UPDATED_AT] as $c) {
    if ($c && !kpi_col_safe2($c)) die('Unsafe column name detected');
}
$HAS_MONTH_COL = false;
foreach ($cols as $c0) {
    if (strcasecmp((string)$c0, (string)$MONTH_COL) === 0) {
        $HAS_MONTH_COL = true;
        break;
    }
}

// KPI_EMP_SCHEMA_GUARD
if (!$DEPT_COL || !$EMP_COL || !$HAS_MONTH_COL) {
    kpi_header('KPI Employee');
    kpi_nav('employee');
    $colsList = implode(', ', array_map('strval', $cols));
    echo "<div class='card'><span class='badge danger'>SCHEMA</span>
            Tabel <b>{$table}</b> tidak punya kolom wajib yang bisa dideteksi (Month/Dept/Employee).<br>
            Kolom terdeteksi: <code>".h($colsList)."</code><br>
            Solusi cepat: jalankan SQL <code>kpi/kpi_enterprise_tables.sql</code> (atau migrasi ALTER TABLE agar ada kolom dept & employee).</div>";
    kpi_footer();
    exit;
}

// Determine column types (INT vs VARCHAR)
$DEPT_IS_NUM = kpi_is_numeric_column($pdo, $table, $DEPT_COL);
$EMP_IS_NUM  = kpi_is_numeric_column($pdo, $table, $EMP_COL);

// Dept scoping for non-admin (session stores dept CODE)
$sessionDeptCode = '';
foreach (['dept_code','department_code','dept','department','departement'] as $k) {
    if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) { $sessionDeptCode = (string)$_SESSION[$k]; break; }
}
// Scope view: SYS lihat semua dept (mutasi KPI hanya SYS). Dept lain hanya lihat dept sendiri.
$enforceDept = (!kpi_can_manage() && $sessionDeptCode !== '');

// Master maps (optional)
$deptRows = kpi_get_master_departements($pdo);
$deptByCode = [];
$deptById = [];
foreach ($deptRows as $d) {
    $id = trim((string)($d['dept_id'] ?? ''));
    $code = kpi_up((string)($d['dept_code'] ?? ''));
    $name = trim((string)($d['dept_name'] ?? ''));
    if ($code === '' || $id === '') continue;
    $deptByCode[$code] = ['dept_id'=>$id, 'dept_code'=>$code, 'dept_name'=>$name];
    $deptById[$id] = ['dept_id'=>$id, 'dept_code'=>$code, 'dept_name'=>$name];
}

// Employees list (optional)
$employees = kpi_get_master_employees($pdo);
$empByPk = [];
$empByCode = [];
$empByName = [];
foreach ($employees as $e) {
    $pkv = trim((string)($e['employee_pk'] ?? ''));
    $code = trim((string)($e['employee_id'] ?? ''));
    $name = trim((string)($e['employee_name'] ?? ''));
    if ($pkv !== '') {
        $empByPk[$pkv] = ['employee_pk'=>$pkv, 'employee_id'=>$code, 'employee_name'=>$name];
    }
    if ($code !== '' && $pkv !== '') {
        $empByCode[strtoupper($code)] = $pkv;
    }
    if ($name !== '' && $pkv !== '') {
        $empByName[strtoupper($name)] = $pkv;
    }
}

// Converters (input code/name -> DB value)
$deptInputToDb = function(string $deptInput) use ($DEPT_IS_NUM, $deptByCode): ?string {
    $deptInput = kpi_up($deptInput);
    if ($deptInput === '') return '';
    if (!$DEPT_IS_NUM) return $deptInput; // store code as-is (normalized)
    if (ctype_digit($deptInput)) return $deptInput;
    return $deptByCode[$deptInput]['dept_id'] ?? null;
};
$deptDbToCode = function($deptDbVal) use ($DEPT_IS_NUM, $deptById): string {
    $v = trim((string)$deptDbVal);
    if ($v === '') return '';
    if (!$DEPT_IS_NUM) return kpi_up($v);
    return $deptById[$v]['dept_code'] ?? $v;
};
$deptDbToLabel = function($deptDbVal) use ($DEPT_IS_NUM, $deptById, $deptDbToCode): string {
    $code = $deptDbToCode($deptDbVal);
    if ($code === '') return '';
    if (!$DEPT_IS_NUM) {
        // try enrich name
        $row = $deptById[$code] ?? null;
        return $code;
    }
    $id = trim((string)$deptDbVal);
    $name = $deptById[$id]['dept_name'] ?? '';
    return $name !== '' ? ($code . " • " . $name) : $code;
};

$empInputToDb = function(string $empInput) use ($EMP_IS_NUM, $empByCode, $empByName): ?string {
    $empInput = trim($empInput);
    if ($empInput === '') return '';
    if (!$EMP_IS_NUM) return $empInput; // store whatever actor identifier string
    if (ctype_digit($empInput)) return $empInput;
    $u = strtoupper($empInput);
    if (isset($empByCode[$u])) return (string)$empByCode[$u];
    if (isset($empByName[$u])) return (string)$empByName[$u];
    return null;
};
$empDbToCode = function($empDbVal) use ($EMP_IS_NUM, $empByPk): string {
    $v = trim((string)$empDbVal);
    if ($v === '') return '';
    if (!$EMP_IS_NUM) return $v;
    return trim((string)($empByPk[$v]['employee_id'] ?? $v));
};
$empDbToLabel = function($empDbVal) use ($EMP_IS_NUM, $empByPk, $empDbToCode): string {
    $v = trim((string)$empDbVal);
    if ($v === '') return '';
    if (!$EMP_IS_NUM) return $v;
    $code = $empDbToCode($v);
    $name = trim((string)($empByPk[$v]['employee_name'] ?? ''));
    if ($name !== '' && $code !== '') return $code . " • " . $name;
    if ($name !== '') return $name;
    return $code !== '' ? $code : $v;
};

// Pre-resolve session dept DB value (if enforced & table expects INT)
$sessionDeptDb = null;
if ($enforceDept && $sessionDeptCode !== '') {
    $tmp = $deptInputToDb($sessionDeptCode);
    if ($tmp !== null && $tmp !== '') $sessionDeptDb = $tmp;
}

// --- Export CSV (before HTML) ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fMonth = trim((string)($_GET['month'] ?? date('Y-m')));
    $fDept  = trim((string)($_GET['dept'] ?? ''));
    $fEmp   = trim((string)($_GET['emp'] ?? ''));

    if ($enforceDept) $fDept = $sessionDeptCode;

    $where = [];
    $params = [];
    if ($DEL_COL) $where[] = "{$DEL_COL} IS NULL";
    if ($fMonth !== '') { $where[] = "{$MONTH_COL} = :m"; $params[':m'] = $fMonth; }

    if ($fDept !== '') {
        $dDb = $deptInputToDb($fDept);
        if ($dDb === null) { $where[] = "1=0"; } else { $where[] = "{$DEPT_COL} = :d"; $params[':d'] = $dDb; }
    }
    if ($fEmp !== '') {
        $eDb = $empInputToDb($fEmp);
        if ($eDb === null) { $where[] = "1=0"; } else { $where[] = "{$EMP_COL} = :e"; $params[':e'] = $eDb; }
    }

    $sql = "SELECT * FROM {$table}" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY {$MONTH_COL} DESC, {$DEPT_COL} ASC, {$EMP_COL} ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_employee', 'export_csv', '', 'filter_month='.(string)$fMonth.';dept='.(string)$fDept.';emp='.(string)$fEmp); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kpi_employee_export.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['month_ym','dept_code','employee_id','status','tasks_done','productivity','quality','on_time_rate','errors','note','source','sources']);

    foreach ($rows as $r) {
        $m = (string)($r[$MONTH_COL] ?? '');
        $d = $deptDbToCode($r[$DEPT_COL] ?? '');
        $e = $EMP_IS_NUM ? $empDbToCode($r[$EMP_COL] ?? '') : (string)($r[$EMP_COL] ?? '');
        $s = (string)($r[$STATUS_COL] ?? '');
        $note = $NOTE_COL ? (string)($r[$NOTE_COL] ?? '') : '';

        $metrics = [];
        if (!empty($r[$JSON_COL])) {
            $tmp = json_decode((string)$r[$JSON_COL], true);
            if (is_array($tmp)) $metrics = $tmp;
        }

        $rowOut = [
            $m,
            $d,
            $e,
            $s,
            (int)($metrics['tasks_done'] ?? 0),
            (float)($metrics['productivity'] ?? 0),
            (float)($metrics['quality'] ?? 0),
            (float)($metrics['on_time_rate'] ?? 0),
            (int)($metrics['errors'] ?? 0),
            $note,
            (string)($metrics['source'] ?? ''),
            is_array($metrics['sources'] ?? null) ? implode('|', $metrics['sources']) : (string)($metrics['sources'] ?? ''),
        ];
        fputcsv($out, $rowOut);
    }
    fclose($out);
    exit;
}

// Remove GET delete handler, replacing with POST below:
// if (isset($_GET['del']) && is_numeric($_GET['del'])) { ... }
// removed

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
    if (!kpi_can_manage()) {
        kpi_flash_em_set('err', 'Akses ditolak. Hanya SYS yang dapat mutasi KPI Employee.');
        rmi_redirect('kpi_employee.php');
    }

    $action = $_POST['_action'] ?? '';

    // New DELETE POST handler
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            kpi_flash_em_set('err', 'ID invalid untuk hapus.');
            rmi_redirect('kpi_employee.php');
        }
        try {
            $stLock = $pdo->prepare("SELECT {$STATUS_COL} FROM {$table} WHERE {$pk}=:id LIMIT 1");
            $stLock->execute([':id'=>$id]);
            if (strtoupper((string)$stLock->fetchColumn()) === 'LOCKED') {
                kpi_flash_em_set('err', 'Data LOCKED tidak dapat dihapus.');
                rmi_redirect('kpi_employee.php');
            }
            if ($DEL_COL) {
                $pdo->prepare("UPDATE {$table} SET {$DEL_COL}=NOW() WHERE {$pk}=:id")->execute([':id'=>$id]);
            } else {
                $pdo->prepare("DELETE FROM {$table} WHERE {$pk}=:id")->execute([':id'=>$id]);
            }
            kpi_audit($pdo, 'kpi_employee', 'delete', (string)$id, '');
            kpi_flash_em_set('ok', 'Terhapus.');
        } catch (Throwable $e) {
            kpi_flash_em_set('err', 'Gagal hapus: '.$e->getMessage());
        }
        rmi_redirect('kpi_employee.php');
    }

    // Import CSV
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv'])) {
        kpi_flash_em_set('err', 'File CSV wajib diupload.');
        rmi_redirect('kpi_employee.php');
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
        kpi_flash_em_set('err', 'Upload CSV gagal.');
        rmi_redirect('kpi_employee.php');
        }
        if ($origName === '') {
        kpi_flash_em_set('err', 'Nama file CSV tidak valid.');
        rmi_redirect('kpi_employee.php');
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
        kpi_flash_em_set('err', 'Format file harus .csv');
        rmi_redirect('kpi_employee.php');
        }
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
        kpi_flash_em_set('err', 'Ukuran CSV terlalu besar (maks 5MB).');
        rmi_redirect('kpi_employee.php');
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
                kpi_audit($pdo, 'kpi_employee', 'import_csv_start', '', 'filename=' . $safeName . ';size=' . $size . ';mime=' . $mime);
        }

        $imported = 0; $skipped = 0;

        $fh = fopen($tmp, 'r');
if (!$fh) {
            kpi_flash_em_set('err', 'Tidak bisa membaca file CSV.');
            rmi_redirect('kpi_employee.php');
        }
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); kpi_flash_em_set('err','CSV tanpa header'); rmi_redirect('kpi_employee.php'); }
        $header = array_map(fn($x)=>strtolower(trim((string)$x)), $header);

        while (($row = fgetcsv($fh)) !== false) {
            $assoc = [];
            foreach ($header as $i=>$k) { $assoc[$k] = $row[$i] ?? ''; }

            $month = trim((string)($assoc['month_ym'] ?? $assoc['month'] ?? ''));
            $deptIn = trim((string)($assoc['dept_code'] ?? $assoc['dept'] ?? $assoc['department'] ?? ''));
            $empIn  = trim((string)($assoc['employee_id'] ?? $assoc['emp'] ?? $assoc['username'] ?? ''));
            if ($month === '' || $deptIn === '' || $empIn === '') { $skipped++; continue; }

            if ($enforceDept) $deptIn = $sessionDeptCode;

            $deptDb = $deptInputToDb($deptIn);
            if ($deptDb === null) { $skipped++; continue; }
            $empDb = $empInputToDb($empIn);
            if ($empDb === null) { $skipped++; continue; }

            $metrics = [
                'tasks_done' => (int)($assoc['tasks_done'] ?? 0),
                'productivity' => (float)($assoc['productivity'] ?? 0),
                'quality' => (float)($assoc['quality'] ?? 0),
                'on_time_rate' => (float)($assoc['on_time_rate'] ?? 0),
                'errors' => (int)($assoc['errors'] ?? 0),
                'dept_code' => kpi_up($deptIn),
                'employee_label' => $empIn,
                'source' => (string)($assoc['source'] ?? 'import'),
                'updated_at' => date('c'),
            ];
            $payload = json_encode($metrics, JSON_UNESCAPED_UNICODE);
            $note = trim((string)($assoc['note'] ?? ''));

            // Upsert (skip LOCKED)
            $existing = null;
            try {
                $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$DEPT_COL}=:d AND {$EMP_COL}=:e{$w} LIMIT 1");
                $st->execute([':m'=>$month,':d'=>$deptDb,':e'=>$empDb]);
                $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}

            if ($existing && (string)($existing[$STATUS_COL] ?? '') === 'LOCKED') { $skipped++; continue; }

            if ($existing) {
                $id = (int)($existing[$pk] ?? 0);
                $sets = ["{$JSON_COL} = :j"];
                $params = [':j'=>$payload, ':id'=>$id];
                if ($NOTE_COL) { $sets[] = "{$NOTE_COL} = :n"; $params[':n']=$note; }
                if ($UPDATED_AT) $sets[] = "{$UPDATED_AT} = NOW()";
                $pdo->prepare("UPDATE {$table} SET ".implode(', ', $sets)." WHERE {$pk}=:id")->execute($params);
            } else {
                $colsIns = [$MONTH_COL,$DEPT_COL,$EMP_COL,$STATUS_COL,$JSON_COL];
                $vals = [':m',':d',':e','\'DRAFT\'',':j'];
                $params = [':m'=>$month,':d'=>$deptDb,':e'=>$empDb,':j'=>$payload];
                if ($NOTE_COL) { $colsIns[] = $NOTE_COL; $vals[]=':n'; $params[':n'] = $note; }
                if ($CREATED_AT) { $colsIns[] = $CREATED_AT; $vals[]='NOW()'; }
                if ($UPDATED_AT) { $colsIns[] = $UPDATED_AT; $vals[]='NOW()'; }
                $pdo->prepare("INSERT INTO {$table} (".implode(',', $colsIns).") VALUES (".implode(',', $vals).")")->execute($params);
            }
            $imported++;
        }
        fclose($fh);

        kpi_audit($pdo, 'kpi_employee', 'import_csv', $month ?? '', "imported={$imported};skipped={$skipped}");
        kpi_flash_em_set('ok', "Import selesai. Imported={$imported}, Skipped={$skipped}");
        rmi_redirect('kpi_employee.php');
    }

    // Auto-generate from audits (bulk)
    if ($action === 'sync_audit') {
        if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_employee', 'sync_audit_start', '', 'month='.$_POST['month_sync'].';dept='.$_POST['dept_sync']); }
        $month = trim((string)($_POST['month_sync'] ?? ''));
        if ($month === '') $month = date('Y-m');

        $deptFilter = trim((string)($_POST['dept_sync'] ?? ''));
        if ($enforceDept) $deptFilter = $sessionDeptCode;

        $rows = kpi_calc_employee_from_audits($pdo, $month, $deptFilter !== '' ? $deptFilter : null);

        $synced = 0;
        $skipped = 0;
        $unmappedDept = [];
        $unmappedEmp = [];

        foreach ($rows as $r) {
            $deptCode = (string)($r['dept_code'] ?? '');
            $empRaw = (string)($r['employee_id'] ?? '');
            $deptCode = kpi_up($deptCode);
            $empRaw = trim($empRaw);

            if ($deptCode==='' || $empRaw==='') { $skipped++; continue; }
            if ($enforceDept && kpi_up($deptCode) !== kpi_up($sessionDeptCode)) continue;

            $deptDb = $deptInputToDb($deptCode);
            if ($deptDb === null) { $skipped++; $unmappedDept[$deptCode]=true; continue; }

            $empDb = $empInputToDb($empRaw);
            if ($empDb === null) { $skipped++; $unmappedEmp[$empRaw]=true; continue; }

            // Upsert (skip LOCKED). Actual audit di-merge agar adjustment/manual lama tidak hilang.
            // Jika actor sebelumnya tersimpan pada departemen yang salah, pindahkan baris audit-sync
            // yang belum LOCKED ke departemen master, bukan membuat duplikasi baru.
            $existing = null;
            try {
                $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL}, {$DEPT_COL}, {$JSON_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$DEPT_COL}=:d AND {$EMP_COL}=:e{$w} LIMIT 1");
                $st->execute([':m'=>$month,':d'=>$deptDb,':e'=>$empDb]);
                $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;

                if (!$existing) {
                    $stAny = $pdo->prepare("SELECT {$pk}, {$STATUS_COL}, {$DEPT_COL}, {$JSON_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$EMP_COL}=:e{$w} ORDER BY {$pk} DESC");
                    $stAny->execute([':m'=>$month,':e'=>$empDb]);
                    foreach ($stAny->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                        if (strtoupper((string)($candidate[$STATUS_COL] ?? '')) === 'LOCKED') continue;
                        $candidateMetrics = json_decode((string)($candidate[$JSON_COL] ?? ''), true);
                        if (!is_array($candidateMetrics)) $candidateMetrics = [];
                        $candidateSource = strtolower((string)($candidateMetrics['source'] ?? ''));
                        $candidateSources = array_map('strtolower', (array)($candidateMetrics['sources'] ?? []));
                        $isAuditGenerated = $candidateSource === 'audit_sync'
                            || in_array('sales_do_audit', $candidateSources, true)
                            || in_array('purchases_audit_log', $candidateSources, true)
                            || in_array('fa_audit_log', $candidateSources, true);
                        if (!$isAuditGenerated) continue;

                        $pdo->prepare("UPDATE {$table} SET {$DEPT_COL}=:d" . ($UPDATED_AT ? ", {$UPDATED_AT}=NOW()" : "") . " WHERE {$pk}=:id")
                            ->execute([':d'=>$deptDb, ':id'=>(int)$candidate[$pk]]);
                        $candidate[$DEPT_COL] = $deptDb;
                        $existing = $candidate;
                        if (function_exists('kpi_audit')) {
                            kpi_audit($pdo, 'kpi_employee', 'repair_actor_dept', (string)$candidate[$pk], 'month='.$month.';employee='.$empRaw.';dept='.$deptCode);
                        }
                        break;
                    }
                }
            } catch (Throwable $e) {}

            if ($existing && strtoupper((string)($existing[$STATUS_COL] ?? '')) === 'LOCKED') {
                continue;
            }

            $existingMetrics = [];
            if ($existing) {
                try {
                    $stJson = $pdo->prepare("SELECT {$JSON_COL} FROM {$table} WHERE {$pk}=:id LIMIT 1");
                    $stJson->execute([':id'=>(int)($existing[$pk] ?? 0)]);
                    $decoded = json_decode((string)$stJson->fetchColumn(), true);
                    if (is_array($decoded)) $existingMetrics = $decoded;
                } catch (Throwable $e) {}
            }
            $actual = is_array($existingMetrics['actual'] ?? null) ? $existingMetrics['actual'] : [];
            foreach (['tasks_done','productivity','quality','on_time_rate','errors'] as $metricKey) {
                if (array_key_exists($metricKey, $r) && $r[$metricKey] !== '' && $r[$metricKey] !== null) {
                    $actual[$metricKey] = is_numeric($r[$metricKey]) ? (float)$r[$metricKey] : null;
                }
            }
            $actual['tasks_done'] = (float)($r['tasks_done'] ?? ($actual['tasks_done'] ?? 0));
            $metrics = array_merge($existingMetrics, [
                'actual' => $actual,
                'sources' => $r['sources'] ?? ($existingMetrics['sources'] ?? []),
                'dept_code' => $deptCode,
                'employee_label' => $empRaw,
                'calc_at' => date('c'),
                'source' => 'audit_sync',
            ]);
            $metrics = kpi_emp_rebuild_metrics($metrics);
            $payload = json_encode($metrics, JSON_UNESCAPED_UNICODE);

            if ($existing) {
                $id = (int)($existing[$pk] ?? 0);
                $sets = ["{$JSON_COL} = :j"]; // keep status and adjustment
                $params = [':j'=>$payload, ':id'=>$id];
                if ($UPDATED_AT) $sets[] = "{$UPDATED_AT} = NOW()";
                $pdo->prepare("UPDATE {$table} SET ".implode(', ', $sets)." WHERE {$pk}=:id")->execute($params);
            } else {
                $colsIns = [$MONTH_COL,$DEPT_COL,$EMP_COL,$STATUS_COL,$JSON_COL];
                $vals = [':m',':d',':e','\'DRAFT\'',':j'];
                $params = [':m'=>$month,':d'=>$deptDb,':e'=>$empDb,':j'=>$payload];
                if ($CREATED_AT) { $colsIns[] = $CREATED_AT; $vals[]='NOW()'; }
                if ($UPDATED_AT) { $colsIns[] = $UPDATED_AT; $vals[]='NOW()'; }
                $pdo->prepare("INSERT INTO {$table} (".implode(',', $colsIns).") VALUES (".implode(',', $vals).")")->execute($params);
            }
            $synced++;
        }

        $ud = array_keys($unmappedDept);
        $ue = array_keys($unmappedEmp);
        $detail = "synced={$synced};skipped={$skipped};dept={$deptFilter}";
        if ($ud) $detail .= ";unmapped_dept=" . implode('|', array_slice($ud,0,8));
        if ($ue) $detail .= ";unmapped_emp=" . implode('|', array_slice($ue,0,8));

        kpi_audit($pdo, 'kpi_employee', 'sync_audit', $month, $detail);

        $msg = "Sync audit selesai. Month={$month} • synced={$synced}";
        if ($synced === 0) $msg .= " • tidak ada actor audit yang dapat dipetakan; cek sales_do_audit.actor_dept/actor_name dan master employee";
        if ($skipped>0) $msg .= " • skipped={$skipped}";
        if ($EMP_IS_NUM && $ue) $msg .= " • unmapped employee=".count($ue)." (cek master_employees)";
        if ($DEPT_IS_NUM && $ud) $msg .= " • unmapped dept=".count($ud)." (cek master_departements)";
        kpi_flash_em_set('ok', $msg);

        rmi_redirect('kpi_employee.php?month='.urlencode($month));
    }

    // Save single
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $month = trim((string)($_POST['month_ym'] ?? ''));
        $deptIn = trim((string)($_POST['dept_code'] ?? ''));
        $empIn  = trim((string)($_POST['employee_id'] ?? ''));
        $status = strtoupper(trim((string)($_POST['status'] ?? 'DRAFT')));
        // LOCKED hanya boleh dibuat oleh proses Snapshot, bukan dari form KPI Employee.
        if (!in_array($status, ['DRAFT','FINAL'], true)) $status='DRAFT';
        $checkerUsername = trim((string)($_POST['checker_username'] ?? ''));

        if ($enforceDept) $deptIn = $sessionDeptCode;

        if ($month==='' || $deptIn==='' || $empIn==='') {
            kpi_flash_em_set('err', 'Bulan, Dept, Employee wajib.');
            rmi_redirect('kpi_employee.php');
        }
        $note = trim((string)($_POST['note'] ?? ''));
        if ($status === 'FINAL') {
            $actor = kpi_emp_current_actor();
            if ($checkerUsername === '') {
                kpi_flash_em_set('err', 'Checker username wajib saat status FINAL.');
                rmi_redirect('kpi_employee.php');
            }
            if ($note === '') {
                kpi_flash_em_set('err', 'Alasan/catatan wajib saat status FINAL.');
                rmi_redirect('kpi_employee.php');
            }
            if ($actor !== '' && strcasecmp($actor, $checkerUsername) === 0) {
                kpi_flash_em_set('err', 'Maker dan checker tidak boleh user yang sama.');
                rmi_redirect('kpi_employee.php');
            }
        }

        if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_employee', 'save_attempt', '', 'month='.$month.';dept='.$deptIn.';emp='.$empIn); }

        $deptDb = $deptInputToDb($deptIn);
        if ($deptDb === null) {
            kpi_flash_em_set('err', "Dept tidak dikenali: {$deptIn} (cek master_departements).");
            rmi_redirect('kpi_employee.php');
        }
        $empDb = $empInputToDb($empIn);
        if ($empDb === null) {
            $hint = $EMP_IS_NUM ? ' (kolom employee di KPI adalah INT, pastikan master_employees terisi & nama/kode match)' : '';
            kpi_flash_em_set('err', "Employee tidak dikenali: {$empIn}{$hint}");
            rmi_redirect('kpi_employee.php');
        }

        // prevent update LOCKED
        if ($id > 0) {
            try {
                $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                $st = $pdo->prepare("SELECT {$STATUS_COL} FROM {$table} WHERE {$pk}=:id{$w} LIMIT 1");
                $st->execute([':id'=>$id]);
                if ((string)($st->fetchColumn() ?: '') === 'LOCKED') {
                    kpi_flash_em_set('err', 'Data LOCKED (read-only).');
                    rmi_redirect('kpi_employee.php');
                }
            } catch (Throwable $e) {}
        }

        // Ambil metrics lama agar actual ERP tidak tertimpa oleh adjustment manual.
        $baseMetrics = [];
        if ($id > 0) {
            try {
                $stOld = $pdo->prepare("SELECT {$JSON_COL} FROM {$table} WHERE {$pk}=:id LIMIT 1");
                $stOld->execute([':id'=>$id]);
                $decodedOld = json_decode((string)$stOld->fetchColumn(), true);
                if (is_array($decodedOld)) $baseMetrics = $decodedOld;
            } catch (Throwable $e) {}
        }
        $adjustment = [
            'tasks_done' => (float)($_POST['m_tasks_done'] ?? 0),
            'productivity' => (float)($_POST['m_productivity'] ?? 0),
            'quality' => (float)($_POST['m_quality'] ?? 0),
            'on_time_rate' => (float)($_POST['m_on_time_rate'] ?? 0),
            'errors' => (float)($_POST['m_errors'] ?? 0),
        ];
        $metrics = array_merge($baseMetrics, [
            'adjustment' => $adjustment,
            'dept_code' => kpi_up($deptIn),
            'employee_label' => $EMP_IS_NUM ? $empDbToLabel($empDb) : $empIn,
            'source' => 'manual_adjustment',
            'checker_username' => $checkerUsername,
            'updated_at' => date('c'),
        ]);
        $metrics = kpi_emp_rebuild_metrics($metrics);
        if ($status === 'FINAL' && empty($metrics['is_valid'])) {
            kpi_flash_em_set('err', 'Status FINAL belum dapat disimpan karena coverage actual kurang dari 60%. Jalankan Sync Audit atau lengkapi sumber actual terlebih dahulu.');
            rmi_redirect('kpi_employee.php?month='.urlencode($month));
        }
        $payload = json_encode($metrics, JSON_UNESCAPED_UNICODE);

        // upsert by (month,dept,emp)
        $existing = null;
        try {
            $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
            $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$DEPT_COL}=:d AND {$EMP_COL}=:e{$w} LIMIT 1");
            $st->execute([':m'=>$month,':d'=>$deptDb,':e'=>$empDb]);
            $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}

        if ($existing && strtoupper((string)($existing[$STATUS_COL] ?? '')) === 'LOCKED') {
            kpi_flash_em_set('err', 'Data sudah LOCKED.');
            rmi_redirect('kpi_employee.php');
        }

        // Bila form membuat adjustment untuk kombinasi bulan/dept/employee yang sudah ada,
        // muat actual ERP dari baris tersebut agar tidak tertimpa.
        if ($id <= 0 && $existing) {
            try {
                $stOld = $pdo->prepare("SELECT {$JSON_COL} FROM {$table} WHERE {$pk}=:id LIMIT 1");
                $stOld->execute([':id'=>(int)($existing[$pk] ?? 0)]);
                $decodedOld = json_decode((string)$stOld->fetchColumn(), true);
                if (is_array($decodedOld)) {
                    $metrics = array_merge($decodedOld, [
                        'adjustment' => $adjustment,
                        'dept_code' => kpi_up($deptIn),
                        'employee_label' => $EMP_IS_NUM ? $empDbToLabel($empDb) : $empIn,
                        'source' => 'manual_adjustment',
                        'checker_username' => $checkerUsername,
                        'updated_at' => date('c'),
                    ]);
                    $metrics = kpi_emp_rebuild_metrics($metrics);
                    $payload = json_encode($metrics, JSON_UNESCAPED_UNICODE);
                }
            } catch (Throwable $e) {}
        }

        if ($id > 0) {
            $sets = [
                "{$MONTH_COL} = :m",
                "{$DEPT_COL} = :d",
                "{$EMP_COL} = :e",
                "{$STATUS_COL} = :s",
                "{$JSON_COL} = :j",
            ];
            $params = [':m'=>$month,':d'=>$deptDb,':e'=>$empDb,':s'=>$status,':j'=>$payload,':id'=>$id];
            if ($NOTE_COL) { $sets[] = "{$NOTE_COL} = :n"; $params[':n']=$note; }
            if ($UPDATED_AT) $sets[] = "{$UPDATED_AT} = NOW()";
            $pdo->prepare("UPDATE {$table} SET ".implode(', ', $sets)." WHERE {$pk}=:id")->execute($params);
            kpi_audit($pdo, 'kpi_employee', 'update', (string)$id, "month={$month};dept={$deptIn};emp={$empIn}");
            if (function_exists('master_audit')) {
                master_audit($pdo, 'kpi_employee', 'kpi_employee_snapshot', 'UPDATE', (int)$id, "{$month}-{$deptIn}-{$empIn}", "KPI employee update: {$month} {$deptIn} {$empIn}", []);
            }
        } elseif ($existing) {
            $eid = (int)($existing[$pk] ?? 0);
            $sets = [
                "{$STATUS_COL} = :s",
                "{$JSON_COL} = :j",
            ];
            $params = [':s'=>$status,':j'=>$payload,':id'=>$eid];
            if ($NOTE_COL) { $sets[] = "{$NOTE_COL} = :n"; $params[':n']=$note; }
            if ($UPDATED_AT) $sets[] = "{$UPDATED_AT} = NOW()";
            $pdo->prepare("UPDATE {$table} SET ".implode(', ', $sets)." WHERE {$pk}=:id")->execute($params);
            kpi_audit($pdo, 'kpi_employee', 'update', (string)$eid, "month={$month};dept={$deptIn};emp={$empIn}");
            if (function_exists('master_audit')) {
                master_audit($pdo, 'kpi_employee', 'kpi_employee_snapshot', 'UPDATE', $eid, "{$month}-{$deptIn}-{$empIn}", "KPI employee update: {$month} {$deptIn} {$empIn}", []);
            }
        } else {
            $colsIns = [$MONTH_COL,$DEPT_COL,$EMP_COL,$STATUS_COL,$JSON_COL];
            $vals = [':m',':d',':e',':s',':j'];
            $params = [':m'=>$month,':d'=>$deptDb,':e'=>$empDb,':s'=>$status,':j'=>$payload];
            if ($NOTE_COL) { $colsIns[] = $NOTE_COL; $vals[]=':n'; $params[':n']=$note; }
            if ($CREATED_AT) { $colsIns[] = $CREATED_AT; $vals[]='NOW()'; }
            if ($UPDATED_AT) { $colsIns[] = $UPDATED_AT; $vals[]='NOW()'; }
            $pdo->prepare("INSERT INTO {$table} (".implode(',', $colsIns).") VALUES (".implode(',', $vals).")")->execute($params);
            $newId = (string)$pdo->lastInsertId();
            kpi_audit($pdo, 'kpi_employee', 'create', $newId, "month={$month};dept={$deptIn};emp={$empIn}");
            if (function_exists('master_audit')) {
                master_audit($pdo, 'kpi_employee', 'kpi_employee_snapshot', 'CREATE', (int)$newId, "{$month}-{$deptIn}-{$empIn}", "KPI employee create: {$month} {$deptIn} {$empIn}", []);
            }
        }

        kpi_flash_em_set('ok', 'Tersimpan.');
        rmi_redirect('kpi_employee.php');
    }

    rmi_redirect('kpi_employee.php');
}

// --- Render UI ---
kpi_header('KPI Employee');
kpi_nav('employee');

$flash = kpi_flash_em_get();
if (!empty($flash['err'])) echo "<div class='card kpi-flash-err'><b>Error:</b> ".h($flash['err'])."</div>";
if (!empty($flash['ok']))  echo "<div class='card kpi-flash-ok'><b>OK:</b> ".h($flash['ok'])."</div>";

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

// Prefill from audit preview
$autoMonth = trim((string)($_GET['auto_month'] ?? ''));
$autoDept  = trim((string)($_GET['auto_dept'] ?? ''));
$autoEmp   = trim((string)($_GET['auto_emp'] ?? ''));
$autoMetrics = null;
if (kpi_can_manage() && isset($_GET['auto']) && $_GET['auto']==='1' && $autoMonth!=='' && $autoDept!=='' && $autoEmp!=='') {
    $aud = kpi_calc_employee_from_audits($pdo, $autoMonth, $autoDept);
    foreach ($aud as $r) {
        if (kpi_up((string)$r['dept_code'])===kpi_up($autoDept) && (string)$r['employee_id']===$autoEmp) {
            $autoMetrics = [
                'tasks_done' => (int)($r['tasks_done'] ?? 0),
                'source' => 'auto_preview',
                'sources' => $r['sources'] ?? [],
            ];
            break;
        }
    }
}

// filters
$fMonth = trim((string)($_GET['month'] ?? date('Y-m')));
$fDept  = trim((string)($_GET['dept'] ?? ''));
$fEmp   = trim((string)($_GET['emp'] ?? ''));
if ($enforceDept) $fDept = $sessionDeptCode;

$where = [];
$params = [];
$filterWarn = '';
if ($DEL_COL) $where[] = "{$DEL_COL} IS NULL";
if ($fMonth !== '') { $where[] = "{$MONTH_COL} = :m"; $params[':m'] = $fMonth; }
if ($fDept !== '')  {
    $dDb = $deptInputToDb($fDept);
    if ($dDb === null) { $where[] = "1=0"; $filterWarn = "Dept filter tidak ditemukan di master_departements: {$fDept}"; }
    else { $where[] = "{$DEPT_COL} = :d"; $params[':d'] = $dDb; }
}
if ($fEmp !== '')   {
    $eDb = $empInputToDb($fEmp);
    if ($eDb === null) { $where[] = "1=0"; $filterWarn = "Employee filter tidak ditemukan: {$fEmp}"; }
    else { $where[] = "{$EMP_COL} = :e"; $params[':e'] = $eDb; }
}

$sql = "SELECT * FROM {$table}" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY {$MONTH_COL} DESC, {$DEPT_COL} ASC, {$EMP_COL} ASC, {$pk} DESC LIMIT 800";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$defaultMonth = $fMonth ?: date('Y-m');

echo "<div class='card'>
  <div class='kpi-header-row'>
    <div>
      <h2>KPI Employee</h2>
      <div class='kpi-subtitle'>Phase 2 (Employee KPI) • Actual otomatis dari audit ERP; input manual hanya adjustment resmi. FINAL memakai maker-checker, LOCKED hanya melalui Snapshot.</div>
      <div class='muted'>Schema mode: Dept=".($DEPT_IS_NUM ? "INT/FK" : "CODE")." • Employee=".($EMP_IS_NUM ? "INT/FK" : "TEXT")."</div>
    </div>
    <div>
      <a class='btn secondary' href='kpi_employee.php?export=csv&month=".urlencode($fMonth)."&dept=".urlencode($fDept)."&emp=".urlencode($fEmp)."'>Export CSV</a>
      <a class='btn secondary' href='kpi_employee.php'>Reset</a>
    </div>
  </div>
</div>";

if ($filterWarn !== '') {
    echo "<div class='card kpi-flash-err'><b>Warning:</b> ".h($filterWarn)."</div>";
}

if (kpi_can_manage()) {
    echo "<div class='card'>
      <h3 class='kpi-section-title'>Sync Audit Metrics (Bulk)</h3>
      <form method='post' class='kpi-form-row'>
        " . (function_exists('csrf_field') ? csrf_field() : '') . "
        <input type='hidden' name='_action' value='sync_audit'>
        <div class='kpi-field'><label>Bulan</label><input type='month' name='month_sync' value='".h($defaultMonth)."'></div>
        <div class='kpi-field'><label>Dept (optional)</label><input name='dept_sync' value='".h($enforceDept?$sessionDeptCode:'')."' placeholder='WQS/SCM/ACT/FIN/PQP...'></div>
        <div class='kpi-field'><button class='btn ok' type='submit'>Sync</button></div>
      </form>
      <div class='muted'>Sync membaca actor audit ERP per bulan, mengisi actual tasks, errors, quality, dan sumber data. Productivity/on-time tetap N/A bila sumber belum tersedia. Baris LOCKED tidak diubah.</div>
      ".($EMP_IS_NUM ? "<div class='muted'>Catatan: karena kolom employee di KPI adalah INT, actor audit harus match ke <code>master_employees</code> (kode atau nama).</div>" : "")."
    </div>";

    echo "<div class='card'>
      <h3 class='kpi-section-title'>Import CSV</h3>
      <form method='post' enctype='multipart/form-data' class='kpi-form-row'>
        " . (function_exists('csrf_field') ? csrf_field() : '') . "
        <input type='hidden' name='_action' value='import_csv'>
        <div class='col'><label>File CSV</label><input type='file' name='csv' accept='.csv,text/csv' required></div>
        <div class='kpi-field'><button class='btn secondary' type='submit'>Import</button></div>
      </form>
      <div class='muted'>Template: <a href='templates/kpi_employee_template.csv'>kpi/templates/kpi_employee_template.csv</a></div>
    </div>";
}

// Form values (convert stored DB to code for display)
$valMonth = $editRow[$MONTH_COL] ?? ($autoMonth ?: $defaultMonth);

$valDeptDb = $editRow[$DEPT_COL] ?? '';
$valDept  = $editRow ? $deptDbToCode($valDeptDb) : ($autoDept ?: ($enforceDept?$sessionDeptCode:''));

$valEmpDb = $editRow[$EMP_COL] ?? '';
$valEmp   = $editRow ? ($EMP_IS_NUM ? $empDbToCode($valEmpDb) : (string)$valEmpDb) : ($autoEmp ?: '');

$valStatus= $editRow[$STATUS_COL] ?? 'DRAFT';
$valNote  = $NOTE_COL ? (string)($editRow[$NOTE_COL] ?? '') : '';

$valMetrics = ['tasks_done'=>null,'productivity'=>null,'quality'=>null,'on_time_rate'=>null,'errors'=>null,'source'=>'manual_adjustment','adjustment'=>[],'actual'=>[]];
if (!empty($editRow[$JSON_COL])) {
    $tmp = json_decode((string)$editRow[$JSON_COL], true);
    if (is_array($tmp)) $valMetrics = array_merge($valMetrics, $tmp);
}
if ($autoMetrics) {
    $valMetrics = array_merge($valMetrics, $autoMetrics);
}

$locked = $editRow && (string)($editRow[$STATUS_COL] ?? '') === 'LOCKED';
$lockedNotice = '';
if ($locked) {
    $lockedNotice = "<span class='badge danger'>LOCKED</span> <span class='muted'>Data terkunci (read-only).</span>";
}

// Employee options (if master_employees exists)
$empOptions = "<option value=''>-- pilih --</option>";
foreach ($employees as $e) {
    $code = (string)($e['employee_id'] ?? '');
    $name = (string)($e['employee_name'] ?? $code);
    $dept = (string)($e['dept_code'] ?? '');
    if ($code === '') continue;
    if ($valDept !== '' && $dept !== '' && kpi_up($dept) !== kpi_up($valDept)) continue;
    $sel = ($code === $valEmp) ? 'selected' : '';
    $empOptions .= "<option {$sel} value='".h($code)."'>".h($code.' • '.$name)."</option>";
}

$autoLink = '';
if (kpi_can_manage() && $valMonth && $valDept && $valEmp) {
    $autoLink = "<a class='btn secondary' href='kpi_employee.php?auto=1&auto_month=".urlencode($valMonth)."&auto_dept=".urlencode($valDept)."&auto_emp=".urlencode($valEmp)."'>Auto-Fill dari Audit</a>";
}

if (kpi_can_manage()) {
    echo "<div class='card'>
  <div class='kpi-header-row'>
    <h3 class='kpi-section-title'>".($editId?"Edit Adjustment KPI Employee #".h((string)$editId):"Input Adjustment KPI Employee")."</h3>
    <div>{$lockedNotice}</div>
  </div>
  <form method='post'>
    " . (function_exists('csrf_field') ? csrf_field() : '') . "
    <input type='hidden' name='_action' value='save'>
    <input type='hidden' name='id' value='".h((string)($editId ?: 0))."'>

    <div class='kpi-form-row'>
      <div class='kpi-field'><label>Bulan</label><input type='month' name='month_ym' value='".h($valMonth)."' required></div>
      <div class='kpi-field'><label>Dept</label><input name='dept_code' value='".h($valDept)."' placeholder='WQS/SCM/ACT/FIN/PQP' required ".($enforceDept?'readonly':'')."></div>
      <div class='kpi-field'><label>Employee</label>".
        ($employees ? "<select name='employee_id' required>{$empOptions}</select>" : "<input name='employee_id' value='".h($valEmp)."' placeholder='EMP_ID / username' required>")
      ."</div>
      <div class='kpi-field'><label>Status</label><select name='status'>
          <option ".($valStatus==='DRAFT'?'selected':'').">DRAFT</option>
          <option ".($valStatus==='FINAL'?'selected':'').">FINAL</option>
      </select></div>
      <div class='kpi-field'>
        <button class='btn ok' type='submit' ".($locked?'disabled':'').">Simpan</button>
        ".($editId?"<a class='btn secondary' href='kpi_employee.php'>Batal</a>":"")."
        {$autoLink}
      </div>
    </div>

    <div class='kpi-form-grid'>
      <div class='kpi-field'><label>Adjustment Tasks Done (+/-)</label><input type='number' name='m_tasks_done' value='".h((string)($valMetrics['adjustment']['tasks_done'] ?? 0))."'></div>
      <div class='kpi-field'><label>Adjustment Productivity (+/-)</label><input type='number' step='0.01' name='m_productivity' value='".h((string)($valMetrics['adjustment']['productivity'] ?? 0))."'></div>
      <div class='kpi-field'><label>Adjustment Quality (+/-)</label><input type='number' step='0.01' name='m_quality' value='".h((string)($valMetrics['adjustment']['quality'] ?? 0))."'></div>
      <div class='kpi-field'><label>Adjustment On-time Rate (+/-)</label><input type='number' step='0.01' name='m_on_time_rate' value='".h((string)($valMetrics['adjustment']['on_time_rate'] ?? 0))."'></div>
      <div class='kpi-field'><label>Adjustment Errors (+/-)</label><input type='number' name='m_errors' value='".h((string)($valMetrics['adjustment']['errors'] ?? 0))."'></div>
      <div class='kpi-field'><label>Source</label><input value='manual_adjustment' readonly></div>
      <div class='kpi-field'><label>Checker Username (wajib saat FINAL)</label><input name='checker_username' value='".h((string)($valMetrics['checker_username'] ?? ''))."'></div>
    </div>
    <div class='muted' style='margin-top:10px'>Actual ERP tidak diubah dari form ini. Isi hanya selisih koreksi (+/-). Status LOCKED hanya melalui Snapshot.</div>

    <div class='kpi-field kpi-field-full' style='margin-top:12px'>
      <label>Alasan / Catatan Adjustment</label>
      <textarea name='note' rows='2'>".h($valNote)."</textarea>
    </div>
  </form>
</div>";
} elseif ($editId > 0) {
    if ($editRow) {
        $jsonPretty = h(json_encode($valMetrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "<div class='card'>
  <div class='kpi-header-row'>
    <h3 class='kpi-section-title'>Detail KPI Employee #".h((string)$editId)." <span class='muted'>(baca saja)</span></h3>
    <div>{$lockedNotice}</div>
  </div>
  <p class='muted'>Bulan: <b>".h($valMonth)."</b> • Dept: <b>".h($valDept)."</b> • Employee: <b>".h($valEmp)."</b> • Status: <b>".h($valStatus)."</b></p>
  ".($valNote !== '' ? "<p class='muted'>Catatan: ".h($valNote)."</p>" : "")."
  <pre class='muted' style='white-space:pre-wrap;font-size:12px;max-height:420px;overflow:auto'>{$jsonPretty}</pre>
  <a class='btn secondary' href='kpi_employee.php'>← Kembali ke daftar</a>
</div>";
    } else {
        echo "<div class='card'><span class='badge danger'>Tidak ditemukan</span> <span class='muted'>ID #".h((string)$editId)."</span> — <a href='kpi_employee.php'>Kembali</a></div>";
    }
}

// Filter form
echo "<div class='card'>
  <h3 class='kpi-section-title'>Filter</h3>
  <form method='get' class='kpi-form-row'>
    <div class='kpi-field'><label>Bulan</label><input type='month' name='month' value='".h($fMonth)."'></div>
    <div class='kpi-field'><label>Dept</label><input name='dept' value='".h($fDept)."' ".($enforceDept?'readonly':'')."></div>
    <div class='kpi-field'><label>Employee</label><input name='emp' value='".h($fEmp)."' placeholder='employee_id / name'></div>
    <div class='kpi-field'>
      <button class='btn secondary' type='submit'>Apply</button>
      <a class='btn secondary' href='kpi_employee.php'>Reset</a>
    </div>
  </form>
</div>";

// List
echo "<div class='card'>
  <h3 class='kpi-section-title'>Data KPI Employee</h3>
  <div class='table-wrap'><table>
    <thead><tr>
      <th>ID</th><th>Bulan</th><th>Dept</th><th>Employee</th><th>Status</th>
      <th style='text-align:right'>Tasks</th>
      <th style='text-align:right'>Prod</th>
      <th style='text-align:right'>Qual</th>
      <th style='text-align:right'>On-time</th>
      <th style='text-align:right'>Errors</th><th style='text-align:right'>Coverage</th><th>Source</th>
      <th>Aksi</th>
    </tr></thead><tbody>";

$fmt2 = fn($v) => number_format((float)$v, 2, ',', '.');

foreach ($rows as $r) {
    $id = (int)($r[$pk] ?? 0);
    $m = (string)($r[$MONTH_COL] ?? '');
    $dRaw = $r[$DEPT_COL] ?? '';
    $eRaw = $r[$EMP_COL] ?? '';
    $s = (string)($r[$STATUS_COL] ?? '');

    $dDisp = $deptDbToLabel($dRaw);
    $eDisp = $EMP_IS_NUM ? $empDbToLabel($eRaw) : trim((string)$eRaw);

    $metrics = [];
    if (!empty($r[$JSON_COL])) {
        $tmp = json_decode((string)$r[$JSON_COL], true);
        if (is_array($tmp)) $metrics = $tmp;
    }

    $tasks = array_key_exists('tasks_done',$metrics) && $metrics['tasks_done'] !== null ? (string)(int)$metrics['tasks_done'] : 'N/A';
    $prod  = array_key_exists('productivity',$metrics) && $metrics['productivity'] !== null ? $fmt2($metrics['productivity']) : 'N/A';
    $qual  = array_key_exists('quality',$metrics) && $metrics['quality'] !== null ? $fmt2($metrics['quality']) : 'N/A';
    $ont   = array_key_exists('on_time_rate',$metrics) && $metrics['on_time_rate'] !== null ? $fmt2($metrics['on_time_rate']) : 'N/A';
    $err   = array_key_exists('errors',$metrics) && $metrics['errors'] !== null ? (string)(int)$metrics['errors'] : 'N/A';
    $coverage = number_format((float)($metrics['coverage_pct'] ?? 0), 1, ',', '.') . '%';
    $sourceDisp = implode(', ', array_values(array_unique(array_filter((array)($metrics['sources'] ?? [])))));
    if ($sourceDisp === '') $sourceDisp = (string)($metrics['source'] ?? '-');

    $badgeCls = ($s === 'LOCKED') ? 'pill danger' : 'pill';

    echo "<tr>
      <td>".h((string)$id)."</td>
      <td>".h($m)."</td>
      <td>".h($dDisp)."</td>
      <td>".h($eDisp)."</td>
      <td><span class='".h($badgeCls)."'>".h($s)."</span></td>
      <td style='text-align:right'>".h($tasks)."</td>
      <td style='text-align:right'>".h($prod)."</td>
      <td style='text-align:right'>".h($qual)."</td>
      <td style='text-align:right'>".h($ont)."</td>
      <td style='text-align:right'>".h($err)."</td><td style='text-align:right'>".h($coverage)."</td><td>".h($sourceDisp)."</td>
      <td>";

    echo "<a class='btn tiny' href='kpi_employee.php?edit=".h((string)$id)."'>Edit</a> ";

    if (kpi_can_manage('SYS')) {
        echo "<form method='post' style='display:inline;margin:0;padding:0' onsubmit=\"return confirm('Hapus data ini?');\">
            " . (function_exists('csrf_field') ? csrf_field() : '') . "
            <input type='hidden' name='_action' value='delete'>
            <input type='hidden' name='id' value='".h((string)$id)."'>
            <button class='btn tiny danger' type='submit'>Del</button>
        </form>";
    }

    echo "</td>
    </tr>";
}

echo "</tbody></table></div>
  <div class='muted' style='margin-top:10px'>Total rows: ".h((string)count($rows))." (limit 800)</div>
</div>";

kpi_footer();

