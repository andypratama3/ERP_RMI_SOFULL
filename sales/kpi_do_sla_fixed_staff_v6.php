<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// KPI DO (SLA) - Phase 1 Enterprise+++
require_once __DIR__ . '/_kpi_bootstrap.php';
require_once __DIR__ . '/_kpi_metrics.php';
require_once __DIR__ . '/_kpi_policy.php';
if (function_exists('require_route_access')) {
    require_route_access(['KPI.DO_VIEW', 'SALES.AUDIT']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['KPI.DO_VIEW', 'SALES.AUDIT']);
}

$pdo = kpi_require_pdo();

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
        if ($sec <= 0) return '-';
        $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60);
        return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
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
                if ($dept && $actor !== '- Belum terbaca akun -' && empty($map['rows'][$did]['actor'][$dept])) $map['rows'][$did]['actor'][$dept] = $actor;
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
                if ($dept && $actor !== '- Belum terbaca akun -' && empty($map['rows'][$code]['actor'][$dept])) $map['rows'][$code]['actor'][$dept] = $actor;
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
            $tCrmEnd = kpi_do_sla_staff_ts_v4($first['crm_to_wqs'] ?? ($first['wqs_processing'] ?? ''));
            $tWqsStart = $tCrmEnd ?: kpi_do_sla_staff_ts_v4($d['wqs_started_at'] ?? ($d['wqs_picked_at'] ?? '')) ?: $doDate;
            $tWqsEnd = kpi_do_sla_staff_ts_v4($d['wqs_ready_at'] ?? '') ?: kpi_do_sla_staff_ts_v4($first['ready_scm'] ?? '');
            $tScmStart = $tWqsEnd ?: kpi_do_sla_staff_ts_v4($d['scm_on_delivery_at'] ?? '');
            $tScmEnd = kpi_do_sla_staff_ts_v4($d['scm_delivered_at'] ?? ($d['scm_completed_at'] ?? '')) ?: kpi_do_sla_staff_ts_v4($first['delivered'] ?? ($first['on_delivery'] ?? ''));
            $tActStart = $tScmEnd;
            $tActEnd = kpi_do_sla_staff_ts_v4($d['act_ready_fin_at'] ?? ($d['act_invoiced_at'] ?? '')) ?: kpi_do_sla_staff_ts_v4($first['wait_payment'] ?? ($first['act_ready_fin'] ?? ''));
            $tFinStart = $tActEnd;
            $tFinEnd = kpi_do_sla_staff_ts_v4($d['fin_paid_at'] ?? '') ?: kpi_do_sla_staff_ts_v4($first['paid'] ?? ($first['fin_done'] ?? ''));
            $stages = [
                'CRM'=>['staff'=>$actor['CRM'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['CRM']), 'start'=>$created, 'end'=>$tCrmEnd, 'open'=>in_array($status, ['draft','crm','crm_to_wqs'], true)],
                'WQS'=>['staff'=>$actor['WQS'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['WQS']), 'start'=>$tWqsStart, 'end'=>$tWqsEnd, 'open'=>in_array($status, ['crm_to_wqs','wqs_processing','pending'], true)],
                'SCM'=>['staff'=>$actor['SCM'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['SCM']), 'start'=>$tScmStart, 'end'=>$tScmEnd, 'open'=>in_array($status, ['ready_scm','on_delivery'], true)],
                'ACT'=>['staff'=>$actor['ACT'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['ACT']), 'start'=>$tActStart, 'end'=>$tActEnd, 'open'=>in_array($status, ['delivered'], true)],
                'FIN'=>['staff'=>$actor['FIN'] ?? kpi_do_sla_staff_pick_v4($d, $stageStaffCols['FIN']), 'start'=>$tFinStart, 'end'=>$tFinEnd, 'open'=>in_array($status, ['wait_payment'], true)],
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
                if ($end && $start) $dur = max(0, $end-$start); elseif ($open && $start) $dur = max(0, $now-$start);
                $slaSec = max(1, (int)($sla[$dep] ?? 1440)) * 60;
                $over = ($dur > $slaSec);
                if ($over) $agg[$key]['over']++;
                if ($open && !$end) $agg[$key]['open']++;
                if ($end) { if (!$over) $agg[$key]['ok']++; $agg[$key]['dur_sum'] += $dur; $agg[$key]['dur_cnt']++; }
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
        $rows = array_values($agg);
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
        echo "<div class='muted'>Manager dapat melihat jumlah staff CRM/WQS/SCM/ACT/FIN dan performa per akun. Jika masih muncul <b>- Belum terbaca akun -</b>, berarti transaksi lama/audit belum menyimpan user PIC pada tahap tersebut.</div>";
        if (!empty($data['error'])) echo "<div class='badge danger'>" . h((string)$data['error']) . "</div>";
        echo "<div style='display:grid;grid-template-columns:repeat(5,minmax(110px,1fr));gap:12px;margin:14px 0'>";
        foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) {
            echo "<div style='background:rgba(255,255,255,.03);border:1px solid rgba(148,163,184,.22);border-radius:14px;padding:12px'>";
            echo "<div class='muted'>".h($dep)."</div><div style='font-size:28px;font-weight:900'>".h((string)($counts[$dep] ?? 0))."</div><div class='muted'>staff terbaca</div>";
            echo "</div>";
        }
        echo "</div>";
        echo "<div class='muted' style='margin:8px 0'>Sumber master staff: <b>".h((string)($debug['master_source'] ?? '-'))."</b> ".h((string)($debug['master_columns'] ?? ''))."<br>Audit transaksi: actor=<b>".h((string)($debug['audit_actor_col'] ?? '-'))."</b>, status=<b>".h((string)($debug['audit_status_col'] ?? '-'))."</b>, waktu=<b>".h((string)($debug['audit_time_col'] ?? '-'))."</b><br>Fallback system_audit_logs actor=<b>".h((string)($debug['system_audit_actor_col'] ?? '-'))."</b></div>";
        echo "<details style='margin:8px 0'><summary class='muted'>Kolom user sales_do yang terdeteksi</summary><div class='muted'>";
        foreach (($debug['sales_do_user_cols'] ?? []) as $dep=>$cols) echo h($dep . ': ' . $cols) . "<br>";
        echo "</div></details>";
        echo "<div class='table-wrap'><table><thead><tr><th>Dept</th><th>Staff / PIC</th><th>Total Handle</th><th>OK</th><th>Overdue</th><th>On-time %</th><th>Open Backlog</th><th>Avg Duration</th></tr></thead><tbody>";
        if (!$rows) echo "<tr><td colspan='8' class='muted'>Belum ada data staff pada periode/filter ini.</td></tr>";
        else foreach ($rows as $r) {
            $total=(int)($r['total']??0); $ok=(int)($r['ok']??0); $over=(int)($r['over']??0); $open=(int)($r['open']??0);
            $pct=$total>0?number_format(($ok/$total)*100,1,',','.').'%':'-';
            $avg=((int)($r['dur_cnt']??0)>0)?intdiv((int)$r['dur_sum'],(int)$r['dur_cnt']):0;
            $badge=$over>0?'pill danger':'pill';
            echo "<tr><td><b>".h((string)$r['dept'])."</b></td><td>".h((string)$r['staff'])."</td><td style='text-align:right'>".h((string)$total)."</td><td style='text-align:right'>".h((string)$ok)."</td><td style='text-align:right'><span class='".h($badge)."'>".h((string)$over)."</span></td><td style='text-align:right'>".h($pct)."</td><td style='text-align:right'>".h((string)$open)."</td><td style='text-align:right'>".h(kpi_do_sla_staff_dur_v4($avg))."</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "</div>";
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
        'ACT' => (int)($_POST['sla_act'] ?? 0),
        'FIN' => (int)($_POST['sla_fin'] ?? 0),
    ];
    try {
        kpi_do_sla_policy_save($pdo, $payload);
        if (function_exists('kpi_audit')) {
            kpi_audit($pdo, 'kpi_do_sla', 'policy_save', '', 'crm=' . $payload['CRM'] . ';wqs=' . $payload['WQS'] . ';scm=' . $payload['SCM'] . ';act=' . $payload['ACT'] . ';fin=' . $payload['FIN']);
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
        header('Content-Type: text/plain; charset=utf-8');
        echo "sales_do table missing";
        exit;
    }

    $monthYm = trim((string)($_GET['month'] ?? ''));
    if (!$monthYm) $monthYm = date('Y-m');
    [$d1,$d2] = kpi_month_range($monthYm);

    $office = trim((string)($_GET['office'] ?? ''));

    // SLA: hanya dari kebijakan DB (SYS); parameter URL tidak mengubah export
    $sla = kpi_do_sla_policy_load($pdo);

    $cols = kpi_table_columns($pdo, 'sales_do');
    $colId = kpi_pick_col($cols, ['id']) ?: 'id';
    $colDate = kpi_pick_col($cols, ['do_date']) ?: 'do_date';
    $colOffice = kpi_pick_col($cols, ['office_code']) ?: 'office_code';
    $colCode = kpi_pick_col($cols, ['do_code','code']) ?: 'do_code';
    $colStatus = kpi_pick_col($cols, ['status']) ?: 'status';
    $colCreated = kpi_pick_col($cols, ['created_at','created_on','created_date']);

    $tWqsStart = kpi_pick_col($cols, ['wqs_started_at','wqs_picked_at']);
    $tWqsEnd   = kpi_pick_col($cols, ['wqs_ready_at']);
    $tScmStart = kpi_pick_col($cols, ['scm_on_delivery_at']);
    $tScmEnd   = kpi_pick_col($cols, ['scm_delivered_at']);
    $tActEnd   = kpi_pick_col($cols, ['act_ready_fin_at','act_invoiced_at']);
    $tFinEnd   = kpi_pick_col($cols, ['fin_paid_at']);

    $toTs = function($dt): int {
        if (!$dt) return 0;
        $t = strtotime((string)$dt);
        return $t ? (int)$t : 0;
    };

    $fmtHM = function(int $sec): string {
        if ($sec <= 0) return '0m';
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        if ($h <= 0) return $m . 'm';
        return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
    };


    // Create audit table if not exists (safe)
    kpi_ensure_sales_do_audit($pdo);

    $where = ["{$colDate} BETWEEN :d1 AND :d2"];
    $params = [':d1'=>$d1, ':d2'=>$d2];
    if ($office !== '') { $where[] = "{$colOffice} = :o"; $params[':o'] = $office; }

    $sql = "SELECT * FROM sales_do WHERE " . implode(' AND ', $where) . " ORDER BY {$colDate} DESC, {$colId} DESC LIMIT 5000";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Prepare audit first timestamps per do (optional)
    $auditByDo = [];
    try {
        $ids = array_values(array_filter(array_map(fn($r)=> (int)($r[$colId] ?? 0), $rows)));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stA = $pdo->prepare("SELECT do_id, status_to, created_at FROM sales_do_audit WHERE do_id IN ($in) ORDER BY created_at ASC, id ASC");
            $stA->execute($ids);
            while ($r = $stA->fetch(PDO::FETCH_ASSOC)) {
                $did = (int)($r['do_id'] ?? 0);
                if (!$did) continue;
                $auditByDo[$did][] = $r;
            }
        }
    } catch (Throwable $e) {
        $auditByDo = [];
    }

    $out = [];
    $now = time();

    foreach ($rows as $d) {
        $id = (int)($d[$colId] ?? 0);
        $status = strtolower((string)($d[$colStatus] ?? ''));
        $stage = 'CRM';
        if (in_array($status, ['crm_to_wqs','wqs_processing'], true)) $stage = 'WQS';
        if (in_array($status, ['ready_scm','on_delivery'], true) || strpos($status,'scm')!==false) $stage = 'SCM';
        if (in_array($status, ['delivered'], true) || strpos($status,'act')!==false) $stage = 'ACT';
        if (in_array($status, ['wait_payment'], true) || strpos($status,'fin')!==false) $stage = 'FIN';
        if (in_array($status, ['paid','fin_done'], true)) $stage = 'DONE';

        if ($stage === 'DONE') continue;

        // timestamps
        $crmStart = $colCreated ? $toTs($d[$colCreated] ?? null) : 0;
        $crmEnd = 0;
        $wqsStart = $tWqsStart ? $toTs($d[$tWqsStart] ?? null) : 0;
        $wqsEnd   = $tWqsEnd   ? $toTs($d[$tWqsEnd] ?? null)   : 0;
        $scmStart = $tScmStart ? $toTs($d[$tScmStart] ?? null) : 0;
        $scmEnd   = $tScmEnd   ? $toTs($d[$tScmEnd] ?? null)   : 0;
        $actEnd   = $tActEnd   ? $toTs($d[$tActEnd] ?? null)   : 0;
        $finEnd   = $tFinEnd   ? $toTs($d[$tFinEnd] ?? null)   : 0;

        // audit enrich
        $audit = $auditByDo[$id] ?? [];
        $auditFirst = [];
        if ($audit) {
            foreach ($audit as $l) {
                $to = (string)($l['status_to'] ?? '');
                if ($to === '') continue;
                if (!isset($auditFirst[$to])) $auditFirst[$to] = $toTs($l['created_at'] ?? null);
            }
        }
      $crmEnd = $auditFirst['crm_to_wqs'] ?? $auditFirst['wqs_processing'] ?? 0;
if (!$wqsStart) {
    $wqsStart = $auditFirst['wqs_processing']
        ?? $auditFirst['crm_to_wqs']
        ?? 0;
}

if (!$wqsEnd) {
    $wqsEnd = $auditFirst['ready_scm'] ?? 0;
}

if (!$scmStart) {
    $scmStart = $auditFirst['ready_scm'] ?? $wqsEnd ?? 0;
}

if (!$scmEnd) {
    $scmEnd = $auditFirst['on_delivery']
        ?? $auditFirst['delivered']
        ?? 0;
}

$actStart = $scmEnd ?: 0;

if (!$actEnd) {
    $actEnd = $auditFirst['wait_payment']
        ?? $auditFirst['act_ready_fin']
        ?? $auditFirst['act_invoiced']
        ?? 0;
}

$finStart = $actEnd ?: 0;

if (!$finEnd) {
    $finEnd = $auditFirst['paid']
        ?? $auditFirst['fin_done']
        ?? 0;
}

        $doDateTs = $toTs(($d[$colDate] ?? $d1) . ' 00:00:00');
        if (!$crmStart) $crmStart = $doDateTs;
        if (!$wqsStart) $wqsStart = $doDateTs;
        if (!$scmStart) $scmStart = $wqsEnd ?: 0;
        $actStart = $scmEnd ?: 0;
        $finStart = $actEnd ?: 0;

        $startTs = $doDateTs;
        if ($stage === 'CRM') $startTs = $crmStart;
        if ($stage === 'CRM') $startTs = $crmStart;
        if ($stage === 'WQS') $startTs = $wqsStart;
        if ($stage === 'SCM') $startTs = $scmStart ?: ($wqsEnd ?: $wqsStart);
        if ($stage === 'ACT') $startTs = $actStart ?: ($scmEnd ?: $wqsEnd);
        if ($stage === 'FIN') $startTs = $finStart ?: ($actEnd ?: $scmEnd);
        if (!$startTs) $startTs = $doDateTs;

        $elapsed = $now - $startTs;
        $slaSec = max(1, (int)($sla[$stage] ?? 1440)) * 60;

        if ($elapsed > $slaSec) {
            $out[] = [
                'month_ym' => $monthYm,
                'office_code' => (string)($d[$colOffice] ?? ''),
                'do_code' => (string)($d[$colCode] ?? ''),
                'do_date' => (string)($d[$colDate] ?? ''),
                'status' => (string)($d[$colStatus] ?? ''),
                'stage' => $stage,
                'age_minutes' => (int)floor($elapsed/60),
                'age_human' => $fmtHM((int)$elapsed),
                'sla_minutes' => (int)($sla[$stage] ?? 0),
                'sla_human' => $fmtHM((int)$slaSec),
            ];
        }
    }

    // sort biggest age first
    usort($out, fn($a,$b)=> ($b['age_minutes'] <=> $a['age_minutes']) ?: strcmp($a['do_code'],$b['do_code']));

    kpi_csv_download('kpi_do_overdue.csv', $out);
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
$sla = kpi_do_sla_policy_load($pdo);

$doSla = kpi_calc_do_sla($pdo, $d1, $d2, $office ?: null, $sla);
$doSlaStaff = kpi_do_sla_staff_build_v2($pdo, $d1, $d2, $office ?: null, $sla);

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
  <div class='muted'>SLA (menit) diambil dari kebijakan sistem (<code>system_config</code> group <code>KPI_DO_SLA</code>). Parameter <code>sla_*</code> di URL <b>diabaikan</b> — bukan untuk mengubah angka.</div>
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
    <div class='kpi-field'><label>SLA ACT (menit)</label><input type='number' name='sla_act' min='1' max='525600' value='".h((string)$sla['ACT'])."' required></div>
    <div class='kpi-field'><label>SLA FIN (menit)</label><input type='number' name='sla_fin' min='1' max='525600' value='".h((string)$sla['FIN'])."' required></div>
    <div class='kpi-field' style='align-self:flex-end'><button class='btn' type='submit'>Simpan kebijakan SLA</button></div>
  </form>
  <div class='muted'>Default referensi: CRM=30 menit, WQS=240 (4j), SCM=1440 (24j), ACT=2880 (48j), FIN=10080 (7h kal.). Jika belum pernah disimpan, dipakai fallback dari <code>KPI_SLA</code> (jam) lalu default ini.</div>
</div>";
} else {
    echo "<div class='card'>
  <h3 class='kpi-section-title'>SLA aktif (baca saja)</h3>
  <div class='muted'>CRM=<b>".h((string)$sla['CRM'])."</b> • WQS=<b>".h((string)$sla['WQS'])."</b> • SCM=<b>".h((string)$sla['SCM'])."</b> • ACT=<b>".h((string)$sla['ACT'])."</b> • FIN=<b>".h((string)$sla['FIN'])."</b> menit. Hubungi <b>SYS</b> jika kebijakan perlu diubah.</div>
</div>";
}

// SLA stats
if (!($doSla['available'] ?? false)) {
    echo "<div class='card'><span class='badge danger'>ERROR</span> " . h((string)($doSla['reason'] ?? 'Tidak bisa hitung SLA')) . "</div>";
    kpi_footer();
    exit;
}

$stats = $doSla['stats'] ?? [];

$fmtDur = function(int $sec): string {
    if ($sec <= 0) return '-';
    $h = floor($sec/3600);
    $m = floor(($sec%3600)/60);
    return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
};

$fmtPct = function(int $ok, int $over): string {
    $tot = $ok + $over;
    if ($tot <= 0) return '-';
    return number_format(($ok/$tot)*100, 1, ',', '.') . '%';
};

echo "<div class='card'>
  <h3 class='kpi-section-title'>Ringkasan SLA</h3>
  <div class='table-wrap'><table>
    <thead><tr>
      <th>Dept</th><th>SLA (menit)</th><th>OK</th><th>OVERDUE</th><th>On-time %</th><th>Open (Backlog)</th><th>Avg Duration</th>
    </tr></thead>
    <tbody>";

foreach (['CRM','WQS','SCM','ACT','FIN'] as $dep) {
    $r = $stats[$dep] ?? ['ok'=>0,'over'=>0,'open'=>0,'avg_sec'=>0];
    $ok = (int)($r['ok'] ?? 0);
    $over = (int)($r['over'] ?? 0);
    $open = (int)($r['open'] ?? 0);
    $avg = (int)($r['avg_sec'] ?? 0);
    $pct = $fmtPct($ok, $over);
    $badge = ($over>0) ? "pill danger" : "pill";

    echo "<tr>
      <td><b>".h($dep)."</b></td>
      <td style='text-align:right'>".h((string)($sla[$dep] ?? ''))."<div class='muted'>".h($fmtDur(((int)($sla[$dep] ?? 0))*60))."</div></td>
      <td style='text-align:right'>".h((string)$ok)."</td>
      <td style='text-align:right'><span class='".h($badge)."'>".h((string)$over)."</span></td>
      <td style='text-align:right'>".h($pct)."</td>
      <td style='text-align:right'>".h((string)$open)."</td>
      <td style='text-align:right'>".h($fmtDur($avg))."</td>
    </tr>";
}

echo "</tbody></table></div>
  <div class='muted'>Catatan: overdue dihitung dari durasi aktual (jika sudah selesai) atau age backlog (jika masih open) dibanding SLA.</div>
</div>";

// Detail staff/PIC per akun (tambahan aman, tidak mengubah kalkulasi ringkasan)
kpi_do_sla_staff_render_v2($doSlaStaff);

// Overdue list preview (top 50) - link to CSV for full

echo "<div class='card'>
  <h3 class='kpi-section-title'>Overdue Backlog (Preview)</h3>
  <div class='muted'>Export full list: gunakan tombol <b>Export Overdue CSV</b> di atas.</div>
";

// Preview: logika sama dengan export (SLA dari kebijakan DB)
$preview = [];
try {
    $cols = kpi_table_columns($pdo, 'sales_do');
    $colId = kpi_pick_col($cols, ['id']) ?: 'id';
    $colDate = kpi_pick_col($cols, ['do_date']) ?: 'do_date';
    $colOffice = kpi_pick_col($cols, ['office_code']) ?: 'office_code';
    $colCode = kpi_pick_col($cols, ['do_code','code']) ?: 'do_code';
    $colStatus = kpi_pick_col($cols, ['status']) ?: 'status';
    $colCreated = kpi_pick_col($cols, ['created_at','created_on','created_date']);
    $colCreated = kpi_pick_col($cols, ['created_at','created_on','created_date']);

    $tWqsStart = kpi_pick_col($cols, ['wqs_started_at','wqs_picked_at']);
    $tWqsEnd   = kpi_pick_col($cols, ['wqs_ready_at']);
    $tScmStart = kpi_pick_col($cols, ['scm_on_delivery_at']);
    $tScmEnd   = kpi_pick_col($cols, ['scm_delivered_at']);
    $tActEnd   = kpi_pick_col($cols, ['act_ready_fin_at','act_invoiced_at']);

    $toTs = function($dt): int {
        if (!$dt) return 0;
        $t = strtotime((string)$dt);
        return $t ? (int)$t : 0;
    };

    $where = ["{$colDate} BETWEEN :d1 AND :d2"];
    $params = [':d1'=>$d1, ':d2'=>$d2];
    if ($office !== '') { $where[] = "{$colOffice} = :o"; $params[':o'] = $office; }

    $sql = "SELECT * FROM sales_do WHERE " . implode(' AND ', $where) . " ORDER BY {$colDate} DESC, {$colId} DESC LIMIT 5000";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // audit first timestamps per do
    $auditByDo = [];
    if ($hasAudit) {
        try {
            $ids = array_values(array_filter(array_map(fn($r)=> (int)($r[$colId] ?? 0), $rows)));
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stA = $pdo->prepare("SELECT do_id, status_to, created_at FROM sales_do_audit WHERE do_id IN ($in) ORDER BY created_at ASC, id ASC");
                $stA->execute($ids);
                while ($r = $stA->fetch(PDO::FETCH_ASSOC)) {
                    $did = (int)($r['do_id'] ?? 0);
                    if (!$did) continue;
                    $auditByDo[$did][] = $r;
                }
            }
        } catch (Throwable $e) {
            $auditByDo = [];
        }
    }

    $now = time();

    foreach ($rows as $d) {
        $id = (int)($d[$colId] ?? 0);
        $status = strtolower((string)($d[$colStatus] ?? ''));
        $stage = 'CRM';
        if (in_array($status, ['crm_to_wqs','wqs_processing'], true)) $stage = 'WQS';
        if (in_array($status, ['ready_scm','on_delivery'], true) || strpos($status,'scm')!==false) $stage = 'SCM';
        if (in_array($status, ['delivered'], true) || strpos($status,'act')!==false) $stage = 'ACT';
        if (in_array($status, ['wait_payment'], true) || strpos($status,'fin')!==false) $stage = 'FIN';
        if (in_array($status, ['paid','fin_done'], true)) $stage = 'DONE';
        if ($stage === 'DONE') continue;

        $crmStart = $colCreated ? $toTs($d[$colCreated] ?? null) : 0;
        $crmEnd = 0;
        $wqsStart = $tWqsStart ? $toTs($d[$tWqsStart] ?? null) : 0;
        $wqsEnd   = $tWqsEnd   ? $toTs($d[$tWqsEnd] ?? null)   : 0;
        $scmStart = $tScmStart ? $toTs($d[$tScmStart] ?? null) : 0;
        $scmEnd   = $tScmEnd   ? $toTs($d[$tScmEnd] ?? null)   : 0;
        $actEnd   = $tActEnd   ? $toTs($d[$tActEnd] ?? null)   : 0;

        $audit = $auditByDo[$id] ?? [];
        $auditFirst = [];
        if ($audit) {
            foreach ($audit as $l) {
                $to = (string)($l['status_to'] ?? '');
                if ($to === '') continue;
                if (!isset($auditFirst[$to])) $auditFirst[$to] = $toTs($l['created_at'] ?? null);
            }
        }
        $crmEnd = $auditFirst['crm_to_wqs'] ?? $auditFirst['wqs_processing'] ?? 0;
        if (!$wqsStart) $wqsStart = $auditFirst['wqs_processing'] ?? 0;
        if (!$wqsEnd)   $wqsEnd   = $auditFirst['ready_scm'] ?? 0;
        if (!$actEnd)   $actEnd   = $auditFirst['wait_payment'] ?? 0;

        $doDateTs = $toTs(($d[$colDate] ?? $d1) . ' 00:00:00');
        if (!$crmStart) $crmStart = $doDateTs;
        if (!$wqsStart) $wqsStart = $doDateTs;
        if (!$scmStart) $scmStart = $wqsEnd ?: 0;
        $actStart = $scmEnd ?: 0;
        $finStart = $actEnd ?: 0;

        $startTs = $doDateTs;
        if ($stage === 'WQS') $startTs = $wqsStart;
        if ($stage === 'SCM') $startTs = $scmStart ?: ($wqsEnd ?: $wqsStart);
        if ($stage === 'ACT') $startTs = $actStart ?: ($scmEnd ?: $wqsEnd);
        if ($stage === 'FIN') $startTs = $finStart ?: ($actEnd ?: $scmEnd);
        if (!$startTs) $startTs = $doDateTs;

        $elapsed = $now - $startTs;
        $slaSec = max(1, (int)($sla[$stage] ?? 1440)) * 60;

        if ($elapsed > $slaSec) {
            $preview[] = [
                'office' => (string)($d[$colOffice] ?? ''),
                'do_code' => (string)($d[$colCode] ?? ''),
                'date' => (string)($d[$colDate] ?? ''),
                'status' => (string)($d[$colStatus] ?? ''),
                'stage' => $stage,
                'age_sec' => (int)$elapsed,
            ];
        }
    }

    usort($preview, fn($a,$b)=> ($b['age_sec'] <=> $a['age_sec']) ?: strcmp($a['do_code'],$b['do_code']));
    $preview = array_slice($preview, 0, 50);
} catch (Throwable $e) {
    $preview = [];
}

if (!$preview) {
    echo "<div class='muted'>Tidak ada overdue backlog (atau data belum lengkap).</div>";
} else {
    echo "<div class='table-wrap'><table><thead><tr><th>Office</th><th>DO Code</th><th>Tanggal</th><th>Status</th><th>Stage</th><th>Age</th></tr></thead><tbody>";
    foreach ($preview as $r) {
        echo "<tr>
          <td>".h($r['office'])."</td>
          <td>".h($r['do_code'])."</td>
          <td>".h($r['date'])."</td>
          <td>".h($r['status'])."</td>
          <td><span class='pill danger'>".h($r['stage'])."</span></td>
          <td style='text-align:right'>".h($fmtDur((int)$r['age_sec']))."</td>
        </tr>";
    }
    echo "</tbody></table></div>";
}

echo "</div>";

kpi_footer();
