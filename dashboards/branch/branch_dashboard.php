<?php
/**
 * dashboards/branch/branch_dashboard.php
 * Branch Office Dashboard — Landing page untuk staff cabang (BGR, BDG, dll).
 */
declare(strict_types=1);

require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
// Dept guard: BRANCH Dashboard — BRANCH + SYS only
$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
$office = function_exists('auth_office_code') ? trim((string)auth_office_code()) : '';
$isSys = function_exists('auth_department') ? strtoupper((string)auth_department()) === 'SYS' : false;

/**
 * SYS / OWNER / DIREKSI boleh melihat semua kantor cabang dan memilih office dari filter.
 * Identitas diambil dari session/auth canonical (department/role/level), bukan dari username.
 */
function branch_can_view_all_offices(): bool {
    $vals = [];
    foreach (['auth_department','auth_role','auth_level'] as $fn) {
        if (function_exists($fn)) {
            try { $vals[] = strtoupper(trim((string)$fn())); } catch (Throwable $e) {}
        }
    }
    if (function_exists('auth_user')) {
        try {
            $u = auth_user();
            if (is_array($u)) {
                foreach (['department','dept_code','role','role_code','level'] as $k) {
                    $vals[] = strtoupper(trim((string)($u[$k] ?? '')));
                }
            }
        } catch (Throwable $e) {}
    }
    foreach (['department','dept_code','role','role_code','level'] as $k) {
        $vals[] = strtoupper(trim((string)($_SESSION[$k] ?? '')));
    }

    $allow = ['SYS','ADMIN','SUPERADMIN','OWNER','DIREKSI','DIRECTOR','DIRECTORATE','BOD'];
    foreach ($vals as $v) {
        if ($v !== '' && in_array($v, $allow, true)) return true;
    }
    return false;
}

$canViewAllOffices = branch_can_view_all_offices();
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function u(string $path): string {
    $bp = $GLOBALS['BASE_PROJECT'] ?? '';
    return rtrim($bp, '/') . '/' . ltrim($path, '/');
}

function fmt_money(float $v): string {
    return number_format($v, 0, ',', '.');
}

$periodStart = date('Y-m-01');
$periodEnd   = date('Y-m-d');
$pdo = $GLOBALS['pdo'] ?? null;

$emptyKpi = [
    'do_count' => 0, 'do_value' => 0.0,
    'do_open' => 0, 'picking' => 0,
    'incoming' => 0, 'hadir' => 0,
    'pr_pending' => 0, 'piutang' => 0.0,
    'on_delivery' => 0, 'team_staff' => 0,
    'hadir_branch' => 0, 'exceptions' => 0,
];
$kpi = $emptyKpi;

require_once __DIR__ . '/../../_shared/rbac.php';
$branchDashShowKpi = false;
if ($pdo instanceof PDO && function_exists('rbac_can')) {
    $branchDashShowKpi = rbac_can($pdo, 'KPI.VIEW');
} elseif (function_exists('rbac_can2')) {
    $branchDashShowKpi = rbac_can2('KPI.VIEW');
}

/**
 * Branch non-SYS tidak boleh jatuh ke scope ALL.
 * Office diambil dari sumber canonical session/login/employee, tanpa parsing username.
 */
function branch_resolve_user_office(?PDO $pdo, string $currentOffice, bool $canViewAllOffices): string {
    $currentOffice = strtoupper(trim($currentOffice));

    // Executive/SYS default = SEMUA CABANG. auth_office_code seperti SYS/BGR tidak boleh
    // mengunci dashboard. Filter ?office= hanya dipakai jika memang dipilih user.
    if ($canViewAllOffices) {
        $requested = strtoupper(trim((string)($_GET['office'] ?? '')));
        return $requested;
    }

    // Staff/manager cabang terkunci pada office akun sendiri.
    if ($currentOffice !== '') return $currentOffice;

    if (function_exists('auth_user')) {
        try {
            $u = auth_user();
            if (is_array($u)) {
                foreach (['office_code', 'office', 'branch_code'] as $key) {
                    $v = strtoupper(trim((string)($u[$key] ?? '')));
                    if ($v !== '') return $v;
                }
            }
        } catch (Throwable $e) {}
    }

    foreach (['office_code', 'office', 'branch_code'] as $key) {
        $v = strtoupper(trim((string)($_SESSION[$key] ?? '')));
        if ($v !== '') return $v;
    }

    if (!$pdo) return '';

    $uid = 0;
    if (function_exists('auth_user_id')) {
        try { $uid = (int)auth_user_id(); } catch (Throwable $e) {}
    }
    if ($uid <= 0 && function_exists('auth_user')) {
        try {
            $u = auth_user();
            if (is_array($u)) $uid = (int)($u['id'] ?? $u['user_id'] ?? 0);
        } catch (Throwable $e) {}
    }
    if ($uid <= 0) $uid = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    if ($uid <= 0) return '';

    try {
        $sql = "SELECT UPPER(TRIM(COALESCE(NULLIF(msl.office_code,''), me.office_code, ''))) AS office_code
                FROM master_system_login msl
                LEFT JOIN master_employees me
                  ON me.employee_code = msl.holder_employee_code
                WHERE msl.id=? AND msl.deleted_at IS NULL
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$uid]);
        return strtoupper(trim((string)$st->fetchColumn()));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Helper schema-safe untuk sumber pencapaian Branch.
 * Logika sengaja mengikuti dashboards/finance/dashboard_detail.php:
 * BMHP recognized/comparable = sales_do + sales_do_items - retur komersial,
 * customer internal dikeluarkan, office revenue diprioritaskan ke achievement/revenue office.
 */
function branch_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $st = $pdo->prepare("SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $st->execute([$table]);
        return $cache[$table] = array_values(array_unique(array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (Throwable $e) { return $cache[$table] = []; }
}
function branch_first_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) if (in_array(strtolower($c), $cols, true)) return $c;
    return null;
}
function branch_truthy_internal($v): bool {
    return in_array(strtoupper(trim((string)$v)), ['1','Y','YES','TRUE','INTERNAL','INTERCOMPANY'], true);
}

/** Ambil KPI satu office. KPI operasional tetap memakai office operasional; pencapaian memakai office revenue canonical. */
function branch_load_office_kpi(PDO $pdo, string $officeCode, string $periodStart, string $periodEnd): array {
    $officeCode = strtoupper(trim($officeCode));
    $out = [
        'do_count' => 0, 'do_value' => 0.0, 'do_open' => 0, 'picking' => 0,
        'incoming' => 0, 'hadir' => 0, 'pr_pending' => 0, 'piutang' => 0.0,
        'on_delivery' => 0, 'team_staff' => 0, 'hadir_branch' => 0, 'exceptions' => 0,
    ];
    if ($officeCode === '') return $out;

    $officeWhere = "UPPER(TRIM(COALESCE(office_code,'')))=UPPER(?)";

    /*
     * NILAI PENJUALAN MTD + DO BULAN INI — SOURCE OF TRUTH SAMA DENGAN DASHBOARD DETAIL.
     * - hanya family BMHP-* (UNITACC-* tidak dicampur ke pencapaian BMHP)
     * - nilai dari SUM sales_do_items.subtotal, bukan grand_total/total_amount
     * - status draft/cancel/reject/void dikeluarkan
     * - customer/internal transfer dikeluarkan
     * - retur komersial SCM completed mengurangi nilai
     * - office pencapaian: sales_do achievement/revenue -> master customer -> office operasional -> prefix DO
     */
    try {
        $salesCols = branch_table_columns($pdo, 'sales_do');
        $custCols  = branch_table_columns($pdo, 'master_customers');
        $retCols   = branch_table_columns($pdo, 'sales_do_returns');
        $itemCols  = branch_table_columns($pdo, 'sales_do_items');
        $retItemCols = branch_table_columns($pdo, 'sales_do_return_items');

        $dateCol = in_array('do_date',$salesCols,true) ? 'do_date' : (in_array('date',$salesCols,true) ? 'date' : null);
        if ($dateCol && $itemCols) {
            $custNameCol = branch_first_col($custCols,['customers_name','customer_name','name']);
            $custTypeCol = branch_first_col($custCols,['customer_type','customers_type','type','category','customer_category']);
            $custIntCol  = branch_first_col($custCols,['is_internal','internal_flag','is_intercompany']);
            $custOfficeCol = branch_first_col($custCols,['office_achievement','achievement_office','revenue_office','sales_office','office_code','office']);
            $doAchievementCol = branch_first_col($salesCols,['office_achievement','achievement_office','revenue_office','sales_office']);
            $doIntCol = branch_first_col($salesCols,['is_internal_transfer','is_internal','internal_flag','is_intercompany']);

            $custMatch = '1=0';
            if (in_array('customer_id',$salesCols,true) && in_array('id',$custCols,true) && in_array('customers_code',$custCols,true)) {
                $custMatch = "((COALESCE(d.customer_id,0)>0 AND c.id=d.customer_id) OR (COALESCE(d.customer_id,0)<=0 AND c.customers_code=d.customers_code))";
            } elseif (in_array('customers_code',$custCols,true)) {
                $custMatch = 'c.customers_code=d.customers_code';
            }
            $sub = static function(?string $col) use ($custMatch): string {
                return $col ? "(SELECT c.`{$col}` FROM master_customers c WHERE {$custMatch} LIMIT 1)" : 'NULL';
            };
            $doAchievementExpr = $doAchievementCol ? "d.`{$doAchievementCol}`" : 'NULL';
            $doIntExpr = $doIntCol ? "d.`{$doIntCol}`" : 'NULL';

            $sql = "SELECT d.id,d.do_code,
                           UPPER(TRIM(COALESCE(d.office_code,''))) office_code_db,
                           UPPER(TRIM(COALESCE({$doAchievementExpr},''))) office_achievement_do,
                           UPPER(TRIM(COALESCE(".$sub($custOfficeCol).",''))) office_achievement_master,
                           d.customers_code, LOWER(TRIM(COALESCE(d.status,''))) status_now,
                           ".$sub($custNameCol)." customer_name,
                           ".$sub($custTypeCol)." customer_type,
                           ".$sub($custIntCol)." customer_internal_flag,
                           {$doIntExpr} do_internal_flag,
                           COALESCE((SELECT SUM(COALESCE(i.subtotal,0)) FROM sales_do_items i WHERE i.do_id=d.id),0) net_items
                    FROM sales_do d
                    WHERE DATE(d.`{$dateCol}`) BETWEEN ? AND ?
                      AND UPPER(TRIM(COALESCE(d.do_code,''))) LIKE 'BMHP-%'";
            if (in_array('deleted_at',$salesCols,true)) $sql .= ' AND d.deleted_at IS NULL';
            $st = $pdo->prepare($sql);
            $st->execute([$periodStart,$periodEnd]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $returnByDo = [];
            if (in_array('commercial_effect',$retCols,true) && $retItemCols) {
                try {
                    $sqlRet = "SELECT r.do_id,
                                      COALESCE(SUM(COALESCE(ri.qty_return,0)*(COALESCE(di.subtotal,0)/NULLIF(di.qty,0))),0) adj
                               FROM sales_do_returns r
                               JOIN sales_do_return_items ri ON ri.return_id=r.id AND ri.do_id=r.do_id
                               JOIN sales_do_items di ON di.id=ri.do_item_id AND di.do_id=r.do_id
                               WHERE LOWER(TRIM(COALESCE(r.status,'')))='return_scm_completed'
                                 AND UPPER(TRIM(COALESCE(r.commercial_effect,''))) IN ('REVERSAL','REPLACEMENT')
                                 AND COALESCE(ri.qty_return,0)>0";
                    if (in_array('scm_completed_at',$retCols,true)) $sqlRet .= ' AND r.scm_completed_at<=?';
                    $sqlRet .= ' GROUP BY r.do_id';
                    $qr = $pdo->prepare($sqlRet);
                    in_array('scm_completed_at',$retCols,true) ? $qr->execute([$periodEnd.' 23:59:59']) : $qr->execute();
                    foreach ($qr->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) $returnByDo[(int)$rr['do_id']] = (float)$rr['adj'];
                } catch (Throwable $e) {}
            }

            $validOffices = ['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'];
            $excluded = ['draft','cancelled','canceled','cancel','rejected','reject','void','voided','deleted','inactive'];
            foreach ($rows as $r) {
                if (in_array(strtolower(trim((string)($r['status_now'] ?? ''))), $excluded, true)) continue;

                $doCode = strtoupper(trim((string)($r['do_code'] ?? '')));
                $officeDb = strtoupper(trim((string)($r['office_code_db'] ?? '')));
                $officeDo = strtoupper(trim((string)($r['office_achievement_do'] ?? '')));
                $officeMaster = strtoupper(trim((string)($r['office_achievement_master'] ?? '')));
                $officePrefix = '';
                if (preg_match('/^BMHP-([A-Z0-9]+)-/', $doCode, $m) && in_array(strtoupper($m[1]),$validOffices,true)) $officePrefix = strtoupper($m[1]);
                $revenueOffice = in_array($officeDo,$validOffices,true) ? $officeDo
                    : (in_array($officeMaster,$validOffices,true) ? $officeMaster
                    : (in_array($officeDb,$validOffices,true) ? $officeDb : $officePrefix));
                if ($revenueOffice !== $officeCode) continue;

                $custCode = strtoupper(trim((string)($r['customers_code'] ?? '')));
                $custName = strtoupper(trim((string)($r['customer_name'] ?? '')));
                $custType = strtoupper(trim((string)($r['customer_type'] ?? '')));
                $isInternal = (bool)preg_match('/(^|[-_])(INT|INTERNAL)($|[-_])/', $custCode)
                    || str_starts_with($custName,'KANTOR RIZQULLAH MEDISKA INDONESIA')
                    || str_starts_with($custName,'KANTOR DEPO ')
                    || in_array($custType,['INTERNAL','INTERCOMPANY'],true)
                    || branch_truthy_internal($r['customer_internal_flag'] ?? '')
                    || branch_truthy_internal($r['do_internal_flag'] ?? '');
                if ($isInternal) continue;

                $original = max(0.0,(float)($r['net_items'] ?? 0));
                $adj = max(0.0,min($original,(float)($returnByDo[(int)$r['id']] ?? 0)));
                $effective = max(0.0,$original-$adj);
                if ($effective <= 0) continue;
                $out['do_count']++;
                $out['do_value'] += $effective;
            }
        }
    } catch (Throwable $e) {}

    // DO belum selesai mengikuti status O2C canonical, bukan negative-list yang bisa menangkap status liar/history.
    try {
        $sql = "SELECT COUNT(*) FROM sales_do
                WHERE LOWER(TRIM(COALESCE(status,''))) IN
                  ('draft','crm_to_wqs','sent_wqs','wqs_processing','ready_scm','wqs_done','on_delivery')
                  AND {$officeWhere}";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['do_open'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    // Piutang = sisa tagihan. DO operasional selesai (delivered/fin_done/closed) tetap bisa punya piutang;
    // hanya cancelled dan paid yang dikeluarkan secara status.
    try {
        $sql = "SELECT COALESCE(SUM(
                    GREATEST(
                      COALESCE(NULLIF(grand_total,0), NULLIF(total_amount,0), 0) - COALESCE(fin_paid_amount,0),
                      0
                    )
                ),0)
                FROM sales_do
                WHERE LOWER(TRIM(COALESCE(status,''))) NOT IN ('cancel','cancelled','canceled','paid')
                  AND {$officeWhere}";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['piutang'] = (float)$st->fetchColumn();
    } catch (Throwable $e) {}

    // WQS / picking pending: status sebelum ready SCM.
    try {
        $sql = "SELECT COUNT(DISTINCT id) FROM sales_do
                WHERE LOWER(TRIM(COALESCE(status,''))) IN ('crm_to_wqs','sent_wqs','wqs_processing')
                  AND {$officeWhere}";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['picking'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    // Delivery active: barang siap SCM sampai sedang dikirim.
    try {
        $sql = "SELECT COUNT(*) FROM sales_do
                WHERE LOWER(TRIM(COALESCE(status,''))) IN ('ready_scm','wqs_done','on_delivery')
                  AND {$officeWhere}";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['on_delivery'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    // Total hadir kantor: mapping canonical login -> employee -> office employee.
    // Ini menghindari absensi_user_profile.office_code yang bisa stale/tidak sama dengan master employee.
    try {
        $sql = "SELECT COUNT(DISTINCT al.user_id)
                FROM absensi_logs al
                JOIN master_system_login msl
                  ON msl.id=al.user_id AND msl.deleted_at IS NULL
                JOIN master_employees me
                  ON me.employee_code=msl.holder_employee_code
                WHERE al.deleted_at IS NULL
                  AND UPPER(TRIM(COALESCE(al.action_type,'')))='IN'
                  AND DATE(al.created_at)=CURDATE()
                  AND UPPER(TRIM(COALESCE(me.status,'')))='ACTIVE'
                  AND UPPER(TRIM(COALESCE(me.office_code,'')))=UPPER(?)";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['hadir'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    // PR open per office.
    try {
        $sql = "SELECT COUNT(*) FROM wqs_pr
                WHERE UPPER(TRIM(COALESCE(status,''))) IN ('DRAFT','SUBMITTED')
                  AND {$officeWhere}";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['pr_pending'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    // Team staff BRANCH aktif per office.
    try {
        $sql = "SELECT COUNT(*) FROM master_employees
                WHERE UPPER(TRIM(COALESCE(dept_code,'')))='BRANCH'
                  AND UPPER(TRIM(COALESCE(status,'')))='ACTIVE'
                  AND {$officeWhere}";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['team_staff'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    // Hadir khusus personel dept BRANCH per office.
    try {
        $sql = "SELECT COUNT(DISTINCT al.user_id)
                FROM absensi_logs al
                JOIN master_system_login msl
                  ON msl.id=al.user_id AND msl.deleted_at IS NULL
                JOIN master_employees me
                  ON me.employee_code=msl.holder_employee_code
                WHERE al.deleted_at IS NULL
                  AND UPPER(TRIM(COALESCE(al.action_type,'')))='IN'
                  AND DATE(al.created_at)=CURDATE()
                  AND UPPER(TRIM(COALESCE(me.dept_code,'')))='BRANCH'
                  AND UPPER(TRIM(COALESCE(me.status,'')))='ACTIVE'
                  AND UPPER(TRIM(COALESCE(me.office_code,'')))=UPPER(?)";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['hadir_branch'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    // Exception operasional WQS/SCM macet > 3 hari per office.
    try {
        $sql = "SELECT COUNT(DISTINCT id) FROM sales_do
                WHERE LOWER(TRIM(COALESCE(status,''))) IN
                  ('crm_to_wqs','sent_wqs','wqs_processing','ready_scm','wqs_done','on_delivery')
                  AND do_date IS NOT NULL
                  AND DATEDIFF(CURDATE(), do_date) > 3
                  AND {$officeWhere}";
        $st = $pdo->prepare($sql); $st->execute([$officeCode]);
        $out['exceptions'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    return $out;
}

// Resolve office BRANCH. Non-SYS tidak pernah boleh menjadi ALL.
$office = branch_resolve_user_office($pdo instanceof PDO ? $pdo : null, $office, $canViewAllOffices);
// Daftar kantor cabang canonical ERP RMI.
// PENTING: jangan mengambil daftar cabang dari master_employees dept BRANCH,
// karena cabang tanpa staff BRANCH aktif akan hilang dari dashboard/filter.
// Sumber acuan ERP_MENU_WORKFLOW_REFERENCE: BGR, BKS, TGR, BDG, SLO, SMG + Depo JGY, KAL.
$canonicalBranchCodes = ['BGR','BKS','TGR','BDG','SLO','SMG','JGY','KAL'];
$canonicalBranchNames = [
    'BGR' => 'Rizqullah Mediska Indonesia Bogor',
    'BKS' => 'Rizqullah Mediska Indonesia Bekasi',
    'TGR' => 'Rizqullah Mediska Indonesia Tangerang',
    'BDG' => 'Rizqullah Mediska Indonesia Bandung',
    'SLO' => 'Rizqullah Mediska Indonesia Solo',
    'SMG' => 'Rizqullah Mediska Indonesia Semarang',
    'JGY' => 'Depo Yogyakarta',
    'KAL' => 'Depo Kalimantan',
];

$branchOffices = [];
foreach ($canonicalBranchCodes as $oc) {
    $branchOffices[$oc] = $canonicalBranchNames[$oc] ?? $oc;
}

// Jika master_office memiliki nama resmi, pakai nama dari DB tanpa mengubah
// daftar office canonical di atas.
if ($pdo instanceof PDO) {
    try {
        $ph = implode(',', array_fill(0, count($canonicalBranchCodes), '?'));
        $sql = "SELECT UPPER(TRIM(office_code)) AS office_code, office_name
                FROM master_office
                WHERE UPPER(TRIM(office_code)) IN ($ph)";
        $st = $pdo->prepare($sql);
        $st->execute($canonicalBranchCodes);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $oc = strtoupper(trim((string)($row['office_code'] ?? '')));
            $name = trim((string)($row['office_name'] ?? ''));
            if ($oc !== '' && isset($branchOffices[$oc]) && $name !== '') {
                $branchOffices[$oc] = $name;
            }
        }
    } catch (Throwable $e) {}
}

// Validasi office SYS dari master. Jangan menerima office arbitrary dari query string.
if ($canViewAllOffices && $office !== '' && !isset($branchOffices[$office])) {
    $office = '';
}

$officeRows = [];
if ($pdo instanceof PDO) {
    if ($office !== '') {
        $name = $branchOffices[$office] ?? $office;
        try {
            $stO = $pdo->prepare("SELECT office_name FROM master_office WHERE UPPER(TRIM(office_code))=UPPER(?) LIMIT 1");
            $stO->execute([$office]);
            $n = trim((string)$stO->fetchColumn());
            if ($n !== '') $name = $n;
        } catch (Throwable $e) {}
        $officeRows[$office] = ['name' => $name, 'kpi' => branch_load_office_kpi($pdo, $office, $periodStart, $periodEnd)];
    } elseif ($canViewAllOffices) {
        // SYS / OWNER / DIREKSI tanpa filter: tampil TERPISAH per office, termasuk Depo JGY/KAL.
        foreach ($branchOffices as $oc => $name) {
            $officeRows[$oc] = ['name' => $name, 'kpi' => branch_load_office_kpi($pdo, $oc, $periodStart, $periodEnd)];
        }
    }
}

// Badge quick links = total dari office yang sedang terlihat; KPI utama tetap dipisah per office.
foreach ($officeRows as $row) {
    foreach ($kpi as $key => $v) {
        if (in_array($key, ['do_value','piutang'], true)) $kpi[$key] += (float)($row['kpi'][$key] ?? 0);
        else $kpi[$key] += (int)($row['kpi'][$key] ?? 0);
    }
}

$office_name = '';
if ($office !== '') {
    $office_name = $officeRows[$office]['name'] ?? ($branchOffices[$office] ?? $office);
} elseif ($canViewAllOffices) {
    $office_name = 'SEMUA CABANG — DIPISAH PER OFFICE';
} else {
    $office_name = 'OFFICE BELUM TERSET';
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';

$extraHead = <<<'HTML'
<style>
body{background:#0b1220;color:#e8ecf4}
.b-wrap{max-width:1100px;margin:0 auto;padding:20px 16px}

/* Greeting */
.b-greeting{background:linear-gradient(135deg,rgba(30,58,138,.8),rgba(17,94,89,.7));border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:22px 26px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;position:relative;overflow:hidden}
.b-greeting::before{content:"";position:absolute;top:-60px;right:-60px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.04)}
.b-greeting h2{margin:0;font-size:20px;font-weight:800;color:#fff}
.b-greeting p{margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.7)}
.b-clock{font-size:26px;font-weight:800;color:#67e8f9;font-variant-numeric:tabular-nums}
.b-date{font-size:11px;color:rgba(255,255,255,.5);text-align:right}

/* KPI grid */
.b-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:18px}
.b-kpi-card{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:14px;transition:all .2s;border-top:3px solid var(--kc)}
.b-kpi-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3)}
.b-kpi-icon{font-size:22px;margin-bottom:6px}
.b-kpi-val{font-size:22px;font-weight:800;color:#fff;line-height:1}
.b-kpi-lbl{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}

/* Quick link groups */
.b-links-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.b-link-group{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px}
.b-link-group-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.b-link{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;text-decoration:none;color:#e2e8f0;font-size:13px;transition:all .2s;margin-bottom:4px}
.b-link:hover{background:rgba(255,255,255,.08);color:#fff;padding-left:14px}
.b-link:last-child{margin-bottom:0}
.b-link .li{font-size:16px;flex-shrink:0}
.b-link .lbadge{margin-left:auto;background:rgba(239,68,68,.2);color:#f87171;font-size:10px;font-weight:700;padding:1px 7px;border-radius:8px}
.b-link .lbadge.green{background:rgba(34,197,94,.2);color:#4ade80}
.b-link .lbadge.yellow{background:rgba(251,191,36,.2);color:#fbbf24}
.b-manager{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0 0 18px}.b-manager .b-kpi-card{min-height:86px}.b-office-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.b-office-filter select{background:#111827;color:#e5e7eb;border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:7px 10px}.b-office-section{margin:0 0 22px;padding:14px;border:1px solid rgba(255,255,255,.10);border-radius:16px;background:rgba(15,23,42,.42)}.b-office-title{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:0 0 12px;padding:0 2px}.b-office-title h3{margin:0;font-size:16px;color:#f8fafc}.b-office-code{font-size:11px;font-weight:800;color:#67e8f9;background:rgba(6,182,212,.12);padding:4px 9px;border-radius:999px}.b-scope-warning{margin:0 0 18px;padding:14px 16px;border-radius:12px;border:1px solid rgba(239,68,68,.45);background:rgba(127,29,29,.22);color:#fecaca;font-size:13px}
</style>
HTML;

rmi_header('Branch Dashboard', [
    'active'       => 'dashboard',
    'subtitle'     => h($office_name) . ' — Operasional Harian',
    'extra_head'   => $extraHead,
    'actions'      => [
        ['label' => '📚 Panduan', 'url' => u('/dashboards/branch/panduan.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<div class="b-wrap">

  <!-- Greeting -->
  <div class="b-greeting">
    <div>
      <h2>🏢 <?= h($office_name ?: 'Branch Dashboard') ?></h2>
      <p>ERP RMI &nbsp;·&nbsp; <?= h(date('l, d F Y')) ?> &nbsp;·&nbsp; Kode: <strong><?= h($office !== '' ? $office : ($canViewAllOffices ? 'ALL' : '')) ?></strong></p>
    </div>
    <div style="text-align:right">
      <div class="b-clock" id="brClock">--:--:--</div>
      <div class="b-date">Waktu Server</div>
    </div>
  </div>

  <?php if ($canViewAllOffices): ?>
  <form method="get" class="b-office-filter" style="margin-bottom:14px">
    <strong style="font-size:12px;color:#94a3b8">Scope Office:</strong>
    <select name="office" onchange="this.form.submit()">
      <option value="">Semua Cabang</option>
      <?php foreach ($branchOffices as $oc => $on): ?>
        <option value="<?= h($oc) ?>" <?= strtoupper($office)===strtoupper($oc)?'selected':'' ?>><?= h($oc.' - '.$on) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>

  <?php if (!$officeRows): ?>
    <div class="b-scope-warning">
      Office akun BRANCH belum terpetakan. Dashboard sengaja tidak menampilkan angka ALL agar data antar cabang tidak tercampur.
      Set <strong>office_code</strong> pada Master System Login / Master Employee, lalu login ulang.
    </div>
  <?php endif; ?>

  <?php foreach ($officeRows as $oc => $officeRow): $okpi = $officeRow['kpi']; ?>
  <section class="b-office-section">
    <div class="b-office-title">
      <h3>🏢 <?= h($officeRow['name']) ?></h3>
      <span class="b-office-code"><?= h($oc) ?></span>
    </div>

    <div class="b-manager">
      <div class="b-kpi-card" style="--kc:#64748b"><div class="b-kpi-lbl">Scope</div><div class="b-kpi-val" style="font-size:18px"><?= h($oc) ?></div></div>
      <div class="b-kpi-card" style="--kc:#3b82f6"><div class="b-kpi-lbl">Team Staff Branch</div><div class="b-kpi-val"><?= $okpi['team_staff'] ?></div></div>
      <div class="b-kpi-card" style="--kc:#10b981"><div class="b-kpi-lbl">Attendance Branch Today</div><div class="b-kpi-val"><?= $okpi['hadir_branch'] ?></div><div class="b-kpi-lbl" style="margin-top:6px;text-transform:none">Personel BRANCH check-in hari ini</div></div>
      <div class="b-kpi-card" style="--kc:#f59e0b"><div class="b-kpi-lbl">Backlog Open</div><div class="b-kpi-val"><?= $okpi['picking'] + $okpi['on_delivery'] ?></div></div>
      <div class="b-kpi-card" style="--kc:#ef4444"><div class="b-kpi-lbl">Exceptions</div><div class="b-kpi-val"><?= $okpi['exceptions'] ?></div><div class="b-kpi-lbl" style="margin-top:6px;text-transform:none">DO WQS/SCM berumur &gt;3 hari (dari tanggal DO)</div></div>
      <div class="b-kpi-card" style="--kc:#06b6d4"><div class="b-kpi-lbl">Delivery Active</div><div class="b-kpi-val"><?= $okpi['on_delivery'] ?></div></div>
    </div>

    <div class="b-kpi">
      <div class="b-kpi-card" style="--kc:#3b82f6">
        <div class="b-kpi-icon">📋</div><div class="b-kpi-val"><?= $okpi['do_count'] ?></div><div class="b-kpi-lbl">DO Bulan Ini</div>
      </div>
      <div class="b-kpi-card" style="--kc:#22c55e">
        <div class="b-kpi-icon">💰</div><div class="b-kpi-val" style="font-size:16px">Rp <?= fmt_money((float)$okpi['do_value']) ?></div><div class="b-kpi-lbl">Nilai Penjualan MTD</div>
      </div>
      <div class="b-kpi-card" style="--kc:#f59e0b">
        <div class="b-kpi-icon">⏳</div><div class="b-kpi-val"><?= $okpi['do_open'] ?></div><div class="b-kpi-lbl">DO Aktif / Belum Selesai</div>
      </div>
      <div class="b-kpi-card" style="--kc:#ef4444">
        <div class="b-kpi-icon">💳</div><div class="b-kpi-val" style="font-size:16px">Rp <?= fmt_money((float)$okpi['piutang']) ?></div><div class="b-kpi-lbl">Piutang Outstanding</div>
      </div>
      <div class="b-kpi-card" style="--kc:#06b6d4">
        <div class="b-kpi-icon">📦</div><div class="b-kpi-val"><?= $okpi['picking'] ?></div><div class="b-kpi-lbl">WQS / Picking Pending</div>
      </div>
      <div class="b-kpi-card" style="--kc:#8b5cf6">
        <div class="b-kpi-icon">📝</div><div class="b-kpi-val"><?= $okpi['pr_pending'] ?></div><div class="b-kpi-lbl">PR Open</div>
      </div>
      <div class="b-kpi-card" style="--kc:#10b981">
        <div class="b-kpi-icon">✅</div><div class="b-kpi-val"><?= $okpi['hadir'] ?></div><div class="b-kpi-lbl">Total Hadir Kantor Hari Ini</div>
      </div>
    </div>
  </section>
  <?php endforeach; ?>

  <!-- Quick Links -->
  <div class="b-links-grid">

    <!-- Sales -->
    <div class="b-link-group">
      <div class="b-link-group-title">💼 Sales & CRM</div>
      <a class="b-link" href="<?= h(u('/sales/sales_do.php')) ?>"><span class="li">📋</span> Delivery Order (DO)</a>
      <a class="b-link" href="<?= h(u('/sales/sales_control_tower.php')) ?>"><span class="li">🗼</span> Sales Control Tower</a>
      <a class="b-link" href="<?= h(u('/sales/crm_leads.php')) ?>"><span class="li">🎯</span> CRM Leads</a>
      <a class="b-link" href="<?= h(u('/stock/wqs_do_tasks.php')) ?>">
        <span class="li">⚡</span> Task DO dari CRM
        <?php if ($kpi['picking'] > 0): ?><span class="lbadge yellow"><?= $kpi['picking'] ?></span><?php endif; ?>
      </a>
    </div>


    <!-- Marketing / Project (MPR Cabang) -->
    <div class="b-link-group">
      <div class="b-link-group-title">📍 Marketing & Project Cabang</div>
      <a class="b-link" href="<?= h(u('/mpr/mpr_plans.php')) ?>"><span class="li">🗓️</span> Buat / Lihat MPR Plan</a>
      <a class="b-link" href="<?= h(u('/mpr/mpr_visits.php')) ?>"><span class="li">📸</span> Kunjungan Customer GPS + Foto</a>
      <a class="b-link" href="<?= h(u('/mpr/mpr_dashboard.php')) ?>"><span class="li">📊</span> Dashboard MPR Cabang</a>
    </div>

    <!-- Stock / WQS -->
    <div class="b-link-group">
      <div class="b-link-group-title">📦 Warehouse & Stock</div>
      <a class="b-link" href="<?= h(u('/stock/wqs_picking.php')) ?>">
        <span class="li">🚚</span> Picking DO
        <?php if ($kpi['picking'] > 0): ?><span class="lbadge yellow"><?= $kpi['picking'] ?></span><?php endif; ?>
      </a>
      <a class="b-link" href="<?= h(u('/stock/wqs_incoming.php')) ?>"><span class="li">📥</span> Incoming Barang</a>
      <a class="b-link" href="<?= h(u('/stock/wqs_stock.php')) ?>"><span class="li">📊</span> Lihat Stok</a>
      <a class="b-link" href="<?= h(u('/stock/wqs_stock_opname.php')) ?>"><span class="li">🔢</span> Stock Opname</a>
      <a class="b-link" href="<?= h(u('/stock/wqs_pr.php')) ?>">
        <span class="li">📝</span> Purchase Request (PR)
        <?php if ($kpi['pr_pending'] > 0): ?><span class="lbadge"><?= $kpi['pr_pending'] ?></span><?php endif; ?>
      </a>
    </div>

    <!-- Purchasing -->
    <div class="b-link-group">
      <div class="b-link-group-title">🛒 Purchasing</div>
      <a class="b-link" href="<?= h(u('/purchases/purchases_po.php')) ?>"><span class="li">📄</span> Purchase Order (PO)</a>
      <a class="b-link" href="<?= h(u('/purchases/purchases_gr.php')) ?>"><span class="li">✅</span> Good Receipt (GR)</a>
      <a class="b-link" href="<?= h(u('/sales/scm_do_tasks.php')) ?>"><span class="li">🔄</span> SCM Task DO</a>
    </div>

    <!-- HR & Lain -->
    <div class="b-link-group">
      <div class="b-link-group-title">👥 HR & Lainnya</div>
      <a class="b-link" href="<?= h(u('/absensi/index.php')) ?>">
        <span class="li">📅</span> Absensi
        <?php if ($kpi['hadir'] > 0): ?><span class="lbadge green"><?= $kpi['hadir'] ?> hadir</span><?php endif; ?>
      </a>
      <a class="b-link" href="<?= h(u('/hrl_process/index.php')) ?>"><span class="li">📋</span> HRL Process</a>
      <a class="b-link" href="<?= h(u('/chat/index.php')) ?>"><span class="li">💬</span> Chat Internal</a>
      <?php if ($branchDashShowKpi): ?>
      <a class="b-link" href="<?= h(u('/kpi/kpi_center.php')) ?>"><span class="li">📊</span> KPI Center</a>
      <?php endif; ?>
      <a class="b-link" href="<?= h(u('/dashboards/index.php')) ?>"><span class="li">🏠</span> Dashboard Center</a>
      <a class="b-link" href="<?= h(u('/dashboards/branch/panduan.php')) ?>" style="border-color:rgba(16,185,129,.4);color:#34d399"><span class="li">📚</span> Panduan BRANCH</a>
    </div>

  </div>

  <div style="margin-top:12px;font-size:11px;color:#334155;text-align:center">
    Periode MTD: <?= h($periodStart) ?> s/d <?= h($periodEnd) ?>
  </div>
</div>

<script>
(function(){
  function pad(n){return n<10?'0'+n:n}
  function tick(){
    var d=new Date(),el=document.getElementById('brClock');
    if(el) el.textContent=pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
  }
  tick(); setInterval(tick,1000);
})();
</script>
<?php rmi_footer(); ?>
