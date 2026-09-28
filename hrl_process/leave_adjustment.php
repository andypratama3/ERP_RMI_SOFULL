<?php
/**
 * hrl_process/leave_adjustment.php
 * Input saldo awal / penyesuaian cuti tahunan.
 * Dipakai untuk cuti yang sudah terpakai sebelum sistem berjalan.
 * Tidak masuk payroll periode berjalan.
 */
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login();

$page_title = 'Input Saldo Cuti';
require_once __DIR__ . '/_layout_top.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function leave_adj_table_columns(PDO $pdo, string $table): array {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower((string)$r['Field'])] = (string)$r['Field'];
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function leave_adj_employee_active_where(array $cols, array &$params): string {
    // Master Employees di server lama bisa memakai status kosong / spasi / ACTIVE.
    // Jangan terlalu ketat hanya status='active', karena karyawan aktif bisa hilang dari dropdown.
    $parts = [];

    if (isset($cols['deleted_at'])) {
        $parts[] = "deleted_at IS NULL";
    }

    if (isset($cols['status'])) {
        $parts[] = "(
            TRIM(COALESCE(status,'')) = ''
            OR LOWER(TRIM(COALESCE(status,''))) IN ('active','aktif')
        )";
    }

    if (isset($cols['is_active'])) {
        $parts[] = "(COALESCE(is_active,1)=1)";
    }

    // Jika tabel tidak punya status/is_active/deleted_at, jangan blok data.
    return $parts ? implode(' AND ', $parts) : '1=1';
}

function leave_adj_employee_join_info(PDO $pdo, int $employeeId): array {
    $cols = leave_adj_table_columns($pdo, 'master_employees');
    $select = ['id', 'employee_code', 'employee_name', 'dept_code', 'office_code'];
    foreach (['join_year','join_month','join_date','date_join','hire_date','tanggal_masuk','start_work_date','work_start_date'] as $c) {
        if (isset($cols[strtolower($c)])) $select[] = '`' . $cols[strtolower($c)] . '` AS `' . $c . '`';
    }
    $sql = "SELECT " . implode(',', $select) . " FROM master_employees WHERE id=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$employeeId]);
    $e = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $joinYear = 0;
    $joinMonth = 0;

    foreach (['join_year'] as $c) {
        if (isset($e[$c]) && (int)$e[$c] > 0) $joinYear = (int)$e[$c];
    }
    foreach (['join_month'] as $c) {
        if (isset($e[$c]) && (int)$e[$c] >= 1 && (int)$e[$c] <= 12) $joinMonth = (int)$e[$c];
    }

    if ($joinYear <= 0 || $joinMonth <= 0) {
        foreach (['join_date','date_join','hire_date','tanggal_masuk','start_work_date','work_start_date'] as $dc) {
            $v = trim((string)($e[$dc] ?? ''));
            if ($v !== '' && $v !== '0000-00-00') {
                $ts = strtotime($v);
                if ($ts) {
                    $joinYear = (int)date('Y', $ts);
                    $joinMonth = (int)date('m', $ts);
                    break;
                }
            }
        }
    }

    return [
        'employee' => $e,
        'join_year' => $joinYear,
        'join_month' => $joinMonth,
        'join_label' => ($joinYear > 0 && $joinMonth > 0) ? sprintf('%04d-%02d', $joinYear, $joinMonth) : '-',
    ];
}

function leave_adj_quota_by_join(PDO $pdo, int $employeeId, int $year): array {
    // Kebijakan aman: hak cuti tahunan muncul setelah masa kerja 12 bulan.
    // Pada tahun pertama berhak, jatah dihitung prorata dari bulan eligible sampai Desember.
    // Tahun berikutnya penuh 12 hari. Jika master employee belum punya Join Year/Month, fallback 12 agar alur lama tidak rusak.
    $info = leave_adj_employee_join_info($pdo, $employeeId);
    $jy = (int)$info['join_year'];
    $jm = (int)$info['join_month'];

    if ($jy <= 0 || $jm <= 0) {
        return ['quota' => 12.0, 'join_label' => '-', 'masa_kerja' => 'Data masuk belum diisi', 'rule' => 'Fallback 12 hari karena data Join Year/Month belum ada'];
    }

    $joinDate = new DateTime(sprintf('%04d-%02d-01', $jy, $jm));
    $eligible = (clone $joinDate)->modify('+12 months');
    $eligYear = (int)$eligible->format('Y');
    $eligMonth = (int)$eligible->format('m');

    if ($year < $eligYear) {
        $quota = 0.0;
        $rule = 'Belum genap 12 bulan';
    } elseif ($year === $eligYear) {
        $quota = (float)max(0, 13 - $eligMonth);
        $rule = 'Prorata tahun pertama hak cuti';
    } else {
        $quota = 12.0;
        $rule = 'Hak cuti penuh';
    }

    $asOf = new DateTime($year . '-12-31');
    $months = max(0, ((int)$asOf->format('Y') - $jy) * 12 + ((int)$asOf->format('m') - $jm) + 1);
    $yearsTxt = intdiv($months, 12);
    $monthTxt = $months % 12;
    $masa = $yearsTxt . ' th ' . $monthTxt . ' bln';

    return [
        'quota' => $quota,
        'join_label' => sprintf('%04d-%02d', $jy, $jm),
        'masa_kerja' => $masa,
        'rule' => $rule,
    ];
}


function leave_adj_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_leave_balances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        year INT NOT NULL,
        quota_days DECIMAL(8,2) NOT NULL DEFAULT 12,
        used_days DECIMAL(8,2) NOT NULL DEFAULT 0,
        remaining_days DECIMAL(8,2) NOT NULL DEFAULT 12,
        updated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_emp_year (employee_id, year),
        KEY idx_year (year),
        KEY idx_employee_id (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_leave_usages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        employee_id INT NOT NULL,
        year INT NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        days DECIMAL(8,2) NOT NULL DEFAULT 0,
        leave_type VARCHAR(50) NOT NULL DEFAULT 'CUTI',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_request_id (request_id),
        KEY idx_emp_year (employee_id, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_leave_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        year INT NOT NULL,
        adjustment_type VARCHAR(40) NOT NULL DEFAULT 'OPENING_BALANCE',
        quota_days DECIMAL(8,2) NOT NULL DEFAULT 12,
        used_days DECIMAL(8,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(100) NULL,
        updated_at DATETIME NULL,
        KEY idx_employee_year (employee_id, year),
        KEY idx_type (adjustment_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upgrade aman untuk instalasi lama. Tidak mengubah data histori yang sudah ada.
    $adjCols = leave_adj_table_columns($pdo, 'hrl_leave_adjustments');
    if (!isset($adjCols['updated_by'])) {
        try { $pdo->exec("ALTER TABLE hrl_leave_adjustments ADD COLUMN updated_by VARCHAR(100) NULL AFTER created_at"); } catch (Throwable $e) {}
    }
    $adjCols = leave_adj_table_columns($pdo, 'hrl_leave_adjustments');
    if (!isset($adjCols['updated_at'])) {
        try { $pdo->exec("ALTER TABLE hrl_leave_adjustments ADD COLUMN updated_at DATETIME NULL AFTER updated_by"); } catch (Throwable $e) {}
    }
}

function leave_adj_approved_used(PDO $pdo, int $employeeId, int $year): float {
    leave_adj_ensure_tables($pdo);
    try {
        $stReq = $pdo->prepare("SELECT COALESCE(SUM(u.days),0)
            FROM hrl_leave_usages u
            JOIN hrl_requests r ON r.id = u.request_id
            WHERE u.employee_id=? AND u.year=?
              AND UPPER(COALESCE(r.req_type,''))='CUTI'
              AND r.deleted_at IS NULL
              AND UPPER(COALESCE(r.status,'')) IN ('HRL_APPROVED','FIN_APPROVED','PAID')");
        $stReq->execute([$employeeId, $year]);
        return (float)$stReq->fetchColumn();
    } catch (Throwable $e) {
        $stReq = $pdo->prepare("SELECT COALESCE(SUM(days),0) FROM hrl_leave_usages WHERE employee_id=? AND year=?");
        $stReq->execute([$employeeId, $year]);
        return (float)$stReq->fetchColumn();
    }
}

function leave_adj_sync_balance(PDO $pdo, int $employeeId, int $year): void {
    leave_adj_ensure_tables($pdo);
    $quotaInfo = leave_adj_quota_by_join($pdo, $employeeId, $year);
    $quota = (float)$quotaInfo['quota'];

    // Jika HRL sudah input saldo awal, kuota custom dari OPENING_BALANCE tetap menjadi override.
    // Ini menjaga saldo historis yang sudah disesuaikan HRL tidak tertimpa otomatis.
    $stAdjQ = $pdo->prepare("SELECT quota_days FROM hrl_leave_adjustments
                             WHERE employee_id=? AND year=? AND adjustment_type='OPENING_BALANCE'
                             ORDER BY id DESC LIMIT 1");
    $stAdjQ->execute([$employeeId, $year]);
    $aq = $stAdjQ->fetchColumn();
    if ($aq !== false && (float)$aq >= 0) $quota = (float)$aq;

    $usedRequests = leave_adj_approved_used($pdo, $employeeId, $year);

    $stAdj = $pdo->prepare("SELECT COALESCE(SUM(used_days),0) FROM hrl_leave_adjustments WHERE employee_id=? AND year=? AND adjustment_type='OPENING_BALANCE'");
    $stAdj->execute([$employeeId, $year]);
    $usedAdjustments = (float)$stAdj->fetchColumn();

    $used = $usedRequests + $usedAdjustments;

    $pdo->prepare("INSERT IGNORE INTO hrl_leave_balances (employee_id, year, quota_days, used_days, remaining_days, updated_at)
                   VALUES (?, ?, ?, 0, ?, NOW())")->execute([$employeeId, $year, $quota, $quota]);

    $pdo->prepare("UPDATE hrl_leave_balances
                   SET quota_days=?, used_days=?, remaining_days=GREATEST(0, ? - ?), updated_at=NOW()
                   WHERE employee_id=? AND year=?")
        ->execute([$quota, $used, $quota, $used, $employeeId, $year]);
}

$canManage = is_admin_owner() || is_dept('HRL');
if (!$canManage) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Akses ditolak. Hanya HRL / SYS yang boleh input saldo cuti.</div>";
    require_once __DIR__ . '/_layout_bottom.php';
    exit;
}

leave_adj_ensure_tables($pdo);

$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
if ($year < 2020 || $year > ((int)date('Y') + 2)) $year = (int)date('Y');
$office = strtoupper(trim((string)($_GET['office'] ?? '')));
$search = trim((string)($_GET['q'] ?? ''));

$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    csrf_check();
    try {
        if ($action === 'save_adjustment') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $yearPost = (int)($_POST['year'] ?? date('Y'));
            $quota = (float)($_POST['quota_days'] ?? 12);
            $used = (float)($_POST['used_days'] ?? 0);
            $targetRemainingRaw = trim((string)($_POST['target_remaining_days'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $editId = (int)($_POST['adjustment_id'] ?? 0);
            if ($employeeId <= 0) throw new RuntimeException('Karyawan wajib dipilih.');
            if ($yearPost < 2020 || $yearPost > ((int)date('Y') + 2)) throw new RuntimeException('Tahun tidak valid.');
            if ($quota < 0) throw new RuntimeException('Jatah cuti tidak boleh minus.');
            if ($used < 0) throw new RuntimeException('Cuti terpakai awal tidak boleh minus.');
            if ($used > $quota) throw new RuntimeException('Cuti terpakai awal tidak boleh lebih besar dari jatah cuti.');

            $actor = me_username();
            $oldRow = null;
            if ($editId > 0) {
                $stOld = $pdo->prepare("SELECT * FROM hrl_leave_adjustments WHERE id=? AND adjustment_type='OPENING_BALANCE' LIMIT 1");
                $stOld->execute([$editId]);
                $oldRow = $stOld->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$oldRow) throw new RuntimeException('Data saldo awal yang akan diedit tidak ditemukan.');
                if ((int)$oldRow['employee_id'] !== $employeeId || (int)$oldRow['year'] !== $yearPost) {
                    throw new RuntimeException('Karyawan/tahun tidak cocok dengan data saldo awal yang diedit.');
                }
                if ($note === '') throw new RuntimeException('Alasan koreksi wajib diisi saat edit saldo cuti.');

                // Pada mode edit, HRL mengoreksi SISA akhir yang benar.
                // Cuti approved di sistem tidak boleh dihapus/ditimpa; sistem menghitung balik
                // berapa pemakaian sebelum sistem (OPENING_BALANCE) yang diperlukan.
                if ($targetRemainingRaw === '') throw new RuntimeException('Sisa cuti yang benar wajib diisi saat edit.');
                $targetRemaining = (float)$targetRemainingRaw;
                if ($targetRemaining < 0) throw new RuntimeException('Sisa cuti tidak boleh minus.');
                if ($targetRemaining > $quota) throw new RuntimeException('Sisa cuti tidak boleh lebih besar dari jatah cuti.');
                $approvedUsed = leave_adj_approved_used($pdo, $employeeId, $yearPost);
                $maxRemaining = max(0.0, $quota - $approvedUsed);
                if ($targetRemaining > $maxRemaining + 0.0001) {
                    throw new RuntimeException('Sisa cuti tidak mungkin lebih besar dari jatah setelah dikurangi cuti approved (' . number_format($maxRemaining, 1, ',', '.') . ' hari).');
                }
                $used = max(0.0, $quota - $approvedUsed - $targetRemaining);

                $st = $pdo->prepare("UPDATE hrl_leave_adjustments
                    SET quota_days=?, used_days=?, note=?, updated_by=?, updated_at=NOW()
                    WHERE id=? AND adjustment_type='OPENING_BALANCE' LIMIT 1");
                $st->execute([$quota, $used, $note, $actor, $editId]);
                $event = 'LEAVE_OPENING_BALANCE_EDIT';
                $message = 'Koreksi saldo awal cuti';
            } else {
                if ($note === '') $note = 'Saldo awal cuti sebelum sistem berjalan.';

                // Jika saldo awal employee-year sudah ada, update record terakhir.
                // Jangan delete+insert agar ID/histori audit tidak putus.
                $stFind = $pdo->prepare("SELECT * FROM hrl_leave_adjustments
                    WHERE employee_id=? AND year=? AND adjustment_type='OPENING_BALANCE'
                    ORDER BY id DESC LIMIT 1");
                $stFind->execute([$employeeId, $yearPost]);
                $existing = $stFind->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existing) {
                    $oldRow = $existing;
                    $editId = (int)$existing['id'];
                    $st = $pdo->prepare("UPDATE hrl_leave_adjustments
                        SET quota_days=?, used_days=?, note=?, updated_by=?, updated_at=NOW()
                        WHERE id=? AND adjustment_type='OPENING_BALANCE' LIMIT 1");
                    $st->execute([$quota, $used, $note, $actor, $editId]);
                    $event = 'LEAVE_OPENING_BALANCE_EDIT';
                    $message = 'Perbarui saldo awal cuti';
                } else {
                    $st = $pdo->prepare("INSERT INTO hrl_leave_adjustments
                        (employee_id, year, adjustment_type, quota_days, used_days, note, created_by, created_at)
                        VALUES (?, ?, 'OPENING_BALANCE', ?, ?, ?, ?, NOW())");
                    $st->execute([$employeeId, $yearPost, $quota, $used, $note, $actor]);
                    $editId = (int)$pdo->lastInsertId();
                    $event = 'LEAVE_OPENING_BALANCE';
                    $message = 'Input saldo awal cuti';
                }
            }

            leave_adj_sync_balance($pdo, $employeeId, $yearPost);
            $newData = ['adjustment_id'=>$editId,'employee_id'=>$employeeId,'year'=>$yearPost,'quota_days'=>$quota,'used_days'=>$used,'note'=>$note];
            if (function_exists('hrlp_audit')) hrlp_audit($event, $newData);
            if (function_exists('master_audit')) master_audit($pdo, 'hrl_process', 'hrl_leave_adjustments', $event, $employeeId, 'LEAVE-'.$employeeId.'-'.$yearPost, $message, ['old'=>$oldRow,'new'=>$newData]);
            flash_set($event === 'LEAVE_OPENING_BALANCE' ? 'Saldo awal cuti berhasil disimpan.' : 'Saldo cuti berhasil dikoreksi dan dihitung ulang.', 'success');
        }
        if ($action === 'resync_balance') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $yearPost = (int)($_POST['year'] ?? date('Y'));
            if ($employeeId <= 0) throw new RuntimeException('Karyawan wajib dipilih.');
            leave_adj_sync_balance($pdo, $employeeId, $yearPost);
            flash_set('Saldo cuti berhasil dihitung ulang.', 'success');
        }
    } catch (Throwable $e) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
        } catch (Throwable $rollbackError) {}
        flash_set('Error: ' . $e->getMessage(), 'danger');
    }
    rmi_redirect(u('/hrl_process/leave_adjustment.php?year=' . urlencode((string)$year)));
}

$editEmployeeId = (int)($_GET['edit_employee_id'] ?? 0);
$editAdjustment = null;
if ($editEmployeeId > 0) {
    $stEdit = $pdo->prepare("SELECT a.*, e.employee_name, e.employee_code
        FROM hrl_leave_adjustments a
        JOIN master_employees e ON e.id=a.employee_id
        WHERE a.employee_id=? AND a.year=? AND a.adjustment_type='OPENING_BALANCE'
        ORDER BY a.id DESC LIMIT 1");
    $stEdit->execute([$editEmployeeId, $year]);
    $editAdjustment = $stEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editAdjustment) {
        $editApprovedUsed = leave_adj_approved_used($pdo, (int)$editAdjustment['employee_id'], $year);
        $editOpeningUsed = (float)($editAdjustment['used_days'] ?? 0);
        $editQuota = (float)($editAdjustment['quota_days'] ?? 0);
        $editTargetRemaining = max(0.0, $editQuota - $editApprovedUsed - $editOpeningUsed);
        $editAdjustment['_approved_used'] = $editApprovedUsed;
        $editAdjustment['_target_remaining'] = $editTargetRemaining;
    }
}

$empCols = leave_adj_table_columns($pdo, 'master_employees');
$params = [];
$where = [leave_adj_employee_active_where($empCols, $params)];

if ($office !== '') {
    $where[] = "UPPER(TRIM(COALESCE(office_code,'')))=?";
    $params[] = $office;
}
if ($search !== '') {
    $where[] = "(employee_name LIKE ? OR employee_code LIKE ? OR dept_code LIKE ? OR office_code LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$joinSelect = '';
foreach (['join_year','join_month','join_date','date_join','hire_date','tanggal_masuk'] as $jc) {
    if (isset($empCols[strtolower($jc)])) $joinSelect .= ', `' . $empCols[strtolower($jc)] . '` AS `' . $jc . '`';
}
$sql = "SELECT id, employee_code, employee_name, dept_code, office_code" . $joinSelect . "
        FROM master_employees
        WHERE " . implode(' AND ', $where) . "
        ORDER BY office_code, dept_code, employee_name LIMIT 2000";
$stEmp = $pdo->prepare($sql);
$stEmp->execute($params);
$employees = $stEmp->fetchAll(PDO::FETCH_ASSOC);
$employeeQuotaMap = [];
foreach ($employees as $eRow) {
    $eidMap = (int)($eRow['id'] ?? 0);
    if ($eidMap > 0) $employeeQuotaMap[$eidMap] = leave_adj_quota_by_join($pdo, $eidMap, $year);
}

$balanceWhere = "b.year=?";
$balanceParams = [$year];
$empActiveSql = leave_adj_employee_active_where($empCols, $balanceParams);
if ($empActiveSql !== '1=1') {
    $empActiveSql = str_replace('deleted_at', 'e.deleted_at', $empActiveSql);
    $empActiveSql = str_replace('status', 'e.status', $empActiveSql);
    $empActiveSql = str_replace('is_active', 'e.is_active', $empActiveSql);
    $balanceWhere .= " AND " . $empActiveSql;
}

$stBal = $pdo->prepare("SELECT b.*, e.employee_code, e.employee_name, e.dept_code, e.office_code,
           e.join_year, e.join_month
    FROM hrl_leave_balances b JOIN master_employees e ON e.id=b.employee_id
    WHERE {$balanceWhere} ORDER BY e.office_code, e.dept_code, e.employee_name LIMIT 1000");
$stBal->execute($balanceParams);
$balances = $stBal->fetchAll(PDO::FETCH_ASSOC);

$offices = [];
try { $offices = $pdo->query("SELECT DISTINCT UPPER(COALESCE(office_code,'')) AS office_code FROM master_employees WHERE COALESCE(office_code,'') <> '' ORDER BY office_code")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card rmi-card">
      <div class="card-header"><?= $editAdjustment ? 'Koreksi Saldo Cuti' : 'Input Saldo Awal Cuti' ?></div>
      <div class="card-body">
        <div class="alert alert-info"><?php if ($editAdjustment): ?>Mode koreksi: isi <strong>Sisa Cuti yang Benar</strong>. Cuti approved di sistem tetap dipertahankan dan sistem menghitung balik pemakaian sebelum sistem.<?php else: ?>Menu ini untuk cuti yang sudah dipakai sebelum sistem berjalan. Data ini hanya memperbaiki saldo cuti tahunan dan tidak masuk payroll periode berjalan.<?php endif; ?></div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_adjustment">
          <input type="hidden" name="adjustment_id" value="<?= (int)($editAdjustment['id'] ?? 0) ?>">
          <div class="mb-2"><label class="form-label">Tahun</label><input type="number" class="form-control" name="year" value="<?= h((string)$year) ?>" min="2020" max="<?= h((string)((int)date('Y') + 2)) ?>" <?= $editAdjustment ? 'readonly' : '' ?>></div>
          <div class="mb-2"><label class="form-label">Karyawan</label><?php if ($editAdjustment): ?><input type="hidden" name="employee_id" value="<?= (int)$editAdjustment['employee_id'] ?>"><?php endif; ?><select class="form-select" name="<?= $editAdjustment ? 'employee_display' : 'employee_id' ?>" required <?= $editAdjustment ? 'disabled' : '' ?>><option value="">-- pilih karyawan --</option><?php foreach ($employees as $e): 
                  $eidOpt = (int)$e['id'];
                  $qi = $employeeQuotaMap[$eidOpt] ?? ['quota'=>12,'join_label'=>'-','masa_kerja'=>'-','rule'=>''];
                ?><?php $isSel = $editAdjustment && (int)$editAdjustment['employee_id'] === $eidOpt; ?><option value="<?= $eidOpt ?>" <?= $isSel ? 'selected' : '' ?> data-quota="<?= h((string)($qi['quota'] ?? 12)) ?>" data-join="<?= h((string)($qi['join_label'] ?? '-')) ?>" data-masa="<?= h((string)($qi['masa_kerja'] ?? '-')) ?>" data-rule="<?= h((string)($qi['rule'] ?? '')) ?>"><?= h(($e['employee_name'] ?? '-') . ' - ' . ($e['employee_code'] ?? '-') . ' / ' . ($e['dept_code'] ?? '-') . ' / ' . ($e['office_code'] ?? '-') . ' / Masuk: ' . ($qi['join_label'] ?? '-') . ' / Hak: ' . number_format((float)($qi['quota'] ?? 12), 1, ',', '.') . ' hari') ?></option><?php endforeach; ?></select>
            <div class="help mt-1" id="quotaInfo">Pilih karyawan untuk melihat Join Year/Month dan hak cuti otomatis.</div>
          </div>
          <div class="row g-2 mb-2"><div class="col"><label class="form-label">Jatah Cuti</label><input type="number" step="0.5" class="form-control" name="quota_days" value="<?= h((string)($editAdjustment['quota_days'] ?? 12)) ?>" min="0" required></div><?php if ($editAdjustment): ?><div class="col"><label class="form-label">Cuti Approved Sistem</label><input type="number" step="0.5" class="form-control" value="<?= h((string)($editAdjustment['_approved_used'] ?? 0)) ?>" readonly></div><?php else: ?><div class="col"><label class="form-label">Dipakai Sebelum Sistem</label><input type="number" step="0.5" class="form-control" name="used_days" value="0" min="0" required></div><?php endif; ?></div>
          <?php if ($editAdjustment): ?><div class="mb-2"><label class="form-label">Sisa Cuti yang Benar</label><input type="number" step="0.5" class="form-control" name="target_remaining_days" value="<?= h((string)($editAdjustment['_target_remaining'] ?? 0)) ?>" min="0" max="<?= h((string)($editAdjustment['quota_days'] ?? 12)) ?>" required><div class="help mt-1">Isi saldo sisa yang seharusnya. Sistem akan menghitung otomatis pemakaian sebelum sistem tanpa mengubah cuti approved.</div></div><input type="hidden" name="used_days" value="<?= h((string)($editAdjustment['used_days'] ?? 0)) ?>"><?php endif; ?>
          <div class="mb-2"><label class="form-label">Catatan</label><textarea class="form-control" name="note" rows="3" placeholder="<?= $editAdjustment ? 'Wajib isi alasan koreksi saldo.' : 'Contoh: Saldo awal per Januari 2026 sebelum sistem berjalan.' ?>"><?= h((string)($editAdjustment['note'] ?? '')) ?></textarea></div>
          <div class="d-grid gap-2"><button class="btn btn-soft"><?= $editAdjustment ? 'Simpan Sisa yang Benar & Recalc' : 'Simpan Saldo Awal' ?></button><?php if ($editAdjustment): ?><a class="btn btn-ghost" href="<?= h(u('/hrl_process/leave_adjustment.php?year=' . urlencode((string)$year))) ?>">Batal Edit</a><?php endif; ?></div>
        </form>
      </div>
    </div>
    <div class="card rmi-card mt-3"><div class="card-header">Filter Karyawan</div><div class="card-body"><form method="get" class="row g-2"><div class="col-12"><label class="form-label">Tahun</label><input class="form-control" name="year" value="<?= h((string)$year) ?>"></div><div class="col-12"><label class="form-label">Office</label><select class="form-select" name="office"><option value="">Semua Office</option><?php foreach ($offices as $oc): ?><option value="<?= h($oc) ?>" <?= $office===$oc?'selected':'' ?>><?= h($oc) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label">Cari</label><input class="form-control" name="q" value="<?= h($search) ?>" placeholder="Nama/kode/dept"></div><div class="col-12 d-grid"><button class="btn btn-ghost">Tampilkan</button></div></form></div></div>
  </div>
  <div class="col-lg-8"><div class="card rmi-card"><div class="card-header d-flex justify-content-between align-items-center"><span>Saldo Cuti Tahunan <?= h((string)$year) ?></span><a class="btn btn-sm btn-ghost" href="<?= h(u('/dashboards/hrl/hrl_dashboard.php#saldo-cuti')) ?>">Kembali ke HRL Dashboard</a></div><div class="card-body"><?php if (!$balances): ?><div class="help">Belum ada saldo cuti untuk tahun ini.</div><?php else: ?><div class="table-wrap"><table class="table table-sm table-dark align-middle mb-0"><thead><tr><th>Karyawan</th><th>Dept</th><th>Office</th><th class="text-end">Jatah</th><th class="text-end">Terpakai</th><th class="text-end">Sisa</th><th>Update</th><th></th></tr></thead><tbody><?php foreach ($balances as $b): ?><tr><td><div class="fw-semibold"><?= h($b['employee_name'] ?? '-') ?></div><div class="help mono"><?= h($b['employee_code'] ?? '-') ?></div></td><td><?= h($b['dept_code'] ?? '-') ?></td><td><?= h($b['office_code'] ?? '-') ?></td><td class="text-end mono"><?= h(number_format((float)$b['quota_days'], 1, ',', '.')) ?></td><td class="text-end mono text-warning"><?= h(number_format((float)$b['used_days'], 1, ',', '.')) ?></td><td class="text-end mono <?= ((float)$b['remaining_days'] <= 2 ? 'text-danger' : 'text-success') ?>"><?= h(number_format((float)$b['remaining_days'], 1, ',', '.')) ?></td><td class="help"><?= h($b['updated_at'] ?? '-') ?></td><td class="text-nowrap"><a class="btn btn-sm btn-soft me-1" href="<?= h(u('/hrl_process/leave_adjustment.php?year=' . urlencode((string)$year) . '&edit_employee_id=' . (int)$b['employee_id'])) ?>">Edit Saldo</a><form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="resync_balance"><input type="hidden" name="employee_id" value="<?= (int)$b['employee_id'] ?>"><input type="hidden" name="year" value="<?= (int)$year ?>"><button class="btn btn-sm btn-ghost">Recalc</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div></div>
</div>

<script>
(function(){
  const sel = document.querySelector('select[name="employee_id"], select[name="employee_display"]');
  const quota = document.querySelector('input[name="quota_days"]');
  const info = document.getElementById('quotaInfo');
  if (!sel || !quota || !info) return;
  function refreshQuota(){
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
      info.textContent = 'Pilih karyawan untuk melihat Join Year/Month dan hak cuti otomatis.';
      return;
    }
    const q = opt.getAttribute('data-quota') || '12';
    const join = opt.getAttribute('data-join') || '-';
    const masa = opt.getAttribute('data-masa') || '-';
    const rule = opt.getAttribute('data-rule') || '';
    quota.value = q;
    info.textContent = 'Masuk kerja: ' + join + ' | Masa kerja: ' + masa + ' | Hak cuti sistem: ' + q + ' hari' + (rule ? ' (' + rule + ')' : '');
  }
  sel.addEventListener('change', refreshQuota);
})();
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
