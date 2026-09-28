<?php
declare(strict_types=1);

/**
 * Owner Activity Control
 * Read-only executive history by Department -> Account -> Activity -> Record Code.
 * Source of truth for this page: system_audit_logs + master_system_login profile mapping.
 *
 * IMPORTANT:
 * - No business mutation is performed here.
 * - "Tahap terakhir" means the latest AUDIT EVENT recorded, not necessarily the final
 *   business status if a module has not written complete audit events yet.
 * - Access is hard-gated to OWNER / ADMIN / SUPERADMIN / SYS only.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_login();

function oac_is_privileged_viewer(): bool
{
    $u = function_exists('auth_user') ? (array)auth_user() : [];
    $role  = strtoupper(trim((string)($u['role'] ?? ($_SESSION['role'] ?? ''))));
    $level = strtoupper(trim((string)($u['level'] ?? ($_SESSION['level'] ?? ''))));
    $dept  = strtoupper(trim((string)($u['department'] ?? ($_SESSION['department'] ?? ''))));

    // OWNER / ADMIN / SYS adalah satu-satunya kelas akses halaman ini.
    // SUPERADMIN dipertahankan sebagai alias legacy ADMIN.
    $allowed = ['OWNER', 'ADMIN', 'SYS', 'SUPERADMIN'];
    if (in_array($role, $allowed, true)
        || in_array($level, $allowed, true)
        || in_array($dept, $allowed, true)) {
        return true;
    }

    // Fallback ke helper auth kanonik untuk instalasi lama yang session profile-nya belum lengkap.
    if (function_exists('auth_is_sys')) {
        try { if (auth_is_sys()) return true; } catch (Throwable $e) {}
    }
    if (function_exists('auth_is_admin')) {
        try { if (auth_is_admin()) return true; } catch (Throwable $e) {}
    }

    return false;
}

if (!oac_is_privileged_viewer()) {
    http_response_code(403);
    echo '<h3>Akses Ditolak</h3><p>Owner Activity Control hanya untuk OWNER / ADMIN / SYS.</p>';
    exit;
}

require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

$pdo = db_pdo();
// Strict read-only page: jangan create/alter/ensure schema dari halaman monitoring ini.

function oac_h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function oac_table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function oac_dt(string $v): string
{
    $v = trim($v);
    if ($v === '') return '—';
    return substr($v, 0, 19);
}

function oac_short(string $v, int $max = 110): string
{
    $v = trim(preg_replace('/\s+/', ' ', $v) ?? '');
    $len = function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);
    if ($len <= $max) return $v;
    $cut = function_exists('mb_substr') ? mb_substr($v, 0, $max - 1) : substr($v, 0, $max - 1);
    return $cut . '…';
}

function oac_action_badge(string $action): string
{
    $a = strtoupper(trim($action));
    if ($a === '') return 'secondary';
    if (preg_match('/(SUCCESS|DONE|COMPLETE|COMPLETED|PAID|APPROVE|APPROVED|POSTED|DELIVERED|RECEIVED)/', $a)) return 'success';
    if (preg_match('/(FAIL|FAILED|DENIED|REJECT|REJECTED|ERROR|CANCEL|CANCELLED|DELETE|DELETED|EXPIRED)/', $a)) return 'danger';
    if (preg_match('/(START|CREATE|CREATED|SUBMIT|SUBMITTED|UPLOAD|UPDATE|EDIT|PROCESS|PICK|PACK|SHIP|LOGIN)/', $a)) return 'primary';
    return 'secondary';
}

/**
 * Status akun dari master_system_login.
 * Legacy/blank dianggap enabled kecuali eksplisit berstatus nonaktif/blocked.
 */
function oac_account_is_enabled(string $status): bool
{
    $s = strtoupper(trim($status));
    if ($s === '') return true;
    return !in_array($s, [
        'INACTIVE', 'NONACTIVE', 'NON-ACTIVE', 'NONAKTIF', 'NON AKTIF',
        'DISABLED', 'DISABLE', 'BLOCKED', 'LOCKED', 'SUSPENDED', '0', 'OFF'
    ], true);
}

/**
 * Event autentikasi/session tetap tampil di timeline, tetapi tidak dianggap
 * "pekerjaan terakhir" agar LOGIN/LOGOUT tidak menutupi aktivitas operasional user.
 */
function oac_work_event_condition(string $alias): string
{
    return "UPPER(COALESCE($alias.module,'')) NOT IN ('AUTH','SESSION')"
        . " AND UPPER(COALESCE($alias.action,'')) NOT IN ("
        . "'LOGIN','LOGIN_SUCCESS','LOGIN_DENIED','LOGIN_FAILED','LOGOUT',"
        . "'SESSION_EXPIRED','SESSION_START','SESSION_REFRESH','MFA_CHALLENGE','MFA_SUCCESS')";
}

function oac_build_where(array $f, array &$params, string $alias = 'sal', string $userAlias = 'msl'): string
{
    $w = [];
    if ($f['date_from'] !== '') {
        $w[] = "$alias.created_at >= ?";
        $params[] = $f['date_from'] . ' 00:00:00';
    }
    if ($f['date_to'] !== '') {
        $w[] = "$alias.created_at <= ?";
        $params[] = $f['date_to'] . ' 23:59:59';
    }
    if ($f['department'] !== '') {
        if (strtoupper($f['department']) === 'UNMAPPED') {
            $w[] = "($userAlias.username IS NULL OR COALESCE($userAlias.department,'')='')";
        } else {
            $w[] = "UPPER(COALESCE($userAlias.department,'')) = ?";
            $params[] = strtoupper($f['department']);
        }
    }
    if ($f['office'] !== '') {
        $w[] = "UPPER(COALESCE($userAlias.office_code,'')) = ?";
        $params[] = strtoupper($f['office']);
    }
    if ($f['username'] !== '') {
        $w[] = "$alias.username = ?";
        $params[] = $f['username'];
    }
    if ($f['module'] !== '') {
        $w[] = "$alias.module = ?";
        $params[] = $f['module'];
    }
    if ($f['action'] !== '') {
        $w[] = "$alias.action = ?";
        $params[] = $f['action'];
    }
    if ($f['record_code'] !== '') {
        $w[] = "$alias.record_code = ?";
        $params[] = $f['record_code'];
    }
    if ($f['q'] !== '') {
        $w[] = "($alias.username LIKE ? OR $alias.record_code LIKE ? OR $alias.description LIKE ? OR $alias.module LIKE ? OR $alias.action LIKE ?)";
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    return $w ? (' WHERE ' . implode(' AND ', $w)) : '';
}

$today = date('Y-m-d');
$filters = [
    'date_from'   => trim((string)($_GET['date_from'] ?? $today)),
    'date_to'     => trim((string)($_GET['date_to'] ?? $today)),
    'department'  => trim((string)($_GET['department'] ?? '')),
    'office'      => trim((string)($_GET['office'] ?? '')),
    'username'    => trim((string)($_GET['username'] ?? '')),
    'module'      => trim((string)($_GET['module'] ?? '')),
    'action'      => trim((string)($_GET['action'] ?? '')),
    'record_code' => trim((string)($_GET['record_code'] ?? '')),
    'q'           => trim((string)($_GET['q'] ?? '')),
];
$limit = min(1000, max(100, (int)($_GET['limit'] ?? 300)));

$hasAudit = oac_table_exists($pdo, 'system_audit_logs');
$hasUsers = oac_table_exists($pdo, 'master_system_login');
$base = rmi_layout_base_project();

$departments = $offices = $users = $modules = $actions = [];
$summary = ['events' => 0, 'accounts' => 0, 'departments' => 0, 'records' => 0];
$deptSummary = [];
$userSummary = [];
$processSummary = [];
$timeline = [];
$coverage = ['mapped' => 0, 'unmapped' => 0];

if ($hasAudit && $hasUsers) {
    try {
        $departments = $pdo->query("SELECT DISTINCT UPPER(department) FROM master_system_login WHERE department IS NOT NULL AND department<>'' ORDER BY UPPER(department)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {}
    try {
        $offices = $pdo->query("SELECT DISTINCT UPPER(office_code) FROM master_system_login WHERE office_code IS NOT NULL AND office_code<>'' ORDER BY UPPER(office_code)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {}
    try {
        $st = $pdo->query("SELECT username, UPPER(COALESCE(department,'')) department, UPPER(COALESCE(office_code,'')) office_code FROM master_system_login WHERE username IS NOT NULL AND username<>'' ORDER BY department, username");
        $users = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {}
    try {
        $modules = $pdo->query("SELECT DISTINCT module FROM system_audit_logs WHERE module IS NOT NULL AND module<>'' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {}
    try {
        $actions = $pdo->query("SELECT DISTINCT action FROM system_audit_logs WHERE action IS NOT NULL AND action<>'' ORDER BY action")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {}

    $params = [];
    $where = oac_build_where($filters, $params);
    $join = ' FROM system_audit_logs sal LEFT JOIN master_system_login msl ON msl.username = sal.username ';

    try {
        $sql = "SELECT COUNT(*) events,
                       COUNT(DISTINCT NULLIF(sal.username,'')) accounts,
                       COUNT(DISTINCT NULLIF(UPPER(COALESCE(msl.department,'')),'')) departments,
                       COUNT(DISTINCT CASE WHEN sal.record_code IS NOT NULL AND sal.record_code<>'' AND sal.record_code<>sal.username THEN sal.record_code END) records,
                       SUM(CASE WHEN msl.username IS NULL THEN 1 ELSE 0 END) unmapped,
                       SUM(CASE WHEN msl.username IS NOT NULL THEN 1 ELSE 0 END) mapped
                $join $where";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary = [
            'events' => (int)($r['events'] ?? 0),
            'accounts' => (int)($r['accounts'] ?? 0),
            'departments' => (int)($r['departments'] ?? 0),
            'records' => (int)($r['records'] ?? 0),
        ];
        $coverage = ['mapped' => (int)($r['mapped'] ?? 0), 'unmapped' => (int)($r['unmapped'] ?? 0)];
    } catch (Throwable $e) {}

    try {
        $sql = "SELECT COALESCE(NULLIF(UPPER(msl.department),''),'UNMAPPED') department,
                       COUNT(*) events,
                       COUNT(DISTINCT NULLIF(sal.username,'')) accounts,
                       MAX(sal.created_at) last_at
                $join $where
                GROUP BY COALESCE(NULLIF(UPPER(msl.department),''),'UNMAPPED')
                ORDER BY events DESC, department";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $deptSummary = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // Semua akun master ditampilkan, termasuk akun yang tidak punya aktivitas pada periode/filter.
    // Statistik + event terakhir dihitung secara batch agar halaman tidak membuat N+1 query per akun.
    try {
        $userWhere = [];
        $userParams = [];
        if ($filters['department'] !== '') {
            if (strtoupper($filters['department']) === 'UNMAPPED') {
                $userWhere[] = "1=0"; // UNMAPPED berarti audit tanpa pasangan user; bukan akun master.
            } else {
                $userWhere[] = "UPPER(COALESCE(department,'')) = ?";
                $userParams[] = strtoupper($filters['department']);
            }
        }
        if ($filters['office'] !== '') {
            $userWhere[] = "UPPER(COALESCE(office_code,'')) = ?";
            $userParams[] = strtoupper($filters['office']);
        }
        if ($filters['username'] !== '') {
            $userWhere[] = 'username = ?';
            $userParams[] = $filters['username'];
        }
        $userWhereSql = $userWhere ? (' WHERE ' . implode(' AND ', $userWhere)) : '';
        $ust = $pdo->prepare("SELECT id, username, COALESCE(department,'') department, COALESCE(role,'') role,
                                     COALESCE(level,'') level, COALESCE(office_code,'') office_code,
                                     COALESCE(status,'') status
                              FROM master_system_login
                              $userWhereSql
                              ORDER BY department, office_code, username
                              LIMIT 1000");
        $ust->execute($userParams);
        $userSummary = $ust->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $statsByUser = [];
        $aggSql = "SELECT sal.username,
                          COUNT(*) events,
                          COUNT(DISTINCT CASE WHEN sal.record_code IS NOT NULL AND sal.record_code<>'' AND sal.record_code<>sal.username THEN sal.record_code END) records,
                          MIN(sal.created_at) first_at,
                          MAX(sal.created_at) last_at
                   $join $where
                   GROUP BY sal.username";
        $aggSt = $pdo->prepare($aggSql);
        $aggSt->execute($params);
        foreach (($aggSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $statsByUser[(string)($r['username'] ?? '')] = $r;
        }

        // Ambil event terbaru per username dalam satu query. GROUP_CONCAT diurutkan newest-first;
        // walau hasil terpotong, ID pertama tetap event yang paling baru.
        $lp = [];
        $lw = oac_build_where($filters, $lp, 'sal2', 'msl2');
        $latestUserSql = "SELECT sal.username, sal.module, sal.action, sal.record_code, sal.description, sal.created_at
                          FROM system_audit_logs sal
                          INNER JOIN (
                              SELECT CAST(SUBSTRING_INDEX(GROUP_CONCAT(sal2.id ORDER BY sal2.created_at DESC, sal2.id DESC), ',', 1) AS UNSIGNED) AS latest_id
                              FROM system_audit_logs sal2
                              LEFT JOIN master_system_login msl2 ON msl2.username = sal2.username
                              $lw
                              GROUP BY sal2.username
                          ) x ON x.latest_id = sal.id";
        $lstSt = $pdo->prepare($latestUserSql);
        $lstSt->execute($lp);
        $latestByUser = [];
        foreach (($lstSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $latestByUser[(string)($r['username'] ?? '')] = $r;
        }

        // Event pekerjaan terakhir: filter yang sama, tetapi LOGIN/LOGOUT/session dikeluarkan.
        $wp = [];
        $ww = oac_build_where($filters, $wp, 'sal3', 'msl3');
        $workCond = oac_work_event_condition('sal3');
        $ww = $ww === '' ? (' WHERE ' . $workCond) : ($ww . ' AND ' . $workCond);
        $latestWorkSql = "SELECT sal.username, sal.module, sal.action, sal.record_code, sal.description, sal.created_at
                          FROM system_audit_logs sal
                          INNER JOIN (
                              SELECT CAST(SUBSTRING_INDEX(GROUP_CONCAT(sal3.id ORDER BY sal3.created_at DESC, sal3.id DESC), ',', 1) AS UNSIGNED) AS latest_id
                              FROM system_audit_logs sal3
                              LEFT JOIN master_system_login msl3 ON msl3.username = sal3.username
                              $ww
                              GROUP BY sal3.username
                          ) x ON x.latest_id = sal.id";
        $workSt = $pdo->prepare($latestWorkSql);
        $workSt->execute($wp);
        $latestWorkByUser = [];
        foreach (($workSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $latestWorkByUser[(string)($r['username'] ?? '')] = $r;
        }

        foreach ($userSummary as &$u) {
            $uname = (string)($u['username'] ?? '');
            $cnt = $statsByUser[$uname] ?? [];
            $lastAudit = $latestByUser[$uname] ?? [];
            $lastWork = $latestWorkByUser[$uname] ?? [];
            $u['events'] = (int)($cnt['events'] ?? 0);
            $u['records'] = (int)($cnt['records'] ?? 0);
            $u['first_at'] = $cnt['first_at'] ?? '';
            $u['last_at'] = $cnt['last_at'] ?? '';

            // Audit terakhir (termasuk login/session).
            $u['last_audit_module'] = $lastAudit['module'] ?? '';
            $u['last_audit_action'] = $lastAudit['action'] ?? '';
            $u['last_audit_record_code'] = $lastAudit['record_code'] ?? '';
            $u['last_audit_description'] = $lastAudit['description'] ?? '';
            $u['last_audit_at'] = $lastAudit['created_at'] ?? ($cnt['last_at'] ?? '');

            // Pekerjaan terakhir (auth/session dikeluarkan).
            $u['last_work_module'] = $lastWork['module'] ?? '';
            $u['last_work_action'] = $lastWork['action'] ?? '';
            $u['last_work_record_code'] = $lastWork['record_code'] ?? '';
            $u['last_work_description'] = $lastWork['description'] ?? '';
            $u['last_work_at'] = $lastWork['created_at'] ?? '';
        }
        unset($u);

        usort($userSummary, static function(array $a, array $b): int {
            $ae = (int)($a['events'] ?? 0);
            $be = (int)($b['events'] ?? 0);
            if (($ae > 0) !== ($be > 0)) return $be <=> $ae;
            $al = (string)($a['last_at'] ?? '');
            $bl = (string)($b['last_at'] ?? '');
            if ($al !== $bl) return strcmp($bl, $al);
            return strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
        });
    } catch (Throwable $e) {
        $userSummary = [];
    }

    // Sempurnakan ringkasan departemen: semua departemen/akun master tetap tampil
    // meskipun 0 aktivitas pada periode yang dipilih.
    $auditDeptByName = [];
    foreach ($deptSummary as $drow) {
        $dk = strtoupper((string)($drow['department'] ?? ''));
        if ($dk !== '') $auditDeptByName[$dk] = $drow;
    }
    $deptMaster = [];
    foreach ($userSummary as $urow) {
        $dk = strtoupper(trim((string)($urow['department'] ?? '')));
        if ($dk === '') $dk = 'UNMAPPED';
        if (!isset($deptMaster[$dk])) {
            $deptMaster[$dk] = ['department' => $dk, 'accounts' => 0, 'events' => 0, 'last_at' => ''];
        }
        $deptMaster[$dk]['accounts']++;
    }
    foreach ($deptMaster as $dk => &$drow) {
        if (isset($auditDeptByName[$dk])) {
            $drow['events'] = (int)($auditDeptByName[$dk]['events'] ?? 0);
            $drow['last_at'] = (string)($auditDeptByName[$dk]['last_at'] ?? '');
        }
    }
    unset($drow);
    if (isset($auditDeptByName['UNMAPPED']) && !isset($deptMaster['UNMAPPED'])
        && ($filters['department'] === '' || strtoupper($filters['department']) === 'UNMAPPED')) {
        $deptMaster['UNMAPPED'] = $auditDeptByName['UNMAPPED'];
    }
    $deptSummary = array_values($deptMaster);
    usort($deptSummary, static fn(array $a, array $b): int =>
        strcmp((string)($a['department'] ?? ''), (string)($b['department'] ?? ''))
    );

    // Ringkasan record + tahap audit terakhir, juga batch (maks. 80 record per tampilan).
    try {
        $p3 = [];
        $w3 = oac_build_where($filters, $p3);
        $extra = $w3 === '' ? ' WHERE ' : ($w3 . ' AND ');
        $extra .= "sal.record_code IS NOT NULL AND sal.record_code<>'' AND sal.record_code<>sal.username";
        $sql = "SELECT sal.record_code,
                       COUNT(*) events,
                       COUNT(DISTINCT NULLIF(sal.username,'')) actors,
                       MIN(sal.created_at) first_at,
                       MAX(sal.created_at) last_at
                $join $extra
                GROUP BY sal.record_code
                ORDER BY last_at DESC
                LIMIT 80";
        $st = $pdo->prepare($sql);
        $st->execute($p3);
        $processSummary = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recordCodes = [];
        foreach ($processSummary as $pRow) {
            $rc = (string)($pRow['record_code'] ?? '');
            if ($rc !== '') $recordCodes[] = $rc;
        }
        $latestByRecord = [];
        if ($recordCodes) {
            $p4 = [];
            $w4 = oac_build_where($filters, $p4, 'sal2', 'msl2');
            $ph = implode(',', array_fill(0, count($recordCodes), '?'));
            $and = $w4 === '' ? ' WHERE ' : ($w4 . ' AND ');
            $and .= "sal2.record_code IN ($ph)";
            $p4 = array_merge($p4, $recordCodes);
            $latestRecordSql = "SELECT sal.record_code, sal.module, sal.action, sal.username, sal.description, sal.created_at,
                                       COALESCE(msl.department,'') department, COALESCE(msl.office_code,'') office_code
                                FROM system_audit_logs sal
                                LEFT JOIN master_system_login msl ON msl.username = sal.username
                                INNER JOIN (
                                    SELECT sal2.record_code,
                                           CAST(SUBSTRING_INDEX(GROUP_CONCAT(sal2.id ORDER BY sal2.created_at DESC, sal2.id DESC), ',', 1) AS UNSIGNED) AS latest_id
                                    FROM system_audit_logs sal2
                                    LEFT JOIN master_system_login msl2 ON msl2.username = sal2.username
                                    $and
                                    GROUP BY sal2.record_code
                                ) x ON x.latest_id = sal.id";
            $lst = $pdo->prepare($latestRecordSql);
            $lst->execute($p4);
            foreach (($lst->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                $latestByRecord[(string)($r['record_code'] ?? '')] = $r;
            }
        }
        foreach ($processSummary as &$p) {
            $last = $latestByRecord[(string)($p['record_code'] ?? '')] ?? [];
            $p['last_module'] = $last['module'] ?? '';
            $p['last_action'] = $last['action'] ?? '';
            $p['last_username'] = $last['username'] ?? '';
            $p['last_department'] = $last['department'] ?? '';
            $p['last_office'] = $last['office_code'] ?? '';
            $p['last_description'] = $last['description'] ?? '';
        }
        unset($p);
    } catch (Throwable $e) {
        $processSummary = [];
    }

    try {
        $sql = "SELECT sal.id, sal.created_at, sal.module, sal.action, sal.record_code, sal.username, sal.ip, sal.description,
                       COALESCE(msl.department,'') department,
                       COALESCE(msl.role,'') role,
                       COALESCE(msl.level,'') level,
                       COALESCE(msl.office_code,'') office_code
                $join $where
                ORDER BY sal.created_at DESC, sal.id DESC
                LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $timeline = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

// Derived account usage indicators for Owner view (read-only, no session/online claim).
$masterActiveAccounts = 0;
$usedAccounts = 0;
$activeUsedAccounts = 0;
foreach ($userSummary as &$oacUserRow) {
    $masterActive = oac_account_is_enabled((string)($oacUserRow['status'] ?? ''));
    $usedInFilter = ((int)($oacUserRow['events'] ?? 0)) > 0;
    $oacUserRow['_master_active'] = $masterActive;
    $oacUserRow['_used_period'] = $usedInFilter;
    if ($masterActive) $masterActiveAccounts++;
    if ($usedInFilter) $usedAccounts++;
    if ($masterActive && $usedInFilter) $activeUsedAccounts++;
}
unset($oacUserRow);

$queryBase = $_GET;
unset($queryBase['username'], $queryBase['record_code']);
function oac_url(array $baseQ, array $override = []): string {
    $q = array_merge($baseQ, $override);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    return '?' . http_build_query($q);
}

$subtitle = 'History semua departemen dan akun berdasarkan system_audit_logs — read-only';
rmi_header('Owner Activity Control', [
    'active' => 'monitoring_center',
    'subtitle' => $subtitle,
    'breadcrumbs' => [
        ['label' => 'Monitoring & Control', 'url' => $base . '/master/monitoring_center.php'],
        'Owner Activity Control',
    ],
    'actions' => [
        ['label' => 'Audit Log Raw', 'url' => $base . '/master/audit_logs.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Monitoring', 'url' => $base . '/master/monitoring_center.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<style>
/* ========================================================================
   OWNER ACTIVITY CONTROL — EXECUTIVE HIGH-CONTRAST UI
   Visual-only layer. Business queries, RBAC, filters and audit semantics stay intact.
   ======================================================================== */
:root {
  --oac-navy-950:#071a2d;
  --oac-navy-900:#0b2239;
  --oac-navy-800:#123b63;
  --oac-blue:#1467d8;
  --oac-blue-100:#eaf3ff;
  --oac-cyan:#087f8c;
  --oac-green:#087a4b;
  --oac-green-100:#e9f8f0;
  --oac-amber:#a95408;
  --oac-red:#b42318;
  --oac-ink:#102a43;
  --oac-muted:#52667b;
  --oac-line:#cbd7e4;
  --oac-soft:#f4f7fb;
  --oac-white:#fff;
  --oac-radius:14px;
}

/* Important: ERP shell is dark; force readable local typography. */
.oac-page, .oac-filter-card, .oac-section, .oac-kpi-card { color:var(--oac-ink); }
.oac-section *, .oac-filter-card *, .oac-kpi-card * { text-rendering:optimizeLegibility; }

/* ---------- Intro ---------- */
.oac-hero-note{
  display:grid;grid-template-columns:auto 1fr;gap:.9rem;align-items:start;
  padding:1rem 1.1rem;margin-bottom:1rem;border:1px solid #27567f;border-radius:var(--oac-radius);
  background:linear-gradient(135deg,var(--oac-navy-950),#164a76 72%,#155e75);
  box-shadow:0 10px 28px rgba(3,19,34,.22);color:#fff;
}
.oac-hero-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:#ffffff18;border:1px solid #ffffff33;color:#fff;font-size:1.2rem}
.oac-hero-title{font-weight:900;font-size:1rem;color:#fff;margin-bottom:.2rem;letter-spacing:.01em}
.oac-hero-text{color:#e4eff9;font-size:.84rem;line-height:1.55;font-weight:520}

/* ---------- Filter ---------- */
.oac-filter-card{padding:1rem;border-radius:var(--oac-radius);background:#eef4fa!important;border:1px solid #8fa8c1!important;box-shadow:0 6px 18px rgba(5,28,50,.12)}
.oac-filter-titlebar{display:flex;justify-content:space-between;align-items:center;gap:.75rem;margin:-1rem -1rem .95rem;padding:.78rem 1rem;background:linear-gradient(90deg,#163a5b,#214e75);border-radius:13px 13px 0 0;border-bottom:1px solid #315d83}
.oac-filter-title{font-weight:900;font-size:.9rem;color:#fff}
.oac-filter-help{color:#d8e8f6;font-size:.74rem;font-weight:600}
.oac-filter-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:.72rem;align-items:end}
.oac-field{min-width:0}.oac-field label{display:block;font-size:.73rem;font-weight:850;color:#233f5d;margin-bottom:.3rem}
.oac-field .form-control,.oac-field .form-select{min-height:37px;border-radius:8px;border:1px solid #8fa6bd;background:#fff;color:#0f2940;font-size:.8rem;font-weight:600;box-shadow:inset 0 1px 2px rgba(12,33,51,.04)}
.oac-field .form-control:focus,.oac-field .form-select:focus{border-color:#1467d8;box-shadow:0 0 0 .17rem rgba(20,103,216,.16)}
.oac-span-1{grid-column:span 1}.oac-span-2{grid-column:span 2}.oac-span-3{grid-column:span 3}.oac-span-4{grid-column:span 4}.oac-span-5{grid-column:span 5}
.oac-filter-actions{display:flex;gap:.5rem;align-items:stretch;white-space:nowrap}
.oac-filter-actions .btn{min-width:86px;min-height:37px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-weight:850;font-size:.78rem}
.oac-filter-actions .btn-primary{background:#0b63ce;border-color:#0b63ce}.oac-filter-actions .btn-primary:hover{background:#084fa8;border-color:#084fa8}
.oac-filter-actions .btn-outline-secondary{background:#fff;color:#3c5168;border-color:#8399af}.oac-filter-actions .btn-outline-secondary:hover{background:#52677c;color:#fff}
.oac-presets{display:flex;align-items:center;gap:.42rem;flex-wrap:wrap;padding-top:.8rem;margin-top:.8rem;border-top:1px solid #b9c8d7}
.oac-presets .text-muted{color:#415a72!important}.oac-presets a{display:inline-flex;align-items:center;padding:.22rem .68rem;border:1px solid #8fa9c2;border-radius:999px;text-decoration:none;background:#fff;color:#124e91;font-size:.74rem;font-weight:850}.oac-presets a:hover{background:#dcecff;border-color:#5d8fca}

/* ---------- KPI ---------- */
.oac-kpi-card{position:relative;overflow:hidden;min-height:112px;border-radius:var(--oac-radius);border:0!important;box-shadow:0 8px 20px rgba(5,28,50,.16);padding:1rem!important;background:linear-gradient(135deg,#123a63,#1b5b92)!important;color:#fff!important}
.row.g-3.mb-3>div:nth-child(2) .oac-kpi-card{background:linear-gradient(135deg,#24506e,#087f8c)!important}
.row.g-3.mb-3>div:nth-child(3) .oac-kpi-card{background:linear-gradient(135deg,#15553d,#087a4b)!important}
.row.g-3.mb-3>div:nth-child(4) .oac-kpi-card{background:linear-gradient(135deg,#75400f,#a95408)!important}
.oac-kpi-card:after{content:"";position:absolute;width:88px;height:88px;border-radius:50%;right:-25px;top:-28px;background:#ffffff12}
.oac-kpi-label{font-size:.72rem;font-weight:900;letter-spacing:.055em;text-transform:uppercase;color:#dceafa!important}
.oac-kpi-value{font-size:2.08rem;line-height:1;font-weight:950;margin:.42rem 0 .34rem;color:#fff!important}
.oac-kpi-note{font-size:.73rem;line-height:1.35;color:#e8f1fa!important;font-weight:580}

/* ---------- Jump bar ---------- */
.oac-jumpbar{display:flex;align-items:center;gap:.42rem;flex-wrap:wrap;padding:.56rem .68rem;margin-bottom:1rem;border:1px solid #9bb0c4;border-radius:11px;background:#eaf1f8;box-shadow:0 3px 10px rgba(5,28,50,.08)}
.oac-jumpbar-label{font-size:.72rem;color:#324d67;font-weight:900;padding:0 .2rem}.oac-jumpbar a{text-decoration:none;font-size:.74rem;font-weight:850;padding:.34rem .58rem;border-radius:7px;color:#0b57ad;background:#fff;border:1px solid #c1cfdd}.oac-jumpbar a:hover{background:#0b63ce;color:#fff;border-color:#0b63ce}

/* ---------- Sections ---------- */
.oac-section{overflow:hidden;border-radius:var(--oac-radius);padding:0!important;background:#fff!important;border:1px solid #8fa5ba!important;box-shadow:0 8px 22px rgba(5,28,50,.14)}
.oac-section-head{display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;padding:.78rem 1rem;margin:0;background:linear-gradient(90deg,#0b2239 0%,#123b63 72%,#165274 100%);border-bottom:1px solid #224d70}
.oac-section-title{font-weight:900;line-height:1.25;font-size:.92rem;color:#fff}.oac-section-subtitle{font-size:.73rem;color:#d7e6f3;font-weight:600}.oac-section-subtitle code{color:#bfe0ff}
.oac-section-body{padding:.9rem;background:#f0f4f8}.oac-section .text-muted{color:#5d7084!important}

/* ---------- Department cards ---------- */
.oac-dept-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}
.oac-dept-card{position:relative;overflow:hidden;border:1px solid #b6c6d5;border-radius:11px;padding:0;background:#fff;min-width:0;box-shadow:0 3px 10px rgba(5,28,50,.08)}
.oac-dept-card:before{content:"";display:block;height:5px;background:#1467d8}.oac-dept-card:nth-child(4n+2):before{background:#087f8c}.oac-dept-card:nth-child(4n+3):before{background:#087a4b}.oac-dept-card:nth-child(4n+4):before{background:#a95408}
.oac-dept-card:hover{transform:translateY(-1px);box-shadow:0 7px 18px rgba(5,28,50,.14)}
.oac-dept-top{display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.7rem .75rem .55rem;background:#f8fafc;border-bottom:1px solid #e0e7ef}
.oac-dept-name{font-weight:950;font-size:.88rem;color:#102a43;overflow:hidden;text-overflow:ellipsis}.oac-dept-card .badge.text-bg-light{background:#e7eef5!important;color:#233f5d!important;border-color:#aebfd0!important;font-weight:850;font-size:.67rem}
.oac-dept-stats{display:grid;grid-template-columns:1fr 1fr;gap:.48rem;padding:.65rem .75rem .52rem;margin:0}
.oac-mini-metric{border-radius:8px;background:#eaf3ff;border:1px solid #c6dcf5;padding:.48rem .56rem}.oac-mini-metric:nth-child(2){background:#e9f8f0;border-color:#c4e9d5}.oac-mini-metric .num{display:block;font-weight:950;font-size:1.04rem;line-height:1.05;color:#0e4f99}.oac-mini-metric:nth-child(2) .num{color:#087a4b}.oac-mini-metric .lbl{display:block;color:#4b6279;font-size:.68rem;font-weight:800;margin-top:.14rem}
.oac-dept-last{color:#52667b;font-size:.71rem;font-weight:650;padding:0 .75rem .55rem}.oac-dept-card .btn{margin:0 .75rem .72rem;width:calc(100% - 1.5rem);border-radius:7px;font-weight:850;font-size:.72rem;background:#fff}.oac-dept-card .btn:hover{background:#0b63ce;color:#fff}

/* ---------- Account summary / legend ---------- */
.oac-account-summary{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.72rem}.oac-summary-chip{display:inline-flex;align-items:center;gap:.42rem;padding:.35rem .62rem;border-radius:999px;border:1px solid #b9c8d7;background:#fff;color:#334e68;font-size:.72rem;font-weight:850}.oac-summary-chip strong{font-size:.82rem;color:#102a43}.oac-summary-chip--green{background:#e8f8ef;border-color:#9ed7b8;color:#0b6b43}.oac-summary-chip--blue{background:#eaf3ff;border-color:#a9c9ef;color:#0b57ad}.oac-summary-dot{width:8px;height:8px;border-radius:50%;background:currentColor;box-shadow:0 0 0 3px currentColor22}
.oac-usage-legend{font-size:.7rem;color:#4f6478;line-height:1.45;margin-left:auto}

/* ---------- Account cards ---------- */
.oac-account-list{display:grid;gap:.72rem}.oac-account-row{border:1px solid #aebfd0;border-left:5px solid #94a3b8;border-radius:12px;background:#fff;box-shadow:0 3px 11px rgba(5,28,50,.08);overflow:hidden}.oac-account-row.is-used{border-left-color:#0b8a57}.oac-account-row.is-unused{border-left-color:#c98215}.oac-account-row.is-disabled{border-left-color:#b42318;opacity:.9}
.oac-account-row:hover{box-shadow:0 7px 18px rgba(5,28,50,.14)}
.oac-account-topline{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.62rem .75rem;background:#f7f9fc;border-bottom:1px solid #d7e1eb}.oac-account-identity{display:flex;align-items:center;gap:.65rem;min-width:0}.oac-avatar{width:34px;height:34px;flex:0 0 auto;border-radius:10px;display:grid;place-items:center;background:#173f67;color:#fff;font-size:.77rem;font-weight:950;letter-spacing:.03em;box-shadow:inset 0 0 0 1px #ffffff20}.is-used .oac-avatar{background:#087a4b}.is-disabled .oac-avatar{background:#8d2530}
.oac-account-name{font-weight:950;font-size:.86rem;color:#102a43;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.oac-account-meta{color:#52667b;font-size:.7rem;font-weight:700;margin-top:.12rem}.oac-role-pill{display:inline-flex;margin-left:.35rem;padding:.12rem .4rem;border-radius:999px;background:#e5edf5;border:1px solid #bdccdb;color:#334e68;font-size:.63rem;font-weight:900;vertical-align:middle}
.oac-account-badges{display:flex;align-items:center;justify-content:flex-end;gap:.38rem;flex-wrap:wrap}.oac-state-badge{display:inline-flex;align-items:center;gap:.34rem;padding:.23rem .5rem;border-radius:999px;font-size:.64rem;font-weight:950;letter-spacing:.02em;border:1px solid}.oac-state-badge:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.oac-state-badge--used{background:#e8f8ef;border-color:#8ed0ad;color:#087a4b}.oac-state-badge--unused{background:#fff4df;border-color:#e6c17b;color:#8d5a08}.oac-state-badge--disabled{background:#fff0f0;border-color:#e6a4a4;color:#a61b1b}.oac-state-badge--master{background:#eaf3ff;border-color:#aac8ed;color:#0b57ad}
.oac-account-body{display:grid;grid-template-columns:122px minmax(360px,1fr) 225px 116px;gap:.68rem;padding:.72rem;align-items:stretch}
.oac-account-counts{display:grid;grid-template-columns:1fr 1fr;gap:.42rem;align-content:start}.oac-account-count{border-radius:8px;background:#eaf3ff;border:1px solid #bfd8f3;text-align:center;padding:.52rem .25rem;color:#0b57ad}.oac-account-count:nth-child(2){background:#e9f8f0;border-color:#bfe4d0;color:#087a4b}.oac-account-count strong{display:block;font-size:1rem;line-height:1;font-weight:950;color:inherit}.oac-account-count span{display:block;margin-top:.18rem;font-size:.62rem;color:#50667c;font-weight:850}
.oac-work-card,.oac-audit-card{min-width:0;border-radius:9px;padding:.62rem .68rem}.oac-work-card{background:#edf5ff;border:1px solid #bcd5f0}.oac-audit-card{background:#edf9f2;border:1px solid #b9dfca}.oac-block-label{color:#285780;font-size:.64rem;font-weight:950;text-transform:uppercase;letter-spacing:.055em;margin-bottom:.34rem}.oac-audit-card .oac-block-label{color:#256344}.oac-work-card code,.oac-audit-card code{font-size:.72rem;font-weight:850;color:#0b57ad}.oac-audit-card code{color:#087a4b}.oac-work-desc{color:#425b73;font-size:.72rem;margin-top:.3rem;line-height:1.45;font-weight:560}.oac-work-time{color:#526a80;font-size:.68rem;font-weight:750;margin-top:.3rem}.oac-account-action{display:flex;align-items:center;justify-content:flex-end}.oac-account-action .btn{width:100%;border-radius:8px;font-weight:900;font-size:.7rem;background:#0b63ce;border-color:#0b63ce;box-shadow:0 3px 8px rgba(11,99,206,.2)}.oac-account-action .btn:hover{background:#084fa8;border-color:#084fa8}

/* ---------- Tables ---------- */
.oac-table-wrap{border:1px solid #a9bbcd;border-radius:10px;overflow:auto;max-width:100%;background:#fff}.oac-table{margin-bottom:0!important;font-size:.78rem;color:#203b54}.oac-table thead th{position:sticky;top:0;z-index:2;white-space:nowrap;font-size:.66rem;font-weight:950;text-transform:uppercase;letter-spacing:.05em;vertical-align:middle;padding:.68rem .62rem!important;background:#0b2239!important;color:#fff!important;border-bottom-color:#1b456a!important}.oac-table tbody td{vertical-align:middle;padding:.66rem .62rem!important;line-height:1.42;color:#203b54;border-color:#dce5ee!important}.oac-table tbody tr:nth-child(even)>*{background:#f5f8fb}.oac-table tbody tr:hover>*{background:#e8f2fc!important}.oac-table code{color:#0b57ad;font-weight:850}.oac-table a{font-weight:750}.oac-table code,.oac-table .badge,.oac-table .btn{white-space:nowrap}.oac-table .small{font-size:.75rem!important}.oac-table .text-muted{color:#586d82!important}.oac-table--process{min-width:1080px}.oac-table--timeline{min-width:1280px}.oac-record{min-width:190px}.oac-stage{min-width:320px;max-width:480px}.oac-description{min-width:360px;max-width:560px}.oac-time,.oac-ip{white-space:nowrap}.oac-empty{padding:1.3rem!important;text-align:center}
.oac-readonly-note{display:flex;gap:.55rem;align-items:flex-start;padding:.78rem .9rem;border:1px dashed #8da6bd;border-radius:10px;background:#e8f0f7;color:#3d556c!important;line-height:1.45;font-weight:600}.oac-readonly-note code{color:#0b57ad}

/* Stronger bootstrap badge contrast inside the white cards. */
.oac-section .badge.text-bg-secondary{background:#64748b!important;color:#fff!important}.oac-section .badge.text-bg-primary{background:#1467d8!important;color:#fff!important}.oac-section .badge.text-bg-success{background:#087a4b!important;color:#fff!important}.oac-section .badge.text-bg-danger{background:#b42318!important;color:#fff!important}.oac-section .badge.text-bg-info{background:#087f8c!important;color:#fff!important}

@media(max-width:1399.98px){.oac-account-body{grid-template-columns:112px minmax(300px,1fr) 205px 108px}}
@media(max-width:1199.98px){.oac-span-1,.oac-span-2{grid-column:span 3}.oac-span-3,.oac-span-4,.oac-span-5{grid-column:span 6}.oac-filter-actions{grid-column:span 6}.oac-dept-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.oac-account-body{grid-template-columns:112px minmax(280px,1fr) 110px}.oac-audit-card{display:none}}
@media(max-width:767.98px){.oac-filter-card{padding:.85rem}.oac-filter-titlebar{margin:-.85rem -.85rem .8rem}.oac-filter-help{display:none}.oac-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem}.oac-span-1,.oac-span-2,.oac-span-3,.oac-span-4,.oac-span-5{grid-column:span 1}.oac-field--wide,.oac-filter-actions{grid-column:1/-1}.oac-filter-actions .btn{flex:1}.oac-kpi-card{min-height:98px}.oac-kpi-value{font-size:1.7rem}.oac-dept-grid{grid-template-columns:1fr}.oac-section-head{align-items:flex-start;padding:.75rem .82rem}.oac-section-body{padding:.68rem}.oac-account-topline{align-items:flex-start}.oac-account-identity{align-items:flex-start}.oac-account-badges{max-width:52%}.oac-account-body{grid-template-columns:1fr;gap:.52rem}.oac-account-counts{grid-template-columns:1fr 1fr}.oac-audit-card{display:block}.oac-account-action{justify-content:stretch}.oac-jumpbar{overflow-x:auto;flex-wrap:nowrap}.oac-jumpbar a,.oac-jumpbar-label{white-space:nowrap}.oac-usage-legend{width:100%;margin-left:0}}
</style>

<?php if (!$hasAudit || !$hasUsers): ?>
<div class="alert alert-warning">
  Data sumber belum lengkap. Dibutuhkan <code>system_audit_logs</code> dan <code>master_system_login</code>.
</div>
<?php else: ?>

<div class="oac-hero-note">
  <div class="oac-hero-icon" aria-hidden="true">◎</div>
  <div>
    <div class="oac-hero-title">Owner Activity Control · Monitoring read-only</div>
    <div class="oac-hero-text">
      Pekerjaan terakhir mengabaikan LOGIN/LOGOUT/session agar aktivitas operasional lebih terlihat.
      Audit terakhir tetap menyimpan event sistem terakhir. Tahap dokumen adalah tahap audit terakhir yang tercatat, bukan pengganti status bisnis resmi modul.
    </div>
  </div>
</div>

<div class="rmi-card oac-filter-card mb-3">
  <div class="oac-filter-titlebar">
    <div class="oac-filter-title">Filter aktivitas</div>
    <div class="oac-filter-help">Pilih periode dan scope, lalu klik Terapkan</div>
  </div>
  <form method="get" class="oac-filter-grid">
    <div class="oac-field oac-span-2">
      <label class="form-label small mb-1">Dari</label>
      <input type="date" class="form-control form-control-sm" name="date_from" value="<?= oac_h($filters['date_from']) ?>">
    </div>
    <div class="oac-field oac-span-2">
      <label class="form-label small mb-1">Sampai</label>
      <input type="date" class="form-control form-control-sm" name="date_to" value="<?= oac_h($filters['date_to']) ?>">
    </div>
    <div class="oac-field oac-span-2">
      <label class="form-label small mb-1">Department</label>
      <select class="form-select form-select-sm" name="department">
        <option value="">Semua</option>
        <?php foreach ($departments as $d): ?><option value="<?= oac_h($d) ?>" <?= strtoupper($filters['department']) === strtoupper((string)$d) ? 'selected' : '' ?>><?= oac_h($d) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="oac-field oac-span-2">
      <label class="form-label small mb-1">Office</label>
      <select class="form-select form-select-sm" name="office">
        <option value="">Semua</option>
        <?php foreach ($offices as $o): ?><option value="<?= oac_h($o) ?>" <?= strtoupper($filters['office']) === strtoupper((string)$o) ? 'selected' : '' ?>><?= oac_h($o) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="oac-field oac-field--wide oac-span-4">
      <label class="form-label small mb-1">Akun</label>
      <select class="form-select form-select-sm" name="username">
        <option value="">Semua akun</option>
        <?php foreach ($users as $u):
          $uname = (string)($u['username'] ?? '');
          $label = $uname . (($u['department'] ?? '') !== '' ? ' · ' . $u['department'] : '') . (($u['office_code'] ?? '') !== '' ? ' · ' . $u['office_code'] : '');
        ?>
        <option value="<?= oac_h($uname) ?>" <?= $filters['username'] === $uname ? 'selected' : '' ?>><?= oac_h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="oac-field oac-span-2">
      <label class="form-label small mb-1">Module</label>
      <select class="form-select form-select-sm" name="module">
        <option value="">Semua</option>
        <?php foreach ($modules as $m): ?><option value="<?= oac_h($m) ?>" <?= $filters['module'] === (string)$m ? 'selected' : '' ?>><?= oac_h($m) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="oac-field oac-span-2">
      <label class="form-label small mb-1">Action</label>
      <select class="form-select form-select-sm" name="action">
        <option value="">Semua</option>
        <?php foreach ($actions as $a): ?><option value="<?= oac_h($a) ?>" <?= $filters['action'] === (string)$a ? 'selected' : '' ?>><?= oac_h($a) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="oac-field oac-field--wide oac-span-5">
      <label class="form-label small mb-1">Cari aktivitas / DO / PO / PR</label>
      <input class="form-control form-control-sm" name="q" value="<?= oac_h($filters['q']) ?>" placeholder="record code, deskripsi, module...">
    </div>
    <div class="oac-field oac-span-1">
      <label class="form-label small mb-1">Rows</label>
      <select class="form-select form-select-sm" name="limit">
        <?php foreach ([100,300,500,1000] as $n): ?><option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="oac-filter-actions oac-span-2">
      <button class="btn btn-sm btn-primary">Terapkan</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?= oac_h($base . '/master/owner_activity_control.php') ?>">Reset</a>
    </div>
  </form>
  <div class="small oac-presets">
    <span class="text-muted fw-semibold">Preset:</span>
    <a href="<?= oac_h(oac_url([], ['date_from'=>$today,'date_to'=>$today])) ?>">Hari ini</a>
    <a href="<?= oac_h(oac_url([], ['date_from'=>date('Y-m-d', strtotime('-6 days')),'date_to'=>$today])) ?>">7 hari</a>
    <a href="<?= oac_h(oac_url([], ['date_from'=>date('Y-m-d', strtotime('-29 days')),'date_to'=>$today])) ?>">30 hari</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <?php
  $cards = [
      ['Aktivitas tercatat', $summary['events'], 'event audit sesuai filter'],
      ['Akun aktif di master', $masterActiveAccounts, 'status akun tidak dinonaktifkan'],
      ['Aktif digunakan', $activeUsedAccounts, 'akun aktif + punya aktivitas sesuai filter'],
      ['Dokumen / Record', $summary['records'], 'record_code berbeda dari username'],
  ];
  foreach ($cards as $c): ?>
  <div class="col-6 col-lg-3"><div class="rmi-card p-3 h-100 oac-kpi-card">
    <div class="oac-kpi-label text-muted"><?= oac_h($c[0]) ?></div>
    <div class="oac-kpi-value"><?= oac_h((string)$c[1]) ?></div>
    <div class="oac-kpi-note text-muted"><?= oac_h($c[2]) ?></div>
  </div></div>
  <?php endforeach; ?>
</div>

<div class="oac-jumpbar">
  <span class="oac-jumpbar-label">Navigasi:</span>
  <a href="#oac-departments">1 · Departemen</a>
  <a href="#oac-accounts">2 · Akun & pekerjaan</a>
  <a href="#oac-process">3 · Dokumen / proses</a>
  <a href="#oac-timeline">4 · Timeline detail</a>
</div>

<?php if ($coverage['unmapped'] > 0): ?>
<div class="alert alert-warning py-2 small">
  Ada <strong><?= oac_h((string)$coverage['unmapped']) ?></strong> event audit yang username-nya belum cocok dengan <code>master_system_login</code>.
  Event tetap ditampilkan sebagai <strong>UNMAPPED</strong>; periksa akun lama/username legacy bila perlu.
</div>
<?php endif; ?>

<div class="rmi-card mb-3 oac-section" id="oac-departments">
  <div class="oac-section-head">
    <div>
      <div class="oac-section-title">1. Ringkasan per Departemen</div>
      <div class="oac-section-subtitle mt-1">Pilih departemen untuk melihat akun dan aktivitasnya</div>
    </div>
    <div class="oac-section-subtitle"><?= oac_h((string)count($deptSummary)) ?> departemen</div>
  </div>
  <div class="oac-section-body">
    <?php if (!$deptSummary): ?>
      <div class="text-muted text-center py-3">Tidak ada aktivitas.</div>
    <?php else: ?>
      <div class="oac-dept-grid">
      <?php foreach ($deptSummary as $d): ?>
        <article class="oac-dept-card">
          <div class="oac-dept-top">
            <div class="oac-dept-name"><?= oac_h($d['department'] ?? '') ?></div>
            <span class="badge text-bg-light border"><?= oac_h((string)($d['accounts'] ?? 0)) ?> akun</span>
          </div>
          <div class="oac-dept-stats">
            <div class="oac-mini-metric">
              <span class="num"><?= oac_h((string)($d['events'] ?? 0)) ?></span>
              <span class="lbl">Aktivitas</span>
            </div>
            <div class="oac-mini-metric">
              <span class="num"><?= oac_h((string)($d['accounts'] ?? 0)) ?></span>
              <span class="lbl">Akun</span>
            </div>
          </div>
          <div class="oac-dept-last">Terakhir: <?= oac_h(oac_dt((string)($d['last_at'] ?? ''))) ?></div>
          <a class="btn btn-sm btn-outline-primary" href="<?= oac_h(oac_url($_GET, ['department'=>(string)($d['department'] ?? ''), 'username'=>''])) ?>">Lihat akun departemen</a>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="rmi-card mb-3 oac-section" id="oac-accounts">
  <div class="oac-section-head">
    <div>
      <div class="oac-section-title">2. Semua Akun & Pekerjaan Terakhir</div>
      <div class="oac-section-subtitle mt-1">Status penggunaan berasal dari audit pada periode/filter ini — bukan indikator sedang online.</div>
    </div>
    <div class="oac-section-subtitle"><?= oac_h((string)count($userSummary)) ?> akun</div>
  </div>
  <div class="oac-section-body">
    <div class="oac-account-summary">
      <span class="oac-summary-chip oac-summary-chip--green"><span class="oac-summary-dot"></span>Aktif digunakan <strong><?= oac_h((string)$activeUsedAccounts) ?></strong></span>
      <span class="oac-summary-chip oac-summary-chip--blue"><span class="oac-summary-dot"></span>Aktif di master <strong><?= oac_h((string)$masterActiveAccounts) ?></strong></span>
      <span class="oac-summary-chip">Punya aktivitas sesuai filter <strong><?= oac_h((string)$usedAccounts) ?></strong></span>
      <span class="oac-usage-legend">Hijau = akun aktif dan benar-benar tercatat digunakan pada periode yang dipilih.</span>
    </div>

    <?php if (!$userSummary): ?>
      <div class="text-muted text-center py-3">Tidak ada akun pada filter ini.</div>
    <?php else: ?>
      <div class="oac-account-list">
      <?php foreach ($userSummary as $u):
        $lastModule = (string)($u['last_work_module'] ?? '');
        $lastAction = (string)($u['last_work_action'] ?? '');
        $lastCode = (string)($u['last_work_record_code'] ?? '');
        $lastWorkAt = (string)($u['last_work_at'] ?? '');
        $lastAuditAction = (string)($u['last_audit_action'] ?? '');
        $lastAuditModule = (string)($u['last_audit_module'] ?? '');
        $masterActive = !empty($u['_master_active']);
        $usedPeriod = !empty($u['_used_period']);
        $usageClass = !$masterActive ? 'is-disabled' : ($usedPeriod ? 'is-used' : 'is-unused');
        $username = (string)($u['username'] ?? '');
        $avatarText = strtoupper(substr($username !== '' ? $username : '?', 0, 2));
      ?>
        <article class="oac-account-row <?= oac_h($usageClass) ?>">
          <div class="oac-account-topline">
            <div class="oac-account-identity">
              <div class="oac-avatar" aria-hidden="true"><?= oac_h($avatarText) ?></div>
              <div style="min-width:0">
                <div class="oac-account-name" title="<?= oac_h($username) ?>">
                  <?= oac_h($username) ?>
                  <span class="oac-role-pill"><?= oac_h(($u['role'] ?? '') ?: ($u['level'] ?? '')) ?></span>
                </div>
                <div class="oac-account-meta">
                  <?= oac_h(($u['department'] ?? '') ?: 'UNMAPPED') ?><?= !empty($u['office_code']) ? ' · ' . oac_h($u['office_code']) : '' ?>
                </div>
              </div>
            </div>

            <div class="oac-account-badges">
              <?php if (!$masterActive): ?>
                <span class="oac-state-badge oac-state-badge--disabled">NONAKTIF DI MASTER</span>
              <?php elseif ($usedPeriod): ?>
                <span class="oac-state-badge oac-state-badge--used">AKTIF DIGUNAKAN</span>
              <?php else: ?>
                <span class="oac-state-badge oac-state-badge--unused">AKTIF · BELUM ADA AKTIVITAS</span>
              <?php endif; ?>
              <?php if ($masterActive): ?><span class="oac-state-badge oac-state-badge--master">MASTER ACTIVE</span><?php endif; ?>
            </div>
          </div>

          <div class="oac-account-body">
            <div class="oac-account-counts">
              <div class="oac-account-count"><strong><?= oac_h((string)($u['events'] ?? 0)) ?></strong><span>Aktivitas</span></div>
              <div class="oac-account-count"><strong><?= oac_h((string)($u['records'] ?? 0)) ?></strong><span>Record</span></div>
            </div>

            <div class="oac-work-card">
              <div class="oac-block-label">Pekerjaan terakhir</div>
              <?php if ($lastWorkAt === ''): ?>
                <span class="badge text-bg-secondary">Belum ada pekerjaan tercatat</span>
                <?php if ((int)($u['events'] ?? 0) > 0): ?><div class="oac-work-desc">Ada event sistem/login pada periode ini.</div><?php endif; ?>
              <?php else: ?>
                <div>
                  <?php if ($lastModule !== ''): ?><code><?= oac_h($lastModule) ?></code><?php endif; ?>
                  <?php if ($lastAction !== ''): ?><span class="badge text-bg-<?= oac_h(oac_action_badge($lastAction)) ?> ms-1"><?= oac_h($lastAction) ?></span><?php endif; ?>
                </div>
                <?php if ($lastCode !== '' && $lastCode !== $username): ?>
                  <div class="mt-1"><a href="<?= oac_h(oac_url($_GET, ['record_code'=>$lastCode])) ?>"><code><?= oac_h($lastCode) ?></code></a></div>
                <?php endif; ?>
                <?php if (!empty($u['last_work_description'])): ?><div class="oac-work-desc" title="<?= oac_h($u['last_work_description']) ?>"><?= oac_h(oac_short((string)$u['last_work_description'], 105)) ?></div><?php endif; ?>
                <div class="oac-work-time"><?= oac_h(oac_dt($lastWorkAt)) ?></div>
              <?php endif; ?>
            </div>

            <div class="oac-audit-card">
              <div class="oac-block-label">Audit terakhir</div>
              <?php if ((int)($u['events'] ?? 0) === 0): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <div>
                  <?php if ($lastAuditModule !== ''): ?><code><?= oac_h($lastAuditModule) ?></code><?php endif; ?>
                  <?php if ($lastAuditAction !== ''): ?><span class="badge text-bg-<?= oac_h(oac_action_badge($lastAuditAction)) ?> ms-1"><?= oac_h($lastAuditAction) ?></span><?php endif; ?>
                </div>
                <div class="oac-work-time"><?= oac_h(oac_dt((string)($u['last_audit_at'] ?? $u['last_at'] ?? ''))) ?></div>
              <?php endif; ?>
            </div>

            <div class="oac-account-action">
              <a class="btn btn-sm btn-primary" href="<?= oac_h(oac_url($_GET, ['username'=>$username, 'record_code'=>''])) ?>">History akun</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="rmi-card mb-3 oac-section" id="oac-process">
  <div class="oac-section-head">
    <div class="oac-section-title">3. Dokumen / Proses — Sampai Mana yang Tercatat</div>
    <div class="oac-section-subtitle">Berdasarkan <code>record_code</code> · tidak mengubah status bisnis modul</div>
  </div>
  <div class="oac-section-body">
  <div class="table-responsive oac-table-wrap">
    <table class="<?= oac_h(rmi_ui_table_class()) ?> mb-0 oac-table oac-table--process">
      <thead><tr><th>Record Code</th><th class="text-end">Event</th><th class="text-end">PIC</th><th>Tahap Audit Terakhir</th><th>PIC terakhir</th><th>Terakhir</th><th></th></tr></thead>
      <tbody>
      <?php if (!$processSummary): ?><tr><td colspan="7" class="text-muted oac-empty">Belum ada record_code pada filter ini.</td></tr><?php endif; ?>
      <?php foreach ($processSummary as $p):
        $pm = (string)($p['last_module'] ?? '');
        $pa = (string)($p['last_action'] ?? '');
      ?>
        <tr>
          <td class="oac-record"><code class="fw-semibold"><?= oac_h($p['record_code'] ?? '') ?></code></td>
          <td class="text-end"><?= oac_h((string)($p['events'] ?? 0)) ?></td>
          <td class="text-end"><?= oac_h((string)($p['actors'] ?? 0)) ?></td>
          <td class="small oac-stage"><code><?= oac_h($pm) ?></code> <?php if ($pa !== ''): ?><span class="badge text-bg-<?= oac_h(oac_action_badge($pa)) ?>"><?= oac_h($pa) ?></span><?php endif; ?>
            <?php if (!empty($p['last_description'])): ?><div class="text-muted" title="<?= oac_h($p['last_description']) ?>"><?= oac_h(oac_short((string)$p['last_description'], 90)) ?></div><?php endif; ?>
          </td>
          <td class="small"><?= oac_h($p['last_username'] ?? '') ?><?php if (!empty($p['last_department'])): ?><div class="text-muted"><?= oac_h($p['last_department']) ?><?= !empty($p['last_office']) ? ' · ' . oac_h($p['last_office']) : '' ?></div><?php endif; ?></td>
          <td class="small text-muted oac-time"><?= oac_h(oac_dt((string)($p['last_at'] ?? ''))) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= oac_h(oac_url($_GET, ['record_code'=>(string)($p['record_code'] ?? ''), 'username'=>''])) ?>">Timeline</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </div>
</div>

<div class="rmi-card mb-3 oac-section" id="oac-timeline">
  <div class="oac-section-head">
    <div class="oac-section-title">4. Timeline Aktivitas Detail</div>
    <div class="oac-section-subtitle"><?= oac_h((string)count($timeline)) ?> baris ditampilkan</div>
  </div>
  <div class="oac-section-body">
  <?php if ($filters['username'] !== '' || $filters['record_code'] !== ''): ?>
    <div class="small mb-2">
      <?php if ($filters['username'] !== ''): ?><span class="badge text-bg-primary">Akun: <?= oac_h($filters['username']) ?></span><?php endif; ?>
      <?php if ($filters['record_code'] !== ''): ?><span class="badge text-bg-info">Record: <?= oac_h($filters['record_code']) ?></span><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="table-responsive oac-table-wrap">
    <table class="<?= oac_h(rmi_ui_table_class()) ?> mb-0 oac-table oac-table--timeline">
      <thead><tr><th>Waktu</th><th>Dept</th><th>Akun</th><th>Module</th><th>Action</th><th>Record</th><th>Deskripsi</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$timeline): ?><tr><td colspan="8" class="text-muted oac-empty">Tidak ada event.</td></tr><?php endif; ?>
      <?php foreach ($timeline as $r):
        $rc = (string)($r['record_code'] ?? '');
        $un = (string)($r['username'] ?? '');
        $act = (string)($r['action'] ?? '');
      ?>
        <tr>
          <td class="small text-muted oac-time"><?= oac_h(oac_dt((string)($r['created_at'] ?? ''))) ?></td>
          <td class="small"><?= oac_h(($r['department'] ?? '') ?: 'UNMAPPED') ?><?= !empty($r['office_code']) ? '<div class="text-muted">' . oac_h($r['office_code']) . '</div>' : '' ?></td>
          <td class="small"><a href="<?= oac_h(oac_url($_GET, ['username'=>$un, 'record_code'=>''])) ?>"><?= oac_h($un) ?></a></td>
          <td><code class="small"><?= oac_h($r['module'] ?? '') ?></code></td>
          <td><span class="badge text-bg-<?= oac_h(oac_action_badge($act)) ?>"><?= oac_h($act) ?></span></td>
          <td class="small"><?php if ($rc !== '' && $rc !== $un): ?><a href="<?= oac_h(oac_url($_GET, ['record_code'=>$rc, 'username'=>''])) ?>"><code><?= oac_h($rc) ?></code></a><?php else: ?>—<?php endif; ?></td>
          <td class="small oac-description"><?= oac_h($r['description'] ?? '') ?></td>
          <td class="small text-muted font-monospace oac-ip"><?= oac_h($r['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </div>
</div>

<div class="small text-muted mb-2 oac-readonly-note">
  <span aria-hidden="true">🔒</span>
  <span>Halaman ini hanya membaca <code>system_audit_logs</code> dan profil <code>master_system_login</code>. Tidak ada UPDATE/DELETE/POST transaksi bisnis.</span>
</div>

<?php endif; ?>
<?php rmi_footer(); ?>
