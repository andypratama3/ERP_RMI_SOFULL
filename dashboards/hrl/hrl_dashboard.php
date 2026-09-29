<?php
/**
 * dashboards/hrl/hrl_dashboard.php
 * Human Resource & Legal Dashboard — Enhanced v2
 */
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
$pdo = $GLOBALS['pdo'] ?? null;
$bp  = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
function hrlUrl(string $path): string {
    $bp = $GLOBALS['BASE_PROJECT'] ?? '';
    return rtrim($bp, '/') . '/' . ltrim($path, '/');
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function hrl_table_exists(PDO $pdo, string $t): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$t]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function hrl_money(float $v): string {
    return 'Rp ' . number_format($v, 0, ',', '.');
}
function hrl_pct(int $a, int $b): string {
    if ($b <= 0) return '—';
    return number_format($a / $b * 100, 0) . '%';
}

// ── Filter params ─────────────────────────────────────────────────────────────
$filter_date   = trim((string)($_GET['d'] ?? ''));
$filter_office = strtoupper(trim((string)($_GET['office'] ?? '')));
if ($filter_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = date('Y-m-d');
}
$is_today = ($filter_date === date('Y-m-d'));

// ── Office color palette ──────────────────────────────────────────────────────
$office_palette = [
    ['bg'=>'rgba(37,99,235,.25)',  'color'=>'#93c5fd'],
    ['bg'=>'rgba(139,92,246,.25)', 'color'=>'#c4b5fd'],
    ['bg'=>'rgba(6,182,212,.25)',  'color'=>'#67e8f9'],
    ['bg'=>'rgba(16,185,129,.25)', 'color'=>'#6ee7b7'],
    ['bg'=>'rgba(245,158,11,.25)', 'color'=>'#fcd34d'],
    ['bg'=>'rgba(249,115,22,.25)', 'color'=>'#fdba74'],
    ['bg'=>'rgba(239,68,68,.25)',  'color'=>'#fca5a5'],
    ['bg'=>'rgba(236,72,153,.25)', 'color'=>'#f9a8d4'],
];
$office_color_map = [];
$all_offices      = [];

// ── Init data arrays ──────────────────────────────────────────────────────────
$kpi = [
    'employees_active'  => 0,
    'checkin_today'     => 0,
    'checkout_today'    => 0,
    'belum_checkout'    => 0,
    'not_present'       => 0,
    'late_count'        => 0,
    'pending_izin'      => 0,
    'pending_approval'  => 0,
    'reg_alkes_open'    => 0,
    'reg_alkes_expiring'=> 0, // total NIE expiring <=90d (30d + 31..90d)
    'reg_alkes_expiring_30' => 0,
    'reg_alkes_expiring_90' => 0,
    'reg_alkes_expired' => 0,
    'reg_alkes_revision_overdue' => 0,
    'reg_alkes_revision_due' => 0,
    'docs_total'        => 0,
    'mpr_visit_today'   => 0,
    'mpr_visit_month'   => 0,
    'mpr_followup_due'  => 0,
    'mpr_deal_month'    => 0,
    'manual_alpha_today' => 0,
    'manual_server_down_today' => 0,
];
$kpi_yesterday  = ['checkin_today' => 0]; // untuk delta
$absensi_today  = [];
$pending_izin   = [];
$expiring_alkes = [];
$dept_breakdown = [];
$payroll_status = null;
$mpr_visits_today = [];
$manual_attendance_today = [];
$leave_balances = [];
$leave_year = (int)date('Y', strtotime($filter_date));
$contract_reminders = [];
$mutation_kpi = ['pending'=>0,'scheduled'=>0,'effective_month'=>0];
$mpr_nav_url = hrlUrl('/mpr/mpr_visits.php');
$mpr_export_today_url = hrlUrl('/mpr/mpr_visits.php?export=1&month=' . date('Y-m', strtotime($filter_date)));
$trend_labels   = [];
$trend_hadir    = [];
$trend_total    = [];
$checkin_std    = '08:30'; // jam standar masuk

if ($pdo) {
    // ── Load offices ───────────────────────────────────────────────────────────
    try {
        foreach (['master_office','absensi_offices'] as $tbl) {
            if (hrl_table_exists($pdo, $tbl)) {
                $rows = $pdo->query("SELECT office_code FROM {$tbl} WHERE COALESCE(is_active,1)=1 ORDER BY office_code")->fetchAll(PDO::FETCH_COLUMN);
                $all_offices = array_map('strtoupper', $rows);
                break;
            }
        }
        foreach ($all_offices as $i => $code) {
            $office_color_map[$code] = $office_palette[$i % count($office_palette)];
        }
    } catch (Throwable $e) {}

    // ── Karyawan aktif + breakdown dept (mengikuti filter office) ─────────────
    try {
        if (hrl_table_exists($pdo, 'master_employees')) {
            $empOfficeSql = $filter_office !== '' ? " AND UPPER(COALESCE(office_code,''))=?" : '';
            $empParams = $filter_office !== '' ? [$filter_office] : [];

            $stEmp = $pdo->prepare(
                "SELECT COUNT(*) FROM master_employees
                 WHERE LOWER(COALESCE(status,''))='active'{$empOfficeSql}"
            );
            $stEmp->execute($empParams);
            $kpi['employees_active'] = (int)$stEmp->fetchColumn();

            $stDept = $pdo->prepare(
                "SELECT UPPER(COALESCE(dept_code,'—')) AS dept,
                        UPPER(COALESCE(office_code,'—')) AS office,
                        COUNT(*) AS cnt
                 FROM master_employees
                 WHERE LOWER(COALESCE(status,''))='active'{$empOfficeSql}
                 GROUP BY dept, office ORDER BY cnt DESC"
            );
            $stDept->execute($empParams);
            $dept_breakdown = $stDept->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}

    // ── Absensi — rekap per tanggal ────────────────────────────────────────────
    if (hrl_table_exists($pdo, 'absensi_logs')) {
        try {
            $baseOfficeFilter = $filter_office !== ''
                ? " AND UPPER(COALESCE(al.office_code,''))=?" : '';
            $baseParams = $filter_office !== '' ? [$filter_office] : [];

            // Checkin unik untuk tanggal dipilih
            $sql = "SELECT COUNT(DISTINCT al.user_id) FROM absensi_logs al
                    WHERE al.deleted_at IS NULL AND al.action_type='IN' AND DATE(al.created_at)=?" . $baseOfficeFilter;
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$filter_date], $baseParams));
            $kpi['checkin_today'] = (int)$st->fetchColumn();

            // Checkout unik
            $sql = "SELECT COUNT(DISTINCT al.user_id) FROM absensi_logs al
                    WHERE al.deleted_at IS NULL AND al.action_type='OUT' AND DATE(al.created_at)=?" . $baseOfficeFilter;
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$filter_date], $baseParams));
            $kpi['checkout_today'] = (int)$st->fetchColumn();
            $kpi['belum_checkout'] = max(0, $kpi['checkin_today'] - $kpi['checkout_today']);

            // Kemarin (untuk delta)
            $yesterday = date('Y-m-d', strtotime($filter_date . ' -1 day'));
            $st = $pdo->prepare("SELECT COUNT(DISTINCT al.user_id) FROM absensi_logs al
                                 WHERE al.deleted_at IS NULL AND al.action_type='IN' AND DATE(al.created_at)=?" . $baseOfficeFilter);
            $st->execute(array_merge([$yesterday], $baseParams));
            $kpi_yesterday['checkin_today'] = (int)$st->fetchColumn();

            // Tidak hadir (aktif tapi tidak checkin) — hanya jika ada master_employees
            if ($kpi['employees_active'] > 0) {
                $kpi['not_present'] = max(0, $kpi['employees_active'] - $kpi['checkin_today']);
            }

            // Terlambat (checkin > jam standar)
            $sql = "SELECT COUNT(DISTINCT al.user_id) FROM absensi_logs al
                    WHERE al.deleted_at IS NULL AND al.action_type='IN' AND DATE(al.created_at)=?
                      AND TIME(al.created_at) > ?" . $baseOfficeFilter;
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$filter_date, $checkin_std], $baseParams));
            $kpi['late_count'] = (int)$st->fetchColumn();

            // Rekap per-user
            $offJoin = $filter_office !== ''
                ? " AND UPPER(COALESCE(al.office_code,''))='{$filter_office}'" : '';
            $stAbs = $pdo->prepare("
                SELECT
                    al.user_id,
                    al.username,
                    COALESCE(
                        MAX(p.office_code),
                        MAX(CASE WHEN al.action_type='IN' THEN al.office_code END),
                        MAX(al.office_code)
                    ) AS office_code,
                    MAX(COALESCE(e.employee_name, m.full_name, '')) AS employee_name,
                    MAX(CASE WHEN al.action_type='IN'  THEN al.created_at END) AS checkin_at,
                    MAX(CASE WHEN al.action_type='OUT' THEN al.created_at END) AS checkout_at,
                    MAX(CASE WHEN al.action_type='IN'  THEN al.photo_path END) AS photo_in,
                    MAX(CASE WHEN al.action_type='OUT' THEN al.photo_path END) AS photo_out
                FROM absensi_logs al
                LEFT JOIN absensi_user_profile p  ON p.user_id = al.user_id
                LEFT JOIN master_system_login m    ON m.id = al.user_id AND m.deleted_at IS NULL
                LEFT JOIN master_employees e       ON e.employee_code = m.holder_employee_code
                WHERE al.deleted_at IS NULL AND DATE(al.created_at) = ?{$offJoin}
                GROUP BY al.user_id, al.username
                ORDER BY checkin_at DESC
                LIMIT 300
            ");
            $stAbs->execute([$filter_date]);
            $absensi_today = $stAbs->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {}
    }


    // ── Manual attendance exception HRL ────────────────────────────────────────
    // Dipakai untuk kondisi khusus: server down/error, lupa absen yang disetujui,
    // atau ALPA resmi yang diinput HRL. Tidak boleh menjadikan semua yang tidak
    // check-in sebagai alpha otomatis.
    if (hrl_table_exists($pdo, 'absensi_manual_attendance')) {
        try {
            $manOfficeSql = $filter_office !== '' ? " AND UPPER(COALESCE(office_code,''))=?" : '';
            $manParams = $filter_office !== '' ? [$filter_office] : [];

            $st = $pdo->prepare("SELECT COUNT(*)
                FROM absensi_manual_attendance
                WHERE deleted_at IS NULL
                  AND tanggal = ?
                  AND status = 'ALPA'{$manOfficeSql}");
            $st->execute(array_merge([$filter_date], $manParams));
            $kpi['manual_alpha_today'] = (int)$st->fetchColumn();

            $st = $pdo->prepare("SELECT COUNT(*)
                FROM absensi_manual_attendance
                WHERE deleted_at IS NULL
                  AND tanggal = ?
                  AND status IN ('SERVER_DOWN','HADIR_MANUAL'){$manOfficeSql}");
            $st->execute(array_merge([$filter_date], $manParams));
            $kpi['manual_server_down_today'] = (int)$st->fetchColumn();

            $st = $pdo->prepare("SELECT *
                FROM absensi_manual_attendance
                WHERE deleted_at IS NULL
                  AND tanggal = ?{$manOfficeSql}
                ORDER BY id DESC
                LIMIT 20");
            $st->execute(array_merge([$filter_date], $manParams));
            $manual_attendance_today = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $manual_attendance_today = [];
        }
    }

    // ── Pending izin/cuti ──────────────────────────────────────────────────────
    if (hrl_table_exists($pdo, 'absensi_requests')) {
        try {
            $reqOfficeSql = $filter_office !== '' ? " AND UPPER(COALESCE(e.office_code,''))=?" : '';
            $reqParams = $filter_office !== '' ? [$filter_office] : [];

            $stReqCount = $pdo->prepare("
                SELECT COUNT(*)
                FROM absensi_requests r
                LEFT JOIN master_system_login m ON m.username=r.username AND m.deleted_at IS NULL
                LEFT JOIN master_employees e ON e.employee_code=m.holder_employee_code
                WHERE r.status='PENDING'
                  AND COALESCE(r.deleted_at,'0000-00-00')='0000-00-00'{$reqOfficeSql}
            ");
            $stReqCount->execute($reqParams);
            $kpi['pending_izin'] = (int)$stReqCount->fetchColumn();

            $stReq = $pdo->prepare("
                SELECT r.id, r.username, r.req_type, r.start_date, r.end_date, r.reason,
                       COALESCE(e.employee_name, m.full_name, r.username) AS employee_name,
                       COALESCE(m.department,'') AS dept
                FROM absensi_requests r
                LEFT JOIN master_system_login m  ON m.username = r.username AND m.deleted_at IS NULL
                LEFT JOIN master_employees e     ON e.employee_code = m.holder_employee_code
                WHERE r.status='PENDING' AND COALESCE(r.deleted_at,'0000-00-00')='0000-00-00'{$reqOfficeSql}
                ORDER BY r.start_date ASC
                LIMIT 30
            ");
            $stReq->execute($reqParams);
            $pending_izin = $stReq->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    // ── Pending approval dokumen HRL ───────────────────────────────────────────
    try {
        if (hrl_table_exists($pdo, 'hrl_doc_versions')) {
            $kpi['pending_approval'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_doc_versions WHERE status='SUBMITTED' AND deleted_at IS NULL"
            )->fetchColumn();
        }
        if (hrl_table_exists($pdo, 'hrl_docs')) {
            $kpi['docs_total'] = (int)$pdo->query("SELECT COUNT(*) FROM hrl_docs WHERE deleted_at IS NULL")->fetchColumn();
        }
    } catch (Throwable $e) {}

    // ── Reg Alkes — workflow deadline vs NIE expiry (WAJIB dipisah) ───────────
    // revision_deadline = SLA revisi Stage 11–13 (+10 hari), BUKAN masa berlaku NIE.
    // NIE expiry monitoring mengikuti field canonical nie_expiry_date, dengan fallback
    // legacy nie_issue_date agar sinkron dengan reg_alkes_expiry_check.php.
    if (hrl_table_exists($pdo, 'hrl_reg_alkes_cases')) {
        try {
            $kpi['reg_alkes_open'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_reg_alkes_cases
                 WHERE UPPER(COALESCE(status,'')) NOT IN ('CLOSED','DONE','COMPLETED')"
            )->fetchColumn();

            $regCols = [];
            try {
                $stCols = $pdo->query("SHOW COLUMNS FROM hrl_reg_alkes_cases");
                foreach ($stCols->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $regCols[] = (string)($c['Field'] ?? '');
                }
            } catch (Throwable $ignore) {}

            $hasNieExpiry = in_array('nie_expiry_date', $regCols, true);
            $hasNieIssue  = in_array('nie_issue_date', $regCols, true);

            if ($hasNieExpiry && $hasNieIssue) {
                // Canonical first. Legacy fallback hanya dipakai ketika field canonical kosong.
                $nieExpiryExpr = "COALESCE(NULLIF(nie_expiry_date,'0000-00-00'), CASE WHEN nie_issue_date >= CURDATE() THEN nie_issue_date ELSE NULL END)";
                $nieExpirySourceExpr = "CASE WHEN nie_expiry_date IS NOT NULL AND nie_expiry_date <> '0000-00-00' THEN 'nie_expiry_date' ELSE 'legacy_nie_issue_date' END";
            } elseif ($hasNieExpiry) {
                $nieExpiryExpr = "NULLIF(nie_expiry_date,'0000-00-00')";
                $nieExpirySourceExpr = "'nie_expiry_date'";
            } elseif ($hasNieIssue) {
                // Schema legacy: nilai tanggal lama masih dipakai sampai field canonical tersedia.
                $nieExpiryExpr = "NULLIF(nie_issue_date,'0000-00-00')";
                $nieExpirySourceExpr = "'legacy_nie_issue_date'";
            } else {
                $nieExpiryExpr = 'NULL';
                $nieExpirySourceExpr = "'unavailable'";
            }

            $nieBaseWhere = "nie_no IS NOT NULL AND TRIM(nie_no) <> '' AND ({$nieExpiryExpr}) IS NOT NULL";

            // Masa berlaku NIE: sama persis dengan bucket expiry checker.
            $kpi['reg_alkes_expired'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_reg_alkes_cases
                 WHERE {$nieBaseWhere}
                   AND ({$nieExpiryExpr}) < CURDATE()"
            )->fetchColumn();

            $kpi['reg_alkes_expiring_30'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_reg_alkes_cases
                 WHERE {$nieBaseWhere}
                   AND ({$nieExpiryExpr}) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
            )->fetchColumn();

            $kpi['reg_alkes_expiring_90'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_reg_alkes_cases
                 WHERE {$nieBaseWhere}
                   AND ({$nieExpiryExpr}) > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                   AND ({$nieExpiryExpr}) <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
            )->fetchColumn();
            $kpi['reg_alkes_expiring'] = $kpi['reg_alkes_expiring_30'] + $kpi['reg_alkes_expiring_90'];

            // Workflow revisi: hanya stage revisi yang boleh dinilai dari revision_deadline.
            $workflowBase = "revision_deadline IS NOT NULL
                             AND stage_no BETWEEN 11 AND 13
                             AND UPPER(COALESCE(status,'')) NOT IN ('CLOSED','DONE','COMPLETED')";
            $kpi['reg_alkes_revision_overdue'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_reg_alkes_cases
                 WHERE {$workflowBase}
                   AND revision_deadline < CURDATE()"
            )->fetchColumn();
            $kpi['reg_alkes_revision_due'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_reg_alkes_cases
                 WHERE {$workflowBase}
                   AND revision_deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 10 DAY)"
            )->fetchColumn();

            // Daftar NIE yang benar-benar expired / akan expired <=90 hari.
            $stExp = $pdo->query("
                SELECT case_code, product_name, nie_no,
                       ({$nieExpiryExpr}) AS nie_expiry_date,
                       {$nieExpirySourceExpr} AS expiry_date_source,
                       status
                FROM hrl_reg_alkes_cases
                WHERE {$nieBaseWhere}
                  AND ({$nieExpiryExpr}) <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                ORDER BY ({$nieExpiryExpr}) ASC, id ASC
                LIMIT 20
            ");
            $expiring_alkes = $stExp->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $expiring_alkes = [];
        }
    }

    // ── Payroll status bulan ini ───────────────────────────────────────────────
    if (hrl_table_exists($pdo, 'payroll_runs')) {
        try {
            $curPeriod = date('Y-m', strtotime($filter_date));
            $st = $pdo->prepare("SELECT id, period_ym, status,
                    (SELECT COALESCE(SUM(net_pay),0) FROM payroll_run_items WHERE run_id=payroll_runs.id) AS total_net,
                    (SELECT COUNT(*) FROM payroll_run_items WHERE run_id=payroll_runs.id) AS emp_count
                FROM payroll_runs WHERE period_ym=? ORDER BY id DESC LIMIT 1");
            $st->execute([$curPeriod]);
            $payroll_status = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }

    // ── MPR Visit Report — daily navigation for HRL dashboard ───────────────────
    // Sumber data: modul MPR / mpr_visits.php (tabel mpr_visits + mpr_plans).
    if (hrl_table_exists($pdo, 'mpr_visits') && hrl_table_exists($pdo, 'mpr_plans')) {
        try {
            $mprOfficeSql = $filter_office !== '' ? " AND UPPER(COALESCE(p.office_code,'')) = ?" : '';
            $mprOfficeParams = $filter_office !== '' ? [$filter_office] : [];
            $mprMonth = date('Y-m', strtotime($filter_date));

            $st = $pdo->prepare("SELECT COUNT(*)
                FROM mpr_visits v
                JOIN mpr_plans p ON p.id = v.plan_id
                WHERE v.deleted_at IS NULL
                  AND DATE(v.visit_date) = ?{$mprOfficeSql}");
            $st->execute(array_merge([$filter_date], $mprOfficeParams));
            $kpi['mpr_visit_today'] = (int)$st->fetchColumn();

            $st = $pdo->prepare("SELECT COUNT(*)
                FROM mpr_visits v
                JOIN mpr_plans p ON p.id = v.plan_id
                WHERE v.deleted_at IS NULL
                  AND DATE_FORMAT(v.visit_date, '%Y-%m') = ?{$mprOfficeSql}");
            $st->execute(array_merge([$mprMonth], $mprOfficeParams));
            $kpi['mpr_visit_month'] = (int)$st->fetchColumn();

            $st = $pdo->prepare("SELECT COUNT(*)
                FROM mpr_visits v
                JOIN mpr_plans p ON p.id = v.plan_id
                WHERE v.deleted_at IS NULL
                  AND v.outcome = 'DEAL_WON'
                  AND DATE_FORMAT(v.visit_date, '%Y-%m') = ?{$mprOfficeSql}");
            $st->execute(array_merge([$mprMonth], $mprOfficeParams));
            $kpi['mpr_deal_month'] = (int)$st->fetchColumn();

            $st = $pdo->prepare("SELECT COUNT(*)
                FROM mpr_visits v
                JOIN mpr_plans p ON p.id = v.plan_id
                WHERE v.deleted_at IS NULL
                  AND v.next_followup_date IS NOT NULL
                  AND v.next_followup_date <= ?
                  AND COALESCE(v.outcome,'') <> 'DEAL_WON'{$mprOfficeSql}");
            $st->execute(array_merge([$filter_date], $mprOfficeParams));
            $kpi['mpr_followup_due'] = (int)$st->fetchColumn();

            $st = $pdo->prepare("SELECT
                    v.id, v.visit_date, v.visit_type, v.outcome, v.customer_name, v.contact_name,
                    v.visit_city, v.result, v.next_followup_date, v.visitor_username,
                    p.plan_code, p.title AS plan_title, p.office_code
                FROM mpr_visits v
                JOIN mpr_plans p ON p.id = v.plan_id
                WHERE v.deleted_at IS NULL
                  AND DATE(v.visit_date) = ?{$mprOfficeSql}
                ORDER BY v.id DESC
                LIMIT 8");
            $st->execute(array_merge([$filter_date], $mprOfficeParams));
            $mpr_visits_today = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $mpr_visits_today = [];
        }
    }


    // ── Saldo cuti tahunan dari HRL Process ───────────────────────────────────
    if (hrl_table_exists($pdo, 'hrl_leave_balances') && hrl_table_exists($pdo, 'master_employees')) {
        try {
            $lbOfficeSql = $filter_office !== '' ? " AND UPPER(COALESCE(e.office_code,'')) = ?" : '';
            $lbParams = $filter_office !== '' ? [$leave_year, $filter_office] : [$leave_year];
            $stLb = $pdo->prepare("SELECT
                    b.employee_id, b.year, b.quota_days, b.used_days, b.remaining_days, b.updated_at,
                    e.employee_code, e.employee_name, e.dept_code, e.office_code
                FROM hrl_leave_balances b
                JOIN master_employees e ON e.id = b.employee_id
                WHERE b.year = ?{$lbOfficeSql}
                ORDER BY e.office_code, e.dept_code, e.employee_name
                LIMIT 300");
            $stLb->execute($lbParams);
            $leave_balances = $stLb->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $leave_balances = []; }
    }

    // ── Pengingat masa kontrak karyawan ───────────────────────────────────────
    if (hrl_table_exists($pdo, 'master_employees')) {
        try {
            $cols = [];
            $stCols = $pdo->query("SHOW COLUMNS FROM master_employees");
            foreach ($stCols->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            if (isset($cols['contract_end_year']) && isset($cols['contract_end_month'])) {
                $contractOfficeSql = $filter_office !== '' ? " AND UPPER(COALESCE(office_code,'')) = ?" : '';
                $contractParams = $filter_office !== '' ? [$filter_office] : [];
                $stContract = $pdo->prepare("
                    SELECT id, employee_code, employee_name, dept_code, office_code,
                           join_year, join_month, contract_end_year, contract_end_month,
                           DATEDIFF(
                             LAST_DAY(STR_TO_DATE(CONCAT(contract_end_year,'-',LPAD(contract_end_month,2,'0'),'-01'), '%Y-%m-%d')),
                             CURDATE()
                           ) AS days_left
                    FROM master_employees
                    WHERE LOWER(COALESCE(status,''))='active'
                      AND COALESCE(contract_end_year,'') <> ''
                      AND COALESCE(contract_end_month,'') <> ''
                      {$contractOfficeSql}
                    HAVING days_left <= 90
                    ORDER BY days_left ASC, office_code, dept_code, employee_name
                    LIMIT 30
                ");
                $stContract->execute($contractParams);
                $contract_reminders = $stContract->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            $contract_reminders = [];
$mutation_kpi = ['pending'=>0,'scheduled'=>0,'effective_month'=>0];
        }
    }


    // ── Mutasi Karyawan ───────────────────────────────────────────────────────
    // Read-only monitoring. Source of truth tetap workflow hrl_employee_mutations.
    if (hrl_table_exists($pdo, 'hrl_employee_mutations')) {
        try {
            $mutation_kpi['pending'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_employee_mutations
                 WHERE status IN ('SUBMITTED','APPROVED')"
            )->fetchColumn();

            $mutation_kpi['scheduled'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM hrl_employee_mutations
                 WHERE status='SCHEDULED'"
            )->fetchColumn();

            $mutMonth = date('Y-m', strtotime($filter_date));
            $stMut = $pdo->prepare(
                "SELECT COUNT(*) FROM hrl_employee_mutations
                 WHERE status='EFFECTIVE'
                   AND DATE_FORMAT(effective_date,'%Y-%m')=?"
            );
            $stMut->execute([$mutMonth]);
            $mutation_kpi['effective_month'] = (int)$stMut->fetchColumn();
        } catch (Throwable $e) {
            $mutation_kpi = ['pending'=>0,'scheduled'=>0,'effective_month'=>0];
        }
    }

    // ── Trend 30 hari (absensi) ────────────────────────────────────────────────
    if (hrl_table_exists($pdo, 'absensi_logs')) {
        try {
            for ($i = 29; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime($filter_date . " -{$i} day"));
                $trend_labels[] = date('d/m', strtotime($d));
                $trend_hadir[]  = 0;
                $trend_total[]  = 0;
            }
            $trendStart = date('Y-m-d', strtotime($filter_date . ' -29 day'));
            $trendOfficeSql = $filter_office !== '' ? " AND UPPER(COALESCE(office_code,''))=?" : '';
            $trendParams = $filter_office !== '' ? [$trendStart, $filter_date, $filter_office] : [$trendStart, $filter_date];
            $st = $pdo->prepare(
                "SELECT DATE(created_at) AS d, COUNT(DISTINCT user_id) AS cnt
                 FROM absensi_logs
                 WHERE deleted_at IS NULL AND action_type='IN'
                   AND DATE(created_at) BETWEEN ? AND ?{$trendOfficeSql}
                 GROUP BY DATE(created_at)"
            );
            $st->execute($trendParams);
            $trendMap = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $trendMap[$r['d']] = (int)$r['cnt']; }

            for ($i = 29; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime($filter_date . " -{$i} day"));
                $idx = 29 - $i;
                $trend_hadir[$idx] = $trendMap[$d] ?? 0;
                $trend_total[$idx] = $kpi['employees_active'];
            }
        } catch (Throwable $e) {}
    }
}

// ── Group absensi by office ────────────────────────────────────────────────────
$absensi_by_office = [];
foreach ($absensi_today as $row) {
    $oc = strtoupper(trim((string)($row['office_code'] ?? '—')));
    if ($oc === '') $oc = '—';
    $absensi_by_office[$oc][] = $row;
}
ksort($absensi_by_office);

// Delta checkin vs kemarin
$delta_checkin = $kpi['checkin_today'] - $kpi_yesterday['checkin_today'];

// JSON untuk chart
$chart_labels = json_encode($trend_labels);
$chart_hadir  = json_encode($trend_hadir);
$chart_total  = json_encode(array_map(fn($v) => $v > 0 ? $v : null, $trend_total));

// ── Layout ────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../_shared/rmi_layout.php';

$extraCSS = <<<'CSS'
:root{--hrl-card:#141f36;--hrl-border:rgba(255,255,255,.09);--hrl-muted:#8b9bb4}
.hrl-card{background:var(--hrl-card);border:1px solid var(--hrl-border);border-radius:14px}
/* ── KPI Tiles ── */
.hrl-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px}
.hrl-tile{background:rgba(255,255,255,.04);border:1px solid var(--hrl-border);border-radius:12px;padding:14px 16px;transition:.15s}
.hrl-tile:hover{border-color:rgba(6,182,212,.3);transform:translateY(-1px)}
.hrl-tile .tl-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--hrl-muted);margin-bottom:6px}
.hrl-tile .tl-val{font-size:24px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums;color:#e2e8f0}
.hrl-tile .tl-sub{font-size:11px;color:var(--hrl-muted);margin-top:5px}
.hrl-tile .tl-delta{font-size:11px;font-weight:700;margin-top:4px}
.td-up{color:#4ade80}.td-dn{color:#f87171}.td-neu{color:var(--hrl-muted)}
/* ── Alert strip ── */
.hrl-alert-strip{display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid;margin-bottom:14px;font-size:12px;font-weight:600;align-items:center}
.alert-warn{background:rgba(251,191,36,.08);border-color:rgba(251,191,36,.3);color:#fbbf24}
.alert-danger{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.3);color:#f87171}
.alert-ok{background:rgba(34,197,94,.06);border-color:rgba(34,197,94,.2);color:#4ade80}
/* ── Section heading ── */
.hrl-sh{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--hrl-muted);margin:18px 0 10px;display:flex;align-items:center;gap:8px}
.hrl-sh::after{content:"";flex:1;height:1px;background:var(--hrl-border)}
/* ── Filter bar ── */
.hrl-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:12px 16px;background:var(--hrl-card);border:1px solid var(--hrl-border);border-radius:12px;margin-bottom:16px}
.hrl-filter label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--hrl-muted);display:block;margin-bottom:3px}
.hrl-filter .form-control,.hrl-filter .form-select{background:rgba(255,255,255,.05)!important;border-color:rgba(255,255,255,.12)!important;color:#e2e8f0!important;font-size:13px;min-width:0}
/* ── Absensi table ── */
.hrl-table{width:100%;border-collapse:collapse;font-size:13px}
.hrl-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--hrl-muted);padding:8px 10px;border-bottom:1px solid var(--hrl-border);text-align:left;white-space:nowrap}
.hrl-table td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.hrl-table tr:last-child td{border-bottom:none}
.hrl-table tr:hover td{background:rgba(255,255,255,.02)}
/* ── Office group header ── */
.hrl-grp-head{display:flex;align-items:center;gap:10px;padding:8px 12px;background:rgba(255,255,255,.03);border-bottom:1px solid var(--hrl-border);font-size:12px;font-weight:700}
/* ── Badge ── */
.hrl-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:8px;font-size:11px;font-weight:700}
/* ── Izin request card ── */
.hrl-req-card{border:1px solid var(--hrl-border);border-radius:10px;padding:10px 14px;font-size:13px;display:flex;align-items:flex-start;gap:12px}
.hrl-req-card .req-type{font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:6px;white-space:nowrap}
.req-IZIN{background:rgba(6,182,212,.15);color:#67e8f9;border:1px solid rgba(6,182,212,.25)}
.req-SAKIT{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.25)}
.req-DINAS{background:rgba(139,92,246,.15);color:#c4b5fd;border:1px solid rgba(139,92,246,.25)}
/* ── Alkes expiry row ── */
.alkes-expired{color:#f87171}.alkes-warn{color:#fbbf24}.alkes-ok{color:#4ade80}

.contract-expired{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
.contract-soon{background:rgba(251,191,36,.15);color:#fde68a;border:1px solid rgba(251,191,36,.3)}
.contract-watch{background:rgba(59,130,246,.15);color:#bfdbfe;border:1px solid rgba(59,130,246,.3)}

/* ── Quick links ── */
.hrl-links{display:flex;flex-wrap:wrap;gap:8px}
.hrl-link{border-radius:10px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#e8ecf4;padding:7px 13px;text-decoration:none;font-size:13px;font-weight:500;transition:.15s}
.hrl-link:hover{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.28)}
/* ── Chart wrap ── */
.mpr-daily-nav{display:grid;grid-template-columns:minmax(230px,300px) 1fr;gap:14px;margin-bottom:16px}
.mpr-side{background:linear-gradient(180deg,rgba(20,184,166,.10),rgba(59,130,246,.06));border:1px solid rgba(20,184,166,.25);border-radius:14px;padding:14px;position:sticky;top:12px}
.mpr-side-title{font-size:13px;font-weight:800;color:#f8fafc;margin-bottom:4px}
.mpr-side-sub{font-size:11px;color:var(--hrl-muted);margin-bottom:12px}
.mpr-side-link{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;margin-bottom:7px;border-radius:10px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.05);color:#e2e8f0;text-decoration:none;font-size:12px;font-weight:700}
.mpr-side-link:hover{background:rgba(20,184,166,.16);border-color:rgba(20,184,166,.35);color:#fff}
.mpr-mini-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:10px}
.mpr-mini{border:1px solid rgba(255,255,255,.08);background:rgba(15,23,42,.70);border-radius:10px;padding:8px;text-align:center}
.mpr-mini .v{font-size:20px;font-weight:900;color:#67e8f9;line-height:1}.mpr-mini .l{font-size:9px;color:var(--hrl-muted);text-transform:uppercase;margin-top:4px}
.mpr-today-card{background:var(--hrl-card);border:1px solid var(--hrl-border);border-radius:14px;overflow:hidden}
.mpr-today-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-bottom:1px solid var(--hrl-border)}
.mpr-visit-row{display:flex;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.05)}.mpr-visit-row:last-child{border-bottom:0}
.mpr-visit-main{flex:1;min-width:0}.mpr-visit-title{font-size:13px;font-weight:800;color:#f8fafc}.mpr-visit-meta{font-size:11px;color:var(--hrl-muted);margin-top:3px}.mpr-pill{display:inline-flex;align-items:center;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:800;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#cbd5e1;white-space:nowrap}
@media(max-width:900px){.mpr-daily-nav{grid-template-columns:1fr}.mpr-side{position:static}}
.hrl-chart-wrap{position:relative;height:200px}
/* ── Photo thumb ── */
.hrl-photo img{width:38px;height:38px;object-fit:cover;border-radius:6px;border:1px solid rgba(255,255,255,.12);cursor:zoom-in}
/* ── Lightbox ── */
#hrlPhotoModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;align-items:center;justify-content:center;cursor:zoom-out}
#hrlPhotoModal img{max-width:90vw;max-height:90vh;border-radius:10px;box-shadow:0 8px 48px #000}
.leave-bar{height:7px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden;min-width:90px}.leave-bar span{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#06b6d4);border-radius:999px}
CSS;

rmi_header('HRL Dashboard', [
    'active'     => 'dashboard',
    'subtitle'   => 'Human Resource & Legal — ' . h(date('d F Y', strtotime($filter_date))),
    'extra_head' => '<style>' . $extraCSS . '</style>'
        . '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>',
    'actions'    => [
        ['label' => rmi_icon('books').' Panduan', 'url' => hrlUrl('/dashboards/hrl/panduan.php')],
        ['label' => rmi_icon('clipboard').' Absensi Admin', 'url' => hrlUrl('/absensi/admin/rekap.php')],
        ['label' => rmi_icon('chart').' KPI Center',    'url' => hrlUrl('/kpi/kpi_center.php')],
    ],
]);
?>

<!-- ── Filter bar ──────────────────────────────────────────────────────────── -->
<form class="hrl-filter" method="get">
    <div>
    <label>Tanggal</label>
    <input type="date" name="d" class="form-control form-control-sm" value="<?= h($filter_date) ?>" style="width:140px">
  </div>
  <div style="flex:1;min-width:120px">
    <label>Office</label>
    <select name="office" class="form-select form-select-sm">
      <option value="">Semua Office</option>
      <?php foreach ($all_offices as $oc): ?>
        <option value="<?= h($oc) ?>" <?= $filter_office===$oc?'selected':'' ?>><?= h($oc) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;gap:6px;align-items:flex-end">
    <button class="btn btn-sm btn-rmi" type="submit">Tampilkan</button>
    <?php if (!$is_today || $filter_office !== ''): ?>
      <a class="btn btn-sm btn-ghost" href="hrl_dashboard.php">Reset</a>
    <?php endif; ?>
    <?php if (!$is_today): ?>
      <span style="font-size:11px;color:#fbbf24;align-self:center"><?=rmi_icon('calendar')?> Data historis: <?= h(date('d M Y', strtotime($filter_date))) ?></span>
    <?php endif; ?>
  </div>
</form>

<?php
// ── Alert strips ──────────────────────────────────────────────────────────────
if ($kpi['pending_izin'] > 0): ?>
<div class="hrl-alert-strip alert-warn">
  <?=rmi_icon('warn')?> <b><?= $kpi['pending_izin'] ?></b> request izin/cuti menunggu approval
  <a href="<?= h(hrlUrl('/absensi/approval.php')) ?>" style="margin-left:auto;color:inherit;text-decoration:underline">Approve sekarang →</a>
</div>
<?php endif; ?>

<?php if ($kpi['reg_alkes_expired'] > 0): ?>
<div class="hrl-alert-strip alert-danger">
  <?=rmi_icon('cross')?> <b><?= $kpi['reg_alkes_expired'] ?></b> NIE Reg Alkes sudah kedaluwarsa
  <a href="<?= h(hrlUrl('/hrl_reg_alkes/reg_alkes_expiry_check.php')) ?>" style="margin-left:auto;color:inherit;text-decoration:underline">Lihat expiry NIE →</a>
</div>
<?php endif; ?>
<?php if ($kpi['reg_alkes_expiring_30'] > 0): ?>
<div class="hrl-alert-strip alert-warn">
  <?=rmi_icon('warn')?> <b><?= $kpi['reg_alkes_expiring_30'] ?></b> NIE Reg Alkes akan kedaluwarsa dalam 30 hari
  <a href="<?= h(hrlUrl('/hrl_reg_alkes/reg_alkes_expiry_check.php')) ?>" style="margin-left:auto;color:inherit;text-decoration:underline">Lihat expiry NIE →</a>
</div>
<?php endif; ?>
<?php if ($kpi['reg_alkes_expiring_90'] > 0): ?>
<div class="hrl-alert-strip alert-warn">
  <?=rmi_icon('warn')?> <b><?= $kpi['reg_alkes_expiring_90'] ?></b> NIE Reg Alkes akan kedaluwarsa dalam 31–90 hari
  <a href="<?= h(hrlUrl('/hrl_reg_alkes/reg_alkes_expiry_check.php')) ?>" style="margin-left:auto;color:inherit;text-decoration:underline">Lihat expiry NIE →</a>
</div>
<?php endif; ?>

<?php if ($kpi['reg_alkes_revision_overdue'] > 0): ?>
<div class="hrl-alert-strip alert-danger">
  <?=rmi_icon('calendar')?> <b><?= $kpi['reg_alkes_revision_overdue'] ?></b> revisi Reg Alkes melewati deadline workflow (Stage 11–13)
  <a href="<?= h(hrlUrl('/hrl_reg_alkes/reg_alkes_control_tower.php')) ?>" style="margin-left:auto;color:inherit;text-decoration:underline">Buka Control Tower →</a>
</div>
<?php endif; ?>
<?php if ($kpi['reg_alkes_revision_due'] > 0): ?>
<div class="hrl-alert-strip alert-warn">
  <?=rmi_icon('refresh')?> <b><?= $kpi['reg_alkes_revision_due'] ?></b> revisi Reg Alkes memiliki deadline workflow ≤10 hari
  <a href="<?= h(hrlUrl('/hrl_reg_alkes/reg_alkes_control_tower.php')) ?>" style="margin-left:auto;color:inherit;text-decoration:underline">Buka Control Tower →</a>
</div>
<?php endif; ?>


<?php if (!$pdo || !hrl_table_exists($pdo, 'absensi_manual_attendance')): ?>
<div class="hrl-alert-strip alert-warn">
  <?=rmi_icon('warn')?> Tabel <b>absensi_manual_attendance</b> belum tersedia. Jalankan SQL patch agar HRL bisa input ALPA/manual hadir saat server down.
</div>
<?php endif; ?>

<?php if ($kpi['manual_server_down_today'] > 0): ?>
<div class="hrl-alert-strip alert-ok">
  <?=rmi_icon('gear')?> <b><?= (int)$kpi['manual_server_down_today'] ?></b> data manual hadir/server down tercatat. Data ini mencegah karyawan dianggap ALPA karena gangguan sistem.
</div>
<?php endif; ?>

<?php if ($pdo && !hrl_table_exists($pdo, 'hrl_leave_adjustments')): ?>
<div class="hrl-alert-strip alert-warn">
  <?=rmi_icon('warn')?> Tabel <b>hrl_leave_adjustments</b> belum tersedia. Jalankan SQL patch agar HRL bisa input saldo awal cuti sebelum sistem berjalan.
</div>
<?php endif; ?>

<!-- ══ KPI TILES ══════════════════════════════════════════════════════════════ -->
<div class="hrl-sh"><?=rmi_icon('chart')?> KPI Hari Ini — <?= h(date('d F Y', strtotime($filter_date))) ?></div>
<div class="hrl-card p-3 mb-3">
  <div class="hrl-kpi-grid">

    <div class="hrl-tile" style="border-color:rgba(34,197,94,.35)">
      <div class="tl-lbl"><?=rmi_icon('check')?> Check-in</div>
      <div class="tl-val" style="color:#4ade80"><?= $kpi['checkin_today'] ?></div>
      <div class="tl-sub">Dari <?= $kpi['employees_active'] ?> aktif — <?= hrl_pct($kpi['checkin_today'], $kpi['employees_active']) ?></div>
      <div class="tl-delta <?= $delta_checkin>0?'td-up':($delta_checkin<0?'td-dn':'td-neu') ?>">
        <?= $delta_checkin>0?'▲':($delta_checkin<0?'▼':'→') ?>
        <?php if ($delta_checkin !== 0): ?><?= abs($delta_checkin) ?> vs kemarin<?php else: ?>sama kemarin<?php endif; ?>
      </div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(59,130,246,.35)">
      <div class="tl-lbl"><?=rmi_icon('home')?> Check-out</div>
      <div class="tl-val" style="color:#60a5fa"><?= $kpi['checkout_today'] ?></div>
      <div class="tl-sub">Sudah pulang</div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(251,191,36,.35)">
      <div class="tl-lbl"><?=rmi_icon('refresh')?> Belum Checkout</div>
      <div class="tl-val" style="color:#fbbf24"><?= $kpi['belum_checkout'] ?></div>
      <div class="tl-sub">Masih di kantor</div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(239,68,68,.3)">
      <div class="tl-lbl"><?=rmi_icon('warn')?> Tidak Check-in</div>
      <div class="tl-val" style="color:#f87171"><?= $kpi['not_present'] ?></div>
      <div class="tl-sub">Bukan otomatis ALPA</div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(249,115,22,.35)">
      <div class="tl-lbl"><?=rmi_icon('calendar')?> Terlambat (><?= $checkin_std ?>)</div>
      <div class="tl-val" style="color:#fb923c"><?= $kpi['late_count'] ?></div>
      <div class="tl-sub">Checkin setelah <?= $checkin_std ?></div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(239,68,68,.35)">
      <div class="tl-lbl"><?=rmi_icon('cross')?> ALPA Manual HRL</div>
      <div class="tl-val" style="color:#f87171"><?= (int)$kpi['manual_alpha_today'] ?></div>
      <div class="tl-sub">Hanya yang dikunci HRL</div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(20,184,166,.35)">
      <div class="tl-lbl"><?=rmi_icon('gear')?> Server Down / Manual Hadir</div>
      <div class="tl-val" style="color:#2dd4bf"><?= (int)$kpi['manual_server_down_today'] ?></div>
      <div class="tl-sub">Tidak dihitung ALPA</div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(6,182,212,.3)">
      <div class="tl-lbl"><?=rmi_icon('users')?> Karyawan Aktif</div>
      <div class="tl-val" style="color:#67e8f9"><?= $kpi['employees_active'] ?></div>
      <div class="tl-sub">Master employees</div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(139,92,246,.35)">
      <div class="tl-lbl"><?=rmi_icon('clipboard')?> Izin Pending</div>
      <div class="tl-val" style="color:<?= $kpi['pending_izin']>0?'#fbbf24':'#4ade80' ?>"><?= $kpi['pending_izin'] ?></div>
      <div class="tl-sub">Menunggu approval</div>
    </div>

    <div class="hrl-tile">
      <div class="tl-lbl"><?=rmi_icon('doc')?> Dok. Pending</div>
      <div class="tl-val" style="color:<?= $kpi['pending_approval']>0?'#fbbf24':'#4ade80' ?>"><?= $kpi['pending_approval'] ?></div>
      <div class="tl-sub">Menunggu TTD</div>
    </div>

    <div class="hrl-tile" style="border-color:rgba(239,68,68,<?= $kpi['reg_alkes_expired']>0?'.35':($kpi['reg_alkes_expiring']>0?'.22':'.1') ?>)">
      <div class="tl-lbl"><?=rmi_icon('search')?> Reg Alkes Open</div>
      <div class="tl-val" style="color:<?= $kpi['reg_alkes_expired']>0?'#f87171':'#e2e8f0' ?>"><?= $kpi['reg_alkes_open'] ?></div>
      <div class="tl-sub"><?= $kpi['reg_alkes_expired'] ?> NIE expired · <?= $kpi['reg_alkes_expiring_30'] ?> ≤30h · <?= $kpi['reg_alkes_expiring_90'] ?> 31–90h</div>
    </div>

    <?php if ($payroll_status): ?>
    <div class="hrl-tile" style="border-color:rgba(16,185,129,.3)">
      <div class="tl-lbl"><?=rmi_icon('money')?> Payroll <?= h($payroll_status['period_ym'] ?? '') ?></div>
      <div class="tl-val" style="color:#6ee7b7;font-size:14px"><?= hrl_money((float)($payroll_status['total_net']??0)) ?></div>
      <div class="tl-sub">
        <?= h($payroll_status['emp_count']??0) ?> karyawan —
        <span style="font-weight:700;color:<?= strtoupper($payroll_status['status']??'')=='APPROVED'?'#4ade80':'#fbbf24' ?>">
          <?= h($payroll_status['status']??'DRAFT') ?>
        </span>
      </div>
    </div>
    <?php else: ?>
    <div class="hrl-tile" style="opacity:.6">
      <div class="tl-lbl"><?=rmi_icon('money')?> Payroll <?= h(date('Y-m', strtotime($filter_date))) ?></div>
      <div class="tl-val" style="font-size:14px;color:var(--hrl-muted)">Belum dibuat</div>
      <div class="tl-sub"><a href="<?= h(hrlUrl('/payroll/index.php')) ?>" style="color:#67e8f9">Buat sekarang →</a></div>
    </div>
    <?php endif; ?>

  </div>
</div>


<!-- ══ PENGINGAT MASA KONTRAK ═══════════════════════════════════════════════ -->
<div class="hrl-sh"><?=rmi_icon('refresh')?> Pengingat Masa Kontrak</div>
<div class="hrl-card p-3 mb-3">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <div class="text-muted" style="font-size:12px">Menampilkan kontrak aktif yang sudah expired atau akan berakhir ≤ 90 hari.</div>
    <a class="hrl-link" href="<?= h(hrlUrl('/master/master_employees.php')) ?>">Update Master Employees</a>
  </div>
  <?php if (!$contract_reminders): ?>
    <div class="text-muted" style="font-size:13px">Belum ada kontrak karyawan yang akan berakhir dalam 90 hari.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="hrl-table">
        <thead>
          <tr>
            <th>Karyawan</th>
            <th>Dept</th>
            <th>Office</th>
            <th>Masuk</th>
            <th>Kontrak Berakhir</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contract_reminders as $cr): ?>
            <?php
              $daysLeft = (int)($cr['days_left'] ?? 0);
              $badgeClass = $daysLeft < 0 ? 'contract-expired' : ($daysLeft <= 30 ? 'contract-soon' : 'contract-watch');
              $badgeText = $daysLeft < 0 ? ('Expired ' . abs($daysLeft) . ' hari') : ($daysLeft . ' hari lagi');
              $endLabel = trim((string)($cr['contract_end_year'] ?? '')) . '-' . str_pad((string)($cr['contract_end_month'] ?? ''), 2, '0', STR_PAD_LEFT);
              $joinLabel = trim((string)($cr['join_year'] ?? '')) . '-' . str_pad((string)($cr['join_month'] ?? ''), 2, '0', STR_PAD_LEFT);
            ?>
            <tr>
              <td>
                <b><?= h($cr['employee_name'] ?? '-') ?></b>
                <div class="text-muted" style="font-size:11px"><?= h($cr['employee_code'] ?? '-') ?></div>
              </td>
              <td><?= h($cr['dept_code'] ?? '-') ?></td>
              <td><?= h($cr['office_code'] ?? '-') ?></td>
              <td><?= h($joinLabel) ?></td>
              <td><b><?= h($endLabel) ?></b></td>
              <td><span class="hrl-badge <?= h($badgeClass) ?>"><?= h($badgeText) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ══ SALDO CUTI TAHUNAN ════════════════════════════════════════════════════ -->
<div id="saldo-cuti" class="hrl-sh"><?=rmi_icon('calendar')?> Saldo Cuti Tahunan <?= h((string)$leave_year) ?></div>
<div class="hrl-card p-3 mb-3">
  <div style="display:flex;justify-content:flex-end;margin-bottom:10px"><a class="hrl-link" href="<?= h(hrlUrl('/hrl_process/leave_adjustment.php?year=' . urlencode((string)$leave_year))) ?>"><?=rmi_icon('memo')?> Input Saldo Awal Cuti</a></div>
  <?php if (!$pdo || !hrl_table_exists($pdo, 'hrl_leave_balances')): ?>
    <div style="font-size:12px;color:#fbbf24;line-height:1.6">
      Tabel saldo cuti belum tersedia. Saldo akan otomatis dibuat saat HRL approve pengajuan CUTI dari HRL Process.
    </div>
  <?php elseif (empty($leave_balances)): ?>
    <div style="font-size:12px;color:var(--hrl-muted);line-height:1.6">
      Belum ada saldo cuti yang tercatat untuk tahun ini. Setelah cuti di-approve HRL, sistem akan mengisi jatah, terpakai, dan sisa cuti otomatis.
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="hrl-table">
        <thead>
          <tr>
            <th>Karyawan</th>
            <th>Dept</th>
            <th>Office</th>
            <th style="text-align:right">Jatah</th>
            <th style="text-align:right">Terpakai</th>
            <th style="text-align:right">Sisa</th>
            <th>Progress</th>
            <th>Update</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($leave_balances as $lb):
            $quota = (float)($lb['quota_days'] ?? 0);
            $used = (float)($lb['used_days'] ?? 0);
            $remain = (float)($lb['remaining_days'] ?? 0);
            $pctUsed = $quota > 0 ? min(100, max(0, ($used / $quota) * 100)) : 0;
          ?>
          <tr>
            <td>
              <div style="font-weight:700;color:#f1f5f9"><?= h($lb['employee_name'] ?? '-') ?></div>
              <div style="font-size:11px;color:var(--hrl-muted)"><?= h($lb['employee_code'] ?? '-') ?></div>
            </td>
            <td><?= h($lb['dept_code'] ?? '-') ?></td>
            <td><?= h($lb['office_code'] ?? '-') ?></td>
            <td style="text-align:right"><?= h(number_format($quota, 0, ',', '.')) ?> hari</td>
            <td style="text-align:right;color:#fbbf24;font-weight:700"><?= h(number_format($used, 0, ',', '.')) ?> hari</td>
            <td style="text-align:right;color:<?= $remain <= 2 ? '#f87171' : '#4ade80' ?>;font-weight:700"><?= h(number_format($remain, 0, ',', '.')) ?> hari</td>
            <td><div class="leave-bar"><span style="width:<?= h((string)$pctUsed) ?>%"></span></div></td>
            <td style="font-size:11px;color:var(--hrl-muted)"><?= h($lb['updated_at'] ?? '-') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ══ MPR DAILY VISIT NAVIGATION ═══════════════════════════════════════════════ -->
<div class="hrl-sh"><?=rmi_icon('target')?> Laporan Kunjungan MPR Harian</div>
<div class="mpr-daily-nav">
  <aside class="mpr-side">
    <div class="mpr-side-title">Navigasi MPR Visit</div>
    <div class="mpr-side-sub">Shortcut laporan kunjungan harian dari modul MPR. Data mengikuti tanggal dan office filter HRL Dashboard.</div>
    <a class="mpr-side-link" href="<?= h($mpr_nav_url) ?>"><?=rmi_icon('target')?> Buka Laporan Kunjungan <span>→</span></a>
    <a class="mpr-side-link" href="<?= h($mpr_nav_url . '?month=' . urlencode(date('Y-m', strtotime($filter_date)))) ?>"><?=rmi_icon('calendar')?> Lihat Bulan Ini <span>→</span></a>
    <a class="mpr-side-link" href="<?= h($mpr_export_today_url) ?>"><?=rmi_icon('outbox')?> Export CSV <span>→</span></a>
    <a class="mpr-side-link" href="<?= h(hrlUrl('/mpr/mpr_plans.php')) ?>"><?=rmi_icon('doc')?> MPR Plan <span>→</span></a>
    <div class="mpr-mini-grid">
      <div class="mpr-mini"><div class="v"><?= (int)$kpi['mpr_visit_today'] ?></div><div class="l">Hari Ini</div></div>
      <div class="mpr-mini"><div class="v"><?= (int)$kpi['mpr_visit_month'] ?></div><div class="l">Bulan Ini</div></div>
      <div class="mpr-mini"><div class="v" style="color:#fbbf24"><?= (int)$kpi['mpr_followup_due'] ?></div><div class="l">Follow-up Due</div></div>
      <div class="mpr-mini"><div class="v" style="color:#4ade80"><?= (int)$kpi['mpr_deal_month'] ?></div><div class="l">Deal Won</div></div>
    </div>
  </aside>

  <section class="mpr-today-card">
    <div class="mpr-today-head">
      <div>
        <div style="font-size:13px;font-weight:800;color:#f8fafc">Kunjungan tanggal <?= h(date('d M Y', strtotime($filter_date))) ?></div>
        <div style="font-size:11px;color:var(--hrl-muted)"><?= $filter_office !== '' ? 'Office: ' . h($filter_office) : 'Semua office' ?></div>
      </div>
      <a class="hrl-link" style="font-size:11px;padding:5px 10px" href="<?= h($mpr_nav_url) ?>">Detail →</a>
    </div>
    <?php if (!hrl_table_exists($pdo, 'mpr_visits') || !hrl_table_exists($pdo, 'mpr_plans')): ?>
      <div style="padding:18px;color:#fbbf24;font-size:12px">Tabel MPR belum tersedia. Pastikan modul MPR sudah terpasang.</div>
    <?php elseif (empty($mpr_visits_today)): ?>
      <div style="padding:22px;text-align:center;color:#64748b;font-size:12px">
        <div style="font-size:32px"><?=rmi_icon('target')?></div>
        Belum ada laporan kunjungan MPR pada tanggal ini.
      </div>
    <?php else: ?>
      <?php foreach ($mpr_visits_today as $mv): ?>
        <div class="mpr-visit-row">
          <div class="mpr-visit-main">
            <div class="mpr-visit-title"><?= h($mv['customer_name'] ?? '—') ?></div>
            <div class="mpr-visit-meta">
              <?=rmi_icon('user')?> <?= h($mv['visitor_username'] ?? '—') ?>
              <?php if (!empty($mv['visit_city'])): ?> · <?=rmi_icon('target')?> <?= h($mv['visit_city']) ?><?php endif; ?>
              <?php if (!empty($mv['plan_code'])): ?> · <?=rmi_icon('clipboard')?> <?= h($mv['plan_code']) ?><?php endif; ?>
            </div>
            <?php if (!empty($mv['result'])): ?>
              <div class="mpr-visit-meta"><?=rmi_icon('memo')?> <?= h(mb_strimwidth((string)$mv['result'], 0, 95, '…')) ?></div>
            <?php endif; ?>
          </div>
          <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end">
            <span class="mpr-pill"><?= h($mv['visit_type'] ?? 'VISIT') ?></span>
            <span class="mpr-pill" style="color:<?= ($mv['outcome'] ?? '') === 'DEAL_WON' ? '#4ade80' : '#fbbf24' ?>"><?= h($mv['outcome'] ?? 'PENDING') ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<!-- ══ TREN ABSENSI 30 HARI ═══════════════════════════════════════════════════ -->
<div class="hrl-sh"><?=rmi_icon('trend')?> Tren Kehadiran — 30 Hari Terakhir</div>
<div class="hrl-card p-3 mb-3">
  <div class="hrl-chart-wrap">
    <canvas id="hrlTrendChart"></canvas>
  </div>
</div>

<!-- ══ PENDING IZIN / CUTI ════════════════════════════════════════════════════ -->
<?php if (!empty($pending_izin)): ?>
<div class="hrl-sh"><?=rmi_icon('clipboard')?> Izin & Cuti Pending (<?= count($pending_izin) ?>)</div>
<div class="hrl-card p-3 mb-3">
  <div style="display:flex;flex-direction:column;gap:8px">
  <?php foreach ($pending_izin as $req):
    $rt = strtoupper(trim((string)($req['req_type']??'IZIN')));
    $rtCls = in_array($rt, ['IZIN','SAKIT','DINAS'], true) ? "req-{$rt}" : 'req-IZIN';
  ?>
    <div class="hrl-req-card">
      <div style="flex:0 0 auto;margin-top:2px">
        <span class="req-type <?= $rtCls ?>"><?= h($rt) ?></span>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;color:#f1f5f9"><?= h($req['employee_name'] ?: $req['username']) ?></div>
        <div style="font-size:12px;color:var(--hrl-muted)"><?= h($req['username']) ?>
          <?php if (trim((string)($req['dept']??'')) !== ''): ?>
            · <span style="color:#67e8f9"><?= h($req['dept']) ?></span>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;margin-top:3px">
          <?=rmi_icon('calendar')?> <?= h($req['start_date']) ?> — <?= h($req['end_date']) ?>
          <?php $days = (int)((strtotime($req['end_date'])-strtotime($req['start_date']))/86400)+1; ?>
          <span style="color:#94a3b8">(<?= $days ?> hari)</span>
        </div>
        <?php if (!empty($req['reason'])): ?>
          <div style="font-size:12px;color:var(--hrl-muted);margin-top:2px;font-style:italic">
            "<?= h(mb_strimwidth((string)$req['reason'], 0, 80, '…')) ?>"
          </div>
        <?php endif; ?>
      </div>
      <div style="flex:0 0 auto">
        <a href="<?= h(hrlUrl('/absensi/approval.php')) ?>" class="hrl-link" style="font-size:11px;padding:5px 10px">Approve →</a>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>



<!-- ══ MANUAL ATTENDANCE EXCEPTION ════════════════════════════════════════════ -->
<div class="hrl-sh"><?=rmi_icon('gear')?> Koreksi Absensi Manual HRL</div>
<div class="hrl-card p-3 mb-3">
  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
    <div style="font-size:12px;color:var(--hrl-muted);line-height:1.5">
      Gunakan menu ini hanya untuk kondisi resmi seperti <b>server mati/error</b>, koreksi hadir manual, atau <b>ALPA yang sudah dipastikan HRL</b>.
      Tidak check-in tidak otomatis menjadi ALPA.
    </div>
    <a class="hrl-link" href="<?= h(hrlUrl('/dashboards/hrl/absensi_manual_alpha.php?d=' . urlencode($filter_date))) ?>">Input ALPA / Koreksi Manual →</a>
  </div>

  <?php if (!empty($manual_attendance_today)): ?>
  <div style="overflow-x:auto;margin-top:12px">
    <table class="hrl-table">
      <thead>
        <tr>
          <th>Tanggal</th>
          <th>Karyawan</th>
          <th>Office</th>
          <th>Status</th>
          <th>Alasan</th>
          <th>Dibuat</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($manual_attendance_today as $ma): ?>
        <tr>
          <td><?= h($ma['tanggal'] ?? '') ?></td>
          <td>
            <div style="font-weight:700;color:#f1f5f9"><?= h($ma['employee_name'] ?? $ma['username'] ?? $ma['employee_code'] ?? '—') ?></div>
            <div style="font-size:11px;color:var(--hrl-muted)"><?= h($ma['employee_code'] ?? '') ?> <?= h($ma['username'] ?? '') ?></div>
          </td>
          <td><?= h($ma['office_code'] ?? '') ?></td>
          <td><span class="hrl-badge" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12)"><?= h($ma['status'] ?? '') ?></span></td>
          <td><?= h(mb_strimwidth((string)($ma['reason'] ?? ''), 0, 80, '…')) ?></td>
          <td style="font-size:11px;color:var(--hrl-muted)"><?= h($ma['created_by'] ?? '') ?> <?= h($ma['created_at'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ══ REKAP ABSENSI (grouped by office) ══════════════════════════════════════ -->
<?php if (!empty($absensi_today)): ?>
<div class="hrl-sh"><?=rmi_icon('user')?> Rekap Absensi — <?= h(date('d F Y', strtotime($filter_date))) ?></div>
<div class="hrl-card mb-3" style="overflow:hidden">

  <?php foreach ($absensi_by_office as $oc => $rows):
    $oc_color = $office_color_map[$oc] ?? ['bg'=>'rgba(255,255,255,.08)','color'=>'#e2e8f0'];
    $cnt_co = count(array_filter($rows, fn($r)=>!empty($r['checkout_at'])));
    $cnt_late = count(array_filter($rows, function($r) use($checkin_std){
        if (empty($r['checkin_at'])) return false;
        return date('H:i', strtotime($r['checkin_at'])) > $checkin_std;
    }));
  ?>
  <!-- Office group header -->
  <div class="hrl-grp-head">
    <span style="background:<?= $oc_color['bg'] ?>;color:<?= $oc_color['color'] ?>;padding:2px 10px;border-radius:8px;font-size:11px;letter-spacing:.3px"><?= h($oc) ?></span>
    <span style="color:#4ade80;font-size:12px"><?=rmi_icon('check')?> <?= count($rows) ?> hadir</span>
    <span style="color:#60a5fa;font-size:12px"><?=rmi_icon('home')?> <?= $cnt_co ?> checkout</span>
    <?php if ($cnt_late > 0): ?>
      <span style="color:#fb923c;font-size:12px"><?=rmi_icon('calendar')?> <?= $cnt_late ?> terlambat</span>
    <?php endif; ?>
  </div>

  <div style="overflow-x:auto">
  <table class="hrl-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Nama / Username</th>
        <th>Check-in</th>
        <th style="text-align:center">Foto In</th>
        <th>Check-out</th>
        <th style="text-align:center">Foto Out</th>
        <th style="text-align:center">Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $row):
      $hasOut  = !empty($row['checkout_at']);
      $photoIn = ltrim((string)($row['photo_in']  ?? ''), '/');
      $photoOut= ltrim((string)($row['photo_out'] ?? ''), '/');
      $urlIn   = $photoIn  ? hrlUrl('/absensi/photo.php?f=' . urlencode($photoIn))  : '';
      $urlOut  = $photoOut ? hrlUrl('/absensi/photo.php?f=' . urlencode($photoOut)) : '';
      $inTime  = $row['checkin_at']  ? date('H:i', strtotime($row['checkin_at']))  : '';
      $outTime = $row['checkout_at'] ? date('H:i', strtotime($row['checkout_at'])) : '';
      $isLate  = $inTime !== '' && $inTime > $checkin_std;
      $rowBg   = !$hasOut ? 'background:rgba(251,191,36,.03)' : '';
    ?>
      <tr style="<?= $rowBg ?>">
        <td style="color:var(--rmi-muted);font-size:11px"><?= $i+1 ?></td>
        <td>
          <?php $empName = trim((string)($row['employee_name'] ?? '')); ?>
          <?php if ($empName !== ''): ?>
            <div style="font-weight:600;color:#f1f5f9;font-size:13px"><?= h($empName) ?></div>
            <div style="font-size:11px;color:var(--hrl-muted)"><?= h($row['username']) ?></div>
          <?php else: ?>
            <div style="font-weight:600;color:#f1f5f9"><?= h($row['username']) ?></div>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($inTime): ?>
            <span style="color:<?= $isLate?'#fb923c':'#4ade80' ?>;font-weight:600"><?= h($inTime) ?></span>
            <?php if ($isLate): ?><span style="font-size:10px;color:#fb923c;margin-left:3px"><?=rmi_icon('calendar')?> TL</span><?php endif; ?>
          <?php else: ?><span style="color:var(--rmi-muted)">—</span><?php endif; ?>
        </td>
        <td style="text-align:center;padding:4px 6px">
          <?php if ($urlIn): ?>
            <a href="<?= h($urlIn) ?>" class="hrl-photo" data-url="<?= h($urlIn) ?>" title="Foto check-in">
              <img src="<?= h($urlIn) ?>" alt="in" loading="lazy"
                   onerror="this.closest('a').innerHTML='<span style=\'color:var(--rmi-muted);font-size:10px\'>—</span>'">
            </a>
          <?php else: ?><span style="color:var(--rmi-muted)">—</span><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?= $outTime ? '<span style="color:#60a5fa;font-weight:600">'.h($outTime).'</span>' : '<span style="color:var(--rmi-muted)">—</span>' ?>
        </td>
        <td style="text-align:center;padding:4px 6px">
          <?php if ($urlOut): ?>
            <a href="<?= h($urlOut) ?>" class="hrl-photo" data-url="<?= h($urlOut) ?>" title="Foto check-out">
              <img src="<?= h($urlOut) ?>" alt="out" loading="lazy"
                   onerror="this.closest('a').innerHTML='<span style=\'color:var(--rmi-muted);font-size:10px\'>—</span>'">
            </a>
          <?php else: ?><span style="color:var(--rmi-muted)">—</span><?php endif; ?>
        </td>
        <td style="text-align:center">
          <?php if ($hasOut): ?>
            <span class="hrl-badge" style="background:#0f2a4a;color:#60a5fa;border:1px solid rgba(96,165,250,.25)">Checkout <?=rmi_icon('tick')?></span>
          <?php else: ?>
            <span class="hrl-badge" style="background:#3b1f02;color:#fbbf24;border:1px solid rgba(251,191,36,.25)">Belum</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ REG ALKES — NIE EXPIRY ═════════════════════════════════════════════════ -->
<?php if (!empty($expiring_alkes)): ?>
<div class="hrl-sh"><?=rmi_icon('search')?> Reg Alkes — Masa Berlaku NIE Perlu Tindakan (<?= count($expiring_alkes) ?>)</div>
<div class="hrl-card p-0 mb-3" style="overflow:hidden">
  <div style="overflow-x:auto">
  <table class="hrl-table">
    <thead>
      <tr>
        <th>Case Code</th>
        <th>Produk</th>
        <th>NIE No.</th>
        <th>NIE Berlaku Sampai</th>
        <th>Status Case</th>
        <th>Status NIE</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($expiring_alkes as $a):
      $expiryDate = $a['nie_expiry_date'] ?? null;
      $daysLeft = $expiryDate ? (int)floor((strtotime($expiryDate . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400) : null;
      $cls = $daysLeft === null ? '' : ($daysLeft < 0 ? 'alkes-expired' : ($daysLeft <= 30 ? 'alkes-warn' : 'alkes-ok'));
    ?>
      <tr>
        <td><code style="font-size:12px;color:#67e8f9"><?= h($a['case_code'] ?? '—') ?></code></td>
        <td style="max-width:200px">
          <div style="font-size:13px;white-space:normal"><?= h(mb_strimwidth((string)($a['product_name']??'—'),0,60,'…')) ?></div>
        </td>
        <td style="font-size:12px"><?= h($a['nie_no'] ?: '—') ?></td>
        <td style="font-size:13px" class="<?= $cls ?>"><?= h($expiryDate ?? '—') ?></td>
        <td><span class="hrl-badge" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);font-size:10px"><?= h($a['status']??'') ?></span></td>
        <td class="<?= $cls ?>" style="font-weight:700">
          <?php if ($daysLeft !== null): ?>
            <?= $daysLeft < 0 ? 'EXPIRED ' . abs($daysLeft) . ' hari' : ($daysLeft === 0 ? 'Berakhir hari ini' : $daysLeft . ' hari lagi') ?>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="padding:8px 12px;text-align:right;border-top:1px solid var(--hrl-border)">
    <a href="<?= h(hrlUrl('/hrl_reg_alkes/reg_alkes_expiry_check.php')) ?>" class="hrl-link" style="font-size:11px;padding:5px 10px">Lihat Semua Expiry NIE →</a>
  </div>
</div>
<?php endif; ?>

<!-- ══ BREAKDOWN KARYAWAN PER DEPT ════════════════════════════════════════════ -->
<?php if (!empty($dept_breakdown)): ?>
<div class="hrl-sh"><?=rmi_icon('office')?> Distribusi Karyawan Aktif per Dept (Total: <?= $kpi['employees_active'] ?>)</div>
<div class="hrl-card p-3 mb-3">
  <?php
  // Group by dept first
  $byDept = [];
  foreach ($dept_breakdown as $r) {
    $dept = (string)($r['dept'] ?? '—');
    $byDept[$dept] = ($byDept[$dept] ?? 0) + (int)$r['cnt'];
  }
  arsort($byDept);
  ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
  <?php foreach ($byDept as $dept => $cnt):
    $pct = $kpi['employees_active'] > 0 ? round($cnt / $kpi['employees_active'] * 100) : 0;
  ?>
    <div style="background:rgba(6,182,212,.07);border:1px solid rgba(6,182,212,.2);border-radius:10px;padding:8px 14px;min-width:80px;text-align:center">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--hrl-muted)"><?= h($dept) ?></div>
      <div style="font-size:20px;font-weight:800;color:#67e8f9"><?= $cnt ?></div>
      <div style="font-size:10px;color:var(--hrl-muted)"><?= $pct ?>%</div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ══ CONTROL CENTER & QUICK LINKS ═══════════════════════════════════════════ -->
<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="hrl-card p-3 h-100">
      <div class="hrl-sh" style="margin-top:0"><?=rmi_icon('office')?> Control Center</div>
      <div class="hrl-links">
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl/hrl_tower.php')) ?>">HRL Tower</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl_process/tower.php')) ?>">Process Tower</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl_process/employee_mutations.php')) ?>"><?=rmi_icon('refresh')?> Mutasi Karyawan</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl_process/leave_adjustment.php')) ?>">Input Saldo Cuti</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl_reg_alkes/reg_alkes_control_tower.php')) ?>">Reg Alkes Tower</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/absensi/approval.php')) ?>">Approval Izin</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/dashboards/hrl/absensi_manual_alpha.php')) ?>">Input ALPA Manual</a>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="hrl-card p-3 h-100">
      <div class="hrl-sh" style="margin-top:0"><?=rmi_icon('doc')?> Quick Links</div>
      <div class="hrl-links">
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl/hrl_docs.php')) ?>">HRL Docs</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl_process/index.php')) ?>">HRL Process</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl_process/employee_mutations.php')) ?>"><?=rmi_icon('refresh')?> Mutasi Karyawan</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl_reg_alkes/index.php')) ?>">Reg Alkes</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/absensi/index.php')) ?>">Absensi</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/absensi/admin/rekap.php')) ?>">Rekap Absensi</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/dashboards/hrl/absensi_manual_alpha.php')) ?>">ALPA / Koreksi Manual</a>
        <a class="hrl-link" href="#saldo-cuti"><?=rmi_icon('calendar')?> Saldo Cuti</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/hrl_process/leave_adjustment.php')) ?>"><?=rmi_icon('memo')?> Input Saldo Cuti</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/payroll/index.php')) ?>">Payroll</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/master/master_employees.php')) ?>">Master Karyawan</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/master/master_employees.php')) ?>"><?=rmi_icon('refresh')?> Masa Kontrak</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/master/master_departements.php')) ?>">Master Departments</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/kpi/kpi_center.php')) ?>">KPI Center</a>
        <a class="hrl-link" href="<?= h(hrlUrl('/mpr/mpr_visits.php')) ?>"><?=rmi_icon('target')?> Laporan Kunjungan MPR</a>
      </div>
    </div>
  </div>
</div>

<!-- ── Lightbox ────────────────────────────────────────────────────────────── -->
<div id="hrlPhotoModal" onclick="this.style.display='none'">
  <img id="hrlPhotoModalImg" src="" alt="Foto Absensi">
</div>

<!-- ── Chart + Lightbox Script ────────────────────────────────────────────── -->
<script>
// Trend chart
(function(){
  const ctx = document.getElementById('hrlTrendChart');
  if (!ctx) return;
  const labels = <?= $chart_labels ?>;
  const hadir  = <?= $chart_hadir ?>;
  const total  = <?= $chart_total ?>;
  const grid   = 'rgba(255,255,255,.06)';
  const tick   = '#64748b';
  new Chart(ctx, {
    data:{
      labels,
      datasets:[
        {
          type:'bar', label:'Hadir',
          data: hadir,
          backgroundColor:'rgba(74,222,128,.25)',
          borderColor:'#4ade80', borderWidth:1.5, borderRadius:3,
          yAxisID:'y', order:2,
        },
        {
          type:'line', label:'Total Aktif',
          data: total,
          borderColor:'rgba(255,255,255,.2)',
          borderWidth:1.5, borderDash:[4,3],
          pointRadius:0, fill:false, tension:0,
          yAxisID:'y', order:1,
        }
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      interaction:{mode:'index',intersect:false},
      plugins:{
        legend:{display:false},
        tooltip:{
          backgroundColor:'rgba(15,23,42,.92)',
          borderColor:'rgba(255,255,255,.1)', borderWidth:1,
          titleColor:'#e2e8f0', bodyColor:'#94a3b8', padding:10,
          callbacks:{
            label: c => ' '+c.dataset.label+': '+c.parsed.y+' orang'
          }
        }
      },
      scales:{
        x:{grid:{color:grid},ticks:{color:tick,font:{size:10},maxTicksLimit:10}},
        y:{grid:{color:grid},ticks:{color:tick,font:{size:11}},beginAtZero:true,
           title:{display:true,text:'Karyawan',color:tick,font:{size:10}}}
      }
    }
  });
})();

// Lightbox
document.addEventListener('click', function(e){
  const a = e.target.closest('.hrl-photo');
  if (!a) return;
  e.preventDefault();
  document.getElementById('hrlPhotoModalImg').src = a.dataset.url;
  document.getElementById('hrlPhotoModal').style.display = 'flex';
});
document.addEventListener('keydown', e => {
  if (e.key==='Escape') document.getElementById('hrlPhotoModal').style.display='none';
});
</script>

<?php
$auditModules = ['HRL', 'ABSENSI', 'KPI', 'PAYROLL', 'USER_PROFILE_SAVE', 'HRL_PROCESS'];
$auditLimit   = 10;
require __DIR__ . '/../_audit_log_widget.php';
?>

<?php rmi_footer(); ?>
