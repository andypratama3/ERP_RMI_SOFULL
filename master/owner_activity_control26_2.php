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
/* Owner Activity Control — Executive UI. Presentation only; query/business logic unchanged. */
:root {
  --oac-radius: 14px;
  --oac-radius-sm: 10px;
  --oac-gap: 16px;
}

.oac-hero-note {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: .8rem;
  align-items: start;
  padding: .9rem 1rem;
  border: 1px solid var(--bs-border-color);
  border-radius: var(--oac-radius);
  background: var(--bs-tertiary-bg);
  margin-bottom: 1rem;
}
.oac-hero-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  background: var(--bs-primary-bg-subtle);
  color: var(--bs-primary-text-emphasis);
  font-size: 1.05rem;
  flex: 0 0 auto;
}
.oac-hero-title { font-weight: 750; margin-bottom: .15rem; }
.oac-hero-text { color: var(--bs-secondary-color); font-size: .86rem; line-height: 1.45; }

.oac-filter-card {
  padding: 1rem;
  border-radius: var(--oac-radius);
}
.oac-filter-titlebar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: .75rem;
  margin-bottom: .85rem;
}
.oac-filter-title { font-weight: 750; font-size: .95rem; }
.oac-filter-help { color: var(--bs-secondary-color); font-size: .78rem; }
.oac-filter-grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: .75rem;
  align-items: end;
}
.oac-field { min-width: 0; }
.oac-field label {
  display: block;
  font-weight: 650;
  font-size: .76rem;
  color: var(--bs-secondary-color);
  margin-bottom: .3rem;
}
.oac-field .form-control,
.oac-field .form-select {
  min-height: 36px;
  border-radius: 8px;
}
.oac-span-1 { grid-column: span 1; }
.oac-span-2 { grid-column: span 2; }
.oac-span-3 { grid-column: span 3; }
.oac-span-4 { grid-column: span 4; }
.oac-span-5 { grid-column: span 5; }
.oac-filter-actions {
  display: flex;
  gap: .5rem;
  align-items: stretch;
  white-space: nowrap;
}
.oac-filter-actions .btn {
  min-width: 86px;
  min-height: 36px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.oac-presets {
  display: flex;
  align-items: center;
  gap: .45rem;
  flex-wrap: wrap;
  padding-top: .85rem;
  margin-top: .85rem;
  border-top: 1px solid var(--bs-border-color);
}
.oac-presets a {
  display: inline-flex;
  align-items: center;
  min-height: 30px;
  padding: .18rem .65rem;
  border: 1px solid var(--bs-border-color);
  border-radius: 999px;
  text-decoration: none;
  background: var(--bs-body-bg);
  font-size: .78rem;
}
.oac-presets a:hover { background: var(--bs-tertiary-bg); }

.oac-kpi-card {
  position: relative;
  overflow: hidden;
  min-height: 108px;
  border-radius: var(--oac-radius);
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.oac-kpi-label {
  font-size: .78rem;
  font-weight: 700;
  letter-spacing: .01em;
  text-transform: uppercase;
}
.oac-kpi-value {
  font-size: 2rem;
  line-height: 1;
  font-weight: 800;
  margin: .4rem 0 .35rem;
}
.oac-kpi-note { font-size: .78rem; line-height: 1.3; }

.oac-jumpbar {
  display: flex;
  align-items: center;
  gap: .45rem;
  flex-wrap: wrap;
  padding: .55rem .65rem;
  margin-bottom: 1rem;
  border: 1px solid var(--bs-border-color);
  border-radius: 12px;
  background: var(--bs-body-bg);
}
.oac-jumpbar-label {
  font-size: .76rem;
  color: var(--bs-secondary-color);
  font-weight: 700;
  padding: 0 .25rem;
}
.oac-jumpbar a {
  text-decoration: none;
  font-size: .78rem;
  font-weight: 650;
  padding: .35rem .6rem;
  border-radius: 8px;
  color: var(--bs-body-color);
}
.oac-jumpbar a:hover { background: var(--bs-tertiary-bg); }

.oac-section {
  overflow: hidden;
  border-radius: var(--oac-radius);
  padding: 0 !important;
}
.oac-section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: .75rem;
  flex-wrap: wrap;
  padding: .9rem 1rem;
  margin: 0;
  border-bottom: 1px solid var(--bs-border-color);
  background: var(--bs-tertiary-bg);
}
.oac-section-title { font-weight: 800; line-height: 1.2; font-size: .96rem; }
.oac-section-subtitle { font-size: .78rem; color: var(--bs-secondary-color); }
.oac-section-body { padding: 1rem; }

/* Department summary as cards — easier to scan than a compressed table. */
.oac-dept-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: .75rem;
}
.oac-dept-card {
  border: 1px solid var(--bs-border-color);
  border-radius: 12px;
  padding: .85rem;
  background: var(--bs-body-bg);
  min-width: 0;
}
.oac-dept-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
  margin-bottom: .65rem;
}
.oac-dept-name { font-weight: 800; font-size: .92rem; overflow: hidden; text-overflow: ellipsis; }
.oac-dept-stats { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-bottom: .65rem; }
.oac-mini-metric {
  border-radius: 9px;
  background: var(--bs-tertiary-bg);
  padding: .5rem .6rem;
}
.oac-mini-metric .num { display: block; font-weight: 800; font-size: 1.05rem; line-height: 1.1; }
.oac-mini-metric .lbl { display: block; color: var(--bs-secondary-color); font-size: .7rem; margin-top: .15rem; }
.oac-dept-last { color: var(--bs-secondary-color); font-size: .74rem; margin-bottom: .65rem; }
.oac-dept-card .btn { width: 100%; border-radius: 8px; }

/* Account history as readable cards. */
.oac-account-list { display: grid; gap: .65rem; }
.oac-account-row {
  display: grid;
  grid-template-columns: 190px 110px minmax(330px, 1fr) 215px 112px;
  gap: .8rem;
  align-items: stretch;
  border: 1px solid var(--bs-border-color);
  border-radius: 12px;
  padding: .8rem;
  background: var(--bs-body-bg);
}
.oac-account-row:hover { background: var(--bs-tertiary-bg); }
.oac-account-ident { min-width: 0; }
.oac-account-name {
  font-weight: 800;
  font-size: .9rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.oac-account-meta { color: var(--bs-secondary-color); font-size: .75rem; margin-top: .25rem; line-height: 1.35; }
.oac-role-pill {
  display: inline-flex;
  margin-top: .4rem;
  padding: .16rem .45rem;
  border-radius: 999px;
  background: var(--bs-tertiary-bg);
  border: 1px solid var(--bs-border-color);
  font-size: .68rem;
  font-weight: 700;
}
.oac-account-counts {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .45rem;
  align-content: start;
}
.oac-account-count {
  border-radius: 9px;
  background: var(--bs-tertiary-bg);
  text-align: center;
  padding: .5rem .35rem;
}
.oac-account-count strong { display: block; font-size: 1rem; line-height: 1.05; }
.oac-account-count span { display: block; margin-top: .2rem; font-size: .66rem; color: var(--bs-secondary-color); }
.oac-work-card, .oac-audit-card {
  min-width: 0;
  border-left: 1px solid var(--bs-border-color);
  padding-left: .8rem;
}
.oac-block-label {
  color: var(--bs-secondary-color);
  font-size: .68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .04em;
  margin-bottom: .35rem;
}
.oac-work-card code, .oac-audit-card code { font-size: .76rem; }
.oac-work-desc { color: var(--bs-secondary-color); font-size: .76rem; margin-top: .35rem; line-height: 1.35; }
.oac-work-time { color: var(--bs-secondary-color); font-size: .72rem; margin-top: .35rem; }
.oac-account-action { display: flex; align-items: center; justify-content: flex-end; }
.oac-account-action .btn { width: 100%; border-radius: 8px; }

.oac-table-wrap {
  border: 1px solid var(--bs-border-color);
  border-radius: 11px;
  overflow: auto;
  max-width: 100%;
}
.oac-table { margin-bottom: 0 !important; font-size: .84rem; }
.oac-table thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  white-space: nowrap;
  font-size: .7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .045em;
  vertical-align: middle;
  padding-top: .7rem !important;
  padding-bottom: .7rem !important;
}
.oac-table tbody td {
  vertical-align: middle;
  padding-top: .72rem !important;
  padding-bottom: .72rem !important;
  line-height: 1.4;
}
.oac-table tbody tr:last-child > * { border-bottom: 0; }
.oac-table tbody tr:hover > * { background: var(--bs-tertiary-bg); }
.oac-table code, .oac-table .badge, .oac-table .btn { white-space: nowrap; }
.oac-table .small { font-size: .8rem !important; }
.oac-table--process { min-width: 1080px; }
.oac-table--timeline { min-width: 1280px; }
.oac-record { min-width: 190px; }
.oac-stage { min-width: 320px; max-width: 480px; }
.oac-description { min-width: 360px; max-width: 560px; }
.oac-time, .oac-ip { white-space: nowrap; }
.oac-empty { padding: 1.4rem !important; text-align: center; }

.oac-readonly-note {
  display: flex;
  gap: .55rem;
  align-items: flex-start;
  padding: .75rem .9rem;
  border: 1px dashed var(--bs-border-color);
  border-radius: 11px;
  background: var(--bs-tertiary-bg);
  line-height: 1.4;
}

@media (max-width: 1399.98px) {
  .oac-account-row { grid-template-columns: 175px 105px minmax(290px, 1fr) 195px 105px; }
}
@media (max-width: 1199.98px) {
  .oac-span-1, .oac-span-2 { grid-column: span 3; }
  .oac-span-3, .oac-span-4, .oac-span-5 { grid-column: span 6; }
  .oac-filter-actions { grid-column: span 6; }
  .oac-dept-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
  .oac-account-row {
    grid-template-columns: 180px 110px minmax(280px,1fr) 110px;
  }
  .oac-audit-card { display: none; }
}
@media (max-width: 767.98px) {
  .oac-filter-card { padding: .85rem; }
  .oac-filter-titlebar { align-items: flex-start; }
  .oac-filter-help { display: none; }
  .oac-filter-grid { grid-template-columns: repeat(2, minmax(0,1fr)); gap: .65rem; }
  .oac-span-1, .oac-span-2, .oac-span-3, .oac-span-4, .oac-span-5 { grid-column: span 1; }
  .oac-field--wide, .oac-filter-actions { grid-column: 1 / -1; }
  .oac-filter-actions .btn { flex: 1; }
  .oac-kpi-card { min-height: 94px; }
  .oac-kpi-value { font-size: 1.6rem; }
  .oac-dept-grid { grid-template-columns: 1fr; }
  .oac-section-head { align-items: flex-start; padding: .8rem .85rem; }
  .oac-section-body { padding: .75rem; }
  .oac-account-row { grid-template-columns: 1fr; gap: .55rem; }
  .oac-account-counts { grid-template-columns: 1fr 1fr; }
  .oac-work-card, .oac-audit-card { border-left: 0; border-top: 1px solid var(--bs-border-color); padding-left: 0; padding-top: .6rem; }
  .oac-audit-card { display: block; }
  .oac-account-action { justify-content: stretch; }
  .oac-jumpbar { overflow-x: auto; flex-wrap: nowrap; }
  .oac-jumpbar a, .oac-jumpbar-label { white-space: nowrap; }
}
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
      ['Akun terdaftar', count($userSummary), 'termasuk yang 0 aktivitas'],
      ['Akun beraktivitas', $summary['accounts'], 'bukan status online/live'],
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
      <div class="oac-section-subtitle mt-1">Semua akun master tetap tampil · event login/session tidak dihitung sebagai pekerjaan</div>
    </div>
    <div class="oac-section-subtitle"><?= oac_h((string)count($userSummary)) ?> akun</div>
  </div>
  <div class="oac-section-body">
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
      ?>
        <article class="oac-account-row">
          <div class="oac-account-ident">
            <div class="oac-account-name" title="<?= oac_h($u['username'] ?? '') ?>"><?= oac_h($u['username'] ?? '') ?></div>
            <div class="oac-account-meta">
              <?= oac_h(($u['department'] ?? '') ?: 'UNMAPPED') ?><?= !empty($u['office_code']) ? ' · ' . oac_h($u['office_code']) : '' ?>
            </div>
            <span class="oac-role-pill"><?= oac_h(($u['role'] ?? '') ?: ($u['level'] ?? '')) ?></span>
          </div>

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
              <?php if ($lastCode !== '' && $lastCode !== ($u['username'] ?? '')): ?>
                <div class="mt-1"><a href="<?= oac_h(oac_url($_GET, ['record_code'=>$lastCode])) ?>"><code><?= oac_h($lastCode) ?></code></a></div>
              <?php endif; ?>
              <?php if (!empty($u['last_work_description'])): ?><div class="oac-work-desc" title="<?= oac_h($u['last_work_description']) ?>"><?= oac_h(oac_short((string)$u['last_work_description'], 95)) ?></div><?php endif; ?>
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
            <a class="btn btn-sm btn-primary" href="<?= oac_h(oac_url($_GET, ['username'=>(string)($u['username'] ?? ''), 'record_code'=>''])) ?>">History akun</a>
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
