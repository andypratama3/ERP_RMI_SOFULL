<?php
// Paksa waktu PHP mengikuti waktu ERP/Jakarta agar durasi berjalan (NOW - act_ready_fin_at) tidak menjadi 0 karena beda timezone server.
if (function_exists('date_default_timezone_set')) { date_default_timezone_set('Asia/Jakarta'); }
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// KPI DO (SLA) - Phase 1 Enterprise+++ RBAC MGR VIEW FIX v11
require_once __DIR__ . '/_kpi_bootstrap.php';

// VIEW gate KPI SLA v12:
// Login tetap wajib dari _kpi_bootstrap.php.
// Halaman KPI SLA dibuat VIEW-ONLY untuk semua user login yang bukan SYS,
// supaya akun Manager FIN/CRM/WQS/SCM/ACT tidak tertahan registry route lama.
// Hak ubah/simpan kebijakan tetap dikunci oleh kpi_can_manage() pada proses POST.
if (function_exists('kpi_require_view_or_die')) {
    kpi_require_view_or_die(['KPI.DO_VIEW', 'KPI.DO_SLA_VIEW', 'KPI.VIEW', 'SALES.AUDIT']);
}

require_once __DIR__ . '/_kpi_metrics.php';
require_once __DIR__ . '/_kpi_policy.php';

$pdo = kpi_require_pdo();
try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}

// --- KPI SLA per akun staff (safe inline, tidak mengubah alur utama) ---
// V4: baca jumlah staff dari master user bila tersedia, dan baca PIC transaksi dari sales_do_audit / kolom user per stage.
if (!function_exists('kpi_do_sla_staff_norm_v4')) {
    function kpi_do_sla_staff_norm_v4($v): string {
        $s = trim((string)$v);
        if ($s === '') return '- Belum terbaca akun -';
        $s = preg_replace('/\s+/', ' ', $s);
        $u = strtoupper($s);
        // Nilai ini biasanya dept/generic/system, bukan akun personal.
        if (in_array($u, ['CRM','WQS','SCM','ACT','FIN','SYS','SYSTEM','ADMIN','USER','USERS','MANAGER','STAFF','-','N/A','NULL'], true)) {
            return '- Belum terbaca akun -';
        }
        return $s;
    }
}

if (!function_exists('kpi_do_sla_staff_canon_v8')) {
    function kpi_do_sla_staff_canon_v8($v): string {
        $s = kpi_do_sla_staff_norm_v4($v);
        if ($s === '- Belum terbaca akun -') return $s;
        // Master user sering tampil "kode — nama jabatan" sedangkan transaksi hanya "kode".
        // Pakai kode sebagai identitas supaya tidak dobel.
        $s = preg_split('/\s+(?:—|-)\s+/', $s, 2)[0] ?? $s;
        $s = trim($s);
        $s = preg_replace('/\s+/', ' ', $s);
        return $s !== '' ? $s : '- Belum terbaca akun -';
    }
}
if (!function_exists('kpi_do_sla_staff_pick_v4')) {
    function kpi_do_sla_staff_pick_v4(array $row, array $keys): string {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') return trim((string)$row[$k]);
        }
        return '';
    }
}
if (!function_exists('kpi_do_sla_staff_ts_v4')) {
    function kpi_do_sla_staff_ts_v4($v): int {
        if (!$v) return 0;
        $t = strtotime((string)$v);
        return $t ? (int)$t : 0;
    }
}
if (!function_exists('kpi_do_sla_staff_dur_v4')) {
    function kpi_do_sla_staff_dur_v4(int $sec): string {
        // Durasi selesai 0 detik tetap valid dan harus tampil 0h 00m.
        // Tanda '-' hanya dipakai oleh renderer jika belum ada transaksi selesai (dur_cnt = 0).
        if ($sec < 0) $sec = 0;
        $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60);
        return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
    }
}
if (!function_exists('kpi_do_sla_staff_dur_by_dept_v10')) {
    function kpi_do_sla_staff_dur_by_dept_v10(string $dept, int $sec): string {
        // CRM/WQS/SCM/ACT tetap menit/jam. FIN ditampilkan per hari supaya sesuai alur pembayaran.
        if (strtoupper($dept) !== 'FIN') return kpi_do_sla_staff_dur_v4($sec);
        if ($sec < 0) $sec = 0;
        $days = intdiv($sec, 86400);
        $h = intdiv($sec % 86400, 3600);
        $m = intdiv($sec % 3600, 60);
        if ($days > 0) return $days . ' hari ' . $h . 'j ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
        return $h . 'j ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
    }
}
if (!function_exists('kpi_do_sla_staff_dept_v4')) {
    function kpi_do_sla_staff_dept_v4($deptRaw, $roleRaw=''): string {
        $x = strtoupper(trim((string)$deptRaw . ' ' . (string)$roleRaw));
        $x = str_replace(['-', '_', '/', '.'], ' ', $x);
        foreach (['CRM','WQS','SCM','ACT','FIN'] as $d) {
            if (preg_match('/(^|\s)' . preg_quote($d, '/') . '(\s|$)/', $x) || strpos($x, $d) !== false) return $d;
        }
        return '';
    }
}

if (!function_exists('kpi_do_sla_staff_explicit_dept_v9')) {
    function kpi_do_sla_staff_explicit_dept_v9($staff): string {
        $s = strtoupper(trim((string)$staff));
        if ($s === '') return '';
        $s = preg_split('/\s+(?:—|-)\s+/', $s, 2)[0] ?? $s;
        $compact = preg_replace('/[^A-Z0-9]/', '', $s) ?? $s;
        $prefixMap = [
            'MGRCRM'=>'CRM','STAFFCRM'=>'CRM',
            'MGRWQS'=>'WQS','STAFFWQS'=>'WQS',
            'MGRSCM'=>'SCM','STAFFSCM'=>'SCM',
            'MGRACT'=>'ACT','STAFFACT'=>'ACT',
            'MGRFIN'=>'FIN','STAFFFIN'=>'FIN',
        ];
        foreach ($prefixMap as $prefix=>$dept) {
            if (str_starts_with($compact, $prefix)) return $dept;
        }
        return '';
    }
}
if (!function_exists('kpi_do_sla_staff_guard_dept_v9')) {
    function kpi_do_sla_staff_guard_dept_v9($staff, string $dept): string {
        $staff = kpi_do_sla_staff_norm_v4($staff);
        if ($staff === '- Belum terbaca akun -') return $staff;
        $explicit = kpi_do_sla_staff_explicit_dept_v9($staff);
        // Hindari salah mapping, contoh FIN terbaca StaffACT_BGR.
        // Akun BRANCH/umum tanpa prefix dept masih diperbolehkan mengikuti actor_dept audit.
        if ($explicit !== '' && $explicit !== strtoupper($dept)) return '- Belum terbaca akun -';
        return $staff;
    }
}
if (!function_exists('kpi_do_sla_staff_master_v4')) {
    function kpi_do_sla_staff_master_v4(PDO $pdo, ?string $office=''): array {
        $out = ['counts'=>['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0], 'set'=>['CRM'=>[], 'WQS'=>[], 'SCM'=>[], 'ACT'=>[], 'FIN'=>[]], 'source'=>'belum ditemukan', 'columns'=>''];
        if (!function_exists('kpi_table_exists')) return $out;
        $tables = ['master_system_login','master_users','users','system_users','app_users','rmi_users','user_accounts','login_users','user_login','user_logins'];
        foreach ($tables as $tbl) {
            try {
                if (!kpi_table_exists($pdo, $tbl)) continue;
                $cols = kpi_table_columns($pdo, $tbl);
                if (!$cols) continue;
                $colId     = kpi_pick_col($cols, ['id','user_id']);
                $colCode   = kpi_pick_col($cols, ['username','user_name','user_code','login','email','staff_code','emp_code','employee_code','code']);
                $colName   = kpi_pick_col($cols, ['full_name','name','employee_name','staff_name','display_name']);
                $colDept   = kpi_pick_col($cols, ['dept','department','dept_code','department_code','divisi','division','unit','bagian']);
                $colRole   = kpi_pick_col($cols, ['role','user_role','level','position','jabatan','group_name']);
                $colStatus = kpi_pick_col($cols, ['status','is_active','active']);
                $colDeleted = kpi_pick_col($cols, ['deleted_at','deleted_on']);
                $colOffice = kpi_pick_col($cols, ['office_code','office','branch_code','cabang']);
                if (!$colCode && !$colName) continue;
                $select = [];
                foreach ([$colId,$colCode,$colName,$colDept,$colRole,$colStatus,$colDeleted,$colOffice] as $c) if ($c) $select[$c] = "`$c`";
                $sql = 'SELECT ' . implode(',', $select) . " FROM `$tbl` LIMIT 5000";
                $st = $pdo->query($sql);
                $found = 0;
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    if ($colStatus) {
                        $sv = strtoupper(trim((string)($r[$colStatus] ?? '')));
                        if ($sv !== '' && in_array($sv, ['0','N','NO','FALSE','INACTIVE','NONACTIVE','NON ACTIVE','RESIGN','DELETED','BLOCKED'], true)) continue;
                    }
                    if ($colDeleted && trim((string)($r[$colDeleted] ?? '')) !== '') continue;
                    if ($office && $colOffice && trim((string)($r[$colOffice] ?? '')) !== '' && strtoupper((string)$r[$colOffice]) !== strtoupper($office)) continue;
                    $dep = kpi_do_sla_staff_dept_v4($colDept ? ($r[$colDept] ?? '') : '', $colRole ? ($r[$colRole] ?? '') : '');
                    if ($dep === '') continue;
                    $code = $colCode ? (string)($r[$colCode] ?? '') : '';
                    $name = $colName ? (string)($r[$colName] ?? '') : '';
                    $staff = kpi_do_sla_staff_norm_v4($code !== '' ? $code : $name);
                    if ($staff === '- Belum terbaca akun -' && $name !== '') $staff = kpi_do_sla_staff_norm_v4($name);
                    if ($staff === '- Belum terbaca akun -') continue;
                    if ($name !== '' && stripos($staff, $name) === false) $label = $staff . ' — ' . $name; else $label = $staff;
                    $out['set'][$dep][$label] = true;
                    $found++;
                }
                if ($found > 0) {
                    foreach (['CRM','WQS','SCM','ACT','FIN'] as $d) $out['counts'][$d] = count($out['set'][$d]);
                    $out['source'] = $tbl;
                    $out['columns'] = 'code=' . ($colCode ?: '-') . ', name=' . ($colName ?: '-') . ', dept=' . ($colDept ?: '-') . ', role=' . ($colRole ?: '-') . ', office=' . ($colOffice ?: '-');
                    return $out;
                }
            } catch (Throwable $e) { /* coba tabel lain */ }
        }
        return $out;
    }
}
if (!function_exists('kpi_do_sla_staff_audit_v4')) {
    function kpi_do_sla_staff_audit_v4(PDO $pdo, array $doIds): array {
        $map = ['rows'=>[], 'actor_col'=>'-', 'status_col'=>'-', 'time_col'=>'-', 'source'=>'sales_do_audit'];
        if (!$doIds || !function_exists('kpi_table_exists') || !kpi_table_exists($pdo, 'sales_do_audit')) return $map;
        $cols = kpi_table_columns($pdo, 'sales_do_audit');
        $colDo = kpi_pick_col($cols, ['do_id','sales_do_id','ref_id','record_id']) ?: 'do_id';
        $colStatus = kpi_pick_col($cols, ['status_to','to_status','new_status','status','action','event','activity']) ?: 'status_to';
        $colDept = kpi_pick_col($cols, ['actor_dept','dept','department','stage','module_dept']);
        $colTime = kpi_pick_col($cols, ['created_at','updated_at','audit_at','event_at','log_at']) ?: 'created_at';
        $colActor = kpi_pick_col($cols, ['actor_name','user_code','username','user_name','user','actor','actor_code','actor_user','action_by','performed_by','created_by','updated_by','staff_code','staff','pic','pic_code','operator','changed_by','last_updated_by']);
        $map['actor_col'] = $colActor ?: '-'; $map['status_col'] = $colStatus; $map['time_col'] = $colTime;
        $ids = array_values(array_unique(array_filter(array_map('intval', $doIds))));
        if (!$ids) return $map;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $select = "`{$colDo}` AS do_id, `{$colStatus}` AS status_to, `{$colTime}` AS created_at";
        if ($colActor) $select .= ", `{$colActor}` AS actor";
        if ($colDept) $select .= ", `{$colDept}` AS actor_dept";
        try {
            $orderId = in_array('id', $cols, true) ? ', id ASC' : '';
            $st = $pdo->prepare("SELECT {$select} FROM sales_do_audit WHERE `{$colDo}` IN ($in) ORDER BY `{$colTime}` ASC{$orderId}");
            $st->execute($ids);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $did = (int)($r['do_id'] ?? 0); if (!$did) continue;
                $toRaw = (string)($r['status_to'] ?? ''); $to = strtolower($toRaw);
                $actor = kpi_do_sla_staff_norm_v4($r['actor'] ?? '');
                $time = (string)($r['created_at'] ?? '');
                if ($to === '') continue;
                if (!isset($map['rows'][$did]['first'][$to])) $map['rows'][$did]['first'][$to] = $time;
                $deptRaw = strtoupper(trim((string)($r['actor_dept'] ?? '')));
                $dept = in_array($deptRaw, ['CRM','WQS','SCM','ACT','FIN'], true) ? $deptRaw : null;
                if (!$dept && in_array($to, ['crm_to_wqs'], true)) $dept = 'CRM';
                if (!$dept && in_array($to, ['wqs_processing','ready_scm'], true)) $dept = 'WQS';
                if (!$dept && (in_array($to, ['on_delivery','delivered','return_scm_completed'], true) || (strpos($to, 'scm') !== false && $to !== 'ready_scm'))) $dept = 'SCM';
                if (!$dept && (in_array($to, ['wait_payment','act_ready_fin','act_invoiced'], true) || strpos($to, 'act') !== false)) $dept = 'ACT';
                if (!$dept && (in_array($to, ['paid','fin_done'], true) || strpos($to, 'fin') !== false)) $dept = 'FIN';
                if ($actor !== '- Belum terbaca akun -') {
                    // Simpan actor per status exact untuk atribusi tahap final (terutama PAID/FIN_DONE).
                    $map['rows'][$did]['actor_status'][$to] = $actor;
                    if ($dept) {
                        // latest actor departemen lebih representatif untuk transaksi yang sudah selesai.
                        $map['rows'][$did]['actor'][$dept] = $actor;
                    }
                }
            }
        } catch (Throwable $e) {
            $map['error'] = $e->getMessage();
        }
        return $map;
    }
}

if (!function_exists('kpi_do_sla_staff_system_audit_v6')) {
    function kpi_do_sla_staff_system_audit_v6(PDO $pdo, array $doCodes): array {
        $map = ['rows'=>[], 'actor_col'=>'-', 'status_col'=>'action', 'time_col'=>'created_at', 'source'=>'system_audit_logs'];
        if (!$doCodes || !function_exists('kpi_table_exists') || !kpi_table_exists($pdo, 'system_audit_logs')) return $map;
        $cols = kpi_table_columns($pdo, 'system_audit_logs');
        $colCode = kpi_pick_col($cols, ['record_code','ref_code','do_code','code']);
        $colAction = kpi_pick_col($cols, ['action','event','activity']) ?: 'action';
        $colTime = kpi_pick_col($cols, ['created_at','updated_at','audit_at','event_at','log_at']) ?: 'created_at';
        $colActor = kpi_pick_col($cols, ['username','user_name','user','actor_name','actor','created_by','updated_by','staff_code','pic']);
        $map['actor_col'] = $colActor ?: '-'; $map['status_col'] = $colAction; $map['time_col'] = $colTime;
        if (!$colCode || !$colActor) return $map;
        $codes = array_values(array_unique(array_filter(array_map('strval', $doCodes))));
        if (!$codes) return $map;
        $in = implode(',', array_fill(0, count($codes), '?'));
        $select = "`{$colCode}` AS do_code, `{$colAction}` AS action_name, `{$colTime}` AS created_at, `{$colActor}` AS actor";
        try {
            $orderId = in_array('id', $cols, true) ? ', id ASC' : '';
            $st = $pdo->prepare("SELECT {$select} FROM system_audit_logs WHERE `{$colCode}` IN ($in) ORDER BY `{$colTime}` ASC{$orderId}");
            $st->execute($codes);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $code = (string)($r['do_code'] ?? ''); if ($code === '') continue;
                $actRaw = (string)($r['action_name'] ?? '');
                $act = strtoupper($actRaw);
                $actor = kpi_do_sla_staff_norm_v4($r['actor'] ?? '');
                $time = (string)($r['created_at'] ?? '');
                if ($act === '') continue;
                $dept = null; $statusKey = '';
                if (strpos($act, 'CRM') !== false || strpos($act, 'CREATE') !== false || strpos($act, 'SEND_WQS') !== false) { $dept='CRM'; $statusKey='crm_to_wqs'; }
                if (strpos($act, 'WQS_START') !== false || strpos($act, 'WQS_SAVE') !== false) { $dept='WQS'; $statusKey='wqs_processing'; }
                if (strpos($act, 'WQS_READY') !== false || strpos($act, 'READY_SCM') !== false) { $dept='WQS'; $statusKey='ready_scm'; }
                if (strpos($act, 'SCM') !== false || strpos($act, 'DELIVERY') !== false || strpos($act, 'DELIVERED') !== false) { $dept='SCM'; $statusKey=(strpos($act, 'DELIVER') !== false ? 'delivered' : 'on_delivery'); }
                if (strpos($act, 'ACT') !== false || strpos($act, 'INVOICE') !== false || strpos($act, 'WAIT_PAYMENT') !== false) { $dept='ACT'; $statusKey='wait_payment'; }
                if (strpos($act, 'FIN') !== false || strpos($act, 'PAID') !== false) { $dept='FIN'; $statusKey='paid'; }
                if ($statusKey !== '' && !isset($map['rows'][$code]['first'][$statusKey])) $map['rows'][$code]['first'][$statusKey] = $time;
                if ($actor !== '- Belum terbaca akun -') {
                    if ($statusKey !== '') $map['rows'][$code]['actor_status'][$statusKey] = $actor;
                    if ($dept) $map['rows'][$code]['actor'][$dept] = $actor;
                }
            }
        } catch (Throwable $e) {
            $map['error'] = $e->getMessage();
        }
        return $map;
    }
}

if (!function_exists('kpi_do_sla_staff_build_v2')) {
    function kpi_do_sla_staff_build_v2(PDO $pdo, string $d1, string $d2, ?string $office, array $sla): array {
        $out = ['counts'=>['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0], 'rows'=>[], 'error'=>'', 'debug'=>[]];
        $master = kpi_do_sla_staff_master_v4($pdo, $office ?: null);
        $staffSet = $master['set'];
        $out['debug']['master_source'] = $master['source'] ?? 'belum ditemukan';
        $out['debug']['master_columns'] = $master['columns'] ?? '';
        try {
            $cols = kpi_table_columns($pdo, 'sales_do');
            $colId = kpi_pick_col($cols, ['id']) ?: 'id';
            $colDate = kpi_pick_col($cols, ['do_date']) ?: 'do_date';
            $colOffice = kpi_pick_col($cols, ['office_code']) ?: 'office_code';
            $colStatus = kpi_pick_col($cols, ['status']) ?: 'status';
            $where = ["`{$colDate}` BETWEEN :d1 AND :d2"];
            $params = [':d1'=>$d1, ':d2'=>$d2];
            if ($office) { $where[] = "`{$colOffice}` = :office"; $params[':office'] = $office; }
            $sql = "SELECT * FROM sales_do WHERE " . implode(' AND ', $where) . " ORDER BY `{$colDate}` ASC, `{$colId}` ASC LIMIT 10000";
            $st = $pdo->prepare($sql); $st->execute($params); $dos = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $out['error'] = 'Gagal membaca sales_do: ' . $e->getMessage();
            return $out;
        }
        $auditPack = kpi_do_sla_staff_audit_v4($pdo, array_map(fn($r)=>(int)($r['id'] ?? 0), $dos));
        $audit = $auditPack['rows'] ?? [];
        $sysAuditPack = kpi_do_sla_staff_system_audit_v6($pdo, array_map(fn($r)=>(string)($r['do_code'] ?? ''), $dos));
        $sysAudit = $sysAuditPack['rows'] ?? [];
        $out['debug']['audit_actor_col'] = $auditPack['actor_col'] ?? '-';
        $out['debug']['audit_status_col'] = $auditPack['status_col'] ?? '-';
        $out['debug']['audit_time_col'] = $auditPack['time_col'] ?? '-';
        $out['debug']['system_audit_actor_col'] = $sysAuditPack['actor_col'] ?? '-';
        if (!empty($auditPack['error'])) $out['debug']['audit_error'] = $auditPack['error'];
        if (!empty($sysAuditPack['error'])) $out['debug']['system_audit_error'] = $sysAuditPack['error'];
        $now = time(); $agg = [];
        $stageStaffCols = [
            'CRM'=>['created_by','requested_by','sales_by','sales_emp_code','crm_created_by','crm_user','crm_by','created_user','user_created'],
            'WQS'=>['wqs_picked_by','wqs_started_by','wqs_updated_by','wqs_ready_by','wqs_user','wqs_by','warehouse_by','picked_by'],
            'SCM'=>['scm_completed_by','scm_delivered_by','scm_updated_by','scm_by','scm_user','delivery_by','delivered_by'],
            'ACT'=>['act_invoiced_by','act_ready_by','act_updated_by','act_by','act_user','invoice_by','invoiced_by'],
            'FIN'=>['fin_paid_by','fin_updated_by','fin_by','fin_user','finance_by','paid_by'],
        ];
        $out['debug']['sales_do_user_cols'] = [];
        foreach ($stageStaffCols as $dep=>$cands) {
            $existing = array_values(array_filter($cands, fn($c)=>in_array($c, $cols, true)));
            $out['debug']['sales_do_user_cols'][$dep] = $existing ? implode(',', $existing) : '-';
        }
        foreach ($dos as $d) {
            $id = (int)($d['id'] ?? 0); $status = strtolower((string)($d['status'] ?? ''));
            $first = $audit[$id]['first'] ?? []; $actor = $audit[$id]['actor'] ?? [];
            $doCodeKey = (string)($d['do_code'] ?? '');
            if ($doCodeKey !== '' && isset($sysAudit[$doCodeKey])) {
                $first = array_merge(($sysAudit[$doCodeKey]['first'] ?? []), $first);
                $actor = array_merge(($sysAudit[$doCodeKey]['actor'] ?? []), $actor);
            }
            $doDate = kpi_do_sla_staff_ts_v4(($d['do_date'] ?? $d1) . ' 00:00:00');
            $created = kpi_do_sla_staff_ts_v4($d['created_at'] ?? '') ?: $doDate;
            // KPI CRM: prioritas start dari jam order manual RS/customer jika tersedia.
            // Fallback aman: crm_start_at -> created_at -> do_date agar data lama tetap terbaca.
            $crmManualStart = kpi_do_sla_staff_ts_v4($d['crm_order_received_at'] ?? '');
            $crmStart = $crmManualStart ?: (kpi_do_sla_staff_ts_v4($d['crm_start_at'] ?? '') ?: $created);
            $tCrmEnd = kpi_do_sla_staff_ts_v4($first['crm_to_wqs'] ?? ($first['wqs_processing'] ?? ''));
            if (!$tCrmEnd) $tCrmEnd = kpi_do_sla_staff_ts_v4($d['crm_finish_at'] ?? '');
            $crmDurationFixed = isset($d['crm_duration_sec']) && $d['crm_duration_sec'] !== null && $d['crm_duration_sec'] !== '' ? max(0, (int)$d['crm_duration_sec']) : null;
            $tWqsStart = $tCrmEnd ?: kpi_do_sla_staff_ts_v4($d['wqs_started_at'] ?? ($d['wqs_picked_at'] ?? '')) ?: $doDate;
            $tWqsEnd = kpi_do_sla_staff_ts_v4($d['wqs_ready_at'] ?? '') ?: kpi_do_sla_staff_ts_v4($first['ready_scm'] ?? '');
            $tScmStart = $tWqsEnd ?: kpi_do_sla_staff_ts_v4($d['scm_on_delivery_at'] ?? '');
            $tScmEnd = kpi_do_sla_staff_ts_v4($d['scm_delivered_at'] ?? ($d['scm_completed_at'] ?? '')) ?: kpi_do_sla_staff_ts_v4($first['delivered'] ?? ($first['on_delivery'] ?? ''));
            $tActStart = $tScmEnd;
            $tActEnd = kpi_do_sla_staff_ts_v4($d['act_ready_fin_at'] ?? ($d['act_invoiced_at'] ?? ($d['act_updated_at'] ?? ''))) ?: kpi_do_sla_staff_ts_v4($first['wait_payment'] ?? ($first['act_ready_fin'] ?? ''));
            $tFinStart = $tActEnd;
            $tFinEnd = kpi_do_sla_staff_ts_v4($d['fin_paid_at'] ?? '') ?: kpi_do_sla_staff_ts_v4($first['paid'] ?? ($first['fin_done'] ?? ''));
            if (!$tFinEnd && in_array($status, ['paid','fin_done'], true)) {
                $tFinEnd = kpi_do_sla_staff_ts_v4($d['fin_updated_at'] ?? '');
            }
            // Pilih PIC per tahap. Untuk FIN, prioritas utama adalah kolom FIN aktual
            // yang ditulis oleh halaman finance/fin_do_tasks.php. Audit hanya fallback.
            // Guard dept mencegah FIN terisi StaffACT_* atau dept lain akibat audit lama.
            $staffCrm = kpi_do_sla_staff_guard_dept_v9($actor['CRM'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['CRM']), 'CRM');
            $staffWqs = kpi_do_sla_staff_guard_dept_v9($actor['WQS'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['WQS']), 'WQS');
            $staffScm = kpi_do_sla_staff_guard_dept_v9($actor['SCM'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['SCM']), 'SCM');
            $staffAct = kpi_do_sla_staff_guard_dept_v9($actor['ACT'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['ACT']), 'ACT');
            $finDirect = kpi_do_sla_staff_pick_v4($d, $stageStaffCols['FIN']);
            $staffFin = kpi_do_sla_staff_guard_dept_v9($finDirect !== '' ? $finDirect : ($actor['FIN'] ?? ''), 'FIN');

            // FIN logic:
            // - Jika FIN sudah melakukan save/paid, pakai akun FIN asli dari fin_updated_by / fin_paid_by / audit FIN.
            // - Jika DO baru masuk WAIT PAYMENT dari ACT dan belum pernah disentuh FIN, jangan salah tempel ke ACT.
            //   Tampilkan sebagai queue FIN supaya backlog FIN tidak terlihat kosong, tetapi tetap bukan staff personal.
            if ($staffFin === '- Belum terbaca akun -' && in_array($status, ['wait_payment'], true) && $tFinStart) {
                $staffFin = 'FIN Queue - Belum diambil finance';
            }

            $stages = [
                'CRM'=>['staff'=>$staffCrm, 'start'=>$crmStart, 'end'=>$tCrmEnd, 'fixed_dur'=>$crmDurationFixed, 'open'=>in_array($status, ['draft','crm','crm_to_wqs'], true)],
                'WQS'=>['staff'=>$staffWqs, 'start'=>$tWqsStart, 'end'=>$tWqsEnd, 'open'=>in_array($status, ['crm_to_wqs','wqs_processing','pending'], true)],
                'SCM'=>['staff'=>$staffScm, 'start'=>$tScmStart, 'end'=>$tScmEnd, 'open'=>in_array($status, ['ready_scm','on_delivery'], true)],
                'ACT'=>['staff'=>$staffAct, 'start'=>$tActStart, 'end'=>$tActEnd, 'open'=>in_array($status, ['delivered'], true)],
                'FIN'=>['staff'=>$staffFin, 'start'=>$tFinStart, 'end'=>$tFinEnd, 'open'=>in_array($status, ['wait_payment'], true)],
            ];
            foreach ($stages as $dep=>$x) {
                $start=(int)($x['start']??0); $end=(int)($x['end']??0); $open=(bool)($x['open']??false);
                if (!$start && !$end && !$open) continue;
                $staff = kpi_do_sla_staff_norm_v4($x['staff'] ?? '');
                if ($staff !== '- Belum terbaca akun -') $staffSet[$dep][$staff] = true;
                $key = $dep . '|' . $staff;
                if (!isset($agg[$key])) $agg[$key] = ['dept'=>$dep,'staff'=>$staff,'total'=>0,'ok'=>0,'over'=>0,'open'=>0,'dur_sum'=>0,'dur_cnt'=>0];
                $agg[$key]['total']++;
                $dur = 0;
                $fixedDur = array_key_exists('fixed_dur', $x) ? $x['fixed_dur'] : null;
                if ($fixedDur !== null && $end) $dur = max(0, (int)$fixedDur);
                elseif ($end && $start) $dur = max(0, $end-$start);
                elseif ($open && $start) $dur = max(0, $now-$start);
                $slaSec = max(1, (int)($sla[$dep] ?? 1440)) * 60;
                $over = ($dur > $slaSec);
                // SLA aktif dihitung untuk transaksi selesai maupun yang masih OPEN.
                // Jika belum selesai tapi durasi berjalan masih <= SLA, tetap masuk OK berjalan.
                // Jika sudah melewati SLA, masuk OVERDUE. Ini penting untuk FIN:
                // setelah ACT tukar faktur (act_ready_fin_at/act_invoiced_at), DO wait_payment
                // sudah menjadi beban FIN walaupun belum paid.
                if ($over) $agg[$key]['over']++;
                else $agg[$key]['ok']++;
                if ($open && !$end) $agg[$key]['open']++;
                if ($dur > 0 || $end || $open) { $agg[$key]['dur_sum'] += $dur; $agg[$key]['dur_cnt']++; }
            }
        }
        // Tambahkan staff master yang belum ada transaksi, supaya jumlah staff per dept tetap terbaca.
        foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) {
            foreach (array_keys($staffSet[$dep] ?? []) as $staff) {
                $key = $dep . '|' . $staff;
                if (!isset($agg[$key])) $agg[$key] = ['dept'=>$dep,'staff'=>$staff,'total'=>0,'ok'=>0,'over'=>0,'open'=>0,'dur_sum'=>0,'dur_cnt'=>0];
            }
            $out['counts'][$dep] = count(array_filter(array_keys($staffSet[$dep] ?? []), fn($s)=>$s !== '- Belum terbaca akun -'));
        }
        // V9: rapikan hasil per akun.
        // 1) Hilangkan baris master staff tanpa transaksi (dianggap akun tidak aktif pada periode/filter).
        // 2) Gabungkan duplikat kode vs label master, contoh: StaffCRM_BGR dan StaffCRM_BGR — Staff CRM (BGR).
        // 3) Jangan tampilkan baris "Belum terbaca akun" di tabel utama; simpan jumlahnya di debug.
        // 4) FIN Queue ditampilkan untuk WAIT PAYMENT yang sudah masuk FIN tetapi belum diambil staff finance.
        $labelByCanon = [];
        foreach ($agg as $r0) {
            $staff0 = (string)($r0['staff'] ?? '');
            $canon0 = kpi_do_sla_staff_canon_v8($staff0);
            if ($canon0 !== '- Belum terbaca akun -' && strpos($staff0, '—') !== false) {
                $labelByCanon[$r0['dept'] . '|' . $canon0] = $staff0;
            }
        }
        $merged = [];
        $unknown = ['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0];
        foreach ($agg as $r0) {
            $dep0 = (string)($r0['dept'] ?? '');
            $total0 = (int)($r0['total'] ?? 0);
            if ($total0 <= 0) continue; // akun master tanpa transaksi tidak ditampilkan
            $canon0 = kpi_do_sla_staff_canon_v8($r0['staff'] ?? '');
            if ($canon0 === '- Belum terbaca akun -') {
                if (isset($unknown[$dep0])) $unknown[$dep0] += $total0;
                continue;
            }
            $key0 = $dep0 . '|' . $canon0;
            if (!isset($merged[$key0])) {
                $merged[$key0] = ['dept'=>$dep0,'staff'=>$labelByCanon[$key0] ?? $canon0,'total'=>0,'ok'=>0,'over'=>0,'open'=>0,'dur_sum'=>0,'dur_cnt'=>0];
            }
            foreach (['total','ok','over','open','dur_sum','dur_cnt'] as $mk) {
                $merged[$key0][$mk] += (int)($r0[$mk] ?? 0);
            }
        }
        foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) $out['counts'][$dep] = 0;
        foreach ($merged as $mr) {
            // FIN Queue adalah antrian departemen, bukan akun staff personal.
            if (($mr['dept'] ?? '') === 'FIN' && ($mr['staff'] ?? '') === 'FIN Queue - Belum diambil finance') continue;
            if (isset($out['counts'][$mr['dept']])) $out['counts'][$mr['dept']]++;
        }
        $out['debug']['unassigned_hidden'] = $unknown;
        $rows = array_values($merged);
        usort($rows, function($a,$b){
            $d = ['CRM'=>1,'WQS'=>2,'SCM'=>3,'ACT'=>4,'FIN'=>5];
            $c = ($d[$a['dept']] ?? 99) <=> ($d[$b['dept']] ?? 99); if ($c) return $c;
            $ta = (int)($a['total'] ?? 0); $tb = (int)($b['total'] ?? 0);
            if ($ta !== $tb) return $tb <=> $ta;
            return strcmp((string)$a['staff'], (string)$b['staff']);
        });
        $out['rows'] = $rows;
        return $out;
    }
}
if (!function_exists('kpi_do_sla_staff_render_v2')) {
    function kpi_do_sla_staff_render_v2(array $data): void {
        $counts = $data['counts'] ?? []; $rows = $data['rows'] ?? []; $debug = $data['debug'] ?? [];
        echo "<div class='card'>";
        echo "<h3 class='kpi-section-title'>KPI SLA per Akun Staff / PIC</h3>";
        echo "<div class='muted'>Manager dapat melihat jumlah staff CRM/WQS/SCM/ACT/FIN dan performa per akun. Durasi CRM memakai jam order manual RS/customer bila tersedia; data lama tetap fallback ke audit/created_at. Untuk FIN: WAIT PAYMENT yang belum disentuh staff finance tampil sebagai <b>FIN Queue - Belum diambil finance</b>; setelah finance save/paid, KPI masuk ke akun FIN asli.</div>";
        if (!empty($data['error'])) echo "<div class='badge danger'>" . h((string)$data['error']) . "</div>";
        echo "<div style='display:grid;grid-template-columns:repeat(5,minmax(110px,1fr));gap:12px;margin:14px 0'>";
        foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) {
            echo "<div style='background:rgba(255,255,255,.03);border:1px solid rgba(148,163,184,.22);border-radius:14px;padding:12px'>";
            echo "<div class='muted'>".h($dep)."</div><div style='font-size:28px;font-weight:900'>".h((string)($counts[$dep] ?? 0))."</div><div class='muted'>staff terbaca</div>";
            echo "</div>";
        }
        echo "</div>";
        $uh = $debug['unassigned_hidden'] ?? [];
        $uhTxt = [];
        foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) { if (!empty($uh[$dep])) $uhTxt[] = $dep . ':' . (int)$uh[$dep]; }
        echo "<div class='muted' style='margin:8px 0'>Sumber master staff: <b>".h((string)($debug['master_source'] ?? '-'))."</b> ".h((string)($debug['master_columns'] ?? ''))."<br>Audit transaksi: actor=<b>".h((string)($debug['audit_actor_col'] ?? '-'))."</b>, status=<b>".h((string)($debug['audit_status_col'] ?? '-'))."</b>, waktu=<b>".h((string)($debug['audit_time_col'] ?? '-'))."</b><br>Fallback system_audit_logs actor=<b>".h((string)($debug['system_audit_actor_col'] ?? '-'))."</b>" . ($uhTxt ? "<br>Transaksi lama tanpa PIC disembunyikan: <b>".h(implode(', ', $uhTxt))."</b>" : "") . "</div>";
        echo "<details style='margin:8px 0'><summary class='muted'>Kolom user sales_do yang terdeteksi</summary><div class='muted'>";
        foreach (($debug['sales_do_user_cols'] ?? []) as $dep=>$cols) echo h($dep . ': ' . $cols) . "<br>";
        echo "</div></details>";
        echo "<div class='table-wrap'><table><thead><tr><th>Dept</th><th>Staff / PIC</th><th>Total Handle</th><th>OK</th><th>Overdue</th><th>On-time %</th><th>Open Backlog</th><th>Avg Duration</th></tr></thead><tbody>";
        if (!$rows) echo "<tr><td colspan='8' class='muted'>Belum ada data staff pada periode/filter ini.</td></tr>";
        else foreach ($rows as $r) {
            $total=(int)($r['total']??0); $ok=(int)($r['ok']??0); $over=(int)($r['over']??0); $open=(int)($r['open']??0);
            $pct=$total>0?number_format(($ok/$total)*100,1,',','.').'%':'-';
            $durCnt=(int)($r['dur_cnt']??0);
            $avg=($durCnt>0)?intdiv((int)$r['dur_sum'],$durCnt):0;
            // Jika ada transaksi selesai, durasi 0 menit tetap ditampilkan 0h 00m.
            // Jika belum ada transaksi selesai dan hanya backlog, tampilkan '-'.
            $avgText=($durCnt>0)?(function_exists('kpi_do_sla_staff_dur_by_dept_v10') ? kpi_do_sla_staff_dur_by_dept_v10((string)$r['dept'], $avg) : kpi_do_sla_staff_dur_v4($avg)):'-';
            $badge=$over>0?'pill danger':'pill';
            echo "<tr><td><b>".h((string)$r['dept'])."</b></td><td>".h((string)$r['staff'])."</td><td style='text-align:right'>".h((string)$total)."</td><td style='text-align:right'>".h((string)$ok)."</td><td style='text-align:right'><span class='".h($badge)."'>".h((string)$over)."</span></td><td style='text-align:right'>".h($pct)."</td><td style='text-align:right'>".h((string)$open)."</td><td style='text-align:right'>".h($avgText)."</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "</div>";
    }
}


// --- Unified KPI SLA source-of-truth v14 ---
// Mengambil durasi langsung dari field hasil proses departemen:
// CRM crm_duration_sec, WQS wqs_duration_sec, SCM scm_duration_sec,
// ACT dua KPI terpisah, FIN fin_duration_days (hari kalender).
if (!function_exists('kpi_do_sla_cfg_get_v13')) {
    function kpi_do_sla_cfg_get_v13(PDO $pdo, string $key, int $fallback): int {
        try {
            $st = $pdo->prepare("SELECT config_value FROM system_config WHERE config_group='KPI_DO_SLA' AND config_key=? AND is_active=1 LIMIT 1");
            $st->execute([$key]);
            $v = trim((string)$st->fetchColumn());
            if ($v !== '' && is_numeric($v) && (int)$v > 0) return (int)$v;
        } catch (Throwable $e) {}
        return max(1, $fallback);
    }
}
if (!function_exists('kpi_do_sla_cfg_set_v13')) {
    function kpi_do_sla_cfg_set_v13(PDO $pdo, string $key, int $value): void {
        $value = max(1, $value);
        $st = $pdo->prepare("UPDATE system_config SET config_value=?, is_active=1 WHERE config_group='KPI_DO_SLA' AND config_key=?");
        $st->execute([(string)$value, $key]);
        if ($st->rowCount() > 0) return;
        $ck = $pdo->prepare("SELECT COUNT(*) FROM system_config WHERE config_group='KPI_DO_SLA' AND config_key=?");
        $ck->execute([$key]);
        if ((int)$ck->fetchColumn() > 0) return;
        $ins = $pdo->prepare("INSERT INTO system_config (config_group, config_key, config_value, is_active) VALUES ('KPI_DO_SLA', ?, ?, 1)");
        $ins->execute([$key, (string)$value]);
    }
}
if (!function_exists('kpi_do_sla_policy_expand_v13')) {
    function kpi_do_sla_policy_expand_v13(PDO $pdo, array $base): array {
        $actFallback = max(1, (int)($base['ACT'] ?? 2880));
        $base['ACT_TAX'] = kpi_do_sla_cfg_get_v13($pdo, 'ACT_TAX_INVOICE', $actFallback);

        // ACT Tukar Faktur memakai satuan HARI, bukan menit.
        // Policy baru disimpan pada ACT_EXCHANGE_DOC_DAYS dan dibatasi maksimal 7 hari.
        // Nilai dikonversi ke menit hanya untuk kompatibilitas engine/config lama.
        $exchangeDays = kpi_do_sla_cfg_get_v13($pdo, 'ACT_EXCHANGE_DOC_DAYS', 7);
        $exchangeDays = max(1, min(7, (int)$exchangeDays));
        $base['ACT_EXCHANGE_DAYS'] = $exchangeDays;
        $base['ACT_EXCHANGE'] = $exchangeDays * 1440;
        return $base;
    }
}
if (!function_exists('kpi_do_sla_ts_v13')) {
    function kpi_do_sla_ts_v13($v): int {
        if (!$v) return 0;
        $t = strtotime((string)$v);
        return $t ? (int)$t : 0;
    }
}
if (!function_exists('kpi_do_sla_upload_ts_v14')) {
    function kpi_do_sla_upload_ts_v14($path): int {
        $path = trim((string)$path);
        if ($path === '') return 0;
        // File upload ERP memakai suffix _YYYYMMDD_HHMMSS_<random>.ext.
        // Ini dipakai hanya sebagai fallback legacy ketika kolom *_at belum tersedia.
        if (preg_match('/_(\d{8})_(\d{6})_[A-Za-z0-9]+\.[A-Za-z0-9]+$/', basename($path), $m)) {
            $dt = DateTimeImmutable::createFromFormat('!Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone('Asia/Jakarta'));
            if ($dt instanceof DateTimeImmutable) return $dt->getTimestamp();
        }
        return 0;
    }
}
if (!function_exists('kpi_do_sla_days_v13')) {
    function kpi_do_sla_days_v13(int $startTs, int $endTs): int {
        if ($startTs <= 0 || $endTs <= 0) return 0;
        try {
            $a = new DateTimeImmutable(date('Y-m-d', $startTs), new DateTimeZone('Asia/Jakarta'));
            $b = new DateTimeImmutable(date('Y-m-d', $endTs), new DateTimeZone('Asia/Jakarta'));
            if ($b < $a) return 0;
            return (int)$a->diff($b)->days;
        } catch (Throwable $e) {
            return max(0, (int)floor(($endTs-$startTs)/86400));
        }
    }
}
if (!function_exists('kpi_do_sla_metric_label_v13')) {
    function kpi_do_sla_metric_label_v13(string $metric): string {
        return [
            'CRM'=>'CRM', 'WQS'=>'WQS', 'SCM'=>'SCM',
            'ACT_TAX'=>'Faktur Pajak', 'ACT_EXCHANGE'=>'Tukar Faktur', 'FIN'=>'FIN'
        ][$metric] ?? $metric;
    }
}
if (!function_exists('kpi_do_sla_dept_v13')) {
    function kpi_do_sla_dept_v13(string $metric): string {
        return str_starts_with($metric, 'ACT_') ? 'ACT' : $metric;
    }
}
if (!function_exists('kpi_do_sla_staff_direct_v13')) {
    function kpi_do_sla_staff_direct_v13(array $d, string $metric, array $actor): string {
        $map = [
            'CRM'=>['crm_finish_by','crm_updated_by','created_by','requested_by','sales_by','sales_emp_code'],
            'WQS'=>['wqs_ready_by','wqs_started_by','wqs_updated_by','wqs_picked_by'],
            'SCM'=>['scm_delivered_by','scm_updated_by','scm_on_delivery_by','scm_completed_by'],
            'ACT_TAX'=>['act_tax_invoice_by','act_ready_by','act_updated_by','act_invoiced_by'],
            'ACT_EXCHANGE'=>['act_exchange_doc_by','act_updated_by','act_ready_by'],
            'FIN'=>['fin_paid_by','fin_updated_by'],
        ];
        foreach ($map[$metric] ?? [] as $k) {
            if (array_key_exists($k, $d) && trim((string)$d[$k]) !== '') return trim((string)$d[$k]);
        }
        $dept = kpi_do_sla_dept_v13($metric);
        return trim((string)($actor[$dept] ?? ''));
    }
}
if (!function_exists('kpi_do_sla_build_unified_v13')) {
    function kpi_do_sla_build_unified_v13(PDO $pdo, string $d1, string $d2, ?string $office, array $sla): array {
        $result = ['available'=>true,'summary'=>[],'staff_rows'=>[],'staff_counts'=>[],'overdue'=>[],'events'=>[],'debug'=>[]];
        $metrics = ['CRM','WQS','SCM','ACT_TAX','ACT_EXCHANGE','FIN'];
        foreach ($metrics as $m) $result['summary'][$m] = ['ok'=>0,'over'=>0,'open'=>0,'dur_sum'=>0,'dur_cnt'=>0,'avg_sec'=>0];
        try {
            $cols = kpi_table_columns($pdo, 'sales_do');
            $dateCol = kpi_pick_col($cols, ['do_date']) ?: 'do_date';
            $officeCol = kpi_pick_col($cols, ['office_code']) ?: 'office_code';
            // Ambil populasi DO kantor terlebih dahulu. Cohort periode diterapkan per-stage di bawah:
            // CRM/WQS/SCM tetap mengikuti tanggal DO (alur lama yang sudah baik),
            // ACT mengikuti SCM DELIVERED, FIN mengikuti upload Tukar Faktur ACT.
            // Ini penting agar recovery historis (DO lama, evidence ACT baru) tetap masuk cohort FIN yang benar.
            $where = ['1=1'];
            $params = [];
            if ($office) { $where[] = "`{$officeCol}`=:office"; $params[':office']=$office; }
            $st=$pdo->prepare("SELECT * FROM sales_do WHERE ".implode(' AND ',$where)." ORDER BY `{$dateCol}` ASC,id ASC LIMIT 15000");
            $st->execute($params); $dos=$st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['available'=>false,'reason'=>'Gagal membaca sales_do: '.$e->getMessage(),'summary'=>[],'staff_rows'=>[],'overdue'=>[]];
        }
        $auditPack = kpi_do_sla_staff_audit_v4($pdo, array_map(fn($r)=>(int)($r['id']??0), $dos));
        $audit = $auditPack['rows'] ?? [];
        $sysPack = kpi_do_sla_staff_system_audit_v6($pdo, array_map(fn($r)=>(string)($r['do_code']??''), $dos));
        $sysAudit = $sysPack['rows'] ?? [];
        // Legacy evidence resolver: read-only, tidak mengubah data sales_do.
        // Source kuat: tabel dokumen ACT per DO. tax_invoices hanya dianggap evidence bila ISSUED DAN file_path ada.
        $taxDocById=[]; $exchangeDocById=[]; $taxIssuedByCode=[];
        $doIds=array_values(array_unique(array_filter(array_map(static fn($r)=>(int)($r['id']??0),$dos))));
        try {
            if (kpi_table_exists($pdo,'sales_do_act_tax_docs')) {
                foreach (array_chunk($doIds,300) as $chunk) {
                    if(!$chunk) continue; $ph=implode(',',array_fill(0,count($chunk),'?'));
                    $q=$pdo->prepare("SELECT do_id,file_path,uploaded_by,created_at,updated_at FROM sales_do_act_tax_docs WHERE do_id IN ($ph) AND COALESCE(file_path,'')<>''");
                    $q->execute($chunk);
                    while($r=$q->fetch(PDO::FETCH_ASSOC)) $taxDocById[(int)$r['do_id']]=$r;
                }
            }
        } catch(Throwable $e) {}
        try {
            if (kpi_table_exists($pdo,'sales_do_act_exchange_docs')) {
                foreach (array_chunk($doIds,300) as $chunk) {
                    if(!$chunk) continue; $ph=implode(',',array_fill(0,count($chunk),'?'));
                    $q=$pdo->prepare("SELECT do_id,file_path,uploaded_by,uploaded_at,updated_at FROM sales_do_act_exchange_docs WHERE do_id IN ($ph) AND COALESCE(file_path,'')<>''");
                    $q->execute($chunk);
                    while($r=$q->fetch(PDO::FETCH_ASSOC)) $exchangeDocById[(int)$r['do_id']]=$r;
                }
            }
        } catch(Throwable $e) {}
        try {
            if (kpi_table_exists($pdo,'tax_invoices')) {
                $codes=array_values(array_unique(array_filter(array_map(static fn($r)=>trim((string)($r['do_code']??'')),$dos))));
                foreach(array_chunk($codes,300) as $chunk){
                    if(!$chunk) continue; $ph=implode(',',array_fill(0,count($chunk),'?'));
                    $q=$pdo->prepare("SELECT sales_invoice_ref,file_path,created_by,created_at,updated_at FROM tax_invoices WHERE sales_invoice_ref IN ($ph) AND UPPER(COALESCE(status,''))='ISSUED' AND COALESCE(file_path,'')<>'' ORDER BY COALESCE(updated_at,created_at) DESC,id DESC");
                    $q->execute($chunk);
                    while($r=$q->fetch(PDO::FETCH_ASSOC)){ $c=trim((string)$r['sales_invoice_ref']); if($c!==''&&!isset($taxIssuedByCode[$c]))$taxIssuedByCode[$c]=$r; }
                }
            }
        } catch(Throwable $e) {}
        $now=time(); $staffAgg=[]; $actCanonicalCohort=0; $finEvidenceCohort=0; $staffUnique=['CRM'=>[],'WQS'=>[],'SCM'=>[],'ACT'=>[],'FIN'=>[]];
        $periodStart = strtotime($d1.' 00:00:00') ?: 0;
        $periodEnd = strtotime($d2.' 23:59:59') ?: PHP_INT_MAX;
        $inPeriod = static function(int $ts) use ($periodStart,$periodEnd): bool { return $ts>0 && $ts >= $periodStart && $ts <= $periodEnd; };

        $add = function(array $d, string $metric, int $start, int $end, ?int $fixedSec, bool $open, bool $forceOver, string $staff, string $status) use (&$result,&$staffAgg,&$staffUnique,$sla,$now) {
            // Untuk data lama, timestamp start kadang belum ada tetapi duration_sec final sudah tersimpan.
            // Jangan membuang KPI tersebut: rekonstruksi start dari end - fixed duration bila memungkinkan.
            if ($start <= 0 && $fixedSec !== null && $end > 0) $start = max(1, $end - max(0, $fixedSec));
            if ($start <= 0) return;
            $dept = kpi_do_sla_dept_v13($metric);
            $isFin = ($metric==='FIN');
            $isExchangeDays = ($metric==='ACT_EXCHANGE');
            $effectiveEnd = $end > 0 ? $end : ($open ? $now : 0);
            if ($effectiveEnd <= 0 && $fixedSec === null) return;
            if ($isFin) {
                // FIN dinilai per HARI kalender. fin_duration_days adalah freeze final.
                if ($fixedSec !== null) $durSec=max(0,$fixedSec);
                else $durSec=kpi_do_sla_days_v13($start,$effectiveEnd)*86400;
                $slaDays=max(1,(int)ceil(((int)($sla['FIN']??43200))/1440));
                $over = $forceOver || (intdiv($durSec,86400) > $slaDays);
            } elseif ($isExchangeDays) {
                // Tukar Faktur ACT dinilai per HARI kalender, maksimal 7 hari.
                if ($end > 0 || $open) $durSec=kpi_do_sla_days_v13($start,$effectiveEnd)*86400;
                elseif ($fixedSec !== null) $durSec=max(0,intdiv(max(0,$fixedSec),86400)*86400);
                else $durSec=0;
                $slaDays=max(1,min(7,(int)($sla['ACT_EXCHANGE_DAYS'] ?? (int)ceil(((int)($sla['ACT_EXCHANGE']??10080))/1440))));
                $over=$forceOver || (intdiv($durSec,86400) > $slaDays);
            } else {
                // Source-of-truth: duration_sec freeze. Namun data legacy kadang terisi 0
                // walau timestamp start/end menunjukkan durasi nyata; pada kondisi itu pakai timestamp.
                if ($fixedSec !== null && !($fixedSec === 0 && $end > $start)) $durSec=max(0,$fixedSec);
                else $durSec=max(0,$effectiveEnd-$start);
                $slaMin=max(1,(int)($sla[$metric] ?? ($sla[$dept] ?? 1440)));
                $over=$forceOver || ($durSec > $slaMin*60);
            }
            $completed = ($end > 0) || (!$open && $fixedSec !== null);
            $isOpen = ($open && $end<=0);
            $s=&$result['summary'][$metric];
            // Kategori ringkasan harus saling eksklusif agar OK + OVERDUE + OPEN BACKLOG = cohort.
            // Backlog yang sudah melewati SLA tetap OPEN BACKLOG (flag overdue tetap dipakai untuk daftar detail),
            // tetapi tidak dihitung kedua kali pada kolom OVERDUE.
            if ($isOpen) $s['open']++;
            elseif ($over) $s['over']++;
            elseif ($completed) $s['ok']++;
            // Avg Duration hanya transaksi selesai; age backlog ditampilkan di daftar overdue/open.
            if ($completed) { $s['dur_sum'] += $durSec; $s['dur_cnt']++; }

            $staff = kpi_do_sla_staff_norm_v4($staff);
            if ($metric==='FIN' && $staff==='- Belum terbaca akun -' && $open) $staff='FIN Queue - Belum diambil finance';
            if ($staff==='- Belum terbaca akun -') {
                if ($open && $metric==='ACT_TAX') $staff='Backlog Faktur Pajak - Belum ada uploader';
                elseif ($open && $metric==='ACT_EXCHANGE') $staff='Backlog Tukar Faktur - Belum ada uploader';
                elseif ($open) $staff='Backlog - Belum ada PIC';
                else $staff='Historis - PIC tidak terekam';
            }
            $nonPersonal = str_starts_with($staff,'FIN Queue -') || str_starts_with($staff,'Backlog ') || str_starts_with($staff,'Historis -');
            if (!$nonPersonal) $staffUnique[$dept][kpi_do_sla_staff_canon_v8($staff)] = true;
            $sk=$metric.'|'.kpi_do_sla_staff_canon_v8($staff);
            if (!isset($staffAgg[$sk])) $staffAgg[$sk]=['dept'=>$dept,'metric'=>$metric,'staff'=>$staff,'total'=>0,'ok'=>0,'over'=>0,'open'=>0,'dur_sum'=>0,'dur_cnt'=>0];
            $a=&$staffAgg[$sk];
            $a['total']++;
            // Sama dengan ringkasan departemen: satu DO hanya boleh berada pada satu bucket utama.
            if ($isOpen) $a['open']++;
            elseif ($over) $a['over']++;
            elseif ($completed) $a['ok']++;
            if ($completed) { $a['dur_sum'] += $durSec; $a['dur_cnt']++; }

            $ev=['office'=>(string)($d['office_code']??''),'do_code'=>(string)($d['do_code']??''),'date'=>(string)($d['do_date']??''),'status'=>$status,'dept'=>$dept,'metric'=>$metric,'stage'=>($dept==='ACT' ? 'ACT - '.kpi_do_sla_metric_label_v13($metric) : $dept),'start'=>$start,'end'=>$end,'duration_sec'=>$durSec,'open'=>($open&&$end<=0),'overdue'=>$over,'staff'=>$staff];
            $result['events'][]=$ev;
            if ($over) $result['overdue'][]=$ev;
        };

        foreach ($dos as $d) {
            $id=(int)($d['id']??0); $code=(string)($d['do_code']??''); $status=strtolower((string)($d['status']??''));
            $first=$audit[$id]['first']??[]; $actor=$audit[$id]['actor']??[]; $actorStatus=$audit[$id]['actor_status']??[];
            if ($code!=='' && isset($sysAudit[$code])) {
                $first=array_merge(($sysAudit[$code]['first']??[]),$first);
                $actor=array_merge(($sysAudit[$code]['actor']??[]),$actor);
                $actorStatus=array_merge(($sysAudit[$code]['actor_status']??[]),$actorStatus);
            }
            $doDate=kpi_do_sla_ts_v13(($d['do_date']??$d1).' 00:00:00');
            $created=kpi_do_sla_ts_v13($d['created_at']??'') ?: $doDate;

            $doInPeriod = $inPeriod($doDate);

            // CRM — pertahankan cohort tanggal DO seperti sebelumnya.
            $crmStart=kpi_do_sla_ts_v13($d['crm_order_received_at']??'') ?: (kpi_do_sla_ts_v13($d['crm_start_at']??'') ?: $created);
            $crmEnd=kpi_do_sla_ts_v13($d['crm_finish_at']??'') ?: kpi_do_sla_ts_v13($first['crm_to_wqs']??($first['wqs_processing']??''));
            $crmFixed=(isset($d['crm_duration_sec']) && $d['crm_duration_sec']!=='' && $d['crm_duration_sec']!==null)?max(0,(int)$d['crm_duration_sec']):null;
            $crmOpen=(!$crmEnd && in_array($status,['draft','crm','new','created'],true));
            if ($doInPeriod && ($crmEnd || $crmOpen || $crmFixed!==null)) $add($d,'CRM',$crmStart,$crmEnd,$crmFixed,$crmOpen,false,kpi_do_sla_staff_direct_v13($d,'CRM',$actor),$status);

            // WQS: mengikuti source field operasional hasil revisi WQS.
            $wqsStart=kpi_do_sla_ts_v13($d['wqs_started_at']??($d['wqs_picked_at']??''));
            if (!$wqsStart) $wqsStart=kpi_do_sla_ts_v13($first['wqs_processing']??'');
            $wqsEnd=kpi_do_sla_ts_v13($d['wqs_ready_at']??'') ?: kpi_do_sla_ts_v13($first['ready_scm']??'');
            $wqsFixed=(isset($d['wqs_duration_sec']) && $d['wqs_duration_sec']!=='' && $d['wqs_duration_sec']!==null)?max(0,(int)$d['wqs_duration_sec']):null;
            $wqsOpen=($wqsStart>0 && !$wqsEnd && in_array($status,['wqs_processing','revision_requested'],true));
            if ($doInPeriod && ($wqsEnd || $wqsOpen || ($wqsFixed!==null && $wqsStart))) $add($d,'WQS',$wqsStart,$wqsEnd,$wqsFixed,$wqsOpen,false,kpi_do_sla_staff_direct_v13($d,'WQS',$actor),$status);

            // SCM: mulai saat READY SCM, selesai saat DELIVERED.
            $scmStart=kpi_do_sla_ts_v13($d['scm_sla_started_at']??'') ?: (kpi_do_sla_ts_v13($d['wqs_ready_at']??'') ?: kpi_do_sla_ts_v13($first['ready_scm']??''));
            $scmEnd=kpi_do_sla_ts_v13($d['scm_delivered_at']??($d['scm_completed_at']??'')) ?: kpi_do_sla_ts_v13($first['delivered']??'');
            $scmFixed=(isset($d['scm_duration_sec']) && $d['scm_duration_sec']!=='' && $d['scm_duration_sec']!==null)?max(0,(int)$d['scm_duration_sec']):null;
            $scmOpen=($scmStart>0 && !$scmEnd && in_array($status,['ready_scm','on_delivery'],true));
            if ($doInPeriod && ($scmEnd || $scmOpen || ($scmFixed!==null && $scmStart))) $add($d,'SCM',$scmStart,$scmEnd,$scmFixed,$scmOpen,false,kpi_do_sla_staff_direct_v13($d,'SCM',$actor),$status);

            // ACT: dua KPI paralel, start sama = SCM DELIVERED CANONICAL.
            // PENTING: cohort ACT WAJIB memakai sales_do.scm_delivered_at langsung.
            // Jangan fallback ke audit/first['delivered'] untuk menentukan cohort, karena audit historis
            // dapat memasukkan DO lama ke bulan berjalan dan menggembungkan KPI ACT.
            // STOP hanya oleh evidence dokumen yang valid; WAIT PAYMENT bukan evidence.
            $actStart=kpi_do_sla_ts_v13($d['scm_delivered_at']??'');
            if ($actStart>0 && $inPeriod($actStart)) {
                $actCanonicalCohort++;
                $taxLegacy=$taxDocById[$id]??null; $taxIssued=$taxIssuedByCode[$code]??null;
                $taxEnd=kpi_do_sla_ts_v13($d['act_tax_invoice_at']??'');
                if(!$taxEnd) $taxEnd=kpi_do_sla_upload_ts_v14($d['act_tax_invoice_file']??'');
                if(!$taxEnd && $taxLegacy) $taxEnd=kpi_do_sla_ts_v13(($taxLegacy['created_at']??'') ?: ($taxLegacy['updated_at']??''));
                if(!$taxEnd && $taxIssued) $taxEnd=kpi_do_sla_ts_v13(($taxIssued['created_at']??'') ?: ($taxIssued['updated_at']??''));
                if($taxEnd && $taxEnd<$actStart) $taxEnd=0;
                $taxFixed=(isset($d['act_tax_invoice_duration_sec'])&&$d['act_tax_invoice_duration_sec']!==''&&$d['act_tax_invoice_duration_sec']!==null)?max(0,(int)$d['act_tax_invoice_duration_sec']):null;
                if($taxFixed===null&&$taxEnd>0)$taxFixed=max(0,$taxEnd-$actStart);
                // Selama belum ada evidence, SLA Faktur tetap berjalan meski status sudah WAIT PAYMENT.
                $taxOpen=(!$taxEnd&&$taxFixed===null&&!in_array($status,['cancelled','void','archived'],true));
                // PIC ACT selesai harus uploader evidence yang sebenarnya. Jangan mewariskan actor ACT generik
                // ke backlog karena itu dapat menuduh staff yang belum pernah meng-upload dokumen tersebut.
                $taxStaff=trim((string)($d['act_tax_invoice_by']??''));
                if($taxStaff===''&&$taxLegacy)$taxStaff=trim((string)($taxLegacy['uploaded_by']??''));
                if($taxStaff===''&&$taxIssued)$taxStaff=trim((string)($taxIssued['created_by']??''));
                if($taxEnd||$taxFixed!==null||$taxOpen)$add($d,'ACT_TAX',$actStart,$taxEnd,$taxFixed,$taxOpen,false,$taxStaff,$status);

                $exLegacy=$exchangeDocById[$id]??null;
                $exEnd=kpi_do_sla_ts_v13($d['act_exchange_doc_at']??'');
                if(!$exEnd)$exEnd=kpi_do_sla_upload_ts_v14($d['act_exchange_doc_file']??'');
                if(!$exEnd&&$exLegacy)$exEnd=kpi_do_sla_ts_v13(($exLegacy['uploaded_at']??'') ?: ($exLegacy['updated_at']??''));
                if($exEnd&&$exEnd<$actStart)$exEnd=0;
                $exFixed=(isset($d['act_exchange_duration_sec'])&&$d['act_exchange_duration_sec']!==''&&$d['act_exchange_duration_sec']!==null)?max(0,(int)$d['act_exchange_duration_sec']):null;
                if($exFixed===null&&$exEnd>0)$exFixed=max(0,$exEnd-$actStart);
                $exOpen=(!$exEnd&&$exFixed===null&&!in_array($status,['cancelled','void','archived'],true));
                // PIC Tukar Faktur = uploader Tukar Faktur. Jika belum upload, jangan fallback ke actor ACT lain.
                $exStaff=trim((string)($d['act_exchange_doc_by']??''));
                if($exStaff===''&&$exLegacy)$exStaff=trim((string)($exLegacy['uploaded_by']??''));
                if($exEnd||$exFixed!==null||$exOpen)$add($d,'ACT_EXCHANGE',$actStart,$exEnd,$exFixed,$exOpen,false,$exStaff,$status);

            }

            // FIN cohort berdiri sendiri: START = evidence Tukar Faktur ACT yang valid.
            // Jangan bergantung pada tanggal DO / SCM delivered / WAIT PAYMENT.
            $exLegacyFin=$exchangeDocById[$id]??null;
            $finStart=kpi_do_sla_ts_v13($d['act_exchange_doc_at']??'');
            if(!$finStart && $exLegacyFin) $finStart=kpi_do_sla_ts_v13(($exLegacyFin['uploaded_at']??'') ?: ($exLegacyFin['updated_at']??''));
            if($finStart>0 && $inPeriod($finStart)) {
                $finEvidenceCohort++;
                $finEnd=kpi_do_sla_ts_v13($d['fin_paid_at']??'') ?: kpi_do_sla_ts_v13($first['paid']??($first['fin_done']??''));
                if($finEnd && $finEnd<$finStart) $finEnd=0;
                $finFixed=null;
                if(isset($d['fin_duration_days'])&&$d['fin_duration_days']!==''&&$d['fin_duration_days']!==null&&$finEnd)$finFixed=max(0,(int)$d['fin_duration_days'])*86400;
                $finOpen=(!$finEnd&&!in_array($status,['cancelled','void','archived'],true));
                // PIC FIN selesai: utamakan actor event PAID/FIN_DONE, lalu kolom canonical FIN,
                // baru latest actor departemen FIN. Jangan pernah memakai uploader ACT sebagai PIC FIN.
                $finStaff='';
                if ($finEnd>0) {
                    $finStaff=trim((string)($actorStatus['paid'] ?? ($actorStatus['fin_done'] ?? '')));
                }
                if ($finStaff==='') $finStaff=kpi_do_sla_staff_direct_v13($d,'FIN',$actor);
                $finStaff=kpi_do_sla_staff_guard_dept_v9($finStaff,'FIN');
                if($finStaff==='' || $finStaff==='- Belum terbaca akun -') {
                    $finStaff=$finOpen ? 'FIN Queue - Belum diambil finance' : 'Historis - PIC FIN tidak terekam';
                }
                $add($d,'FIN',$finStart,$finEnd,$finFixed,$finOpen,false,$finStaff,$status);
            }
        }
        foreach ($result['summary'] as $m=>&$r) $r['avg_sec']=$r['dur_cnt']>0?intdiv($r['dur_sum'],$r['dur_cnt']):0;
        unset($r);
        $result['staff_rows']=array_values($staffAgg);
        usort($result['staff_rows'], function($a,$b){ $ord=['CRM'=>1,'WQS'=>2,'SCM'=>3,'ACT_TAX'=>4,'ACT_EXCHANGE'=>5,'FIN'=>6]; $c=($ord[$a['metric']]??99)<=>($ord[$b['metric']]??99); if($c)return$c; return (($b['total']??0)<=>($a['total']??0)); });
        foreach ($staffUnique as $dep=>$set) $result['staff_counts'][$dep]=count($set);
        usort($result['overdue'], fn($a,$b)=>(($b['duration_sec']??0)<=>($a['duration_sec']??0)) ?: strcmp((string)$a['do_code'],(string)$b['do_code']));
        $result['debug']=['audit_actor_col'=>$auditPack['actor_col']??'-','system_audit_actor_col'=>$sysPack['actor_col']??'-','act_canonical_scm_delivered_cohort'=>$actCanonicalCohort,'fin_exchange_evidence_cohort'=>$finEvidenceCohort];
        return $result;
    }
}

// --- Flash (one-shot) ---
if (!array_key_exists('_kpi_do_sla_flash', $_SESSION)) {
    $_SESSION['_kpi_do_sla_flash'] = null;
}
$kpiDoSlaFlashSet = static function (string $type, string $msg): void {
    $_SESSION['_kpi_do_sla_flash'] = ['type' => $type, 'msg' => $msg];
};
$kpiDoSlaFlashGet = static function (): array {
    $f = $_SESSION['_kpi_do_sla_flash'] ?? null;
    $_SESSION['_kpi_do_sla_flash'] = null;
    return is_array($f) ? $f : [];
};

// --- SYS: simpan kebijakan SLA DO (menit) — sebelum export / HTML ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['_action'] ?? '') === 'save_do_sla_policy') {
    if (function_exists('csrf_verify_or_die')) {
        csrf_verify_or_die();
    } elseif (function_exists('rmi_csrf_verify')) {
        rmi_csrf_verify();
    } else {
        http_response_code(500);
        exit('CSRF helper missing');
    }
    if (!kpi_can_manage()) {
        $kpiDoSlaFlashSet('err', 'Akses ditolak. Hanya SYS yang dapat mengubah kebijakan SLA DO.');
        $m = trim((string)($_POST['month'] ?? $_GET['month'] ?? ''));
        $o = trim((string)($_POST['office'] ?? $_GET['office'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
            $m = '';
        }
        $q = 'kpi_do_sla.php' . ($m !== '' ? '?month=' . rawurlencode($m) . ($o !== '' ? '&office=' . rawurlencode($o) : '') : '');
        rmi_redirect($q);
    }
    $payload = [
        'CRM' => (int)($_POST['sla_crm'] ?? 0),
        'WQS' => (int)($_POST['sla_wqs'] ?? 0),
        'SCM' => (int)($_POST['sla_scm'] ?? 0),
        'ACT' => max((int)($_POST['sla_act_tax'] ?? $_POST['sla_act'] ?? 0), (int)(max(1, min(7, (int)($_POST['sla_act_exchange_days'] ?? 7))) * 1440)),
        // FIN tetap disimpan dalam menit agar kompatibel dengan engine KPI lama,
        // tetapi UI memakai hari karena SLA FIN dihitung sejak ACT tukar faktur/ready finance.
        'FIN' => (int)(isset($_POST['sla_fin_days']) ? ((int)$_POST['sla_fin_days'] * 1440) : ($_POST['sla_fin'] ?? 0)),
    ];
    try {
        kpi_do_sla_policy_save($pdo, $payload);
        kpi_do_sla_cfg_set_v13($pdo, 'ACT_TAX_INVOICE', (int)($_POST['sla_act_tax'] ?? $payload['ACT']));
        $actExchangeDays = max(1, min(7, (int)($_POST['sla_act_exchange_days'] ?? 7)));
        kpi_do_sla_cfg_set_v13($pdo, 'ACT_EXCHANGE_DOC_DAYS', $actExchangeDays);
        // Mirror menit untuk kompatibilitas report/helper lama.
        kpi_do_sla_cfg_set_v13($pdo, 'ACT_EXCHANGE_DOC', $actExchangeDays * 1440);
        if (function_exists('kpi_audit')) {
            kpi_audit($pdo, 'kpi_do_sla', 'policy_save', '', 'crm=' . $payload['CRM'] . ';wqs=' . $payload['WQS'] . ';scm=' . $payload['SCM'] . ';act_tax=' . (int)($_POST['sla_act_tax'] ?? $payload['ACT']) . ';act_exchange_days=' . $actExchangeDays . ';fin=' . $payload['FIN']);
        }
        $kpiDoSlaFlashSet('ok', 'Kebijakan SLA DO tersimpan.');
    } catch (Throwable $e) {
        $kpiDoSlaFlashSet('err', 'Gagal menyimpan: ' . $e->getMessage());
    }
    $m = trim((string)($_POST['month'] ?? ''));
    $o = trim((string)($_POST['office'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
        $m = '';
    }
    $q = 'kpi_do_sla.php' . ($m !== '' ? '?month=' . rawurlencode($m) . ($o !== '' ? '&office=' . rawurlencode($o) : '') : '');
    rmi_redirect($q);
}

// Export CSV must run before any HTML
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!kpi_table_exists($pdo, 'sales_do')) {
        header('Content-Type: text/plain; charset=utf-8'); echo "sales_do table missing"; exit;
    }
    $monthYm=trim((string)($_GET['month']??'')); if(!$monthYm)$monthYm=date('Y-m');
    [$d1,$d2]=kpi_month_range($monthYm); $office=trim((string)($_GET['office']??''));
    $sla=kpi_do_sla_policy_expand_v13($pdo,kpi_do_sla_policy_load($pdo));
    $u=kpi_do_sla_build_unified_v13($pdo,$d1,$d2,$office?:null,$sla);
    $out=[];
    foreach (($u['overdue']??[]) as $e) {
        $out[]=[
            'month_ym'=>$monthYm,'office_code'=>$e['office'],'do_code'=>$e['do_code'],'do_date'=>$e['date'],
            'status'=>$e['status'],'dept'=>$e['dept'],'kpi'=>kpi_do_sla_metric_label_v13($e['metric']),
            'state'=>$e['open']?'OPEN':'CLOSED','staff_pic'=>$e['staff'],
            'age_minutes'=>(int)floor(((int)$e['duration_sec'])/60),
            'age_days'=>$e['metric']==='FIN'?intdiv((int)$e['duration_sec'],86400):'',
        ];
    }
    kpi_csv_download('kpi_do_overdue.csv',$out);
}

// --- UI ---

kpi_header('KPI DO (SLA)');
kpi_nav('do');

if (!kpi_table_exists($pdo, 'sales_do')) {
    echo "<div class='card'><span class='badge danger'>MISSING</span> Table <b>sales_do</b> tidak ditemukan. Modul Sales wajib ada.</div>";
    kpi_footer();
    exit;
}

$monthYm = trim((string)($_GET['month'] ?? ''));
if (!$monthYm) {
    $monthYm = date('Y-m');
}
[$d1,$d2] = kpi_month_range($monthYm);

$office = trim((string)($_GET['office'] ?? ''));

// SLA: selalu dari system_config (fallback legacy jam / default). Non-SYS tidak bisa override via URL.
$sla = kpi_do_sla_policy_expand_v13($pdo, kpi_do_sla_policy_load($pdo));
$unifiedSla = kpi_do_sla_build_unified_v13($pdo, $d1, $d2, $office ?: null, $sla);

$hasAudit = kpi_table_exists($pdo, 'sales_do_audit');

$offices = kpi_get_master_offices($pdo);

$officeOptions = "<option value=''>-- Semua Office --</option>";
foreach ($offices as $o) {
    $code = (string)($o['office_code'] ?? '');
    $name = (string)($o['office_name'] ?? $code);
    if ($code === '') continue;
    $sel = ($code === $office) ? 'selected' : '';
    $officeOptions .= "<option {$sel} value='".h($code)."'>".h($code.' • '.$name)."</option>";
}

$flash = $kpiDoSlaFlashGet();
$flashHtml = '';
if (($flash['type'] ?? '') !== '' && ($flash['msg'] ?? '') !== '') {
    $cls = ($flash['type'] === 'ok') ? 'ok' : 'danger';
    $flashHtml = "<div class='card' style='border-left:4px solid var(--rmi-border, #334155)'><span class='badge {$cls}'>" . h((string)$flash['type']) . "</span> " . h((string)$flash['msg']) . "</div>";
}

$exportHref = 'kpi_do_sla.php?export=csv&month=' . rawurlencode($monthYm) . ($office !== '' ? '&office=' . rawurlencode($office) : '');
$isSys = kpi_can_manage();

// Summary

echo $flashHtml;

echo "<div class='card'>
  <div class='kpi-header-row'>
    <div>
      <h2>KPI DO (SLA)</h2>
      <div class='kpi-subtitle'>Phase 1 • Range: <b>".h($d1)."</b> s/d <b>".h($d2)."</b> • " . ($office?('Office: <b>'.h($office).'</b>'):'Semua office') . "</div>
      <div class='muted'>Audit: " . ($hasAudit?"<span class='badge ok'>sales_do_audit OK</span>":"<span class='badge danger'>sales_do_audit missing</span>") . "</div>
    </div>
    <div>
      <a class='btn secondary' href='".h($exportHref)."'>Export Overdue CSV</a>
      <a class='btn secondary' href='kpi_do_sla.php'>Reset</a>
    </div>
  </div>
</div>";

// Filter form (bulan/office saja — SLA tidak lewat GET)

echo "<div class='card'>
  <h3 class='kpi-section-title'>Filter</h3>
  <form method='get' class='kpi-form-row'>
    <div class='kpi-field'><label>Bulan</label><input type='month' name='month' value='".h($monthYm)."'></div>
    <div class='kpi-field'><label>Office</label>" . ($offices ? "<select name='office'>{$officeOptions}</select>" : "<input name='office' value='".h($office)."'>") . "</div>
    <div class='kpi-field'>
      <button class='btn secondary' type='submit'>Apply</button>
    </div>
  </form>
  <div class='muted'>SLA diambil dari kebijakan sistem (<code>system_config</code> group <code>KPI_DO_SLA</code>). Parameter <code>sla_*</code> di URL <b>diabaikan</b> — bukan untuk mengubah angka.</div>
</div>";

// SYS: form simpan kebijakan SLA
if ($isSys) {
    $csrf = function_exists('csrf_field') ? csrf_field() : '';
    echo "<div class='card'>
  <h3 class='kpi-section-title'>Kebijakan SLA DO (SYS)</h3>
  <p class='muted'>Hanya user level <b>SYS</b> yang dapat mengubah nilai ini. User lain melihat KPI dengan SLA yang sama.</p>
  <form method='post' class='kpi-form-row'>
    <input type='hidden' name='_action' value='save_do_sla_policy'>
    <input type='hidden' name='month' value='".h($monthYm)."'>
    <input type='hidden' name='office' value='".h($office)."'>
    {$csrf}
    <div class='kpi-field'><label>SLA CRM (menit)</label><input type='number' name='sla_crm' min='1' max='525600' value='".h((string)$sla['CRM'])."' required></div>
    <div class='kpi-field'><label>SLA WQS (menit)</label><input type='number' name='sla_wqs' min='1' max='525600' value='".h((string)$sla['WQS'])."' required></div>
    <div class='kpi-field'><label>SLA SCM (menit)</label><input type='number' name='sla_scm' min='1' max='525600' value='".h((string)$sla['SCM'])."' required></div>
    <div class='kpi-field'><label>SLA ACT Faktur Pajak (menit)</label><input type='number' name='sla_act_tax' min='1' max='525600' value='".h((string)$sla['ACT_TAX'])."' required></div>
    <div class='kpi-field'><label>SLA ACT Tukar Faktur (hari)</label><input type='number' name='sla_act_exchange_days' min='1' max='7' value='".h((string)max(1, min(7, (int)($sla['ACT_EXCHANGE_DAYS'] ?? ceil(((int)($sla['ACT_EXCHANGE'] ?? 10080))/1440)))))."' required></div>
    <div class='kpi-field'><label>SLA FIN (hari)</label><input type='number' name='sla_fin_days' min='1' max='365' value='".h((string)max(1, (int)ceil(((int)$sla['FIN'])/1440)))."' required><input type='hidden' name='sla_fin' value='".h((string)$sla['FIN'])."'></div>
    <div class='kpi-field' style='align-self:flex-end'><button class='btn' type='submit'>Simpan kebijakan SLA</button></div>
  </form>
  <div class='muted'>Default referensi: CRM=30 menit, WQS=240 (4j), SCM=1440 (24j), ACT Faktur Pajak mengikuti policy menit, ACT Tukar Faktur=<b>7 hari</b>, FIN=30 hari. Jika belum pernah disimpan, dipakai fallback dari <code>KPI_SLA</code> (jam) lalu default ini.</div>
</div>";
} else {
    echo "<div class='card'>
  <h3 class='kpi-section-title'>SLA aktif (baca saja)</h3>
  <div class='muted'>CRM=<b>".h((string)$sla['CRM'])."</b> menit • WQS=<b>".h((string)$sla['WQS'])."</b> menit • SCM=<b>".h((string)$sla['SCM'])."</b> menit • ACT Faktur Pajak=<b>".h((string)$sla['ACT_TAX'])."</b> menit • ACT Tukar Faktur=<b>".h((string)max(1, min(7, (int)($sla['ACT_EXCHANGE_DAYS'] ?? ceil(((int)($sla['ACT_EXCHANGE'] ?? 10080))/1440)))))."</b> hari • FIN=<b>".h((string)max(1, (int)ceil(((int)$sla['FIN'])/1440)))."</b> hari. Hubungi <b>SYS</b> jika kebijakan perlu diubah.</div>
</div>";
}

// SLA stats — unified source-of-truth dari field departemen hasil proses operasional.
if (!($unifiedSla['available'] ?? false)) {
    echo "<div class='card'><span class='badge danger'>ERROR</span> ".h((string)($unifiedSla['reason']??'Tidak bisa hitung SLA'))."</div>";
    kpi_footer(); exit;
}
$stats=$unifiedSla['summary']??[];
$fmtDur=function(int $sec):string{ if($sec<0)$sec=0; $h=intdiv($sec,3600);$m=intdiv($sec%3600,60);return $h.'h '.str_pad((string)$m,2,'0',STR_PAD_LEFT).'m'; };
$fmtMetricDur=function(string $metric,int $sec)use($fmtDur):string{
    if($metric!=='FIN' && $metric!=='ACT_EXCHANGE') return $fmtDur($sec);
    $sec=max(0,$sec); $days=intdiv($sec,86400); $hours=intdiv($sec%86400,3600);
    return $days.' hari'.($hours>0?' '.$hours.' jam':'');
};
$fmtSla=function(string $metric)use($sla):string{
    if($metric==='FIN') return max(1,(int)ceil(((int)$sla['FIN'])/1440)).' hari';
    if($metric==='ACT_EXCHANGE') return max(1,min(7,(int)($sla['ACT_EXCHANGE_DAYS'] ?? ceil(((int)($sla['ACT_EXCHANGE']??10080))/1440)))).' hari';
    return (int)($sla[$metric]??0).' menit';
};
$fmtPct=function(int $ok,int $over):string{$t=$ok+$over;return $t>0?number_format(($ok/$t)*100,1,',','.').'%':'-';};
$metricOrder=['CRM','WQS','SCM','ACT_TAX','ACT_EXCHANGE','FIN'];

echo "<div class='card'><h3 class='kpi-section-title'>Ringkasan SLA Departemen</h3>";
echo "<div class='muted'>Sumber nilai: CRM <code>crm_duration_sec</code>, WQS <code>wqs_duration_sec</code>, SCM <code>scm_duration_sec</code>, ACT Faktur Pajak <code>act_tax_invoice_duration_sec</code>, ACT Tukar Faktur <code>act_exchange_duration_sec</code>/<b>hari kalender</b>, FIN <code>fin_duration_days</code>. Jika transaksi masih berjalan, age dihitung dari timestamp start masing-masing stage.</div>";
echo "<div class='table-wrap'><table><thead><tr><th>Dept</th><th>KPI</th><th>SLA</th><th>OK</th><th>Overdue</th><th>On-time %</th><th>Open Backlog</th><th>Avg Duration</th></tr></thead><tbody>";
foreach($metricOrder as $m){$r=$stats[$m]??['ok'=>0,'over'=>0,'open'=>0,'avg_sec'=>0];$ok=(int)$r['ok'];$over=(int)$r['over'];$open=(int)$r['open'];$avg=(int)$r['avg_sec'];$dept=kpi_do_sla_dept_v13($m);$badge=$over>0?'pill danger':'pill';echo "<tr><td><b>".h($dept)."</b></td><td>".h(kpi_do_sla_metric_label_v13($m))."</td><td style='text-align:right'>".h($fmtSla($m))."</td><td style='text-align:right'>".$ok."</td><td style='text-align:right'><span class='".h($badge)."'>".$over."</span></td><td style='text-align:right'>".h($fmtPct($ok,$over))."</td><td style='text-align:right'>".$open."</td><td style='text-align:right'>".h($fmtMetricDur($m,$avg))."</td></tr>";}
echo "</tbody></table></div><div class='muted'>ACT sengaja dipisah menjadi dua KPI. Faktur Pajak memakai menit dan berhenti hanya saat evidence upload Faktur Pajak valid; SLA Tukar Faktur dihitung dalam <b>hari kalender</b> seperti FIN dengan batas maksimal <b>7 hari</b>. Bukti Tukar Faktur tetap dapat berjalan setelah WAIT PAYMENT; jika DO sudah PAID tetapi bukti tukar faktur belum ada, dicatat sebagai SLA breach, bukan dianggap selesai.</div></div>";

// Staff / PIC — metric ACT tetap terpisah.
$counts=$unifiedSla['staff_counts']??[];
echo "<div class='card'><h3 class='kpi-section-title'>KPI SLA per Akun Staff / PIC</h3>";
echo "<div class='muted'>PIC CRM/WQS/SCM memakai actor tahap/fallback audit. Untuk ACT, PIC selesai wajib uploader evidence Faktur Pajak/Tukar Faktur; backlog tanpa uploader diberi label antrian dan tidak diwariskan ke actor ACT lain. FIN Queue berarti Tukar Faktur ACT sudah selesai dan transaksi sudah menjadi beban FIN, tetapi belum disentuh akun FIN personal. Transaksi historis yang benar-benar tidak menyimpan actor diberi label Historis - PIC tidak terekam, bukan dianggap akun staff.</div>";
echo "<div style='display:grid;grid-template-columns:repeat(5,minmax(110px,1fr));gap:12px;margin:14px 0'>";foreach(['CRM','WQS','SCM','ACT','FIN'] as $d){echo "<div style='background:rgba(255,255,255,.03);border:1px solid rgba(148,163,184,.22);border-radius:14px;padding:12px'><div class='muted'>".h($d)."</div><div style='font-size:28px;font-weight:900'>".(int)($counts[$d]??0)."</div><div class='muted'>staff terbaca</div></div>";}echo "</div>";
echo "<div class='table-wrap'><table><thead><tr><th>Dept</th><th>KPI</th><th>Staff / PIC</th><th>Total Handle</th><th>OK</th><th>Overdue</th><th>On-time %</th><th>Open Backlog</th><th>Avg Duration</th></tr></thead><tbody>";
$staffRows=$unifiedSla['staff_rows']??[];if(!$staffRows)echo "<tr><td colspan='9' class='muted'>Belum ada data staff pada periode/filter ini.</td></tr>";else foreach($staffRows as $r){$m=(string)$r['metric'];$total=(int)$r['total'];$ok=(int)$r['ok'];$over=(int)$r['over'];$open=(int)$r['open'];$avg=(int)($r['dur_cnt']>0?intdiv((int)$r['dur_sum'],(int)$r['dur_cnt']):0);$badge=$over>0?'pill danger':'pill';echo "<tr><td><b>".h((string)$r['dept'])."</b></td><td>".h(kpi_do_sla_metric_label_v13($m))."</td><td>".h((string)$r['staff'])."</td><td style='text-align:right'>".$total."</td><td style='text-align:right'>".$ok."</td><td style='text-align:right'><span class='".h($badge)."'>".$over."</span></td><td style='text-align:right'>".h($fmtPct($ok,$over))."</td><td style='text-align:right'>".$open."</td><td style='text-align:right'>".h($fmtMetricDur($m,$avg))."</td></tr>";}
echo "</tbody></table></div></div>";

// Overdue / breach preview menggunakan engine yang sama dengan ringkasan dan staff.
echo "<div class='card'><h3 class='kpi-section-title'>Overdue / Breach (Preview)</h3><div class='muted'>Satu sumber perhitungan dengan ringkasan dan staff. OPEN = backlog aktif; CLOSED = selesai tetapi melewati SLA / dokumen wajib hilang.</div>";
$ov=array_slice($unifiedSla['overdue']??[],0,50);if(!$ov){echo "<div class='muted'>Tidak ada overdue / breach pada filter ini.</div>";}else{echo "<div class='table-wrap'><table><thead><tr><th>Office</th><th>DO Code</th><th>Tanggal</th><th>Status</th><th>Dept</th><th>KPI</th><th>State</th><th>PIC</th><th>Age / Duration</th></tr></thead><tbody>";foreach($ov as $e){echo "<tr><td>".h((string)$e['office'])."</td><td>".h((string)$e['do_code'])."</td><td>".h((string)$e['date'])."</td><td>".h((string)$e['status'])."</td><td><span class='pill danger'>".h((string)$e['dept'])."</span></td><td>".h(kpi_do_sla_metric_label_v13((string)$e['metric']))."</td><td>".h($e['open']?'OPEN':'CLOSED')."</td><td>".h((string)$e['staff'])."</td><td style='text-align:right'>".h($fmtMetricDur((string)$e['metric'],(int)$e['duration_sec']))."</td></tr>";}echo "</tbody></table></div>";}echo "</div>";

kpi_footer();
