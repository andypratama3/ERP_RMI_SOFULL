<?php
/**
 * Shared payroll synchronization helpers.
 * Tidak mengubah transaksi sumber. Hanya membaca absensi/HRL/settings dan
 * mengembalikan snapshot yang akan disimpan ke payroll_run_items.
 */

declare(strict_types=1);

/**
 * Periode payroll RMI memakai rentang cutoff tanggal 26 sampai 25.
 * Label periode YYYY-MM berarti transaksi tanggal 26 bulan sebelumnya
 * sampai tanggal 25 pada bulan label.
 * Contoh: 2026-07 = 2026-06-26 s.d. 2026-07-25.
 */
if (!function_exists('rmi_payroll_cutoff_period')) {
    function rmi_payroll_cutoff_period(string $periodYm, int $cutoffDay = 25): ?array {
        if (!preg_match('/^(\d{4})-(\d{2})$/', trim($periodYm), $m)) return null;
        $year = (int)$m[1];
        $month = (int)$m[2];
        if ($month < 1 || $month > 12) return null;
        $cutoffDay = max(1, min(27, $cutoffDay));
        try {
            $periodMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $previousMonth = $periodMonth->modify('-1 month');
            $startDay = $cutoffDay + 1;
            $startDay = min($startDay, (int)$previousMonth->format('t'));
            $endDay = min($cutoffDay, (int)$periodMonth->format('t'));
            $start = $previousMonth->setDate((int)$previousMonth->format('Y'), (int)$previousMonth->format('m'), $startDay)->format('Y-m-d');
            $end = $periodMonth->setDate($year, $month, $endDay)->format('Y-m-d');
            return [$start, $end];
        } catch (Throwable $e) {
            return null;
        }
    }
}


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


if (!function_exists('rmi_payroll_holiday_condition')) {
    function rmi_payroll_holiday_condition(string $dateExpr, ?string $office, array &$params): string {
        $condition = "NOT EXISTS (
            SELECT 1
            FROM payroll_holidays ph
            WHERE ph.holiday_date = {$dateExpr}
              AND ph.is_active = 1
              AND (ph.office_code IS NULL OR TRIM(ph.office_code) = ''";
        if ($office !== null && trim($office) !== '') {
            $condition .= " OR UPPER(TRIM(ph.office_code)) = UPPER(TRIM(?))";
            $params[] = trim($office);
        }
        $condition .= ")
        )";
        return $condition;
    }
}

if (!function_exists('rmi_payroll_effective_workdays')) {
    function rmi_payroll_effective_workdays(PDO $pdo, string $start, string $end, ?string $office = null): int {
        try {
            $from = new DateTimeImmutable($start);
            $to = new DateTimeImmutable($end);
        } catch (Throwable $e) {
            return 0;
        }
        if ($to < $from) return 0;

        $holidays = [];
        if (rmi_payroll_table_exists($pdo, 'payroll_holidays')) {
            try {
                $sql = "SELECT holiday_date FROM payroll_holidays
                        WHERE is_active=1 AND holiday_date BETWEEN ? AND ?
                          AND (office_code IS NULL OR TRIM(office_code)=''";
                $params = [$start, $end];
                if ($office !== null && trim($office) !== '') {
                    $sql .= " OR UPPER(TRIM(office_code))=UPPER(TRIM(?))";
                    $params[] = trim($office);
                }
                $sql .= ")";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $holidays[(string)$d] = true;
            } catch (Throwable $e) {}
        }

        $count = 0;
        for ($d=$from; $d <= $to; $d=$d->modify('+1 day')) {
            $weekday = (int)$d->format('N');
            $ymd = $d->format('Y-m-d');
            if ($weekday <= 5 && !isset($holidays[$ymd])) $count++;
        }
        return $count;
    }
}

if (!function_exists('rmi_payroll_direct_attendance')) {
    function rmi_payroll_direct_attendance(PDO $pdo, int $loginUserId, string $start, string $end, ?string $office=null): array {
        $out = ['present'=>0, 'checkout'=>0, 'has_logs'=>0];
        if ($loginUserId <= 0 || !rmi_payroll_table_exists($pdo, 'absensi_logs')) return $out;
        try {
            $holidayParams = [];
            $holidayCond = rmi_payroll_table_exists($pdo, 'payroll_holidays')
                ? rmi_payroll_holiday_condition('DATE(created_at)', $office, $holidayParams)
                : '1=1';
            $sql = "SELECT
                        COUNT(DISTINCT CASE WHEN UPPER(TRIM(action_type))='IN' AND WEEKDAY(DATE(created_at))<5 AND {$holidayCond} THEN DATE(created_at) END) AS present_days,
                        COUNT(DISTINCT CASE WHEN UPPER(TRIM(action_type))='OUT' AND WEEKDAY(DATE(created_at))<5 AND {$holidayCond} THEN DATE(created_at) END) AS checkout_days,
                        COUNT(*) AS total_logs
                    FROM absensi_logs
                    WHERE user_id=?
                      AND created_at>=?
                      AND created_at<DATE_ADD(?, INTERVAL 1 DAY)";
            if (rmi_payroll_col_exists($pdo, 'absensi_logs', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge($holidayParams, $holidayParams, [$loginUserId, $start . ' 00:00:00', $end . ' 00:00:00']));
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['present'] = max(0, (int)($r['present_days'] ?? 0));
            $out['checkout'] = max(0, (int)($r['checkout_days'] ?? 0));
            $out['has_logs'] = ((int)($r['total_logs'] ?? 0) > 0) ? 1 : 0;
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('rmi_payroll_effective_present_days')) {
    function rmi_payroll_effective_present_days(PDO $pdo, int $employeeId, int $loginUserId, string $username, string $start, string $end, ?string $office=null): int {
        $parts = [];
        $params = [];

        if ($loginUserId > 0 && rmi_payroll_table_exists($pdo, 'absensi_logs')) {
            $hp = [];
            $holidayCond = rmi_payroll_table_exists($pdo, 'payroll_holidays')
                ? rmi_payroll_holiday_condition('DATE(created_at)', $office, $hp)
                : '1=1';
            $sql = "SELECT DATE(created_at) AS tanggal FROM absensi_logs
                    WHERE user_id=?
                      AND UPPER(TRIM(action_type))='IN'
                      AND WEEKDAY(DATE(created_at))<5
                      AND {$holidayCond}
                      AND created_at>=?
                      AND created_at<DATE_ADD(?, INTERVAL 1 DAY)";
            if (rmi_payroll_col_exists($pdo, 'absensi_logs', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            $parts[] = $sql;
            $params = array_merge($params, [$loginUserId], $hp, [$start . ' 00:00:00', $end . ' 00:00:00']);
        }

        $table = 'absensi_manual_attendance';
        if (rmi_payroll_table_exists($pdo, $table)) {
            $ids = [];
            $idParams = [];
            if ($employeeId > 0 && rmi_payroll_col_exists($pdo, $table, 'employee_id')) { $ids[] = 'employee_id=?'; $idParams[] = $employeeId; }
            if ($loginUserId > 0 && rmi_payroll_col_exists($pdo, $table, 'user_id')) { $ids[] = 'user_id=?'; $idParams[] = $loginUserId; }
            if ($username !== '' && rmi_payroll_col_exists($pdo, $table, 'username')) { $ids[] = 'username=?'; $idParams[] = $username; }
            if ($ids) {
                $hp = [];
                $holidayCond = rmi_payroll_table_exists($pdo, 'payroll_holidays')
                    ? rmi_payroll_holiday_condition('tanggal', $office, $hp)
                    : '1=1';
                $sql = "SELECT tanggal FROM {$table}
                        WHERE tanggal BETWEEN ? AND ?
                          AND WEEKDAY(tanggal)<5
                          AND {$holidayCond}
                          AND UPPER(status) IN ('SERVER_DOWN','HADIR_MANUAL')
                          AND (" . implode(' OR ', $ids) . ")";
                if (rmi_payroll_col_exists($pdo, $table, 'deleted_at')) $sql .= " AND deleted_at IS NULL";
                $parts[] = $sql;
                $params = array_merge($params, [$start, $end], $hp, $idParams);
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

if (!function_exists('rmi_payroll_effective_work_dates')) {
    function rmi_payroll_effective_work_dates(PDO $pdo, string $start, string $end, ?string $office=null): array {
        try { $from=new DateTimeImmutable($start); $to=new DateTimeImmutable($end); }
        catch (Throwable $e) { return []; }
        if ($to < $from) return [];
        $holidays=[];
        if (rmi_payroll_table_exists($pdo,'payroll_holidays')) {
            try {
                $sql="SELECT holiday_date FROM payroll_holidays WHERE is_active=1 AND holiday_date BETWEEN ? AND ? AND (office_code IS NULL OR TRIM(office_code)=''";
                $params=[$start,$end];
                if ($office!==null && trim($office)!=='') { $sql.=" OR UPPER(TRIM(office_code))=UPPER(TRIM(?))"; $params[]=trim($office); }
                $sql.=')';
                $st=$pdo->prepare($sql); $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $holidays[(string)$d]=true;
            } catch (Throwable $e) {}
        }
        $dates=[];
        for($d=$from;$d<=$to;$d=$d->modify('+1 day')) {
            $ymd=$d->format('Y-m-d');
            if((int)$d->format('N')<=5 && !isset($holidays[$ymd])) $dates[$ymd]=true;
        }
        return array_keys($dates);
    }
}

if (!function_exists('rmi_payroll_expand_dates')) {
    function rmi_payroll_expand_dates(?string $from, ?string $to, array $workDateSet): array {
        if (!$from) return [];
        $to=$to ?: $from;
        $a=strtotime($from); $b=strtotime($to);
        if($a===false || $b===false || $b<$a) return [];
        $out=[];
        for($t=$a;$t<=$b;$t=strtotime('+1 day',$t)) {
            $d=date('Y-m-d',$t); if(isset($workDateSet[$d])) $out[$d]=true;
        }
        return array_keys($out);
    }
}

if (!function_exists('rmi_payroll_request_date_sets')) {
    function rmi_payroll_request_date_sets(PDO $pdo, int $loginUserId, string $username, int $employeeId, string $start, string $end, array $workDateSet): array {
        $sets=['leave'=>[],'izin'=>[],'sick'=>[]];
        $approved=['APPROVED','HRL_APPROVED','FIN_APPROVED','PAID','DONE'];
        $read=function(string $table, string $identityCol, $identityVal) use($pdo,$start,$end,$workDateSet,$approved,&$sets): void {
            if($identityVal==='' || $identityVal===0 || !rmi_payroll_table_exists($pdo,$table)) return;
            $typeCol=rmi_payroll_first_col($pdo,$table,['req_type','request_type','type','jenis','kategori','leave_type']);
            if($typeCol==='' || !rmi_payroll_col_exists($pdo,$table,$identityCol) || !rmi_payroll_col_exists($pdo,$table,'start_date')) return;
            $endCol=rmi_payroll_col_exists($pdo,$table,'end_date')?'end_date':'start_date';
            $statusCol=rmi_payroll_col_exists($pdo,$table,'status')?'status':'';
            try {
                $sql="SELECT start_date, `{$endCol}` AS end_date, `{$typeCol}` AS req_type FROM `{$table}` WHERE `{$identityCol}`=? AND start_date<=? AND COALESCE(`{$endCol}`,start_date)>=?";
                $params=[$identityVal,$end,$start];
                if($statusCol!=='') { $ph=implode(',',array_fill(0,count($approved),'?')); $sql.=" AND UPPER(`{$statusCol}`) IN ({$ph})"; $params=array_merge($params,$approved); }
                if(rmi_payroll_col_exists($pdo,$table,'deleted_at')) $sql.=" AND deleted_at IS NULL";
                $st=$pdo->prepare($sql); $st->execute($params);
                foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $typ=strtoupper(trim((string)($r['req_type']??'')));
                    $bucket=(strpos($typ,'SAKIT')!==false||strpos($typ,'SICK')!==false)?'sick':((strpos($typ,'IZIN')!==false||strpos($typ,'PERMISSION')!==false||strpos($typ,'DINAS')!==false)?'izin':'leave');
                    foreach(rmi_payroll_expand_dates($r['start_date']??null,$r['end_date']??null,$workDateSet) as $d) $sets[$bucket][$d]=true;
                }
            } catch(Throwable $e) {}
        };
        $read('hrl_requests','created_by',$username);
        if(rmi_payroll_table_exists($pdo,'absensi_requests')) {
            if(rmi_payroll_col_exists($pdo,'absensi_requests','user_id') && $loginUserId>0) $read('absensi_requests','user_id',$loginUserId);
            if(rmi_payroll_col_exists($pdo,'absensi_requests','username') && $username!=='') $read('absensi_requests','username',$username);
        }
        if($employeeId>0 && rmi_payroll_table_exists($pdo,'hrl_leave_usages')) {
            try {
                $st=$pdo->prepare("SELECT start_date,end_date FROM hrl_leave_usages WHERE employee_id=? AND start_date<=? AND COALESCE(end_date,start_date)>=?");
                $st->execute([$employeeId,$end,$start]);
                foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) foreach(rmi_payroll_expand_dates($r['start_date']??null,$r['end_date']??null,$workDateSet) as $d) $sets['leave'][$d]=true;
            } catch(Throwable $e) {}
        }
        return $sets;
    }
}

if (!function_exists('rmi_payroll_manual_date_sets')) {
    function rmi_payroll_manual_date_sets(PDO $pdo, int $employeeId, int $loginUserId, string $username, string $start, string $end, array $workDateSet): array {
        $sets = [
            'present' => [],
            'leave'   => [],
            'izin'    => [],
            'sick'    => [],
            'absent'  => [],
        ];
        $table = 'absensi_manual_attendance';
        if (!rmi_payroll_table_exists($pdo, $table)) return $sets;

        $ids = [];
        $params = [];
        if ($employeeId > 0 && rmi_payroll_col_exists($pdo, $table, 'employee_id')) {
            $ids[] = 'employee_id=?';
            $params[] = $employeeId;
        }
        if ($loginUserId > 0 && rmi_payroll_col_exists($pdo, $table, 'user_id')) {
            $ids[] = 'user_id=?';
            $params[] = $loginUserId;
        }
        if ($username !== '' && rmi_payroll_col_exists($pdo, $table, 'username')) {
            $ids[] = 'UPPER(TRIM(username))=UPPER(TRIM(?))';
            $params[] = $username;
        }
        if (!$ids) return $sets;

        try {
            $sql = "SELECT tanggal,status FROM {$table} WHERE tanggal BETWEEN ? AND ? AND (" . implode(' OR ', $ids) . ")";
            $all = array_merge([$start, $end], $params);
            if (rmi_payroll_col_exists($pdo, $table, 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            $sql .= " ORDER BY tanggal, id";
            $st = $pdo->prepare($sql);
            $st->execute($all);

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = trim((string)($r['tanggal'] ?? ''));
                if ($d === '' || !isset($workDateSet[$d])) continue;
                $stt = strtoupper(trim((string)($r['status'] ?? '')));
                if (in_array($stt, ['SERVER_DOWN','HADIR_MANUAL'], true)) {
                    $sets['present'][$d] = true;
                } elseif (in_array($stt, ['CUTI','LEAVE'], true)) {
                    $sets['leave'][$d] = true;
                } elseif (in_array($stt, ['IZIN','PERMISSION','DINAS'], true)) {
                    $sets['izin'][$d] = true;
                } elseif (in_array($stt, ['SAKIT','SICK'], true)) {
                    $sets['sick'][$d] = true;
                } elseif (in_array($stt, ['ALPA','ABSENT'], true)) {
                    $sets['absent'][$d] = true;
                }
            }
        } catch (Throwable $e) {}
        return $sets;
    }
}

if (!function_exists('rmi_payroll_attendance_snapshot')) {
    function rmi_payroll_attendance_snapshot(PDO $pdo, int $loginUserId, int $employeeId, string $start, string $end, int $workDays, ?string $office=null): array {
        $resolvedLoginUserId=rmi_payroll_login_user_id($pdo,$loginUserId,$employeeId);
        $username=rmi_payroll_username($pdo,$resolvedLoginUserId,$employeeId);
        $workDates=rmi_payroll_effective_work_dates($pdo,$start,$end,$office);
        if(!$workDates && $workDays>0){
            try{$a=new DateTimeImmutable($start);$b=new DateTimeImmutable($end);for($d=$a;$d<=$b&&count($workDates)<$workDays;$d=$d->modify('+1 day'))if((int)$d->format('N')<=5)$workDates[]=$d->format('Y-m-d');}catch(Throwable $e){}
        }
        $workDateSet=array_fill_keys($workDates,true);
        $daily=[]; foreach($workDates as $d)$daily[$d]='UNCLASSIFIED';
        $hasSource=0;

        if($resolvedLoginUserId>0 && rmi_payroll_table_exists($pdo,'absensi_logs')){
            try{
                $sql="SELECT DISTINCT DATE(created_at) d FROM absensi_logs WHERE user_id=? AND UPPER(TRIM(action_type))='IN' AND created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY)";
                if(rmi_payroll_col_exists($pdo,'absensi_logs','deleted_at'))$sql.=" AND deleted_at IS NULL";
                $st=$pdo->prepare($sql);$st->execute([$resolvedLoginUserId,$start.' 00:00:00',$end.' 00:00:00']);
                foreach($st->fetchAll(PDO::FETCH_COLUMN) as $d){$d=(string)$d;if(isset($workDateSet[$d])){$daily[$d]='PRESENT';$hasSource=1;}}
            }catch(Throwable $e){}
        }
        $manual=rmi_payroll_manual_date_sets($pdo,$employeeId,$resolvedLoginUserId,$username,$start,$end,$workDateSet);
        foreach($manual['present'] as $d=>$_){$daily[$d]='PRESENT';$hasSource=1;}
        $req=rmi_payroll_request_date_sets($pdo,$resolvedLoginUserId,$username,$employeeId,$start,$end,$workDateSet);
        // Ketidakhadiran resmi mengalahkan status hadir pada tanggal yang sama.
        foreach($req['leave'] as $d=>$_){$daily[$d]='LEAVE';$hasSource=1;}
        foreach($manual['leave'] as $d=>$_){$daily[$d]='LEAVE';$hasSource=1;}
        foreach($req['izin'] as $d=>$_){$daily[$d]='IZIN';$hasSource=1;}
        foreach($manual['izin'] as $d=>$_){$daily[$d]='IZIN';$hasSource=1;}
        foreach($req['sick'] as $d=>$_){$daily[$d]='SICK';$hasSource=1;}
        foreach($manual['sick'] as $d=>$_){$daily[$d]='SICK';$hasSource=1;}
        foreach($manual['absent'] as $d=>$_){$daily[$d]='ABSENT';$hasSource=1;}

        $counts=['PRESENT'=>0,'LEAVE'=>0,'IZIN'=>0,'SICK'=>0,'ABSENT'=>0,'UNCLASSIFIED'=>0];$unclassified=[];
        foreach($daily as $d=>$status){$counts[$status]++;if($status==='UNCLASSIFIED')$unclassified[]=$d;}
        $late=0;
        if($resolvedLoginUserId>0 && function_exists('payroll_calc_late_deduction')){try{$lc=payroll_calc_late_deduction($pdo,$resolvedLoginUserId,$start,$end,$office);$late=(int)($lc['late_count']??0);}catch(Throwable $e){}}
        return [
            'present'=>$counts['PRESENT'],'leave'=>$counts['LEAVE'],'izin'=>$counts['IZIN'],'sick'=>$counts['SICK'],'absent'=>$counts['ABSENT'],
            'late'=>$late,'source_found'=>$hasSource,'username'=>$username,'login_user_id'=>$resolvedLoginUserId,
            'work_dates'=>$workDates,'daily_status'=>$daily,'unclassified_dates'=>$unclassified,'unclassified_days'=>$counts['UNCLASSIFIED']
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
