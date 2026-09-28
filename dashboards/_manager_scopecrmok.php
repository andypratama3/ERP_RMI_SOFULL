<?php
declare(strict_types=1);

if (!function_exists('ds_scope_ctx')) {
    function ds_scope_ctx(): array
    {
        $role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
        $level = strtoupper(trim((string)($_SESSION['level'] ?? $role)));
        $dept = strtoupper(trim((string)($_SESSION['department'] ?? '')));
        $office = strtoupper(trim((string)($_SESSION['office_code'] ?? '')));
        $username = trim((string)($_SESSION['username'] ?? ''));
        $isAdmin = in_array($role, ['SYS', 'SUPERADMIN', 'ADMIN'], true)
            || in_array($level, ['SYS', 'SUPERADMIN', 'ADMIN'], true)
            || ($dept === 'SYS');
        $isManager = !$isAdmin && ($role === 'MANAGER' || $level === 'MANAGER');
        $isStaff = !$isAdmin && !$isManager;
        return [
            'role' => $role,
            'level' => $level,
            'department' => $dept,
            'office_code' => $office,
            'username' => $username,
            'is_admin' => $isAdmin,
            'is_manager' => $isManager,
            'is_staff' => $isStaff,
        ];
    }
}

if (!function_exists('ds_scope_apply_dept_office')) {
    function ds_scope_apply_dept_office(array &$where, array &$params, string $deptCol = 'department', string $officeCol = 'office_code'): void
    {
        $ctx = ds_scope_ctx();
        if ((bool)$ctx['is_admin']) return;
        if ($ctx['department'] !== '' && $deptCol !== '') {
            $where[] = "UPPER(COALESCE({$deptCol},'')) = ?";
            $params[] = (string)$ctx['department'];
        }
        if ($ctx['office_code'] !== '' && $officeCol !== '') {
            $where[] = "UPPER(COALESCE({$officeCol},'')) = ?";
            $params[] = (string)$ctx['office_code'];
        }
    }
}

if (!function_exists('ds_scalar')) {
    function ds_scalar(PDO $pdo, string $sql, array $params = []): float
    {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $v = $st->fetchColumn();
            return (float)($v !== false ? $v : 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('ds_table_exists')) {
    function ds_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
            $st->execute([$table]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ds_table_columns')) {
    function ds_table_columns(PDO $pdo, string $table): array
    {
        $out = [];
        if (!ds_table_exists($pdo, $table)) return $out;
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $r) {
                $f = (string)($r['Field'] ?? '');
                if ($f !== '') $out[$f] = true;
            }
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('ds_pick_col')) {
    function ds_pick_col(array $cols, array $candidates): ?string
    {
        foreach ($candidates as $c) if (isset($cols[$c])) return $c;
        return null;
    }
}

/**
 * Manager Controlling Staff — read-only monitoring widget.
 *
 * Important rules:
 * - SYS/Admin scope is ALL and MUST NOT be filtered by its session department/office.
 * - Manager/Staff scope is restricted to its session department + office.
 * - Backlog may be driven by workflow status rather than a physical department column.
 *
 * Options:
 * - backlog_table, backlog_status_col, backlog_open_statuses
 * - backlog_statuses_by_dept: ['CRM'=>[...], 'WQS'=>[...], ...]
 * - backlog_dept_col, backlog_office_col
 * - exceptions_count, extra_metrics
 */
if (!function_exists('ds_manager_widget_data')) {
    function ds_manager_widget_data(PDO $pdo, array $opts = []): array
    {
        $ctx = ds_scope_ctx();
        $isAdmin = (bool)$ctx['is_admin'];
        $dept = $isAdmin ? '' : (string)$ctx['department'];
        $office = $isAdmin ? '' : (string)$ctx['office_code'];

        // TEAM STAFF + scoped identities for attendance matching.
        $staffCount = 0;
        $staffUsernames = [];
        $staffEmployeeCodes = [];
        $staffLoginIds = [];
        if (ds_table_exists($pdo, 'master_system_login')) {
            $cols = ds_table_columns($pdo, 'master_system_login');
            $statusCol = ds_pick_col($cols, ['status','is_active','active']);
            $levelCol = ds_pick_col($cols, ['level']);
            $roleCol = ds_pick_col($cols, ['role']);
            $deptCol = ds_pick_col($cols, ['department','dept_code','department_code']);
            $officeCol = ds_pick_col($cols, ['office_code','office','branch_code']);

            $where = ['1=1'];
            $params = [];
            if ($statusCol !== null) {
                $where[] = "LOWER(COALESCE(`{$statusCol}`,'')) IN ('active','1','yes','enabled')";
            }
            if ($levelCol !== null && $roleCol !== null) {
                $where[] = "(LOWER(COALESCE(`{$levelCol}`,''))='staff' OR LOWER(COALESCE(`{$roleCol}`,''))='staff')";
            } elseif ($levelCol !== null) {
                $where[] = "LOWER(COALESCE(`{$levelCol}`,''))='staff'";
            } elseif ($roleCol !== null) {
                $where[] = "LOWER(COALESCE(`{$roleCol}`,''))='staff'";
            }
            if ($dept !== '' && $deptCol !== null) {
                $where[] = "UPPER(COALESCE(`{$deptCol}`,''))=?";
                $params[] = $dept;
            }
            if ($office !== '' && $officeCol !== null) {
                $where[] = "UPPER(COALESCE(`{$officeCol}`,''))=?";
                $params[] = $office;
            }

            $select = ['id'];
            if (isset($cols['username'])) $select[] = 'username';
            if (isset($cols['holder_employee_code'])) $select[] = 'holder_employee_code';
            try {
                $qStaff = $pdo->prepare('SELECT ' . implode(',', array_map(static fn($c)=>"`{$c}`", $select)) . ' FROM master_system_login WHERE ' . implode(' AND ', $where));
                $qStaff->execute($params);
                $rowsStaff = $qStaff->fetchAll(PDO::FETCH_ASSOC);
                $staffCount = count($rowsStaff);
                foreach ($rowsStaff as $sr) {
                    if (!empty($sr['id'])) $staffLoginIds[] = (string)(int)$sr['id'];
                    $u = trim((string)($sr['username'] ?? ''));
                    if ($u !== '') $staffUsernames[] = strtoupper($u);
                    $ec = trim((string)($sr['holder_employee_code'] ?? ''));
                    if ($ec !== '') $staffEmployeeCodes[] = strtoupper($ec);
                }
                $staffUsernames = array_values(array_unique($staffUsernames));
                $staffEmployeeCodes = array_values(array_unique($staffEmployeeCodes));
                $staffLoginIds = array_values(array_unique($staffLoginIds));
            } catch (Throwable $e) {
                $staffCount = (int)ds_scalar($pdo, 'SELECT COUNT(*) FROM master_system_login WHERE ' . implode(' AND ', $where), $params);
            }
        }

        // ATTENDANCE TODAY — count unique PRESENT staff within the same staff scope.
        // Never allow attendance to exceed Team Staff.
        $attendanceToday = 0;
        foreach (['absensi_logs','attendance_logs','attendance','absensi'] as $attTable) {
            if (!ds_table_exists($pdo, $attTable)) continue;
            $cols = ds_table_columns($pdo, $attTable);
            $dateCol = ds_pick_col($cols, ['created_at','checkin_at','check_in_at','attendance_date','date','tanggal','checkin_time']);
            if ($dateCol === null) continue;
            $officeCol = ds_pick_col($cols, ['office_code','office','branch_code']);
            $userCol = ds_pick_col($cols, ['username','user_code','employee_code','employee_id','user_id','staff_code']);
            $statusCol = ds_pick_col($cols, ['status','attendance_status','type']);

            $where = ["DATE(`{$dateCol}`)=CURDATE()"];
            $params = [];
            if ($statusCol !== null) {
                $where[] = "LOWER(COALESCE(`{$statusCol}`,'')) NOT IN ('alpa','absent','izin','cuti','sakit','leave')";
            }
            if ($office !== '' && $officeCol !== null) {
                $where[] = "UPPER(COALESCE(`{$officeCol}`,''))=?";
                $params[] = $office;
            }

            // Restrict attendance to the exact active STAFF population used by Team Staff.
            if ($userCol !== null && $staffCount > 0) {
                $allowed = [];
                $uc = strtolower($userCol);
                if (str_contains($uc, 'employee')) $allowed = $staffEmployeeCodes;
                elseif ($uc === 'user_id') $allowed = $staffLoginIds;
                else $allowed = $staffUsernames;

                if ($allowed) {
                    $in = implode(',', array_fill(0, count($allowed), '?'));
                    if ($uc === 'user_id') {
                        $where[] = "CAST(`{$userCol}` AS CHAR) IN ({$in})";
                        $params = array_merge($params, $allowed);
                    } else {
                        $where[] = "UPPER(TRIM(COALESCE(`{$userCol}`,''))) IN ({$in})";
                        $params = array_merge($params, $allowed);
                    }
                }
            }

            $countExpr = $userCol !== null ? "COUNT(DISTINCT `{$userCol}`)" : 'COUNT(*)';
            $rawAttendance = (int)ds_scalar($pdo, "SELECT {$countExpr} FROM `{$attTable}` WHERE " . implode(' AND ', $where), $params);
            $attendanceToday = $staffCount > 0 ? min($staffCount, $rawAttendance) : 0;
            break;
        }

        // BACKLOG OPEN
        $backlogOpen = 0;
        $backlogTable = (string)($opts['backlog_table'] ?? '');
        $statusCol = (string)($opts['backlog_status_col'] ?? 'status');
        $openStatuses = (array)($opts['backlog_open_statuses'] ?? ['OPEN','SUBMITTED','PENDING','IN_PROGRESS']);
        $byDept = (array)($opts['backlog_statuses_by_dept'] ?? []);
        if (!$isAdmin && $dept !== '' && isset($byDept[$dept]) && is_array($byDept[$dept])) {
            $openStatuses = $byDept[$dept];
        }
        $deptColOpt = (string)($opts['backlog_dept_col'] ?? 'department');
        $officeColOpt = (string)($opts['backlog_office_col'] ?? 'office_code');

        if ($backlogTable !== '' && $openStatuses && ds_table_exists($pdo, $backlogTable)) {
            $cols = ds_table_columns($pdo, $backlogTable);
            if (isset($cols[$statusCol])) {
                $in = implode(',', array_fill(0, count($openStatuses), '?'));
                $sql = "SELECT COUNT(*) FROM `{$backlogTable}` WHERE UPPER(COALESCE(`{$statusCol}`,'')) IN ({$in})";
                $params = array_map(static fn($v) => strtoupper((string)$v), $openStatuses);

                // Only apply physical department filter when explicitly requested and column exists.
                if ($dept !== '' && $deptColOpt !== '' && isset($cols[$deptColOpt])) {
                    $sql .= " AND UPPER(COALESCE(`{$deptColOpt}`,''))=?";
                    $params[] = $dept;
                }
                if ($office !== '' && $officeColOpt !== '' && isset($cols[$officeColOpt])) {
                    $sql .= " AND UPPER(COALESCE(`{$officeColOpt}`,''))=?";
                    $params[] = $office;
                }

                // Optional dashboard period alignment. Backlog must use the same period as the page KPI.
                $dateColOpt = (string)($opts['backlog_date_col'] ?? '');
                $dateFromOpt = trim((string)($opts['backlog_date_from'] ?? ''));
                $dateToOpt = trim((string)($opts['backlog_date_to'] ?? ''));
                if ($dateColOpt !== '' && isset($cols[$dateColOpt]) && $dateFromOpt !== '' && $dateToOpt !== '') {
                    $sql .= " AND DATE(`{$dateColOpt}`) BETWEEN ? AND ?";
                    $params[] = $dateFromOpt;
                    $params[] = $dateToOpt;
                }

                $backlogOpen = (int)ds_scalar($pdo, $sql, $params);
            }
        }

        return [
            'scope_label' => ($isAdmin ? 'ALL' : (($dept !== '' ? $dept : 'NA') . ' / ' . ($office !== '' ? $office : 'ALL'))),
            'team_staff' => $staffCount,
            'attendance_today' => $attendanceToday,
            'backlog_open' => $backlogOpen,
            'exceptions' => (int)($opts['exceptions_count'] ?? 0),
            'extra_metrics' => (array)($opts['extra_metrics'] ?? []),
        ];
    }
}

if (!function_exists('ds_render_manager_cards')) {
    function ds_render_manager_cards(array $w): void
    {
        $extra = (array)($w['extra_metrics'] ?? []);
        $esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        echo '<div class="card mb-3 ds-manager-section"><div class="card-body">';
        echo '<div class="fw-semibold mb-2">Manager Controlling Staff</div>';
        echo '<div class="row g-2 ds-manager-grid">';
        echo '<div class="col-md-3 col-6"><div class="p-2 border rounded ds-manager-cell"><div class="small text-muted">Scope</div><div class="fw-bold">' . $esc($w['scope_label'] ?? '') . '</div></div></div>';
        echo '<div class="col-md-2 col-6"><div class="p-2 border rounded ds-manager-cell"><div class="small text-muted">Team Staff</div><div class="fw-bold">' . (int)($w['team_staff'] ?? 0) . '</div></div></div>';
        echo '<div class="col-md-2 col-6"><div class="p-2 border rounded ds-manager-cell"><div class="small text-muted">Attendance Today</div><div class="fw-bold">' . (int)($w['attendance_today'] ?? 0) . '</div></div></div>';
        echo '<div class="col-md-2 col-6"><div class="p-2 border rounded ds-manager-cell"><div class="small text-muted">Backlog Open</div><div class="fw-bold">' . (int)($w['backlog_open'] ?? 0) . '</div></div></div>';
        echo '<div class="col-md-3 col-6"><div class="p-2 border rounded ds-manager-cell"><div class="small text-muted">Exceptions</div><div class="fw-bold">' . (int)($w['exceptions'] ?? 0) . '</div></div></div>';
        foreach ($extra as $m) {
            $lbl = $esc($m['label'] ?? '');
            $val = isset($m['value']) ? (is_numeric($m['value']) ? number_format((float)$m['value'], 0, ',', '.') : $esc($m['value'])) : '-';
            echo '<div class="col-md-2 col-6"><div class="p-2 border rounded ds-manager-cell"><div class="small text-muted">' . $lbl . '</div><div class="fw-bold">' . $val . '</div></div></div>';
        }
        echo '</div></div></div>';
    }
}

if (!function_exists('ds_manager_section')) {
    function ds_manager_section(?PDO $pdo, array $opts = []): void
    {
        $w = $pdo ? ds_manager_widget_data($pdo, $opts) : [
            'scope_label' => 'ALL',
            'team_staff' => 0,
            'attendance_today' => 0,
            'backlog_open' => 0,
            'exceptions' => (int)($opts['exceptions_count'] ?? 0),
            'extra_metrics' => (array)($opts['extra_metrics'] ?? []),
        ];
        ds_render_manager_cards($w);
    }
}
