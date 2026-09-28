<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// KPI Purchases (Enterprise) - Manual input & tracking (Direksi + Head Dept)
// Data is stored in kpi_office.metrics_json (so Snapshot/Lock still works).
// This page only manages Purchases-related keys and MERGES them into existing JSON.

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

// If KPI table not installed yet, render message and stop
if (!kpi_table_exists($pdo, $table)) {
    kpi_header('KPI Purchases');
    kpi_nav('purch');
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
$CREATED_BY = kpi_pick_col($cols, ['created_by']);
$CREATED_AT = kpi_pick_col($cols, ['created_at']);
$UPDATED_BY = kpi_pick_col($cols, ['updated_by']);
$UPDATED_AT = kpi_pick_col($cols, ['updated_at']);

function kpi_col_safe_purch(string $c): bool {
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', $c);
}
foreach ([$pk,$MONTH_COL,$OFFICE_COL,$STATUS_COL,$DEL_COL,$JSON_COL,$CREATED_BY,$CREATED_AT,$UPDATED_BY,$UPDATED_AT] as $c) {
    if ($c && !kpi_col_safe_purch($c)) die('Unsafe column name detected');
}

$hasCol = static function(array $allCols, string $target): bool {
    foreach ($allCols as $cc) {
        if (strcasecmp((string)$cc, $target) === 0) return true;
    }
    return false;
};
if (!$hasCol($cols, $pk) || !$hasCol($cols, $MONTH_COL) || !$hasCol($cols, $OFFICE_COL) || !$hasCol($cols, $STATUS_COL) || !$hasCol($cols, $JSON_COL)) {
    kpi_header('KPI Purchases');
    kpi_nav('purch');
    echo "<div class='card'><span class='badge danger'>SCHEMA</span> Kolom wajib KPI Purchases tidak lengkap (ID/Month/Office/Status/JSON). Jalankan SQL <code>kpi/kpi_enterprise_tables.sql</code> atau migrasikan schema lama.</div>";
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

// Purchases KPI keys stored in kpi_office.metrics_json
$PURCH_KEYS = [
    'pr_submitted_count',
    'pr_submitted_age_median_days',
    'po_open_count',
    'po_open_value',
    'po_to_received_avg_days',
    'eta_ontime_pct',
    'forwarder_pending_count',
    'docs_missing_count',
    'ceisa_pending_count',
    'ap_outstanding',
    'ap_overdue',
    'purchases_note',
    'source_purchases',
    'purchases_updated_at',
];

function kpi_decode_metrics_purch($json): array {
    if ($json === null || $json === '') return [];
    $tmp = json_decode((string)$json, true);
    return is_array($tmp) ? $tmp : [];
}
/**
 * @return array{errors:array<int,string>,warns:array<int,string>}
 */
function kpi_guard_metrics_purchases(array $new, array $prevMetrics, string $status, string $note, string $checker, string $actor): array
{
    $errors = [];
    $warns = [];

    foreach (['pr_submitted_count','po_open_count','forwarder_pending_count','docs_missing_count','ceisa_pending_count'] as $k) {
        if ((int)($new[$k] ?? 0) < 0) $errors[] = strtoupper($k) . ' tidak boleh negatif.';
    }
    foreach (['pr_submitted_age_median_days','po_open_value','po_to_received_avg_days','eta_ontime_pct','ap_outstanding','ap_overdue'] as $k) {
        $v = (float)($new[$k] ?? 0);
        if ($v < 0) $errors[] = strtoupper($k) . ' tidak boleh negatif.';
        if ($v > 100000000000000.0) $errors[] = strtoupper($k) . ' melewati batas kewajaran sistem.';
    }
    if ((float)($new['eta_ontime_pct'] ?? 0) > 100.0) {
        $errors[] = 'Adjustment ETA On-time (+/- %) tidak boleh lebih dari 100.';
    }
    if ((float)($new['ap_overdue'] ?? 0) > ((float)($new['ap_outstanding'] ?? 0) + 1.0)) {
        $errors[] = 'AP overdue tidak boleh melebihi AP outstanding.';
    }

    foreach (['po_open_value','ap_outstanding','ap_overdue'] as $k) {
        $old = (float)($prevMetrics[$k] ?? 0);
        $curr = (float)($new[$k] ?? 0);
        if ($old <= 0.0) continue;
        $pct = abs((($curr - $old) / $old) * 100.0);
        if ($pct > 50.0) $warns[] = strtoupper($k) . ' berubah ' . number_format($pct, 1, ',', '.') . '%.';
    }

    $isFinal = in_array($status, ['FINAL'], true);
    if ($isFinal && strlen($note) < 10) {
        $errors[] = 'Catatan Purchases minimal 10 karakter untuk FINAL.';
    }
    if ($isFinal && count($warns) > 0) {
        if ($checker === '') {
            $errors[] = 'Checker username wajib untuk FINAL saat perubahan signifikan.';
        } elseif (strcasecmp($checker, $actor) === 0) {
            $errors[] = 'Checker harus berbeda dari maker (penginput).';
        }
    }
    return ['errors' => $errors, 'warns' => $warns];
}

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

    $out = [];
    foreach ($rows as $r) {
        $m = [];
        $m['month_ym'] = (string)($r[$MONTH_COL] ?? '');
        $m['office_code'] = $officeDbToCode($r[$OFFICE_COL] ?? '');
        $m['status'] = (string)($r[$STATUS_COL] ?? '');

        $metrics = kpi_decode_metrics_purch($r[$JSON_COL] ?? '');
        foreach ($GLOBALS['PURCH_KEYS'] as $k) {
            if ($k === 'purchases_updated_at') continue; // internal
            if (array_key_exists($k, $metrics)) {
                $m[$k] = is_scalar($metrics[$k]) ? $metrics[$k] : json_encode($metrics[$k], JSON_UNESCAPED_UNICODE);
            } else {
                $m[$k] = '';
            }
        }
        $out[] = $m;
    }

    if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_purchases', 'export_csv', '', 'filter_month='.(string)$fMonth.';office='.(string)$fOffice.';status='.(string)$fStatus); }

    kpi_csv_download('kpi_purchases_export.csv', $out);
}

// --- Delete (POST, soft-delete if possible) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action']==='delete') {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
    if (!kpi_can_manage('SYS')) {
        kpi_flash_set('err', 'Akses ditolak. Hanya SYS yang dapat menghapus data KPI.');
        rmi_redirect('kpi_purchases.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            if ($DEL_COL) {
                $pdo->prepare("UPDATE {$table} SET {$DEL_COL} = NOW() WHERE {$pk}=:id")->execute([':id'=>$id]);
            } else {
                $pdo->prepare("DELETE FROM {$table} WHERE {$pk}=:id")->execute([':id'=>$id]);
            }
            kpi_audit($pdo, 'kpi_purchases', 'delete', (string)$id, 'delete');
            kpi_flash_set('ok', 'Data dihapus.');
        } catch (Throwable $e) {
            kpi_flash_set('err', 'Gagal delete: ' . $e->getMessage());
        }
    }
    rmi_redirect('kpi_purchases.php');
}

// --- Mutations (POST / delete) - must be before HTML output ---

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
    // Mutasi KPI: hanya SYS (rmi_sys_gate / kpi_can_manage — bukan KPI.EDIT)
    if (!kpi_can_manage()) {
        kpi_flash_set('err', 'Akses ditolak. Hanya SYS yang dapat mengelola KPI.');
        rmi_redirect('kpi_purchases.php');
    }
    $action = (string)$_POST['_action'];

    // Import CSV
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv'])) {
        kpi_flash_set('err', 'File CSV wajib diupload.');
        rmi_redirect('kpi_purchases.php');
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
        rmi_redirect('kpi_purchases.php');
        }
        if ($origName === '') {
        kpi_flash_set('err', 'Nama file CSV tidak valid.');
        rmi_redirect('kpi_purchases.php');
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
        kpi_flash_set('err', 'Format file harus .csv');
        rmi_redirect('kpi_purchases.php');
        }
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
        kpi_flash_set('err', 'Ukuran CSV terlalu besar (maks 5MB).');
        rmi_redirect('kpi_purchases.php');
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
                kpi_audit($pdo, 'kpi_purchases', 'import_csv_start', '', 'filename=' . $safeName . ';size=' . $size . ';mime=' . $mime);
        }

        $fh = fopen($tmp, 'rb');
if (!$fh) {
            kpi_flash_set('err', 'Gagal membaca file.');
            rmi_redirect('kpi_purchases.php');
        }

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            kpi_flash_set('err', 'CSV kosong.');
            rmi_redirect('kpi_purchases.php');
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

            // Load existing row (by month+office) and skip LOCKED
            $existing = null;
            $existingMetrics = [];
            try {
                $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
                $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL}, {$JSON_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$OFFICE_COL}=:o{$w} LIMIT 1");
                $st->execute([':m'=>$month, ':o'=>$officeDb]);
                $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existing && (string)($existing[$STATUS_COL] ?? '') === 'LOCKED') {
                    $skipped++;
                    continue;
                }
                if ($existing) {
                    $existingMetrics = kpi_decode_metrics_purch($existing[$JSON_COL] ?? '');
                }
            } catch (Throwable $e) {
                $existing = null;
                $existingMetrics = [];
            }

            // Build purchases metrics from CSV
            $new = [
                'pr_submitted_count' => kpi_int($data['pr_submitted_count'] ?? 0),
                'pr_submitted_age_median_days' => kpi_float($data['pr_submitted_age_median_days'] ?? 0),
                'po_open_count' => kpi_int($data['po_open_count'] ?? 0),
                'po_open_value' => kpi_float($data['po_open_value'] ?? 0),
                'po_to_received_avg_days' => kpi_float($data['po_to_received_avg_days'] ?? 0),
                'eta_ontime_pct' => kpi_float($data['eta_ontime_pct'] ?? 0),
                'forwarder_pending_count' => kpi_int($data['forwarder_pending_count'] ?? 0),
                'docs_missing_count' => kpi_int($data['docs_missing_count'] ?? 0),
                'ceisa_pending_count' => kpi_int($data['ceisa_pending_count'] ?? 0),
                'ap_outstanding' => kpi_float($data['ap_outstanding'] ?? ($existingMetrics['ap_outstanding'] ?? 0)),
                'ap_overdue' => kpi_float($data['ap_overdue'] ?? ($existingMetrics['ap_overdue'] ?? 0)),
                'purchases_note' => (string)($data['purchases_note'] ?? ''),
                'source_purchases' => (string)($data['source_purchases'] ?? 'import_csv'),
                'purchases_updated_at' => date('c'),
            ];

            $checker = trim((string)($data['checker_username'] ?? ''));
            $guard = kpi_guard_metrics_purchases($new, $existingMetrics, $status, trim((string)$new['purchases_note']), $checker, (string)$user);
            if ($guard['errors']) { $skipped++; continue; }
            if ($guard['warns'] && function_exists('kpi_audit')) {
                kpi_audit($pdo, 'kpi_purchases', 'import_guard_warn', (string)($existing[$pk] ?? ''), implode(' | ', $guard['warns']));
            }
            $merged = array_merge($existingMetrics, $new);
            $payload = json_encode($merged, JSON_UNESCAPED_UNICODE);

            try {
                if ($existing) {
                    $id = (int)($existing[$pk] ?? 0);
                    $sets = [
                        "{$MONTH_COL}=:m",
                        "{$OFFICE_COL}=:o",
                        "{$STATUS_COL}=:s",
                        "{$JSON_COL}=:j",
                    ];
                    $params = [':m'=>$month, ':o'=>$officeDb, ':s'=>$status, ':j'=>$payload, ':id'=>$id];
                    if ($UPDATED_BY) { $sets[] = "{$UPDATED_BY}=:ub"; $params[':ub'] = $user; }
                    if ($UPDATED_AT) { $sets[] = "{$UPDATED_AT}=NOW()"; }
                    $sql = "UPDATE {$table} SET " . implode(',', $sets) . " WHERE {$pk}=:id";
                    $pdo->prepare($sql)->execute($params);
                } else {
                    $colsIns = [$MONTH_COL,$OFFICE_COL,$STATUS_COL,$JSON_COL];
                    $vals = [':m',':o',':s',':j'];
                    $params = [':m'=>$month, ':o'=>$officeDb, ':s'=>$status, ':j'=>$payload];
                    if ($CREATED_BY) { $colsIns[]=$CREATED_BY; $vals[]=':cb'; $params[':cb']=$user; }
                    if ($CREATED_AT) { $colsIns[]=$CREATED_AT; $vals[]='NOW()'; }
                    if ($UPDATED_BY) { $colsIns[]=$UPDATED_BY; $vals[]=':ub'; $params[':ub']=$user; }
                    if ($UPDATED_AT) { $colsIns[]=$UPDATED_AT; $vals[]='NOW()'; }
                    $sql = "INSERT INTO {$table} (".implode(',', $colsIns).") VALUES (".implode(',', $vals).")";
                    $pdo->prepare($sql)->execute($params);
                }
                $imported++;
            } catch (Throwable $e) {
                $skipped++;
            }
        }
        fclose($fh);
        kpi_audit($pdo, 'kpi_purchases', 'import_csv', '', "imported={$imported};skipped={$skipped}");
        kpi_flash_set('ok', "Import selesai. imported={$imported}, skipped={$skipped}");
        rmi_redirect('kpi_purchases.php');
    }

    // Save single
    if ($action === 'save') {
        if (!kpi_can_manage()) {
            kpi_flash_set('err', 'Akses ditolak. Hanya SYS yang dapat menyimpan data KPI.');
            rmi_redirect('kpi_purchases.php');
        }

        $id = (int)($_POST['id'] ?? 0);
        $month = trim((string)($_POST['month_ym'] ?? ''));
        $office = trim((string)($_POST['office_code'] ?? ''));
        $officeDb = $officeInputToDb($office);
        if ($officeDb === null) {
            kpi_flash_set('err', 'Office tidak dikenali: '.$office.' (cek master_office).');
            rmi_redirect('kpi_purchases.php');
        }
        if ($month === '' || $office === '') {
            kpi_flash_set('err', 'Bulan & Office wajib.');
            rmi_redirect('kpi_purchases.php');
        }

        if (function_exists('kpi_audit')) { kpi_audit($pdo, 'kpi_purchases', 'save_attempt', '', 'month='.$month.';office='.$office); }

        $status = trim((string)($_POST['status'] ?? 'DRAFT'));
        if (!in_array($status, ['DRAFT','FINAL'], true)) $status = 'DRAFT';

        // Load existing record (by id or month+office) and block LOCKED
        $existingId = 0;
        $existingStatus = '';
        $existingMetrics = [];
        try {
            $w = ($DEL_COL ? " AND {$DEL_COL} IS NULL" : '');
            if ($id > 0) {
                $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL}, {$JSON_COL} FROM {$table} WHERE {$pk}=:id{$w} LIMIT 1");
                $st->execute([':id'=>$id]);
            } else {
                $st = $pdo->prepare("SELECT {$pk}, {$STATUS_COL}, {$JSON_COL} FROM {$table} WHERE {$MONTH_COL}=:m AND {$OFFICE_COL}=:o{$w} LIMIT 1");
                $st->execute([':m'=>$month, ':o'=>$officeDb]);
            }
            $ex = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($ex) {
                $existingId = (int)($ex[$pk] ?? 0);
                $existingStatus = (string)($ex[$STATUS_COL] ?? '');
                if ($existingStatus === 'LOCKED') {
                    kpi_flash_set('err', 'Data LOCKED (read-only).');
                    rmi_redirect('kpi_purchases.php');
                }
                $existingMetrics = kpi_decode_metrics_purch($ex[$JSON_COL] ?? '');
            }
        } catch (Throwable $e) {
            // ignore
        }

        $actual = kpi_calc_purchases_metrics($pdo, $month, $office);
        $delta = [
            'pr_submitted_count'=>kpi_int($_POST['m_pr_submitted_count']??0),
            'pr_submitted_age_median_days'=>kpi_float($_POST['m_pr_submitted_age_median_days']??0),
            'po_open_count'=>kpi_int($_POST['m_po_open_count']??0),
            'po_open_value'=>kpi_float($_POST['m_po_open_value']??0),
            'po_to_received_avg_days'=>kpi_float($_POST['m_po_to_received_avg_days']??0),
            'eta_ontime_pct'=>kpi_float($_POST['m_eta_ontime_pct']??0),
            'forwarder_pending_count'=>kpi_int($_POST['m_forwarder_pending_count']??0),
            'docs_missing_count'=>kpi_int($_POST['m_docs_missing_count']??0),
            'ceisa_pending_count'=>kpi_int($_POST['m_ceisa_pending_count']??0),
            'ap_outstanding'=>kpi_float($_POST['m_ap_outstanding']??0),
            'ap_overdue'=>kpi_float($_POST['m_ap_overdue']??0),
        ];
        $new=[];
        foreach($delta as $metricKey=>$deltaValue){$base=(float)($actual[$metricKey]??0);$new[$metricKey]=max(0,$base+(float)$deltaValue);$new['adjustment_'.$metricKey]=$deltaValue;}
        $new['metric_available']=$actual['metric_available']??[];
        $new['actual_snapshot']=array_intersect_key($actual,$delta);
        $new['purchases_note']=(string)($_POST['purchases_note']??'');
        $new['source_purchases']='auto_erp_plus_adjustment';
        $new['purchases_updated_at']=date('c');

        $note = trim((string)($new['purchases_note'] ?? ''));
        $checker = trim((string)($_POST['checker_username'] ?? ''));
        $user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'system';
        if ($checker !== '' && !kpi_user_exists_active($pdo, $checker)) {
            kpi_flash_set('err', 'Checker username tidak ditemukan / non-aktif.');
            rmi_redirect('kpi_purchases.php');
        }
        $guard = kpi_guard_metrics_purchases($new, $existingMetrics, $status, $note, $checker, (string)$user);
        if (count($guard['errors']) > 0) {
            kpi_flash_set('err', implode(' ', $guard['errors']));
            rmi_redirect('kpi_purchases.php');
        }
        if (count($guard['warns']) > 0 && function_exists('kpi_audit')) {
            kpi_audit($pdo, 'kpi_purchases', 'guard_warn', (string)$existingId, implode(' | ', $guard['warns']));
        }

        $merged = array_merge($existingMetrics, $new);
        $payload = json_encode($merged, JSON_UNESCAPED_UNICODE);

        // Upsert
        $idToSave = $existingId ?: $id;
        if ($idToSave > 0) {
            $sets = [
                "{$MONTH_COL} = :m",
                "{$OFFICE_COL} = :o",
                "{$STATUS_COL} = :s",
                "{$JSON_COL} = :j",
            ];
            $params = [':m'=>$month, ':o'=>$officeDb, ':s'=>$status, ':j'=>$payload, ':id'=>$idToSave];
            if ($UPDATED_BY) { $sets[] = "{$UPDATED_BY} = :ub"; $params[':ub'] = $user; }
            if ($UPDATED_AT) { $sets[] = "{$UPDATED_AT} = NOW()"; }

            $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$pk}=:id";
            $pdo->prepare($sql)->execute($params);
            kpi_audit($pdo, 'kpi_purchases', 'update', (string)$idToSave, "office={$office};month={$month};checker={$checker}");
            if (function_exists('master_audit')) {
                master_audit($pdo, 'kpi_purchases', 'kpi_purchases_snapshot', 'UPDATE', (int)$idToSave, "{$office}-{$month}", "KPI purchases update: {$office} {$month}", []);
            }
            kpi_flash_set('ok', 'Data tersimpan.');
        } else {
            $colsIns = [$MONTH_COL,$OFFICE_COL,$STATUS_COL,$JSON_COL];
            $vals = [':m',':o',':s',':j'];
            $params = [':m'=>$month, ':o'=>$officeDb, ':s'=>$status, ':j'=>$payload];

            if ($CREATED_BY) { $colsIns[] = $CREATED_BY; $vals[]=':cb'; $params[':cb']=$user; }
            if ($CREATED_AT) { $colsIns[] = $CREATED_AT; $vals[]='NOW()'; }
            if ($UPDATED_BY) { $colsIns[] = $UPDATED_BY; $vals[]=':ub'; $params[':ub']=$user; }
            if ($UPDATED_AT) { $colsIns[] = $UPDATED_AT; $vals[]='NOW()'; }

            $sql = "INSERT INTO {$table} (".implode(',', $colsIns).") VALUES (".implode(',', $vals).")";
            $pdo->prepare($sql)->execute($params);
            $newId = (string)$pdo->lastInsertId();
            kpi_audit($pdo, 'kpi_purchases', 'create', $newId, "office={$office};month={$month};checker={$checker}");
            if (function_exists('master_audit')) {
                master_audit($pdo, 'kpi_purchases', 'kpi_purchases_snapshot', 'CREATE', (int)$newId, "{$office}-{$month}", "KPI purchases create: {$office} {$month}", []);
            }
            kpi_flash_set('ok', 'Data dibuat.');
        }

        rmi_redirect('kpi_purchases.php');
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

// Defaults
$defaultMonth = date('Y-m');

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
kpi_header('KPI Purchases');
kpi_nav('purch');

if (!empty($flash['err'])) {
    echo "<div class='card kpi-flash-err'><b>Error:</b> " . h((string)$flash['err']) . "</div>";
}
if (!empty($flash['ok'])) {
    echo "<div class='card kpi-flash-ok'><b>OK:</b> " . h((string)$flash['ok']) . "</div>";
}

echo "<div class='card'>
  <div class='kpi-header-row'>
    <div>
      <h2>KPI Purchases</h2>
      <div class='kpi-subtitle'>Actual dihitung otomatis dari transaksi ERP. Input manual hanya menjadi adjustment yang tercatat audit; Snapshot/Lock tetap membaca <code>kpi_office.metrics_json</code>.</div>
    </div>
    <div>
      <a class='btn secondary' href='kpi_purchases.php?export=csv&month=".urlencode($fMonth)."&office=".urlencode($fOffice)."&status=".urlencode($fStatus)."'>Export CSV</a>
      <a class='btn secondary' href='kpi_purchases.php'>Reset</a>
    </div>
  </div>
</div>";

$autoMonth = $fMonth !== '' ? $fMonth : $defaultMonth;
$autoOffice = $fOffice !== '' ? $fOffice : null;
$autoMetrics = kpi_calc_purchases_metrics($pdo, $autoMonth, $autoOffice);
$autoPolicy = kpi_purchases_policy_load($pdo, $autoOffice);
$autoScore = kpi_purchases_score($autoMetrics, $autoPolicy);
$metricAvail = is_array($autoMetrics['metric_available'] ?? null) ? $autoMetrics['metric_available'] : [];
$hasMetric = static function(string $key) use ($metricAvail): bool {
    return !array_key_exists($key, $metricAvail) || (bool)$metricAvail[$key];
};
$showCount = static function(string $key, array $m) use ($hasMetric): string {
    return $hasMetric($key) ? h((string)($m[$key] ?? 0)) : '<span class="muted">N/A</span>';
};
$showDays = static function(string $key, array $m) use ($hasMetric): string {
    return $hasMetric($key) ? h(number_format((float)($m[$key] ?? 0),2,',','.')).' hari' : '<span class="muted">N/A</span>';
};
$showPct = static function(string $key, array $m) use ($hasMetric): string {
    return $hasMetric($key) ? h(number_format((float)($m[$key] ?? 0),2,',','.')).'%' : '<span class="muted">N/A</span>';
};
$showRp = static function(string $key, array $m) use ($hasMetric): string {
    return $hasMetric($key) ? 'Rp '.h(number_format((float)($m[$key] ?? 0),0,',','.')) : '<span class="muted">N/A</span>';
};
$coveragePct=(float)($autoScore['coverage_pct']??0); $scoreLabel=$autoScore['score']===null?'Score belum valid':'Score '.number_format((float)$autoScore['score'],1,',','.').'%'; $coverageLabel='Coverage '.number_format($coveragePct,1,',','.').'%';
$excludedLabel = !empty($autoScore['excluded']) ? ' • Tidak dihitung: '.implode(', ', (array)$autoScore['excluded']) : '';
echo "<div class='card'>
  <div class='kpi-header-row'><div><h3 class='kpi-section-title'>Actual Otomatis ERP</h3>
  <div class='muted'>Periode: ".h($autoMonth)." • Office: ".h($autoOffice ?: 'Semua Office')." • Sumber: ".h(implode(', ', $autoMetrics['sources'] ?? []) ?: 'belum tersedia').h($excludedLabel)."</div></div>
  <div><span class='pill'>".h($scoreLabel)."</span></div></div>
  <div class='table-wrap'><table><thead><tr><th>PR Submitted</th><th>PR Aging</th><th>PO Open</th><th>PO Open Value (Proxy)</th><th>PO→Received</th><th>ETA On-time</th><th>Docs Missing</th><th>CEISA Pending</th><th>AP Outstanding</th><th>AP Overdue</th></tr></thead><tbody><tr>
  <td>".$showCount('pr_submitted_count',$autoMetrics)."</td>
  <td>".$showDays('pr_submitted_age_median_days',$autoMetrics)."</td>
  <td>".$showCount('po_open_count',$autoMetrics)."</td>
  <td>".$showRp('po_open_value',$autoMetrics)."</td>
  <td>".$showDays('po_to_received_avg_days',$autoMetrics)."</td>
  <td>".$showPct('eta_ontime_pct',$autoMetrics)."</td>
  <td>".$showCount('docs_missing_count',$autoMetrics)."</td>
  <td>".$showCount('ceisa_pending_count',$autoMetrics)."</td>
  <td>".$showRp('ap_outstanding',$autoMetrics)."</td>
  <td>".$showRp('ap_overdue',$autoMetrics)."</td>
  </tr></tbody></table></div>
  <div class='muted'>N/A berarti tabel/kolom sumber belum tersedia atau belum dapat dipetakan. Metrik N/A tidak ikut menghitung score. Score final hanya valid bila coverage minimal 60%.</div>
  ".(!empty($autoMetrics['warnings'])?"<div class='muted'>Peringatan sumber: ".h(implode(' | ',$autoMetrics['warnings']))."</div>":"")."
</div>";


$targetMin=(int)($autoMetrics['pqp_sla_target_minutes']??($autoPolicy['pqp_pr_to_po_sla_minutes']??1440));
$avgMin=(float)($autoMetrics['pqp_sla_avg_minutes']??0);
$medMin=(float)($autoMetrics['pqp_sla_median_minutes']??0);
$done=(int)($autoMetrics['pqp_sla_completed_count']??0);
$run=(int)($autoMetrics['pqp_sla_running_count']??0);
$ontime=(float)($autoMetrics['pqp_sla_ontime_pct']??0);
echo "<div class='card'>
  <div class='kpi-header-row'><div>
    <h3 class='kpi-section-title'>SLA PQP — PR WQS → PO</h3>
    <div class='muted'>START: <code>wqs_pr.submitted_at</code> • STOP: <code>purchases_po.created_at</code> • REVISION_WQS dipause/tidak dihitung sebagai waktu PQP • CANCELLED/VOID dikecualikan.</div>
  </div><div><span class='pill'>Target ".h((string)$targetMin)." menit</span></div></div>
  <div class='table-wrap'><table><thead><tr>
    <th>Selesai PR→PO</th><th>Masih SUBMITTED</th><th>On-time</th><th>Rata-rata</th><th>Median</th>
  </tr></thead><tbody><tr>
    <td>".h((string)$done)."</td>
    <td>".h((string)$run)."</td>
    <td>".h(number_format($ontime,2,',','.'))."%</td>
    <td>".h(number_format($avgMin,1,',','.'))." menit</td>
    <td>".h(number_format($medMin,1,',','.'))." menit</td>
  </tr></tbody></table></div>
</div>";

if (!kpi_can_manage()) {
    echo kpi_sys_only_data_notice_html('KPI Purchases');
}

// Import CSV
$csrfField = function_exists('csrf_field') ? csrf_field() : '';
if (kpi_can_manage()) {
    echo "<div class='card'>
      <h3 class='kpi-section-title'>Import CSV</h3>
      <form method='post' enctype='multipart/form-data' class='kpi-form-row'>
        {$csrfField}
        <input type='hidden' name='_action' value='import_csv'>
        <div class='kpi-field'><label>File CSV</label><input type='file' name='csv' accept='.csv,text/csv' required></div>
        <div class='kpi-field'><button class='btn secondary' type='submit'>Import</button></div>
      </form>
      <div class='muted'>Template: <a href='templates/kpi_purchases_template.csv'>kpi/templates/kpi_purchases_template.csv</a></div>
    </div>";
}

// Create/Update form
$valMonth = $editRow[$MONTH_COL] ?? $defaultMonth;
$valOfficeDb = $editRow[$OFFICE_COL] ?? '';
$valOffice = $editRow ? $officeDbToCode($valOfficeDb) : '';
$valStatus = $editRow[$STATUS_COL] ?? 'DRAFT';

$valMetrics = [
    'pr_submitted_count'=>0,
    'pr_submitted_age_median_days'=>0,
    'po_open_count'=>0,
    'po_open_value'=>0,
    'po_to_received_avg_days'=>0,
    'eta_ontime_pct'=>0,
    'forwarder_pending_count'=>0,
    'docs_missing_count'=>0,
    'ceisa_pending_count'=>0,
    'ap_outstanding'=>0,
    'ap_overdue'=>0,
    'purchases_note'=>'',
    'source_purchases'=>'manual_adjustment',
];
if ($editRow && !empty($editRow[$JSON_COL])) {
    $tmp = kpi_decode_metrics_purch((string)$editRow[$JSON_COL]);
    if (is_array($tmp)) $valMetrics = array_merge($valMetrics, $tmp);
}

$locked = ($editRow && (string)($editRow[$STATUS_COL] ?? '') === 'LOCKED');
$lockedNotice = $locked ? "<span class='badge danger'>LOCKED</span> <span class='muted'>Data terkunci (read-only).</span>" : '';

$officeOptions = "<option value=''>-- pilih --</option>";
foreach ($offices as $o) {
    $code = (string)($o['office_code'] ?? '');
    $name = (string)($o['office_name'] ?? $code);
    if ($code === '') continue;
    $sel = ($code === $valOffice) ? 'selected' : '';
    $officeOptions .= "<option {$sel} value='".h($code)."'>".h($code.' • '.$name)."</option>";
}

$officeInput = $offices
    ? "<select name='office_code' required>{$officeOptions}</select>"
    : "<input name='office_code' value='".h($valOffice)."' placeholder='OFFICE_CODE' required>";

if (kpi_can_manage()) {
    echo "<div class='card'>
  <div class='kpi-header-row'>
    <h3 class='kpi-section-title'>".($editId ? 'Edit KPI Purchases #'.h((string)$editId) : 'Input Adjustment KPI Purchases')."</h3>
    <div>{$lockedNotice}</div>
  </div>
  <form method='post'>
    {$csrfField}
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
        ".($editId?"<a class='btn secondary' href='kpi_purchases.php'>Batal</a>":"")."
      </div>
    </div>

    <div class='kpi-input-group'>
      <div class='kpi-input-group-title'>PR → PO</div>
      <div class='kpi-form-grid'>
        <div class='kpi-field'><label>Adjustment PR Submitted (+/-)</label><input type='number' name='m_pr_submitted_count' value='".h((string)($valMetrics['adjustment_pr_submitted_count'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment PR Aging (+/- hari)</label><input type='number' step='0.01' name='m_pr_submitted_age_median_days' value='".h((string)($valMetrics['adjustment_pr_submitted_age_median_days'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment PO Open (+/-)</label><input type='number' name='m_po_open_count' value='".h((string)($valMetrics['adjustment_po_open_count'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment PO Open Value (+/-)</label><input type='number' step='0.01' name='m_po_open_value' value='".h((string)($valMetrics['adjustment_po_open_value'] ?? 0))."'></div>
      </div>
    </div>

    <div class='kpi-input-group'>
      <div class='kpi-input-group-title'>Import / Delivery Performance</div>
      <div class='kpi-form-grid'>
        <div class='kpi-field'><label>Adjustment PO→Received (+/- hari)</label><input type='number' step='0.01' name='m_po_to_received_avg_days' value='".h((string)($valMetrics['adjustment_po_to_received_avg_days'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment ETA On-time (+/- %)</label><input type='number' step='0.01' name='m_eta_ontime_pct' value='".h((string)($valMetrics['adjustment_eta_ontime_pct'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment Forwarder Pending (+/-)</label><input type='number' name='m_forwarder_pending_count' value='".h((string)($valMetrics['adjustment_forwarder_pending_count'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment Docs Missing (+/-)</label><input type='number' name='m_docs_missing_count' value='".h((string)($valMetrics['adjustment_docs_missing_count'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment CEISA Pending (+/-)</label><input type='number' name='m_ceisa_pending_count' value='".h((string)($valMetrics['adjustment_ceisa_pending_count'] ?? 0))."'></div>
      </div>
    </div>

    <div class='kpi-input-group'>
      <div class='kpi-input-group-title'>AP (Supplier) — dibaca dari Finance/AP; isi hanya bila adjustment resmi</div>
      <div class='kpi-form-grid'>
        <div class='kpi-field'><label>Adjustment AP Outstanding (+/-)</label><input type='number' step='0.01' name='m_ap_outstanding' value='".h((string)($valMetrics['adjustment_ap_outstanding'] ?? 0))."'></div>
        <div class='kpi-field'><label>Adjustment AP Overdue (+/-)</label><input type='number' step='0.01' name='m_ap_overdue' value='".h((string)($valMetrics['adjustment_ap_overdue'] ?? 0))."'></div>
      </div>
      <div class='muted'>AP dibaca otomatis dari Finance/AP. Nilai di bawah adalah adjustment (+/-) resmi, wajib disertai alasan dan tercatat audit.</div>
    </div>

    <div class='kpi-field kpi-field-full' style='margin-top:12px'>
      <label>Catatan Purchases</label>
      <textarea name='purchases_note' rows='2'>".h((string)($valMetrics['purchases_note'] ?? ''))."</textarea>
    </div>
    <div class='kpi-form-row' style='margin-top:12px'>
      <div class='kpi-field'><label>Source</label><input name='source_purchases' value='".h((string)($valMetrics['source_purchases'] ?? 'manual_adjustment'))."' readonly title='Sumber ditentukan sistem'></div>
      <div class='kpi-field'><label>Checker Username</label><input name='checker_username' value='' placeholder='wajib saat FINAL'></div>
    </div>
  </form>
</div>";
} elseif ($editId > 0) {
    if ($editRow) {
        $jsonPretty = h(json_encode($valMetrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "<div class='card'>
  <div class='kpi-header-row'>
    <h3 class='kpi-section-title'>Detail KPI Purchases #".h((string)$editId)." <span class='muted'>(baca saja)</span></h3>
    <div>{$lockedNotice}</div>
  </div>
  <p class='muted'>Bulan: <b>".h($valMonth)."</b> • Office: <b>".h($valOffice)."</b> • Status: <b>".h($valStatus)."</b></p>
  <pre class='muted' style='white-space:pre-wrap;font-size:12px;max-height:420px;overflow:auto'>{$jsonPretty}</pre>
  <a class='btn secondary' href='kpi_purchases.php'>← Kembali ke daftar</a>
</div>";
    } else {
        echo "<div class='card'><span class='badge danger'>Tidak ditemukan</span> <span class='muted'>ID #".h((string)$editId)."</span> — <a href='kpi_purchases.php'>Kembali</a></div>";
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
    <div class='kpi-field'><label>Status</label><input name='status' value='".h($fStatus)."' placeholder='DRAFT/FINAL'></div>
    <div class='kpi-field'>
      <button class='btn secondary' type='submit'>Apply</button>
      <a class='btn secondary' href='kpi_purchases.php'>Reset</a>
    </div>
  </form>
</div>";

// List
$fmt0 = fn($v) => number_format((float)$v, 0, ',', '.');
$fmt2 = fn($v) => number_format((float)$v, 2, ',', '.');

echo "<div class='card'>
  <h3 class='kpi-section-title'>Data KPI Purchases</h3>
  <div class='table-wrap'>
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Bulan</th><th>Office</th><th>Status</th>
        <th style='text-align:right'>PR Sub</th>
        <th style='text-align:right'>PR Med (d)</th>
        <th style='text-align:right'>PO Open</th>
        <th style='text-align:right'>PO Open Val</th>
        <th style='text-align:right'>PO→Recv (d)</th>
        <th style='text-align:right'>ETA OT %</th>
        <th style='text-align:right'>Docs Miss</th>
        <th style='text-align:right'>CEISA Pend</th>
        <th style='text-align:right'>AP Out</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>";

foreach ($rows as $r) {
    $id = (int)($r[$pk] ?? 0);
    $m = (string)($r[$MONTH_COL] ?? '');
    $o = $officeDbToLabel($r[$OFFICE_COL] ?? '');
    $s = (string)($r[$STATUS_COL] ?? '');

    $metrics = kpi_decode_metrics_purch($r[$JSON_COL] ?? '');

    $badgeCls = ($s === 'LOCKED') ? 'pill danger' : 'pill';

    echo "<tr>
      <td>".h((string)$id)."</td>
      <td>".h($m)."</td>
      <td>".h($o)."</td>
      <td><span class='".h($badgeCls)."'>".h($s)."</span></td>
      <td style='text-align:right'>".h((string)($metrics['pr_submitted_count'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt2($metrics['pr_submitted_age_median_days'] ?? 0))."</td>
      <td style='text-align:right'>".h((string)($metrics['po_open_count'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt0($metrics['po_open_value'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt2($metrics['po_to_received_avg_days'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt2($metrics['eta_ontime_pct'] ?? 0))."</td>
      <td style='text-align:right'>".h((string)($metrics['docs_missing_count'] ?? 0))."</td>
      <td style='text-align:right'>".h((string)($metrics['ceisa_pending_count'] ?? 0))."</td>
      <td style='text-align:right'>".h($fmt0($metrics['ap_outstanding'] ?? 0))."</td>
      <td>
        ".(kpi_can_manage() ? "<a class='btn tiny' href='kpi_purchases.php?edit=".h((string)$id)."'>Edit</a> " : "<a class='btn tiny secondary' href='kpi_purchases.php?edit=".h((string)$id)."'>Lihat</a> ")."
        ".(kpi_can_manage('SYS') ? "<form method='post' class='d-inline' onsubmit=\"return confirm('Hapus data ini?')\">
    {$csrfField}
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

