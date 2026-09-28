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
        $isAdmin = in_array($role, ['SYS', 'SUPERADMIN', 'ADMIN'], true) || in_array($level, ['SYS', 'SUPERADMIN', 'ADMIN'], true) || ($dept === 'SYS');
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
        if ((bool)$ctx['is_admin']) {
            return;
        }
        if ($ctx['department'] !== '') {
            $where[] = "UPPER(COALESCE({$deptCol},'')) = ?";
            $params[] = (string)$ctx['department'];
        }
        if ($ctx['office_code'] !== '') {
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

/**
 * Manager Controlling Staff — data untuk widget monitoring tim.
 *
 * @param PDO $pdo
 * @param array $opts Opsi konfigurasi:
 *   - backlog_table: string — tabel untuk hitung backlog (contoh: sales_do, wqs_incoming)
 *   - backlog_status_col: string — kolom status (default: status)
 *   - backlog_open_statuses: array — status yang dianggap "open" (default: OPEN, SUBMITTED, PENDING, IN_PROGRESS)
 *   - backlog_dept_col: string — kolom dept untuk filter (default: department), kosongkan '' jika tidak ada
 *   - backlog_office_col: string — kolom office untuk filter (default: office_code), kosongkan '' jika tidak ada
 *   - exceptions_count: int — jumlah exception (dari KPI dashboard, mis. AR overdue, stock negatif)
 *   - extra_metrics: array — metrik tambahan [['label'=>'X','value'=>N], ...] untuk tampil di widget
 * @return array {scope_label, team_staff, attendance_today, backlog_open, exceptions, extra_metrics}
 */
if (!function_exists('ds_manager_widget_data')) {
    function ds_manager_widget_data(PDO $pdo, array $opts = []): array
    {
        $ctx = ds_scope_ctx();
        $dept = (string)$ctx['department'];
        $office = (string)$ctx['office_code'];

        $staffCount = 0;
        if (ds_table_exists($pdo, 'master_system_login')) {
            $sql = "SELECT COUNT(*) FROM master_system_login WHERE LOWER(COALESCE(status,''))='active' AND LOWER(COALESCE(level,''))='staff'";
            $params = [];
            if ($dept !== '') {
                $sql .= " AND UPPER(COALESCE(department,''))=?";
                $params[] = $dept;
            }
            if ($office !== '') {
                $sql .= " AND UPPER(COALESCE(office_code,''))=?";
                $params[] = $office;
            }
            $staffCount = (int)ds_scalar($pdo, $sql, $params);
        }

        $attendanceToday = 0;
        if (ds_table_exists($pdo, 'absensi_logs')) {
            $sql = "SELECT COUNT(*) FROM absensi_logs WHERE DATE(created_at)=CURDATE()";
            $params = [];
            if ($office !== '') {
                $sql .= " AND UPPER(COALESCE(office_code,''))=?";
                $params[] = $office;
            }
            $attendanceToday = (int)ds_scalar($pdo, $sql, $params);
        }

        $backlogOpen = 0;
        $backlogTable = (string)($opts['backlog_table'] ?? '');
        $statusCol = (string)($opts['backlog_status_col'] ?? 'status');
        $openStatuses = (array)($opts['backlog_open_statuses'] ?? ['OPEN', 'SUBMITTED', 'PENDING', 'IN_PROGRESS']);
        $deptCol = (string)($opts['backlog_dept_col'] ?? 'department');
        $officeCol = (string)($opts['backlog_office_col'] ?? 'office_code');
        if ($backlogTable !== '' && ds_table_exists($pdo, $backlogTable)) {
            $in = implode(',', array_fill(0, count($openStatuses), '?'));
            $sql = "SELECT COUNT(*) FROM {$backlogTable} WHERE UPPER(COALESCE({$statusCol},'')) IN ({$in})";
            $params = array_map(static fn($v) => strtoupper((string)$v), $openStatuses);
            if ($dept !== '' && $deptCol !== '') {
                $sql .= " AND UPPER(COALESCE({$deptCol},''))=?";
                $params[] = $dept;
            }
            if ($office !== '' && $officeCol !== '') {
                $sql .= " AND UPPER(COALESCE({$officeCol},''))=?";
                $params[] = $office;
            }
            $backlogOpen = (int)ds_scalar($pdo, $sql, $params);
        }

        $exceptionsCount = (int)($opts['exceptions_count'] ?? 0);
        $extraMetrics = (array)($opts['extra_metrics'] ?? []);

        return [
            'scope_label' => ($ctx['is_admin'] ? 'ALL' : (($dept !== '' ? $dept : 'NA') . ' / ' . ($office !== '' ? $office : 'ALL'))),
            'team_staff' => $staffCount,
            'attendance_today' => $attendanceToday,
            'backlog_open' => $backlogOpen,
            'exceptions' => $exceptionsCount,
            'extra_metrics' => $extraMetrics,
        ];
    }
}

/**
 * Render widget Manager Controlling Staff.
 * Pakai data dari ds_manager_widget_data() atau ds_manager_section().
 *
 * @param array $w Data widget (scope_label, team_staff, attendance_today, backlog_open, exceptions, extra_metrics?)
 */
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

/**
 * Helper satu baris: ambil data + render Manager Controlling Staff.
 * Untuk dashboard baru cukup: ds_manager_section($pdo, $opts);
 *
 * @param PDO|null $pdo
 * @param array $opts Same as ds_manager_widget_data()
 */
if (!function_exists('ds_manager_section')) {
    function ds_manager_section(?PDO $pdo, array $opts = []): void
    {
        if (!$pdo) {
            $w = [
                'scope_label' => 'ALL',
                'team_staff' => 0,
                'attendance_today' => 0,
                'backlog_open' => 0,
                'exceptions' => (int)($opts['exceptions_count'] ?? 0),
                'extra_metrics' => (array)($opts['extra_metrics'] ?? []),
            ];
        } else {
            $w = ds_manager_widget_data($pdo, $opts);
        }
        ds_render_manager_cards($w);
    }
}

