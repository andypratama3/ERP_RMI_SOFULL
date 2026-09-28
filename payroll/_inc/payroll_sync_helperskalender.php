<?php
/**
 * Shared payroll synchronization helpers.
 * Tidak mengubah transaksi sumber. Hanya membaca absensi/HRL/settings dan
 * mengembalikan snapshot yang akan disimpan ke payroll_run_items.
 */

declare(strict_types=1);

if (!function_exists('rmi_payroll_table_exists')) {
    function rmi_payroll_table_exists(PDO $pdo, string $table): bool {
        try {
            $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('rmi_payroll_col_exists')) {
    function rmi_payroll_col_exists(PDO $pdo, string $table, string $col): bool {
        try {
            $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
            $st->execute([$table, $col]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('rmi_payroll_first_col')) {
    function rmi_payroll_first_col(PDO $pdo, string $table, array $candidates): string {
        foreach ($candidates as $col) {
            if (rmi_payroll_col_exists($pdo, $table, (string)$col)) return (string)$col;
        }
        return '';
    }
}



if (!function_exists('rmi_payroll_login_user_id')) {
    function rmi_payroll_login_user_id(PDO $pdo, int $loginUserId, int $employeeId = 0): int {
        if ($employeeId <= 0 || !rmi_payroll_table_exists($pdo, 'master_employees') || !rmi_payroll_table_exists($pdo, 'master_system_login')) {
            return $loginUserId > 0 ? $loginUserId : 0;
        }

        try {
            $st = $pdo->prepare("SELECT employee_code FROM master_employees WHERE id=? LIMIT 1");
            $st->execute([$employeeId]);
            $code = trim((string)($st->fetchColumn() ?: ''));
            if ($code === '' || !rmi_payroll_col_exists($pdo, 'master_system_login', 'holder_employee_code')) {
                return $loginUserId > 0 ? $loginUserId : 0;
            }

            // Jangan langsung mempercayai login_user_id lama. Validasi bahwa akun
            // tersebut benar-benar dimiliki employee_code yang sama.
            if ($loginUserId > 0) {
                $sqlCurrent = "SELECT COUNT(*) FROM master_system_login WHERE id=? AND UPPER(TRIM(holder_employee_code))=UPPER(TRIM(?))";
                if (rmi_payroll_col_exists($pdo, 'master_system_login', 'deleted_at')) {
                    $sqlCurrent .= " AND deleted_at IS NULL";
                }
                $stCurrent = $pdo->prepare($sqlCurrent);
                $stCurrent->execute([$loginUserId, $code]);
                if ((int)$stCurrent->fetchColumn() > 0) {
                    return $loginUserId;
                }
            }

            // Jika kosong atau salah mapping, cari akun berdasarkan employee_code.
            $sql = "SELECT id FROM master_system_login WHERE UPPER(TRIM(holder_employee_code))=UPPER(TRIM(?))";
            if (rmi_payroll_col_exists($pdo, 'master_system_login', 'deleted_at')) {
                $sql .= " AND deleted_at IS NULL";
            }
            $sql .= " ORDER BY id DESC LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([$code]);
            return (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return $loginUserId > 0 ? $loginUserId : 0;
        }
    }
}

if (!function_exists('rmi_payroll_username')) {
    function rmi_payroll_username(PDO $pdo, int $loginUserId, int $employeeId = 0): string {
        if (!rmi_payroll_table_exists($pdo, 'master_system_login')) return '';
        try {
            if ($loginUserId > 0) {
                $st = $pdo->prepare("SELECT username FROM master_system_login WHERE id=? LIMIT 1");
                $st->execute([$loginUserId]);
                $u = trim((string)($st->fetchColumn() ?: ''));
                if ($u !== '') return $u;
            }
            if ($employeeId > 0 && rmi_payroll_table_exists($pdo, 'master_employees')) {
                $st = $pdo->prepare("SELECT employee_code FROM master_employees WHERE id=? LIMIT 1");
                $st->execute([$employeeId]);
                $code = trim((string)($st->fetchColumn() ?: ''));
                if ($code !== '' && rmi_payroll_col_exists($pdo, 'master_system_login', 'holder_employee_code')) {
                    $sql = "SELECT username FROM master_system_login WHERE holder_employee_code=?";
                    if (rmi_payroll_col_exists($pdo, 'master_system_login', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
                    $sql .= " LIMIT 1";
                    $st = $pdo->prepare($sql);
                    $st->execute([$code]);
                    return trim((string)($st->fetchColumn() ?: ''));
                }
            }
        } catch (Throwable $e) {}
        return '';
    }
}

if (!function_exists('rmi_payroll_overlap_workdays')) {
    function rmi_payroll_overlap_workdays(?string $from, ?string $to, string $start, string $end): int {
        if (!$from) return 0;
        $to = $to ?: $from;
        $a = strtotime($from); $b = strtotime($to); $s = strtotime($start); $e = strtotime($end);
        if ($a === false || $b === false || $s === false || $e === false) return 0;
        $a = max($a, $s); $b = min($b, $e);
        if ($b < $a) return 0;
        $n = 0;
        for ($t = $a; $t <= $b; $t = strtotime('+1 day', $t)) {
            if ((int)date('N', $t) <= 5) $n++;
        }
        return $n;
    }
}

if (!function_exists('rmi_payroll_request_days')) {
    function rmi_payroll_request_days(PDO $pdo, string $table, string $usernameCol, string $username, array $types, array $statuses, string $start, string $end): int {
        if ($username === '' || !$types || !rmi_payroll_table_exists($pdo, $table)) return 0;
        foreach ([$usernameCol, 'start_date', 'status'] as $col) if (!rmi_payroll_col_exists($pdo, $table, $col)) return 0;
        $typeCol = rmi_payroll_first_col($pdo, $table, ['req_type','request_type','type']);
        if ($typeCol === '') return 0;
        $endCol = rmi_payroll_col_exists($pdo, $table, 'end_date') ? 'end_date' : 'start_date';
        $deleted = rmi_payroll_col_exists($pdo, $table, 'deleted_at') ? " AND deleted_at IS NULL" : '';
        try {
            $tp = implode(',', array_fill(0, count($types), '?'));
            $sp = implode(',', array_fill(0, count($statuses), '?'));
            $sql = "SELECT start_date, {$endCol} AS end_date FROM `{$table}`
                    WHERE `{$usernameCol}`=? AND UPPER(`{$typeCol}`) IN ({$tp})
                      AND UPPER(status) IN ({$sp}) {$deleted}
                      AND start_date IS NOT NULL
                      AND COALESCE(`{$endCol}`,start_date)>=? AND start_date<=?";
            $params = array_merge([$username], array_map('strtoupper', $types), array_map('strtoupper', $statuses), [$start, $end]);
            $st = $pdo->prepare($sql); $st->execute($params);
            $n = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $n += rmi_payroll_overlap_workdays($r['start_date'] ?? null, $r['end_date'] ?? null, $start, $end);
            }
            return $n;
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('rmi_payroll_leave_days')) {
    function rmi_payroll_leave_days(PDO $pdo, int $employeeId, string $start, string $end): int {
        if ($employeeId <= 0 || !rmi_payroll_table_exists($pdo, 'hrl_leave_usages')) return 0;
        try {
            $st = $pdo->prepare("SELECT start_date,end_date,days FROM hrl_leave_usages WHERE employee_id=? AND start_date IS NOT NULL AND COALESCE(end_date,start_date)>=? AND start_date<=?");
            $st->execute([$employeeId,$start,$end]);
            $n=0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = rmi_payroll_overlap_workdays($r['start_date'] ?? null,$r['end_date'] ?? null,$start,$end);
                $n += $d > 0 ? $d : max(0,(int)round((float)($r['days'] ?? 0)));
            }
            return $n;
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('rmi_payroll_manual_count')) {
    function rmi_payroll_manual_count(PDO $pdo, int $employeeId, int $loginUserId, string $username, string $start, string $end, array $statuses, ?string $office): int {
        $table='absensi_manual_attendance';
        if (!rmi_payroll_table_exists($pdo,$table) || !rmi_payroll_col_exists($pdo,$table,'tanggal') || !rmi_payroll_col_exists($pdo,$table,'status')) return 0;
        $ids=[]; $idParams=[];
        if ($employeeId>0 && rmi_payroll_col_exists($pdo,$table,'employee_id')) { $ids[]='employee_id=?'; $idParams[]=$employeeId; }
        if ($loginUserId>0 && rmi_payroll_col_exists($pdo,$table,'user_id')) { $ids[]='user_id=?'; $idParams[]=$loginUserId; }
        if ($username!=='' && rmi_payroll_col_exists($pdo,$table,'username')) { $ids[]='username=?'; $idParams[]=$username; }
        if (!$ids) return 0;
        try {
            $sp=implode(',',array_fill(0,count($statuses),'?'));
            $sql="SELECT COUNT(DISTINCT tanggal) FROM {$table} WHERE tanggal BETWEEN ? AND ? AND UPPER(status) IN ({$sp}) AND (".implode(' OR ',$ids).")";
            $params=array_merge([$start,$end],array_map('strtoupper',$statuses),$idParams);
            if (rmi_payroll_col_exists($pdo,$table,'deleted_at')) $sql.=" AND deleted_at IS NULL";
            // Koreksi manual diikat ke employee/user, bukan office saat ini.
            $st=$pdo->prepare($sql); $st->execute($params); return (int)$st->fetchColumn();
        } catch(Throwable $e){ return 0; }
    }
}


if (!function_exists('rmi_payroll_direct_attendance')) {
    function rmi_payroll_direct_attendance(PDO $pdo, int $loginUserId, string $start, string $end): array {
        $out = ['present'=>0, 'checkout'=>0, 'has_logs'=>0];
        if ($loginUserId <= 0 || !rmi_payroll_table_exists($pdo, 'absensi_logs')) return $out;
        try {
            $sql = "SELECT
                        COUNT(DISTINCT CASE WHEN UPPER(TRIM(action_type))='IN' AND WEEKDAY(DATE(created_at))<5 THEN DATE(created_at) END) AS present_days,
                        COUNT(DISTINCT CASE WHEN UPPER(TRIM(action_type))='OUT' AND WEEKDAY(DATE(created_at))<5 THEN DATE(created_at) END) AS checkout_days,
                        COUNT(*) AS total_logs
                    FROM absensi_logs
                    WHERE user_id=?
                      AND created_at>=?
                      AND created_at<DATE_ADD(?, INTERVAL 1 DAY)";
            if (rmi_payroll_col_exists($pdo, 'absensi_logs', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            $st = $pdo->prepare($sql);
            $st->execute([$loginUserId, $start . ' 00:00:00', $end . ' 00:00:00']);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['present'] = max(0, (int)($r['present_days'] ?? 0));
            $out['checkout'] = max(0, (int)($r['checkout_days'] ?? 0));
            $out['has_logs'] = ((int)($r['total_logs'] ?? 0) > 0) ? 1 : 0;
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('rmi_payroll_effective_present_days')) {
    function rmi_payroll_effective_present_days(PDO $pdo, int $employeeId, int $loginUserId, string $username, string $start, string $end): int {
        $parts = [];
        $params = [];

        if ($loginUserId > 0 && rmi_payroll_table_exists($pdo, 'absensi_logs')) {
            $sql = "SELECT DATE(created_at) AS tanggal FROM absensi_logs
                    WHERE user_id=?
                      AND UPPER(TRIM(action_type))='IN'
                      AND WEEKDAY(DATE(created_at))<5
                      AND created_at>=?
                      AND created_at<DATE_ADD(?, INTERVAL 1 DAY)";
            if (rmi_payroll_col_exists($pdo, 'absensi_logs', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            $parts[] = $sql;
            array_push($params, $loginUserId, $start . ' 00:00:00', $end . ' 00:00:00');
        }

        $table = 'absensi_manual_attendance';
        if (rmi_payroll_table_exists($pdo, $table)) {
            $ids = [];
            $idParams = [];
            if ($employeeId > 0 && rmi_payroll_col_exists($pdo, $table, 'employee_id')) { $ids[] = 'employee_id=?'; $idParams[] = $employeeId; }
            if ($loginUserId > 0 && rmi_payroll_col_exists($pdo, $table, 'user_id')) { $ids[] = 'user_id=?'; $idParams[] = $loginUserId; }
            if ($username !== '' && rmi_payroll_col_exists($pdo, $table, 'username')) { $ids[] = 'username=?'; $idParams[] = $username; }
            if ($ids) {
                $sql = "SELECT tanggal FROM {$table}
                        WHERE tanggal BETWEEN ? AND ?
                          AND WEEKDAY(tanggal)<5
                          AND UPPER(status) IN ('SERVER_DOWN','HADIR_MANUAL')
                          AND (" . implode(' OR ', $ids) . ")";
                if (rmi_payroll_col_exists($pdo, $table, 'deleted_at')) $sql .= " AND deleted_at IS NULL";
                $parts[] = $sql;
                array_push($params, $start, $end, ...$idParams);
            }
        }

        if (!$parts) return 0;
        try {
            $st = $pdo->prepare("SELECT COUNT(DISTINCT tanggal) FROM (" . implode(" UNION ", $parts) . ") effective_present");
            $st->execute($params);
            return max(0, (int)$st->fetchColumn());
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('rmi_payroll_attendance_snapshot')) {
    function rmi_payroll_attendance_snapshot(PDO $pdo, int $loginUserId, int $employeeId, string $start, string $end, int $workDays, ?string $office=null): array {
        $resolvedLoginUserId = rmi_payroll_login_user_id($pdo, $loginUserId, $employeeId);
        $base=['present'=>0,'leave'=>0,'izin'=>0,'sick'=>0,'late'=>0,'source_found'=>0];

        // Pertahankan helper resmi untuk cuti/izin/sakit/telat, tetapi jangan batasi hadir berdasarkan office.
        if ($resolvedLoginUserId>0 && function_exists('payroll_get_absensi_summary')) {
            try { $base=array_merge($base,(array)payroll_get_absensi_summary($pdo,$resolvedLoginUserId,$start,$end,null)); } catch(Throwable $e){}
        }

        // Sumber hadir utama: tanggal unik action_type=IN pada absensi_logs.
        $direct = rmi_payroll_direct_attendance($pdo, $resolvedLoginUserId, $start, $end);
        $username=rmi_payroll_username($pdo,$resolvedLoginUserId,$employeeId);
        $approved=['APPROVED','HRL_APPROVED','FIN_APPROVED','PAID','DONE'];
        $leave=max((int)($base['leave']??0),rmi_payroll_leave_days($pdo,$employeeId,$start,$end),rmi_payroll_request_days($pdo,'hrl_requests','created_by',$username,['CUTI'],$approved,$start,$end),rmi_payroll_request_days($pdo,'absensi_requests','username',$username,['CUTI','LEAVE'],$approved,$start,$end));
        $izin=max((int)($base['izin']??0),rmi_payroll_request_days($pdo,'hrl_requests','created_by',$username,['IZIN'],$approved,$start,$end),rmi_payroll_request_days($pdo,'absensi_requests','username',$username,['IZIN','DINAS'],$approved,$start,$end));
        $sick=max((int)($base['sick']??0),rmi_payroll_request_days($pdo,'hrl_requests','created_by',$username,['SAKIT'],$approved,$start,$end),rmi_payroll_request_days($pdo,'absensi_requests','username',$username,['SAKIT','SICK'],$approved,$start,$end));
        $manualPresent=rmi_payroll_manual_count($pdo,$employeeId,$resolvedLoginUserId,$username,$start,$end,['SERVER_DOWN','HADIR_MANUAL'],$office);
        $manualAlpha=rmi_payroll_manual_count($pdo,$employeeId,$resolvedLoginUserId,$username,$start,$end,['ALPA'],$office);

        // Hitung gabungan tanggal hadir normal + koreksi SERVER_DOWN/HADIR_MANUAL.
        // UNION DISTINCT mencegah tanggal yang sama dihitung dua kali.
        $effectivePresent = rmi_payroll_effective_present_days($pdo,$employeeId,$resolvedLoginUserId,$username,$start,$end);
        $present=max((int)($base['present']??0),(int)($direct['present']??0),$effectivePresent);
        $late=max((int)($base['late']??0),(int)($base['late_count']??0));
        $source=(int)($base['source_found']??0);
        if ((int)($direct['has_logs']??0)>0 || $present>0 || $leave>0 || $izin>0 || $sick>0 || $late>0 || $manualAlpha>0) $source=1;

        $present=max(0,min($workDays,$present));
        $remaining=max(0,$workDays-$present);
        $leave=max(0,min($remaining,$leave)); $remaining-=$leave;
        $izin=max(0,min($remaining,$izin)); $remaining-=$izin;
        $sick=max(0,min($remaining,$sick)); $remaining-=$sick;

        // Alpa hanya dari sumber ALPA resmi/manual. Sisa hari tidak otomatis menjadi alpa.
        $absent=max(0,min($remaining,$manualAlpha));

        return [
            'present'=>$present,'leave'=>$leave,'izin'=>$izin,'sick'=>$sick,
            'absent'=>$absent,'late'=>$late,'source_found'=>$source,
            'username'=>$username,'login_user_id'=>$resolvedLoginUserId,
            'direct_present'=>(int)($direct['present']??0),
            'direct_checkout'=>(int)($direct['checkout']??0),
            'manual_present'=>$manualPresent,
            'effective_present'=>$effectivePresent,
            'unclassified_days'=>max(0,$workDays-$present-$leave-$izin-$sick-$absent)
        ];
    }
}

if (!function_exists('rmi_payroll_overtime_hours')) {
    function rmi_payroll_overtime_hours(PDO $pdo, int $employeeId, string $periodYm): float {
        if ($employeeId<=0 || !preg_match('/^\d{4}-\d{2}$/',$periodYm) || !rmi_payroll_table_exists($pdo,'hrl_request_overtime') || !rmi_payroll_table_exists($pdo,'hrl_requests')) return 0.0;
        $username=rmi_payroll_username($pdo,0,$employeeId); if($username==='') return 0.0;
        try {
            $st=$pdo->prepare("SELECT COALESCE(SUM(o.duration_minutes),0) FROM hrl_request_overtime o JOIN hrl_requests r ON r.id=o.request_id WHERE r.created_by=? AND o.payroll_period=? AND UPPER(r.req_type)='LEMBUR' AND UPPER(r.status) IN ('HRL_APPROVED','FIN_APPROVED','PAID')".(rmi_payroll_col_exists($pdo,'hrl_requests','deleted_at')?" AND r.deleted_at IS NULL":""));
            $st->execute([$username,$periodYm]); return round(((float)$st->fetchColumn())/60,2);
        } catch(Throwable $e){ return 0.0; }
    }
}

if (!function_exists('rmi_payroll_setting_amounts')) {
    function rmi_payroll_setting_amounts(PDO $pdo, int $employeeId, array $context=[]): array {
        $out=['tax_pph21'=>0.0,'bpjs_tk'=>0.0,'bpjs_kes'=>0.0,'deduction_fixed'=>0.0,'overtime_rate_per_hour'=>0.0];
        if ($employeeId<=0 || !rmi_payroll_table_exists($pdo,'payroll_employee_settings')) return $out;
        try {
            $st=$pdo->prepare("SELECT * FROM payroll_employee_settings WHERE employee_id=? LIMIT 1"); $st->execute([$employeeId]); $s=$st->fetch(PDO::FETCH_ASSOC)?:[];
            foreach (array_keys($out) as $k) if (array_key_exists($k,$s)) $out[$k]=(float)$s[$k];
            foreach (['pph21','pph_21','tax_amount'] as $c) if ($out['tax_pph21']==0.0 && array_key_exists($c,$s)) $out['tax_pph21']=(float)$s[$c];
            foreach (['bpjstk','bpjs_tk_employee','bpjs_ketenagakerjaan'] as $c) if ($out['bpjs_tk']==0.0 && array_key_exists($c,$s)) $out['bpjs_tk']=(float)$s[$c];
            foreach (['bpjskes','bpjs_kes_employee','bpjs_kesehatan'] as $c) if ($out['bpjs_kes']==0.0 && array_key_exists($c,$s)) $out['bpjs_kes']=(float)$s[$c];
        } catch(Throwable $e){}
        if ($out['overtime_rate_per_hour']<=0) {
            $monthly=max(0,(float)($context['salary_basic']??0)+(float)($context['allowance_fixed']??0));
            if ($monthly>0) $out['overtime_rate_per_hour']=round($monthly/173,2);
        }
        if (function_exists('payroll_calc_pph21')) {
            try { $v=payroll_calc_pph21($pdo,$employeeId,$context); if (is_numeric($v)) $out['tax_pph21']=(float)$v; elseif(is_array($v)) $out['tax_pph21']=(float)($v['tax_pph21']??$v['amount']??$out['tax_pph21']); } catch(Throwable $e){}
        }
        if (function_exists('payroll_calc_bpjs_deductions')) {
            try { $v=(array)payroll_calc_bpjs_deductions($pdo,$employeeId,$context); $out['bpjs_tk']=(float)($v['bpjs_tk']??$out['bpjs_tk']); $out['bpjs_kes']=(float)($v['bpjs_kes']??$out['bpjs_kes']); } catch(Throwable $e){}
        }
        return $out;
    }
}
