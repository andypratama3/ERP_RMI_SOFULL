<?php
declare(strict_types=1);

/**
 * HRL Employee Mutation helper.
 *
 * Design principles:
 * - employee id + employee_code never change.
 * - master data only changes at EFFECTIVE.
 * - all effective changes happen in one DB transaction.
 * - existing assignment history is closed, then a new row is inserted.
 * - login department/office/role follow the effective assignment.
 */

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

function hrlm_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function hrlm_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $out = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[strtolower((string)$r['Field'])] = (string)$r['Field'];
        }
    } catch (Throwable $e) {}
    return $cache[$table] = $out;
}

function hrlm_position_column(PDO $pdo): ?string {
    $cols = hrlm_columns($pdo, 'master_employees');
    foreach (['position_name','job_title','position','jabatan'] as $c) {
        if (isset($cols[$c])) return $cols[$c];
    }
    return null;
}

function hrlm_username(): string {
    if (function_exists('me_username')) return (string)me_username();
    return (string)($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'SYSTEM'));
}

function hrlm_is_admin(): bool {
    if (function_exists('is_admin_owner') && is_admin_owner()) return true;
    if (function_exists('auth_is_sys') && auth_is_sys()) return true;
    $dept = strtoupper((string)($_SESSION['department'] ?? ($_SESSION['user']['department'] ?? '')));
    $role = strtoupper((string)($_SESSION['role'] ?? ($_SESSION['user']['role'] ?? '')));
    $level = strtoupper((string)($_SESSION['level'] ?? ($_SESSION['user']['level'] ?? '')));
    return $dept === 'SYS' || in_array($role, ['SYS','ADMIN','SUPERADMIN'], true) || in_array($level, ['SYS','ADMIN','SUPERADMIN'], true);
}

function hrlm_can_view(): bool {
    if (hrlm_is_admin()) return true;
    if (function_exists('can')) {
        return can('HRL.PROCESS_VIEW') || can('HRL.PROCESS_EDIT');
    }
    return false;
}

function hrlm_can_manage(): bool {
    if (hrlm_is_admin()) return true;
    if (function_exists('can')) return can('HRL.PROCESS_EDIT');
    return false;
}

function hrlm_employee(PDO $pdo, int $employeeId): ?array {
    $posCol = hrlm_position_column($pdo);
    $posSel = $posCol ? ", e.`{$posCol}` AS position_name" : ", '' AS position_name";
    $sql = "SELECT e.id, e.employee_code, e.employee_name,
                   UPPER(COALESCE(e.dept_code,'')) AS dept_code,
                   UPPER(COALESCE(e.office_code,'')) AS office_code
                   {$posSel},
                   m.id AS login_id, m.username,
                   UPPER(COALESCE(m.role,'')) AS role_code,
                   UPPER(COALESCE(m.department,'')) AS login_department,
                   UPPER(COALESCE(m.office_code,'')) AS login_office
            FROM master_employees e
            LEFT JOIN master_system_login m
              ON m.holder_employee_code=e.employee_code AND m.deleted_at IS NULL
            WHERE e.id=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$employeeId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function hrlm_active_mutation(PDO $pdo, int $employeeId, ?int $excludeId = null): ?array {
    $sql = "SELECT * FROM hrl_employee_mutations
            WHERE employee_id=?
              AND status IN ('SUBMITTED','APPROVED','SCHEDULED')";
    $params = [$employeeId];
    if ($excludeId !== null) {
        $sql .= " AND id<>?";
        $params[] = $excludeId;
    }
    $sql .= " ORDER BY id DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function hrlm_generate_code(int $id): string {
    return 'MUT-' . date('Ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function hrlm_write_audit(PDO $pdo, string $action, int $id, array $details = []): void {
    if (function_exists('master_audit')) {
        try {
            master_audit(
                $pdo,
                'hrl_employee_mutation',
                'hrl_employee_mutations',
                $action,
                $id,
                (string)($details['mutation_code'] ?? ('MUT#'.$id)),
                'Employee mutation ' . $action,
                $details
            );
        } catch (Throwable $e) {}
    }
}

function hrlm_seed_initial_assignment_if_needed(PDO $pdo, array $emp, string $effectiveBefore): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM hrl_employee_assignment_history WHERE employee_id=?");
    $st->execute([(int)$emp['id']]);
    if ((int)$st->fetchColumn() > 0) return;

    // Use an intentionally early baseline. This preserves future mutations without
    // pretending we know the employee's true historical join date.
    $baseline = '2000-01-01';
    $to = date('Y-m-d', strtotime($effectiveBefore . ' -1 day'));
    $ins = $pdo->prepare("INSERT INTO hrl_employee_assignment_history
        (employee_id,employee_code,dept_code,office_code,position_name,role_code,
         effective_from,effective_to,source,source_id,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        (int)$emp['id'], (string)$emp['employee_code'],
        (string)$emp['dept_code'], (string)$emp['office_code'],
        (string)($emp['position_name'] ?? ''), (string)($emp['role_code'] ?? ''),
        $baseline, $to, 'BASELINE', null, hrlm_username()
    ]);
}

function hrlm_apply_effective(PDO $pdo, int $mutationId, string $actor, bool $allowFuture = false): array {
    if (!hrlm_table_exists($pdo, 'hrl_employee_mutations') || !hrlm_table_exists($pdo, 'hrl_employee_assignment_history')) {
        throw new RuntimeException('Tabel mutasi belum tersedia. Jalankan migration terlebih dahulu.');
    }

    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        $st = $pdo->prepare("SELECT * FROM hrl_employee_mutations WHERE id=? FOR UPDATE");
        $st->execute([$mutationId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new RuntimeException('Mutasi tidak ditemukan.');

        $status = strtoupper((string)$m['status']);
        if (!in_array($status, ['APPROVED','SCHEDULED'], true)) {
            throw new RuntimeException('Mutasi belum berada pada status APPROVED/SCHEDULED.');
        }

        $effectiveDate = (string)$m['effective_date'];
        if (!$allowFuture && $effectiveDate > date('Y-m-d')) {
            throw new RuntimeException('Tanggal efektif belum tiba.');
        }

        $emp = hrlm_employee($pdo, (int)$m['employee_id']);
        if (!$emp) throw new RuntimeException('Master karyawan tidak ditemukan.');

        if ((string)$emp['employee_code'] !== (string)$m['employee_code']) {
            throw new RuntimeException('Employee code tidak konsisten. Proses dihentikan untuk keamanan.');
        }

        // Ensure no other active mutation can race this one.
        $other = hrlm_active_mutation($pdo, (int)$m['employee_id'], $mutationId);
        if ($other) {
            throw new RuntimeException('Masih ada mutasi aktif lain untuk karyawan ini: ' . ($other['mutation_code'] ?: ('#'.$other['id'])));
        }

        hrlm_seed_initial_assignment_if_needed($pdo, $emp, $effectiveDate);

        // Close currently-open assignment row, if any.
        $close = $pdo->prepare("UPDATE hrl_employee_assignment_history
                               SET effective_to=DATE_SUB(?, INTERVAL 1 DAY)
                               WHERE employee_id=? AND effective_to IS NULL");
        $close->execute([$effectiveDate, (int)$m['employee_id']]);

        // Update master_employees only on effective.
        $sets = ["dept_code=?", "office_code=?"];
        $params = [(string)$m['to_dept_code'], (string)$m['to_office_code']];
        $posCol = hrlm_position_column($pdo);
        if ($posCol && trim((string)$m['to_position_name']) !== '') {
            $sets[] = "`{$posCol}`=?";
            $params[] = (string)$m['to_position_name'];
        }
        $params[] = (int)$m['employee_id'];
        $upEmp = $pdo->prepare("UPDATE master_employees SET " . implode(',', $sets) . " WHERE id=?");
        $upEmp->execute($params);

        // Update linked login; preserve id, username, holder_employee_code.
        $loginCols = hrlm_columns($pdo, 'master_system_login');
        if (!empty($emp['login_id'])) {
            $lsets = [];
            $lparams = [];
            if (isset($loginCols['department'])) {
                $lsets[] = "department=?";
                $lparams[] = (string)$m['to_dept_code'];
            }
            if (isset($loginCols['office_code'])) {
                $lsets[] = "office_code=?";
                $lparams[] = (string)$m['to_office_code'];
            }
            if (isset($loginCols['role']) && trim((string)$m['to_role_code']) !== '') {
                $lsets[] = "role=?";
                $lparams[] = (string)$m['to_role_code'];
            }
            if ($lsets) {
                $lparams[] = (int)$emp['login_id'];
                $upLogin = $pdo->prepare("UPDATE master_system_login SET " . implode(',', $lsets) . " WHERE id=?");
                $upLogin->execute($lparams);
            }
        }

        $newRole = trim((string)$m['to_role_code']) !== '' ? (string)$m['to_role_code'] : (string)($emp['role_code'] ?? '');
        $newPos = trim((string)$m['to_position_name']) !== '' ? (string)$m['to_position_name'] : (string)($emp['position_name'] ?? '');

        $ins = $pdo->prepare("INSERT INTO hrl_employee_assignment_history
            (employee_id,employee_code,dept_code,office_code,position_name,role_code,
             effective_from,effective_to,source,source_id,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            (int)$m['employee_id'], (string)$m['employee_code'],
            (string)$m['to_dept_code'], (string)$m['to_office_code'],
            $newPos, $newRole,
            $effectiveDate, null, 'MUTATION', $mutationId, $actor
        ]);

        $up = $pdo->prepare("UPDATE hrl_employee_mutations
                            SET status='EFFECTIVE', effective_by=?, effective_at=NOW(), updated_at=NOW()
                            WHERE id=?");
        $up->execute([$actor, $mutationId]);

        hrlm_write_audit($pdo, 'EFFECTIVE', $mutationId, [
            'mutation_code' => (string)$m['mutation_code'],
            'employee_id' => (int)$m['employee_id'],
            'employee_code' => (string)$m['employee_code'],
            'before' => [
                'dept' => (string)$emp['dept_code'],
                'office' => (string)$emp['office_code'],
                'position' => (string)($emp['position_name'] ?? ''),
                'role' => (string)($emp['role_code'] ?? ''),
            ],
            'after' => [
                'dept' => (string)$m['to_dept_code'],
                'office' => (string)$m['to_office_code'],
                'position' => $newPos,
                'role' => $newRole,
            ],
            'effective_date' => $effectiveDate,
        ]);

        if ($started && $pdo->inTransaction()) $pdo->commit();
        return ['ok'=>true, 'mutation_code'=>(string)$m['mutation_code'], 'effective_date'=>$effectiveDate];
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
