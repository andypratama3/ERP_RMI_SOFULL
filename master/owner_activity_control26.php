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

<?php if (!$hasAudit || !$hasUsers): ?>
<div class="alert alert-warning">
  Data sumber belum lengkap. Dibutuhkan <code>system_audit_logs</code> dan <code>master_system_login</code>.
</div>
<?php else: ?>

<div class="alert alert-info py-2 small">
  <strong>Interpretasi:</strong> halaman ini membaca <code>system_audit_logs</code> secara read-only.
  <strong>Pekerjaan terakhir</strong> mengabaikan event LOGIN/LOGOUT/session agar tidak menutupi aktivitas operasional,
  sedangkan <strong>Audit terakhir</strong> tetap menampilkan event terakhir apa pun.
  Untuk dokumen/record, “sampai mana” berarti <strong>tahap audit terakhir yang tercatat</strong>, bukan pengganti status bisnis resmi modul.
</div>

<div class="rmi-card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-6 col-md-auto">
      <label class="form-label small mb-0">Dari</label>
      <input type="date" class="form-control form-control-sm" name="date_from" value="<?= oac_h($filters['date_from']) ?>">
    </div>
    <div class="col-6 col-md-auto">
      <label class="form-label small mb-0">Sampai</label>
      <input type="date" class="form-control form-control-sm" name="date_to" value="<?= oac_h($filters['date_to']) ?>">
    </div>
    <div class="col-6 col-md-auto">
      <label class="form-label small mb-0">Department</label>
      <select class="form-select form-select-sm" name="department">
        <option value="">Semua</option>
        <?php foreach ($departments as $d): ?><option value="<?= oac_h($d) ?>" <?= strtoupper($filters['department']) === strtoupper((string)$d) ? 'selected' : '' ?>><?= oac_h($d) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-auto">
      <label class="form-label small mb-0">Office</label>
      <select class="form-select form-select-sm" name="office">
        <option value="">Semua</option>
        <?php foreach ($offices as $o): ?><option value="<?= oac_h($o) ?>" <?= strtoupper($filters['office']) === strtoupper((string)$o) ? 'selected' : '' ?>><?= oac_h($o) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label small mb-0">Akun</label>
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
    <div class="col-6 col-md-auto">
      <label class="form-label small mb-0">Module</label>
      <select class="form-select form-select-sm" name="module">
        <option value="">Semua</option>
        <?php foreach ($modules as $m): ?><option value="<?= oac_h($m) ?>" <?= $filters['module'] === (string)$m ? 'selected' : '' ?>><?= oac_h($m) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-auto">
      <label class="form-label small mb-0">Action</label>
      <select class="form-select form-select-sm" name="action">
        <option value="">Semua</option>
        <?php foreach ($actions as $a): ?><option value="<?= oac_h($a) ?>" <?= $filters['action'] === (string)$a ? 'selected' : '' ?>><?= oac_h($a) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label small mb-0">Cari aktivitas / DO / PO / PR</label>
      <input class="form-control form-control-sm" name="q" value="<?= oac_h($filters['q']) ?>" placeholder="record code, deskripsi, module...">
    </div>
    <div class="col-auto">
      <label class="form-label small mb-0">Rows</label>
      <select class="form-select form-select-sm" name="limit">
        <?php foreach ([100,300,500,1000] as $n): ?><option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Terapkan</button></div>
    <div class="col-auto"><a class="btn btn-sm btn-outline-secondary" href="<?= oac_h($base . '/master/owner_activity_control.php') ?>">Reset</a></div>
  </form>
  <div class="small mt-2 d-flex gap-2 flex-wrap">
    <span class="text-muted">Preset:</span>
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
  <div class="col-6 col-lg-3"><div class="rmi-card p-3 h-100">
    <div class="small text-muted"><?= oac_h($c[0]) ?></div>
    <div class="fs-3 fw-bold"><?= oac_h((string)$c[1]) ?></div>
    <div class="small text-muted"><?= oac_h($c[2]) ?></div>
  </div></div>
  <?php endforeach; ?>
</div>

<?php if ($coverage['unmapped'] > 0): ?>
<div class="alert alert-warning py-2 small">
  Ada <strong><?= oac_h((string)$coverage['unmapped']) ?></strong> event audit yang username-nya belum cocok dengan <code>master_system_login</code>.
  Event tetap ditampilkan sebagai <strong>UNMAPPED</strong>; periksa akun lama/username legacy bila perlu.
</div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">1. Ringkasan per Departemen</div>
    <div class="small text-muted">Klik departemen untuk fokus</div>
  </div>
  <div class="table-responsive">
    <table class="<?= oac_h(rmi_ui_table_class()) ?> mb-0">
      <thead><tr><th>Department</th><th class="text-end">Akun</th><th class="text-end">Aktivitas</th><th>Aktivitas terakhir</th><th></th></tr></thead>
      <tbody>
      <?php if (!$deptSummary): ?><tr><td colspan="5" class="text-muted">Tidak ada aktivitas.</td></tr><?php endif; ?>
      <?php foreach ($deptSummary as $d): ?>
        <tr>
          <td class="fw-semibold"><?= oac_h($d['department'] ?? '') ?></td>
          <td class="text-end"><?= oac_h((string)($d['accounts'] ?? 0)) ?></td>
          <td class="text-end"><?= oac_h((string)($d['events'] ?? 0)) ?></td>
          <td class="small text-muted"><?= oac_h(oac_dt((string)($d['last_at'] ?? ''))) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= oac_h(oac_url($_GET, ['department'=>(string)($d['department'] ?? ''), 'username'=>''])) ?>">Lihat akun</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">2. Semua Akun & Pekerjaan Terakhir</div>
    <div class="small text-muted">Semua akun master tetap tampil · login/session tidak dianggap pekerjaan</div>
  </div>
  <div class="table-responsive">
    <table class="<?= oac_h(rmi_ui_table_class()) ?> mb-0">
      <thead>
        <tr><th>Akun</th><th>Dept / Office</th><th>Role</th><th class="text-end">Aktivitas</th><th class="text-end">Record</th><th>Pekerjaan terakhir</th><th>Audit terakhir</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$userSummary): ?><tr><td colspan="8" class="text-muted">Tidak ada akun beraktivitas pada filter ini.</td></tr><?php endif; ?>
      <?php foreach ($userSummary as $u):
        $lastModule = (string)($u['last_work_module'] ?? '');
        $lastAction = (string)($u['last_work_action'] ?? '');
        $lastCode = (string)($u['last_work_record_code'] ?? '');
        $lastWorkAt = (string)($u['last_work_at'] ?? '');
        $lastAuditAction = (string)($u['last_audit_action'] ?? '');
        $lastAuditModule = (string)($u['last_audit_module'] ?? '');
      ?>
        <tr>
          <td><strong><?= oac_h($u['username'] ?? '') ?></strong></td>
          <td class="small"><?= oac_h($u['department'] ?? 'UNMAPPED') ?><?= !empty($u['office_code']) ? ' · ' . oac_h($u['office_code']) : '' ?></td>
          <td class="small"><?= oac_h(($u['role'] ?? '') ?: ($u['level'] ?? '')) ?></td>
          <td class="text-end"><?= oac_h((string)($u['events'] ?? 0)) ?></td>
          <td class="text-end"><?= oac_h((string)($u['records'] ?? 0)) ?></td>
          <td class="small">
            <?php if ($lastWorkAt === ''): ?>
              <span class="badge text-bg-secondary">Belum ada pekerjaan tercatat</span>
              <?php if ((int)($u['events'] ?? 0) > 0): ?><div class="text-muted mt-1">Ada event sistem/login pada periode ini.</div><?php endif; ?>
            <?php else: ?>
              <?php if ($lastModule !== ''): ?><code><?= oac_h($lastModule) ?></code><?php endif; ?>
              <?php if ($lastAction !== ''): ?><span class="badge text-bg-<?= oac_h(oac_action_badge($lastAction)) ?> ms-1"><?= oac_h($lastAction) ?></span><?php endif; ?>
              <?php if ($lastCode !== '' && $lastCode !== ($u['username'] ?? '')): ?><div><a href="<?= oac_h(oac_url($_GET, ['record_code'=>$lastCode])) ?>"><code><?= oac_h($lastCode) ?></code></a></div><?php endif; ?>
              <?php if (!empty($u['last_work_description'])): ?><div class="text-muted mt-1" title="<?= oac_h($u['last_work_description']) ?>"><?= oac_h(oac_short((string)$u['last_work_description'], 80)) ?></div><?php endif; ?>
              <div class="text-muted mt-1"><?= oac_h(oac_dt($lastWorkAt)) ?></div>
            <?php endif; ?>
          </td>
          <td class="small" style="white-space:nowrap">
            <?php if ((int)($u['events'] ?? 0) === 0): ?>
              <span class="text-muted">—</span>
            <?php else: ?>
              <?php if ($lastAuditModule !== ''): ?><code><?= oac_h($lastAuditModule) ?></code><?php endif; ?>
              <?php if ($lastAuditAction !== ''): ?><span class="badge text-bg-<?= oac_h(oac_action_badge($lastAuditAction)) ?> ms-1"><?= oac_h($lastAuditAction) ?></span><?php endif; ?>
              <div class="text-muted mt-1"><?= oac_h(oac_dt((string)($u['last_audit_at'] ?? $u['last_at'] ?? ''))) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-end"><a class="btn btn-sm btn-primary" href="<?= oac_h(oac_url($_GET, ['username'=>(string)($u['username'] ?? ''), 'record_code'=>''])) ?>">History akun</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">3. Dokumen / Proses — Sampai Mana yang Tercatat</div>
    <div class="small text-muted">Berdasarkan <code>record_code</code> · tidak mengubah status bisnis modul</div>
  </div>
  <div class="table-responsive">
    <table class="<?= oac_h(rmi_ui_table_class()) ?> mb-0">
      <thead><tr><th>Record Code</th><th class="text-end">Event</th><th class="text-end">PIC</th><th>Tahap Audit Terakhir</th><th>PIC terakhir</th><th>Terakhir</th><th></th></tr></thead>
      <tbody>
      <?php if (!$processSummary): ?><tr><td colspan="7" class="text-muted">Belum ada record_code pada filter ini.</td></tr><?php endif; ?>
      <?php foreach ($processSummary as $p):
        $pm = (string)($p['last_module'] ?? '');
        $pa = (string)($p['last_action'] ?? '');
      ?>
        <tr>
          <td><code class="fw-semibold"><?= oac_h($p['record_code'] ?? '') ?></code></td>
          <td class="text-end"><?= oac_h((string)($p['events'] ?? 0)) ?></td>
          <td class="text-end"><?= oac_h((string)($p['actors'] ?? 0)) ?></td>
          <td class="small"><code><?= oac_h($pm) ?></code> <?php if ($pa !== ''): ?><span class="badge text-bg-<?= oac_h(oac_action_badge($pa)) ?>"><?= oac_h($pa) ?></span><?php endif; ?>
            <?php if (!empty($p['last_description'])): ?><div class="text-muted" title="<?= oac_h($p['last_description']) ?>"><?= oac_h(oac_short((string)$p['last_description'], 90)) ?></div><?php endif; ?>
          </td>
          <td class="small"><?= oac_h($p['last_username'] ?? '') ?><?php if (!empty($p['last_department'])): ?><div class="text-muted"><?= oac_h($p['last_department']) ?><?= !empty($p['last_office']) ? ' · ' . oac_h($p['last_office']) : '' ?></div><?php endif; ?></td>
          <td class="small text-muted" style="white-space:nowrap"><?= oac_h(oac_dt((string)($p['last_at'] ?? ''))) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= oac_h(oac_url($_GET, ['record_code'=>(string)($p['record_code'] ?? ''), 'username'=>''])) ?>">Timeline</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">4. Timeline Aktivitas Detail</div>
    <div class="small text-muted"><?= oac_h((string)count($timeline)) ?> baris ditampilkan</div>
  </div>
  <?php if ($filters['username'] !== '' || $filters['record_code'] !== ''): ?>
    <div class="small mb-2">
      <?php if ($filters['username'] !== ''): ?><span class="badge text-bg-primary">Akun: <?= oac_h($filters['username']) ?></span><?php endif; ?>
      <?php if ($filters['record_code'] !== ''): ?><span class="badge text-bg-info">Record: <?= oac_h($filters['record_code']) ?></span><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="table-responsive">
    <table class="<?= oac_h(rmi_ui_table_class()) ?> mb-0">
      <thead><tr><th>Waktu</th><th>Dept</th><th>Akun</th><th>Module</th><th>Action</th><th>Record</th><th>Deskripsi</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$timeline): ?><tr><td colspan="8" class="text-muted">Tidak ada event.</td></tr><?php endif; ?>
      <?php foreach ($timeline as $r):
        $rc = (string)($r['record_code'] ?? '');
        $un = (string)($r['username'] ?? '');
        $act = (string)($r['action'] ?? '');
      ?>
        <tr>
          <td class="small text-muted" style="white-space:nowrap"><?= oac_h(oac_dt((string)($r['created_at'] ?? ''))) ?></td>
          <td class="small"><?= oac_h(($r['department'] ?? '') ?: 'UNMAPPED') ?><?= !empty($r['office_code']) ? '<div class="text-muted">' . oac_h($r['office_code']) . '</div>' : '' ?></td>
          <td class="small"><a href="<?= oac_h(oac_url($_GET, ['username'=>$un, 'record_code'=>''])) ?>"><?= oac_h($un) ?></a></td>
          <td><code class="small"><?= oac_h($r['module'] ?? '') ?></code></td>
          <td><span class="badge text-bg-<?= oac_h(oac_action_badge($act)) ?>"><?= oac_h($act) ?></span></td>
          <td class="small"><?php if ($rc !== '' && $rc !== $un): ?><a href="<?= oac_h(oac_url($_GET, ['record_code'=>$rc, 'username'=>''])) ?>"><code><?= oac_h($rc) ?></code></a><?php else: ?>—<?php endif; ?></td>
          <td class="small"><?= oac_h($r['description'] ?? '') ?></td>
          <td class="small text-muted font-monospace"><?= oac_h($r['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="small text-muted mb-2">
  Halaman ini hanya membaca <code>system_audit_logs</code> dan profil <code>master_system_login</code>. Tidak ada UPDATE/DELETE/POST transaksi bisnis.
</div>

<?php endif; ?>
<?php rmi_footer(); ?>
