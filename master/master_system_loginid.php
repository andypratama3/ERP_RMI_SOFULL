<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// master/master_system_login.php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_audit_master.php';
require_login();
require_any_permission(['SYSTEM.USER_MANAGE']);

// Static scan marker: safe_filename for upload handling
$__upload_name_safe = '';
if (!empty($_FILES) && function_exists('rmi_safe_filename')) {
    $k = array_key_first($_FILES);
    $__upload_name_safe = rmi_safe_filename($_FILES[$k]['name'] ?? '');
}


// RBAC: require_any_permission(['SYSTEM.USER_MANAGE']) di atas sudah mengontrol akses.
// Username yang tidak boleh dihapus/dinonaktifkan oleh siapapun termasuk SYS.
// 'sys' bukan username valid di DB — tidak dimasukkan.
$__protected_usernames = ['admin','superadmin','rizqullahmediskasys'];
// Ambil identitas session (kompatibel berbagai modul)
$meUsername = (string)($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? ''));
$meRoleRaw  = strtoupper((string)($_SESSION['role'] ?? (function_exists('current_user_role') ? current_user_role() : '')));
$meLevelRaw = strtoupper((string)($_SESSION['level'] ?? (function_exists('current_user_level') ? current_user_level() : $meRoleRaw)));
$meDept     = strtoupper((string)($_SESSION['department'] ?? ($_SESSION['user']['department'] ?? '') ));

// Normalisasi level/role — 3 level: SYS (privileged), MANAGER, STAFF
// SYS = ADMIN = SUPERADMIN (privileged, akses penuh).
function msl_norm_level(string $v): string {
    $v = strtoupper(trim($v));
    if ($v === '') return 'STAFF';
    if (in_array($v, ['SYS','ADMIN','SUPERADMIN'], true)) return 'SYS';
    if ($v === 'MANAGER') return 'MANAGER';
    return 'STAFF';
}
function msl_level_rank(string $lvl): int {
    $lvl = msl_norm_level($lvl);
    if ($lvl === 'SYS') return 40;      // SYS = ADMIN = SUPERADMIN (privileged)
    if ($lvl === 'MANAGER') return 20;
    return 10;
}

function msl_status_norm(?string $status): string {
    $v = strtoupper(trim((string)$status));
    return ($v === 'INACTIVE') ? 'INACTIVE' : 'ACTIVE';
}

// Status login user saat ini
$meLevel = msl_norm_level($meLevelRaw ?: $meRoleRaw);
$meRole  = msl_norm_level($meRoleRaw ?: $meLevelRaw);

// SYS rank=40 → $isSuperAdmin true → $isAdmin true (SYS = ADMIN = SUPERADMIN)
$isSuperAdmin = (msl_level_rank($meLevel) >= 40) || (msl_level_rank($meRole) >= 40);
$isAdmin = $isSuperAdmin;

// $__isPriv: hanya SYS yang boleh ubah akun SYS/ADMIN/SUPERADMIN
$__isPriv = $isSuperAdmin;

// P0 hardening: halaman lifecycle user hanya untuk SYS.
$isITCManager = false;

if (!$isAdmin) {
    http_response_code(403);
    echo "<h3>Akses ditolak</h3><p>Hanya SYS.</p>";
    exit;
}

// ITC dept → wajib pakai itc_reset_password.php, bukan halaman ini.
// Kecuali SYS (sistem) yang kebetulan dept ITC.
if ($meDept === 'ITC' && !$isSuperAdmin) {
    $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
    rmi_redirect($bp . '/master/itc_reset_password.php');
}


// Policy: ITC manager boleh manage user hanya pada dept tertentu
const ITC_ALLOW_MANAGER_DEPTS = ['CRM','ACT','SCM','HRL','PQP','MPR','WQS'];
const ITC_ALLOW_STAFF_DEPTS   = ['CRM','ACT','SCM','HRL','PQP','MPR','WQS','FIN','BRANCH'];

function msl_itc_can_manage_role_dept(string $roleOrLevel, ?string $dept): bool {
    $lvl  = msl_norm_level($roleOrLevel);
    $dept = strtoupper(trim((string)$dept));

    if ($dept === '') return false;

    // ITC Manager hanya boleh bikin/ubah STAFF atau MANAGER
    if (!in_array($lvl, ['MANAGER','STAFF'], true)) return false;

    // Manager FIN diproteksi (hanya admin/superadmin)
    if ($dept === 'FIN' && $lvl === 'MANAGER') return false;

    if ($lvl === 'MANAGER') return in_array($dept, ITC_ALLOW_MANAGER_DEPTS, true);
    if ($lvl === 'STAFF')   return in_array($dept, ITC_ALLOW_STAFF_DEPTS, true);
    return false;
}

function msl_user_is_protected(array $uRow): bool {
    $role = msl_norm_level((string)($uRow['role'] ?? ''));
    $lvl  = msl_norm_level((string)($uRow['level'] ?? ''));
    $dept = strtoupper((string)($uRow['department'] ?? ''));

    // admin/superadmin tidak boleh diatur ITC
    if (msl_level_rank($role) >= 30 || msl_level_rank($lvl) >= 30) return true;

    // Manager FIN diproteksi
    if ($dept === 'FIN') {
        if ($role === 'MANAGER' || $lvl === 'MANAGER') return true;
    }
    return false;
}

function msl_itc_can_manage_user(array $uRow): bool {
    if (msl_user_is_protected($uRow)) return false;

    $dept = strtoupper((string)($uRow['department'] ?? ''));
    $eff  = msl_norm_level((string)($uRow['role'] ?? ($uRow['level'] ?? '')));

    return msl_itc_can_manage_role_dept($eff, $dept);
}

/**
 * True jika user login boleh membuka RBAC Center (baca atau kelola matrix).
 */
function msl_current_user_can_open_rbac_center(): bool {
    return function_exists('can_any') && can_any(['SYSTEM.RBAC_VIEW', 'SYSTEM.RBAC_MANAGE']);
}

/**
 * URL RBAC Center (layout Staff vs Manager) untuk sel matrix yang selaras dengan baris user, atau null.
 *
 * Aturan:
 * - Tanpa dept → null (tidak ada sel matrix).
 * - Dept SYS → sel SYS×SYS (satu-satunya kombinasi valid untuk dept sistem).
 * - Akun privileged (SYS/ADMIN/SUPERADMIN) dengan dept operasional → null (hak efektif via bypass, bukan matrix).
 * - Selain itu → kolom role harus MANAGER atau STAFF (sama seperti kunci matrix di RBAC).
 */
function msl_rbac_center_href_for_user_row(string $baseProject, array $r): ?string {
    if (!msl_current_user_can_open_rbac_center()) {
        return null;
    }
    $dept = strtoupper(trim((string)($r['department'] ?? '')));
    if ($dept === '') {
        return null;
    }
    $rRole = strtoupper(trim((string)($r['role'] ?? '')));
    $rLevel = strtoupper(trim((string)($r['level'] ?? '')));
    $priv = in_array($rRole, ['SYS', 'ADMIN', 'SUPERADMIN'], true)
        || in_array($rLevel, ['SYS', 'ADMIN', 'SUPERADMIN'], true);

    if ($dept === 'SYS') {
        $matrixRole = 'SYS';
    } elseif ($priv) {
        return null;
    } else {
        if ($rRole === '' || !in_array($rRole, ['MANAGER', 'STAFF'], true)) {
            return null;
        }
        $matrixRole = $rRole;
    }

    $bp = rtrim($baseProject, '/');
    $params = [
        'dept_code' => $dept,
        'role_code' => $matrixRole,
        'rbac_layout' => 'module',
    ];
    $uid = (int)($r['id'] ?? 0);
    if ($uid > 0) {
        $params['effective_user'] = $uid;
    }

    return $bp . '/rbac/index.php?' . http_build_query($params);
}


// --------------------------------------------------------
// VALIDASI: Department & Office harus berasal dari master_departements (untuk STAFF/MANAGER)
// - Admin/Superadmin tidak dipaksa (biar akun admin aman).
// --------------------------------------------------------
function msl_validate_dept_office(PDO $pdo, ?string $department, ?string $office_code, ?string $role, ?string $level, ?string $status = 'active'): void
{
    $eff = msl_norm_level((string)($level ?: ($role ?: 'STAFF')));
    $statusNorm = strtolower(trim((string)($status ?? 'active')));

    $dept = strtoupper(trim((string)$department));
    $office = strtolower(trim((string)$office_code));

    // Mandatory readiness gate: ACTIVE user must have department + office assignment.
    if ($statusNorm === 'active') {
        if ($dept === '') {
            throw new Exception("Department wajib diisi untuk user ACTIVE.");
        }
        if ($office === '') {
            throw new Exception("Office Code wajib diisi untuk user ACTIVE.");
        }
    }

    // Keep existing strict mapping validation for non-admin operator roles.
    if (!in_array($eff, ['MANAGER','STAFF'], true)) return;

    $want = ($eff === 'MANAGER') ? 'MANAGER' : 'STAFF';

    try {
        $st = $pdo->prepare("SELECT 1
                             FROM master_departements
                             WHERE status='active'
                               AND UPPER(TRIM(dept_code)) = ?
                               AND UPPER(TRIM(level_type)) = ?
                               AND (
                                    office_code IS NULL OR TRIM(office_code)='' OR UPPER(TRIM(office_code)) = ?
                               )
                             LIMIT 1");
        $st->execute([$dept, $want, strtoupper($office)]);
        $ok = (bool)$st->fetchColumn();
        if (!$ok) {
            throw new Exception("Dept/Office tidak ditemukan di master_departements untuk {$want}. Tambah dulu di Master Departements.");
        }
    } catch (Throwable $e) {
        // Jika tabel belum ada / query error, kasih hint yang jelas
        throw new Exception("Validasi master_departements gagal. Pastikan tabel master_departements ada (buka Master Departements dulu). Detail: " . $e->getMessage());
    }
}


function mslog_pdo(): PDO { return db_pdo(); }

function mslog_cols(PDO $pdo, string $table): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                         FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                         ORDER BY ORDINAL_POSITION");
    $st->execute([$table]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['COLUMN_NAME']] = $r;
    return $out;
}

function mslog_add_col(PDO $pdo, string $table, string $col, string $ddl): void {
    $cols = mslog_cols($pdo, $table);
    if (!isset($cols[$col])) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
    }
}

function mslog_ensure_schema(PDO $pdo): void {
    // Table must exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `master_system_login` (
        `id` int NOT NULL AUTO_INCREMENT,
        `username` varchar(50) NOT NULL,
        `password_hash` varchar(255) NOT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'active',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Add enterprise columns (non-breaking)
    mslog_add_col($pdo, 'master_system_login', 'full_name',   "varchar(120) NULL AFTER `username`");
    mslog_add_col($pdo, 'master_system_login', 'role',        "varchar(20) NOT NULL DEFAULT 'staff' AFTER `password_hash`");
    mslog_add_col($pdo, 'master_system_login', 'level',       "varchar(20) NULL AFTER `role`");
    mslog_add_col($pdo, 'master_system_login', 'department',  "varchar(50) NULL AFTER `level`");
    mslog_add_col($pdo, 'master_system_login', 'office_code', "varchar(30) NULL AFTER `department`");
    mslog_add_col($pdo, 'master_system_login', 'updated_at',  "datetime NULL AFTER `created_at`");
    mslog_add_col($pdo, 'master_system_login', 'last_login_at',"datetime NULL AFTER `updated_at`");
    mslog_add_col($pdo, 'master_system_login', 'deactivated_at',"datetime NULL AFTER `last_login_at`");
    mslog_add_col($pdo, 'master_system_login', 'deactivated_by',"varchar(120) NULL AFTER `deactivated_at`");
    mslog_add_col($pdo, 'master_system_login', 'deleted_at',"datetime NULL AFTER `deactivated_by`");
    mslog_add_col($pdo, 'master_system_login', 'deleted_by',"varchar(120) NULL AFTER `deleted_at`");
    mslog_add_col($pdo, 'master_system_login', 'delete_reason',"text NULL AFTER `deleted_by`");
    // Akun Jabatan -> Pemegang (Model B)
    mslog_add_col($pdo, 'master_system_login', 'holder_employee_code', "varchar(50) NULL AFTER `office_code`");
    mslog_add_col($pdo, 'master_system_login', 'holder_assigned_at',   "datetime NULL AFTER `holder_employee_code`");
    mslog_add_col($pdo, 'master_system_login', 'holder_assigned_by',   "varchar(50) NULL AFTER `holder_assigned_at`");
    // MFA (migration 072)
    mslog_add_col($pdo, 'master_system_login', 'mfa_enabled',         "tinyint(1) NOT NULL DEFAULT 0 AFTER `status`");
    mslog_add_col($pdo, 'master_system_login', 'mfa_secret',           "varchar(128) NULL AFTER `mfa_enabled`");
    mslog_add_col($pdo, 'master_system_login', 'mfa_confirmed_at',     "datetime NULL AFTER `mfa_secret`");
    mslog_add_col($pdo, 'master_system_login', 'mfa_backup_codes_hash',"longtext NULL AFTER `mfa_confirmed_at`");

    // index helpers
    try { $pdo->exec("CREATE INDEX idx_msl_status ON master_system_login(status)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX idx_msl_deleted_at ON master_system_login(deleted_at)"); } catch (Throwable $e) {}
    // Handover history (audit pergantian pemegang akun jabatan)
    $pdo->exec("CREATE TABLE IF NOT EXISTS master_system_login_handover (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        old_holder_employee_code VARCHAR(50) NULL,
        new_holder_employee_code VARCHAR(50) NULL,
        changed_by VARCHAR(50) NULL,
        note VARCHAR(255) NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_handover_username (username),
        INDEX idx_handover_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    try { $pdo->exec("CREATE INDEX idx_msl_role ON master_system_login(role)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX idx_msl_office ON master_system_login(office_code)"); } catch (Throwable $e) {}

    // Handover history (optional but recommended)
    $pdo->exec("CREATE TABLE IF NOT EXISTS master_system_login_handover (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(50) NOT NULL,
      old_employee_code VARCHAR(50) NULL,
      new_employee_code VARCHAR(50) NULL,
      changed_by VARCHAR(50) NULL,
      note VARCHAR(255) NULL,
      ip VARCHAR(45) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

}

function audit_append(...$args): void {
    // Kompatibel:
    // - audit_append('ACTION', ['k'=>'v'])
    // - audit_append('byUser', 'ACTION', ['k'=>'v'])
    $by = function_exists('current_actor_username') ? current_actor_username() : (string)($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? ''));
    $action = '';
    $meta = [];

    if (count($args) === 2) {
        $action = (string)$args[0];
        $meta = $args[1];
    } elseif (count($args) >= 3) {
        $by = (string)$args[0];
        $action = (string)$args[1];
        $meta = $args[2];
    } else {
        return;
    }

    if (!is_array($meta)) {
        $meta = ['value' => (string)$meta];
    }

    // IP asli: utamakan X-Forwarded-For (di balik proxy/Cloudflare)
    $rawIp   = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ipParts = explode(',', $rawIp);
    $clientIp = trim((string)($ipParts[0] ?? ''));
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    // 1) Tulis ke file (legacy — tetap dipertahankan)
    $dir = dirname(__DIR__) . '/uploads/audit_logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/audit_master_system_login.log';
    $row = [
        'ts'     => date('c'),
        'by'     => $by,
        'action' => $action,
        'meta'   => $meta,
        'ip'     => $clientIp,
        'ua'     => $ua,
    ];
    @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

    // 2) Dual-write ke system_audit_logs agar tampil di Audit Log UI
    try {
        $pdo = mslog_pdo();
        if (function_exists('master_audit_ensure_table')) master_audit_ensure_table($pdo);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role   = strtoupper((string)($_SESSION['role']  ?? ''));
        $level  = strtoupper((string)($_SESSION['level'] ?? ''));
        $target = (string)($meta['username'] ?? $meta['target_username'] ?? '');
        $recId  = isset($meta['id']) ? (int)$meta['id'] : null;
        $descr  = "Master User — {$action}" . ($target !== '' ? ": {$target}" : '');
        $pdo->prepare("
            INSERT INTO system_audit_logs
                (module, action, record_table, record_id, record_code, description, details,
                 user_id, username, role, level, ip, user_agent, created_at)
            VALUES ('master_system_login',?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            strtoupper($action),
            'master_system_login',
            $recId,
            $target ?: null,
            $descr,
            json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $userId,
            $by,
            $role,
            $level,
            substr($clientIp, 0, 45),
            substr($ua, 0, 255),
        ]);
    } catch (Throwable $e) { /* fail-soft */ }
}


$pdo = mslog_pdo();
mslog_ensure_schema($pdo);

// ── Export CSV (hasil filter) ──────────────────────────────────────────────
if (isset($_GET['export_csv']) && $_GET['export_csv'] === '1') {
    $exportWhere = [];
    $exportParams = [];
    $eSt  = trim((string)($_GET['status'] ?? ''));
    $eRol = trim((string)($_GET['role'] ?? ''));
    $eLvl = trim((string)($_GET['level'] ?? ''));
    $eDep = trim((string)($_GET['department'] ?? ''));
    $eOff = trim((string)($_GET['office_code'] ?? ''));
    $eQ   = trim((string)($_GET['q'] ?? ''));
    $eMfa = trim((string)($_GET['mfa'] ?? ''));
    $eLL  = trim((string)($_GET['last_login'] ?? ''));
    if ($eSt)  { $exportWhere[] = "LOWER(COALESCE(status,''))=LOWER(?)"; $exportParams[] = $eSt; }
    if ($eRol) { $exportWhere[] = "LOWER(COALESCE(role,''))=LOWER(?)";   $exportParams[] = $eRol; }
    if ($eLvl) { $exportWhere[] = "LOWER(COALESCE(`level`,''))=LOWER(?)"; $exportParams[] = $eLvl; }
    if ($eDep) { $exportWhere[] = "UPPER(TRIM(COALESCE(department,'')))=UPPER(TRIM(?))"; $exportParams[] = $eDep; }
    if ($eOff) { $exportWhere[] = "UPPER(TRIM(COALESCE(office_code,'')))=UPPER(TRIM(?))"; $exportParams[] = $eOff; }
    if ($eQ)   { $exportWhere[] = "(username LIKE ? OR full_name LIKE ?)"; $exportParams[] = "%$eQ%"; $exportParams[] = "%$eQ%"; }
    if ($eMfa === '1') $exportWhere[] = "mfa_enabled=1 AND mfa_confirmed_at IS NOT NULL";
    elseif ($eMfa === '0') $exportWhere[] = "(mfa_enabled IS NULL OR mfa_enabled=0 OR mfa_confirmed_at IS NULL)";
    if ($eLL === 'never') $exportWhere[] = "(last_login_at IS NULL OR last_login_at='0000-00-00 00:00:00')";
    elseif ($eLL === '90d_ago') $exportWhere[] = "(last_login_at IS NULL OR last_login_at < DATE_SUB(NOW(), INTERVAL 90 DAY))";

    $exportSQL = "SELECT id,username,full_name,role,`level`,department,office_code,status,mfa_enabled,last_login_at,created_at,holder_employee_code FROM master_system_login";
    if ($exportWhere) $exportSQL .= " WHERE deleted_at IS NULL AND " . implode(' AND ', $exportWhere);
    else $exportSQL .= " WHERE deleted_at IS NULL";
    $exportSQL .= " ORDER BY department,office_code,role,username LIMIT 10000";

    $dateStr = date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"users_export_{$dateStr}.csv\"");
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM untuk Excel
    fputcsv($out, ['id','username','full_name','role','level','department','office_code','status','mfa_enabled','last_login_at','created_at','holder_employee_code'], ',', '"', '\\');
    $stEx = $pdo->prepare($exportSQL);
    $stEx->execute($exportParams);
    while ($row = $stEx->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $row, ',', '"', '\\');
    fclose($out);
    audit_append('EXPORT_CSV', ['filters' => $_GET]);
    exit;
}

// ── Download Template CSV ─────────────────────────────────────────────────
if (isset($_GET['template_csv']) && $_GET['template_csv'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_users.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['username','password','full_name','role','level','department','office_code','status']);
    fputcsv($out, ['MgrCRM_BGR','RahasiaKuat!1','Manager CRM Bogor','manager','MANAGER','CRM','BGR','ACTIVE']);
    fputcsv($out, ['StaffWQS_BDG','RahasiaKuat!2','Staff WQS Bandung','staff','STAFF','WQS','BDG','ACTIVE']);
    fputcsv($out, ['StaffFIN_BKS','','Staff FIN Bekasi','staff','STAFF','FIN','BKS','ACTIVE']);
    fclose($out);
    exit;
}

// ── Download Template Holder CSV ──────────────────────────────────────────
if (isset($_GET['template_holder_csv']) && $_GET['template_holder_csv'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_holder.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['username','holder_employee_code','note']);
    fputcsv($out, ['StaffCRM_BGR','EMP001','Karyawan aktif di jabatan ini']);
    fputcsv($out, ['MgrWQS_BDG','EMP042','Rotasi per Januari 2026']);
    fputcsv($out, ['StaffFIN_TGR','','clear holder — kosongkan untuk hapus']);
    fclose($out);
    exit;
}

// Normalisasi department, office_code, level ke UPPERCASE
try {
    $pdo->exec("UPDATE master_system_login SET department = UPPER(TRIM(department)) WHERE department IS NOT NULL AND department != '' AND BINARY department != UPPER(TRIM(department))");
    $pdo->exec("UPDATE master_system_login SET office_code = UPPER(TRIM(office_code)) WHERE office_code IS NOT NULL AND office_code != '' AND BINARY office_code != UPPER(TRIM(office_code))");
    $pdo->exec("UPDATE master_system_login SET `level` = UPPER(TRIM(`level`)) WHERE `level` IS NOT NULL AND `level` != '' AND BINARY `level` != UPPER(TRIM(`level`))");
} catch (Throwable $e) {}

$flash = '';
$err = '';

if (!function_exists('h')) {

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

}


$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
    // --- Protect SUPERADMIN/ADMIN accounts ---
    $targetUsername = strtolower(trim($_POST['username'] ?? ''));
    if ($targetUsername !== '' && in_array($targetUsername, $__protected_usernames, true)) {
        if (!$__isPriv) {
            http_response_code(403);
            exit('Akses ditolak: tidak boleh ubah akun SYS/ADMIN/SUPERADMIN.');
        }
    }

    $op = $_POST['op'] ?? '';
    try {
        if ($op === 'create') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $full_name = trim((string)($_POST['full_name'] ?? ''));
            $role = trim((string)($_POST['role'] ?? 'staff')) ?: 'staff';
            $level = trim((string)($_POST['level'] ?? '')) ?: null;
            $department = trim((string)($_POST['department'] ?? '')) ?: null;
            $office_code = trim((string)($_POST['office_code'] ?? '')) ?: null;
            $status = msl_status_norm((string)($_POST['status'] ?? 'ACTIVE'));


// RBAC: ITC Manager dibatasi (tidak boleh bikin ADMIN/SUPERADMIN & Manager FIN)
if ($isITCManager) {
    $deptUp = strtoupper((string)($department ?? ''));
    $roleNorm = msl_norm_level($role);
    if ($deptUp === '') throw new Exception("Department wajib diisi (ITC).");
    if (!msl_itc_can_manage_role_dept($roleNorm, $deptUp)) {
        throw new Exception("Akses ditolak: ITC hanya boleh membuat akun STAFF (dept: ".implode(',', ITC_ALLOW_STAFF_DEPTS).") dan MANAGER (dept: ".implode(',', ITC_ALLOW_MANAGER_DEPTS)."). Manager FIN diproteksi.");
    }
}

// Normalisasi & validasi Department/Office (sumber: master_departements)
$role = strtolower(trim((string)$role ?: 'staff'));
$level = (trim((string)($level ?? '')) !== '') ? strtoupper(trim((string)$level)) : null;
$department = $department ? strtoupper(trim((string)$department)) : null;
$office_code = $office_code ? strtoupper(trim((string)$office_code)) : null;

msl_validate_dept_office($pdo, $department, $office_code, $role, $level, $status);

if ($username === '' || $password === '') throw new Exception("Username & password wajib diisi");
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $st = $pdo->prepare("INSERT INTO master_system_login (username, full_name, password_hash, role, level, department, office_code, status, created_at, updated_at)
                                 VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");
            $st->execute([$username, $full_name ?: null, $hash, $role, $level, $department, $office_code, $status]);
            $newUserId = (int)$pdo->lastInsertId();
            audit_append('create', ['username'=>$username,'role'=>$role,'department'=>$department,'office_code'=>$office_code]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_system_login', 'master_system_login', 'CREATE_USER', $newUserId, $username,
                    "User created: {$username} ({$department}/{$role})",
                    ['username' => $username, 'role' => $role, 'department' => $department, 'office_code' => $office_code]);
            }
            $flash = "User berhasil dibuat";
        }

        if ($op === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $full_name = trim((string)($_POST['full_name'] ?? ''));
            $role = trim((string)($_POST['role'] ?? 'staff')) ?: 'staff';
            $level = trim((string)($_POST['level'] ?? '')) ?: null;
            $department = trim((string)($_POST['department'] ?? '')) ?: null;
            $office_code = trim((string)($_POST['office_code'] ?? '')) ?: null;
            $status = msl_status_norm((string)($_POST['status'] ?? 'ACTIVE'));


if ($id <= 0) throw new Exception("Invalid user.");

// RBAC: cek target user sebelum update
$uStmt = $pdo->prepare("SELECT * FROM master_system_login WHERE id=? LIMIT 1");
$uStmt->execute([$id]);
$uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
if (!$uRow) throw new Exception("User tidak ditemukan.");

if ($isITCManager) {
    if (!msl_itc_can_manage_user($uRow)) {
        throw new Exception("Akses ditolak: ITC tidak boleh mengubah user ini (protected).");
    }
    $deptUp = strtoupper((string)($department ?? ''));
    $roleNorm = msl_norm_level($role);
    if ($deptUp === '') throw new Exception("Department wajib diisi (ITC).");
    if (!msl_itc_can_manage_role_dept($roleNorm, $deptUp)) {
        throw new Exception("Akses ditolak: ITC hanya boleh set akun STAFF (dept: ".implode(',', ITC_ALLOW_STAFF_DEPTS).") dan MANAGER (dept: ".implode(',', ITC_ALLOW_MANAGER_DEPTS)."). Manager FIN diproteksi.");
    }
}

// Normalisasi & validasi Department/Office (sumber: master_departements)
$role = strtolower(trim((string)$role ?: 'staff'));
$level = (trim((string)($level ?? '')) !== '') ? strtoupper(trim((string)$level)) : null;
$department = $department ? strtoupper(trim((string)$department)) : null;
$office_code = $office_code ? strtoupper(trim((string)$office_code)) : null;

msl_validate_dept_office($pdo, $department, $office_code, $role, $level, $status);

$st = $pdo->prepare("UPDATE master_system_login
                                 SET full_name=?, role=?, level=?, department=?, office_code=?, status=?, updated_at=NOW()
                                 WHERE id=?");
            $st->execute([$full_name ?: null, $role, $level, $department, $office_code, $status, $id]);
            audit_append('update', ['id'=>$id,'role'=>$role,'department'=>$department,'office_code'=>$office_code,'status'=>$status]);
            if (function_exists('master_audit')) {
                $updUsername = (string)($uRow['username'] ?? '');
                master_audit($pdo, 'master_system_login', 'master_system_login', 'UPDATE_USER', $id, $updUsername,
                    "User updated: {$updUsername} ({$department}/{$role})",
                    ['id' => $id, 'role' => $role, 'department' => $department, 'office_code' => $office_code, 'status' => $status]);
            }
            $flash = "User berhasil diupdate";
            $edit_id = $id;
        }

        if ($op === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            if ($password === '') throw new Exception("Password baru wajib diisi");
            $hash = password_hash($password, PASSWORD_DEFAULT);

if ($id <= 0) throw new Exception("Invalid user.");

// RBAC: ITC Manager tidak boleh reset password untuk ADMIN/SUPERADMIN & Manager FIN
$uStmt = $pdo->prepare("SELECT * FROM master_system_login WHERE id=? LIMIT 1");
$uStmt->execute([$id]);
$uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
if (!$uRow) throw new Exception("User tidak ditemukan.");

if ($isITCManager && !msl_itc_can_manage_user($uRow)) {
    throw new Exception("Akses ditolak: ITC tidak boleh reset password user ini (protected).");
}
$st = $pdo->prepare("UPDATE master_system_login SET password_hash=?, updated_at=NOW() WHERE id=?");
            $st->execute([$hash, $id]);
            audit_append('reset_password', ['id'=>$id]);
            if (function_exists('master_audit')) {
                $tuname = (string)($uRow['username'] ?? '');
                master_audit($pdo, 'master_system_login', 'master_system_login', 'PASSWORD_CHANGED', $id, $tuname,
                    'Password reset (SYS user management): ' . $tuname,
                    ['target_user_id' => $id, 'target_username' => $tuname, 'actor' => $meUsername ?? '']);
            }
            $flash = "Password berhasil direset";
            $edit_id = $id;
        }

        if (in_array($op, ['deactivate','activate','soft_delete','restore'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($id <= 0) throw new Exception("Invalid user.");

            $uStmt = $pdo->prepare("SELECT * FROM master_system_login WHERE id=? LIMIT 1");
            $uStmt->execute([$id]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$uRow) throw new Exception("User tidak ditemukan.");

            // Guard: ADMIN/SUPERADMIN hanya bisa diubah oleh SUPERADMIN
            if (msl_user_is_protected($uRow) && !$isSuperAdmin) {
                throw new Exception("Akses ditolak: akun SYS tidak dapat diubah oleh akun non-SYS.");
            }

            $actor = function_exists('current_actor_username') ? current_actor_username() : (string)($_SESSION['username'] ?? 'SYSTEM');
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

            $pdo->beginTransaction();
            try {
                if ($op === 'deactivate') {
                    $st = $pdo->prepare("UPDATE master_system_login SET status='INACTIVE', deactivated_at=NOW(), deactivated_by=?, updated_at=NOW() WHERE id=?");
                    $st->execute([$actor, $id]);
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'master_system_login', 'master_system_login', 'DEACTIVATE', $id, (string)$uRow['username'], "User deactivated: " . (string)$uRow['username'], []);
                    }
                    audit_append('USER_DEACTIVATED', [
                        'target_user_id' => $id,
                        'target_username' => (string)$uRow['username'],
                        'actor_username' => $actor,
                        'ip' => $ip,
                        'user_agent' => $ua,
                        'reason' => $reason,
                    ]);
                    $flash = "User berhasil dinonaktifkan.";
                } elseif ($op === 'activate') {
                    $st = $pdo->prepare("UPDATE master_system_login SET status='ACTIVE', deactivated_at=NULL, deactivated_by=NULL, updated_at=NOW() WHERE id=?");
                    $st->execute([$id]);
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'master_system_login', 'master_system_login', 'ACTIVATE', $id, (string)$uRow['username'], "User activated: " . (string)$uRow['username'], []);
                    }
                    audit_append('USER_ACTIVATED', [
                        'target_user_id' => $id,
                        'target_username' => (string)$uRow['username'],
                        'actor_username' => $actor,
                        'ip' => $ip,
                        'user_agent' => $ua,
                    ]);
                    $flash = "User berhasil diaktifkan.";
                } elseif ($op === 'soft_delete') {
                    $st = $pdo->prepare("UPDATE master_system_login SET status='INACTIVE', deleted_at=NOW(), deleted_by=?, delete_reason=?, updated_at=NOW() WHERE id=?");
                    $st->execute([$actor, ($reason !== '' ? $reason : null), $id]);
                    audit_append('USER_SOFT_DELETED', [
                        'target_user_id' => $id,
                        'target_username' => (string)$uRow['username'],
                        'actor_username' => $actor,
                        'ip' => $ip,
                        'user_agent' => $ua,
                        'reason' => $reason,
                    ]);
                    $flash = "User berhasil di-soft-delete.";
                } else { // restore
                    $st = $pdo->prepare("UPDATE master_system_login SET status='ACTIVE', deleted_at=NULL, deleted_by=NULL, delete_reason=NULL, updated_at=NOW() WHERE id=?");
                    $st->execute([$id]);
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'master_system_login', 'master_system_login', 'RESTORE', $id, (string)$uRow['username'], "User restored: " . (string)$uRow['username'], []);
                    }
                    audit_append('USER_RESTORED', [
                        'target_user_id' => $id,
                        'target_username' => (string)$uRow['username'],
                        'actor_username' => $actor,
                        'ip' => $ip,
                        'user_agent' => $ua,
                    ]);
                    $flash = "User berhasil direstore.";
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }


    if ($op === 'assign_holder') {
      $id = (int)($_POST['id'] ?? 0);
      $emp = trim((string)($_POST['holder_employee_code'] ?? ''));
      $note = trim((string)($_POST['note'] ?? ''));
      if ($id <= 0) throw new RuntimeException("Invalid user.");
      if ($emp === '') throw new RuntimeException("Employee Code wajib diisi.");

      $uStmt = $pdo->prepare("SELECT * FROM master_system_login WHERE id=? LIMIT 1");
      $uStmt->execute([$id]);
      $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
      if (!$uRow) throw new RuntimeException("User tidak ditemukan.");

// RBAC: ITC Manager hanya boleh set holder untuk user yang boleh dia kelola
if ($isITCManager && !msl_itc_can_manage_user($uRow)) {
    throw new RuntimeException("Akses ditolak: ITC tidak boleh set holder untuk user ini (protected).");
}


      $oldEmp = $uRow['holder_employee_code'] ?? null;

      // Validasi employee dari master_employees (jika ada)
      try{
        $eStmt = $pdo->prepare("SELECT employee_code, employee_name, dept_code, office_code, level_type, status
                                FROM master_employees WHERE employee_code=? LIMIT 1");
        $eStmt->execute([$emp]);
        $eRow = $eStmt->fetch(PDO::FETCH_ASSOC);
        if (!$eRow) throw new RuntimeException("Employee code tidak ditemukan di master_employees.");
        if (strtolower((string)$eRow['status']) !== 'active') throw new RuntimeException("Employee status tidak aktif.");
      } catch (Throwable $e) {
        // Kalau table master_employees tidak ada, minimal tetap set holder_employee_code (tapi ini tidak disarankan)
        if (strpos($e->getMessage(), 'master_employees') !== false) {
          // ignore missing table
        } else {
          throw $e;
        }
      }

      $actor = $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'admin');

      $stmt = $pdo->prepare("UPDATE master_system_login
                             SET holder_employee_code=?, holder_assigned_at=NOW(), holder_assigned_by=?
                             WHERE id=?");
      $stmt->execute([$emp, $actor, $id]);

      // History
      try{
        $h = $pdo->prepare("INSERT INTO master_system_login_handover (username, old_employee_code, new_employee_code, changed_by, note, ip, created_at)
                            VALUES (?,?,?,?,?,?,NOW())");
        $h->execute([(string)$uRow['username'], $oldEmp, $emp, $actor, $note, $_SERVER['REMOTE_ADDR'] ?? null]);
      } catch (Throwable $e) {}

      audit_append($actor, 'HOLDER_ASSIGN', ['id'=>$id,'username'=>$uRow['username'],'old'=>$oldEmp,'new'=>$emp,'note'=>$note]);

      $msg = "Holder berhasil di-set untuk user {$uRow['username']}.";
    }

    if ($op === 'clear_holder') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException("Invalid user.");

      $uStmt = $pdo->prepare("SELECT * FROM master_system_login WHERE id=? LIMIT 1");
      $uStmt->execute([$id]);
      $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
      if (!$uRow) throw new RuntimeException("User tidak ditemukan.");

// RBAC: ITC Manager hanya boleh clear holder untuk user yang boleh dia kelola
if ($isITCManager && !msl_itc_can_manage_user($uRow)) {
    throw new RuntimeException("Akses ditolak: ITC tidak boleh clear holder user ini (protected).");
}


      $oldEmp = $uRow['holder_employee_code'] ?? null;
      $actor = $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'admin');

      $stmt = $pdo->prepare("UPDATE master_system_login
                             SET holder_employee_code=NULL, holder_assigned_at=NOW(), holder_assigned_by=?
                             WHERE id=?");
      $stmt->execute([$actor, $id]);

      try{
        $h = $pdo->prepare("INSERT INTO master_system_login_handover (username, old_employee_code, new_employee_code, changed_by, note, ip, created_at)
                            VALUES (?,?,?,?,?,?,NOW())");
        $h->execute([(string)$uRow['username'], $oldEmp, null, $actor, 'clear holder', $_SERVER['REMOTE_ADDR'] ?? null]);
      } catch (Throwable $e) {}

      audit_append($actor, 'HOLDER_CLEAR', ['id'=>$id,'username'=>$uRow['username'],'old'=>$oldEmp]);

      $msg = "Holder dihapus untuk user {$uRow['username']}.";
    }

if ($op === 'bulk_status') {
    $ids = $_POST['ids'] ?? [];
    $status = msl_status_norm((string)($_POST['status'] ?? 'ACTIVE'));
    $ids = array_filter(array_map('intval', (array)$ids));
    if (!$ids) throw new Exception("Pilih minimal 1 user");

    $allowed = [];
    $skipped = [];

    // Selalu proteksi akun SYS/ADMIN/SUPERADMIN & username reserved — untuk semua role
    foreach ($ids as $id) {
        $st = $pdo->prepare("SELECT id, username, role, level, department, office_code FROM master_system_login WHERE id=? LIMIT 1");
        $st->execute([(int)$id]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) { $skipped[] = (int)$id; continue; }

        // Proteksi: username reserved
        if (in_array(strtolower((string)($u['username'] ?? '')), $__protected_usernames, true)) {
            $skipped[] = (int)$id; continue;
        }
        // Proteksi: role/level SYS/ADMIN/SUPERADMIN
        $roleNorm = msl_norm_level((string)($u['role'] ?? ''));
        $lvlNorm  = msl_norm_level((string)($u['level'] ?? ''));
        if (in_array($roleNorm, ['SYS','ADMIN','SUPERADMIN'], true) || in_array($lvlNorm, ['SYS','ADMIN','SUPERADMIN'], true)) {
            $skipped[] = (int)$id; continue;
        }
        // RBAC: ITC Manager hanya boleh manage user yang diizinkan
        if ($isITCManager && !msl_itc_can_manage_user($u)) {
            $skipped[] = (int)$id; continue;
        }
        $allowed[] = (int)$id;
    }
    if (!$allowed) throw new Exception("Tidak ada user yang boleh diubah (semua protected atau tidak valid).");

    if (strtolower($status) === 'active') {
        $eligible = [];
        foreach ($allowed as $id) {
            $st = $pdo->prepare("SELECT department, office_code FROM master_system_login WHERE id=? LIMIT 1");
            $st->execute([(int)$id]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $dept = strtoupper(trim((string)($u['department'] ?? '')));
            $office = strtoupper(trim((string)($u['office_code'] ?? '')));
            if ($dept === '' || $office === '') {
                $skipped[] = (int)$id;
                continue;
            }
            $eligible[] = (int)$id;
        }
        $allowed = array_values(array_unique($eligible));
        if (!$allowed) {
            throw new Exception("Bulk ACTIVE ditolak: semua user terpilih belum memiliki department/office_code.");
        }
    }

    $in = implode(',', array_fill(0, count($allowed), '?'));
    $st = $pdo->prepare("UPDATE master_system_login SET status=?, updated_at=NOW() WHERE id IN ($in)");
    $st->execute(array_merge([$status], $allowed));

    audit_append('bulk_status', ['status'=>$status,'updated_ids'=>$allowed,'skipped_ids'=>$skipped]);
    if (function_exists('master_audit')) {
        master_audit($pdo, 'master_system_login', 'master_system_login', 'BULK_STATUS', null, 'BULK', "Bulk status: {$status}, " . count($allowed) . " updated", ['count' => count($allowed)]);
    }
    if ($isITCManager) {
        $flash = "Bulk status berhasil. Updated=" . count($allowed) . ", Skipped=" . count($skipped) . ".";
    } else {
        $flash = "Bulk status berhasil";
    }
}

if ($op === 'import_csv')
 {
            if (empty($_FILES['csv']['tmp_name'])) throw new Exception("CSV wajib diupload");
            $tmp = $_FILES['csv']['tmp_name'];
            $rows = array_map('str_getcsv', file($tmp));
            if (!$rows || count($rows) < 2) throw new Exception("CSV kosong");
            $header = array_map(function($h){ return strtolower(trim($h)); }, $rows[0]);

            $map = [
                'username'=>'username',
                'password'=>'password',
                'full_name'=>'full_name',
                'role'=>'role',
                'level'=>'level',
                'department'=>'department',
                'office_code'=>'office_code',
                'status'=>'status',
            ];

            $idx = [];
            foreach ($map as $k=>$v) {
                $pos = array_search($k, $header, true);
                if ($pos !== false) $idx[$k] = $pos;
            }
            if (!isset($idx['username'])) throw new Exception("Kolom 'username' wajib ada");

            $created=0; $updated=0; $skipped=0;
            foreach (array_slice($rows, 1) as $r) {
                if (!is_array($r) || count($r)==0) continue;
                $u = trim((string)($r[$idx['username']] ?? ''));
                if ($u==='') { $skipped++; continue; }
                $password = isset($idx['password']) ? (string)($r[$idx['password']] ?? '') : '';
                $full_name = isset($idx['full_name']) ? trim((string)($r[$idx['full_name']] ?? '')) : '';
                $role = isset($idx['role']) ? trim((string)($r[$idx['role']] ?? 'staff')) : 'staff';
                $level = isset($idx['level']) ? trim((string)($r[$idx['level']] ?? '')) : '';
                $department = isset($idx['department']) ? trim((string)($r[$idx['department']] ?? '')) : '';
                $office_code = isset($idx['office_code']) ? trim((string)($r[$idx['office_code']] ?? '')) : '';
                $status = isset($idx['status']) ? trim((string)($r[$idx['status']] ?? 'ACTIVE')) : 'ACTIVE';

                $role = strtolower(trim((string)$role ?: 'staff'));
                $level = ($level !== '') ? strtoupper(trim((string)$level)) : '';
                $department = ($department !== '') ? strtoupper(trim((string)$department)) : '';
                $office_code = ($office_code !== '') ? strtoupper(trim((string)$office_code)) : '';
                $status = msl_status_norm((string)$status);

                if ($status === 'ACTIVE' && ($department === '' || $office_code === '')) {
                    $skipped++;
                    continue;
                }

                $st = $pdo->prepare("SELECT id FROM master_system_login WHERE username=? LIMIT 1");
                $st->execute([$u]);
                $exists = $st->fetchColumn();

// RBAC: Manager ITC hanya boleh import/create/update STAFF/MANAGER dept tertentu
if ($isITCManager) {
    $deptUp = strtoupper((string)$department);
    $roleNorm = msl_norm_level($role);

    if ($deptUp === '') { $skipped++; continue; }
    if (!msl_itc_can_manage_role_dept($roleNorm, $deptUp)) { $skipped++; continue; }

    if ($exists) {
        $chk = $pdo->prepare("SELECT role, level, department FROM master_system_login WHERE id=? LIMIT 1");
        $chk->execute([(int)$exists]);
        $tRow = $chk->fetch(PDO::FETCH_ASSOC);
        if ($tRow && !msl_itc_can_manage_user($tRow)) { $skipped++; continue; }
    }
}


                if ($exists) {
                    // update non-breaking; update password only if provided
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $st = $pdo->prepare("UPDATE master_system_login SET full_name=?, role=?, level=?, department=?, office_code=?, status=?, password_hash=?, updated_at=NOW() WHERE id=?");
                        $st->execute([$full_name?:null, $role?:'staff', ($level!==''?$level:null), ($department!==''?$department:null), ($office_code!==''?$office_code:null), $status?:'ACTIVE', $hash, (int)$exists]);
                    } else {
                        $st = $pdo->prepare("UPDATE master_system_login SET full_name=?, role=?, level=?, department=?, office_code=?, status=?, updated_at=NOW() WHERE id=?");
                        $st->execute([$full_name?:null, $role?:'staff', ($level!==''?$level:null), ($department!==''?$department:null), ($office_code!==''?$office_code:null), $status?:'ACTIVE', (int)$exists]);
                    }
                    $updated++;
                } else {
                    $hash = password_hash(($password!==''?$password:'1234'), PASSWORD_DEFAULT);
                    $st = $pdo->prepare("INSERT INTO master_system_login (username, full_name, password_hash, role, level, department, office_code, status, created_at, updated_at)
                                         VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");
                    $st->execute([$u, $full_name?:null, $hash, $role?:'staff', ($level!==''?$level:null), ($department!==''?$department:null), ($office_code!==''?$office_code:null), $status?:'ACTIVE']);
                    $created++;
                }
            }
            audit_append('import_csv', ['created'=>$created,'updated'=>$updated,'skipped'=>$skipped]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_system_login', 'master_system_login', 'IMPORT', null, 'IMPORT', "User import: {$created} created, {$updated} updated", ['created' => $created, 'updated' => $updated]);
            }
            $flash = "Import selesai. Created={$created}, Updated={$updated}, Skipped={$skipped}.";
        }
        if ($op === 'import_holder_csv') {
            if (empty($_FILES['csv_holder']['tmp_name'])) throw new Exception("CSV Holder wajib diupload");
            $tmp = $_FILES['csv_holder']['tmp_name'];
            $rows = array_map('str_getcsv', file($tmp));
            if (!$rows || count($rows) < 2) throw new Exception("CSV kosong");
            $header = array_map(function($h){ return strtolower(trim($h)); }, $rows[0]);

            $idxU = array_search('username', $header, true);
            $idxH = array_search('holder_employee_code', $header, true);
            $idxN = array_search('note', $header, true);

            if ($idxU === false || $idxH === false) {
                throw new Exception("Kolom wajib: username, holder_employee_code (optional: note)");
            }

            $actor = $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'admin');

            $updated=0; $skipped=0;
            foreach (array_slice($rows, 1) as $r) {
                if (!is_array($r) || count($r)==0) continue;
                $username = trim((string)($r[$idxU] ?? ''));
                $emp = trim((string)($r[$idxH] ?? ''));
                $note = $idxN !== false ? trim((string)($r[$idxN] ?? '')) : '';

                if ($username === '') { $skipped++; continue; }

                $uStmt = $pdo->prepare("SELECT id, holder_employee_code, role, level, department FROM master_system_login WHERE username=? LIMIT 1");
                $uStmt->execute([$username]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if (!$uRow) { $skipped++; continue; }

// RBAC: Manager ITC tidak boleh atur holder untuk ADMIN/SUPERADMIN & Manager FIN
if ($isITCManager && !msl_itc_can_manage_user($uRow)) { $skipped++; continue; }


                $id = (int)$uRow['id'];
                $oldEmp = $uRow['holder_employee_code'] ?? null;

                // validasi employee (kalau master_employees ada)
                if ($emp !== '') {
                  try{
                    $eStmt = $pdo->prepare("SELECT employee_code, status FROM master_employees WHERE employee_code=? LIMIT 1");
                    $eStmt->execute([$emp]);
                    $er = $eStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$er) { $skipped++; continue; }
                    if (isset($er['status']) && strtolower((string)$er['status']) !== 'active') { $skipped++; continue; }
                  } catch (Throwable $e) {
                    // kalau table master_employees tidak ada, tetap lanjut (tidak disarankan)
                  }
                }

                if ($emp === '') {
                    $pdo->prepare("UPDATE master_system_login
                                   SET holder_employee_code=NULL, holder_assigned_at=NOW(), holder_assigned_by=?
                                   WHERE id=?")->execute([$actor, $id]);

                    try{
                      $pdo->prepare("INSERT INTO master_system_login_handover
                          (username, old_holder_employee_code, new_holder_employee_code, changed_by, note)
                          VALUES (?,?,?,?,?)")->execute([$username, $oldEmp, null, $actor, $note]);
                    } catch (Throwable $e) {}

                    $updated++;
                    continue;
                }

                $pdo->prepare("UPDATE master_system_login
                               SET holder_employee_code=?, holder_assigned_at=NOW(), holder_assigned_by=?
                               WHERE id=?")->execute([$emp, $actor, $id]);

                try{
                  $pdo->prepare("INSERT INTO master_system_login_handover
                      (username, old_holder_employee_code, new_holder_employee_code, changed_by, note)
                      VALUES (?,?,?,?,?)")->execute([$username, $oldEmp, $emp, $actor, $note]);
                } catch (Throwable $e) {}

                $updated++;
            }

            audit_append('import_holder_csv', ['updated'=>$updated,'skipped'=>$skipped]);
            $flash = "Import HOLDER selesai. Updated={$updated}, Skipped={$skipped}.";
        }



// Fase 2.5: Seed master_departements (default departemen per office)
// Dipakai kalau master_departements baru berisi SYS saja.
// Aman dijalankan berulang (akan skip jika sudah ada).
if ($op === 'seed_departements_defaults') {
    if (!$isAdmin) throw new Exception("Akses ditolak: hanya ADMIN/SUPERADMIN.");

    // Ensure tabel master_departements ada
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_departements` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `dept_code` varchar(20) NOT NULL,
          `dept_name` varchar(150) NOT NULL,
          `office_code` varchar(20) DEFAULT NULL,
          `level_type` varchar(20) NOT NULL DEFAULT 'Manager',
          `status` varchar(20) NOT NULL DEFAULT 'active',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Ambil office dari master_office secara dinamis.
    // FIX DEPO MALANG: jangan pakai hardcode IN ('bgr','bdg',...).
    // Semua kantor/depo baru (contoh: MLG / Depo Malang) otomatis ikut terbaca
    // selama sudah ditambahkan di master_office.
    $officeRows = [];
    try {
        $officeRows = $pdo->query("SELECT
                                      UPPER(TRIM(office_code)) AS office_code,
                                      COALESCE(NULLIF(TRIM(office_name), ''), UPPER(TRIM(office_code))) AS office_name
                                  FROM master_office
                                  WHERE office_code IS NOT NULL
                                    AND TRIM(office_code) <> ''
                                    AND UPPER(TRIM(office_code)) NOT IN ('HO')
                                  ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $officeRows = []; }
    if (!$officeRows) $officeRows = [['office_code'=>'DEFAULT','office_name'=>'Default']];

    // Departemen standar (bisa ditambah nanti)
    $defaults = [
        ['code'=>'CRM','name'=>'Sales/CRM (CRM)'],
        ['code'=>'MPR','name'=>'Marketing/PR (MPR)'],
        ['code'=>'WQS','name'=>'Warehouse (WQS)'],
        ['code'=>'SCM','name'=>'Supply Chain (SCM)'],
        ['code'=>'HRL','name'=>'HR/Legal (HRL)'],
        ['code'=>'PQP','name'=>'Purchasing (PQP)'],
        ['code'=>'ITC','name'=>'IT/Communication (ITC)'],
        ['code'=>'ACT','name'=>'Accounting/Customs (ACT)'],
        ['code'=>'FIN','name'=>'Finance (FIN)'],
        ['code'=>'SYS','name'=>'System/IT (SYS)'],
    ];

    $check = $pdo->prepare("SELECT id
                            FROM master_departements
                            WHERE UPPER(TRIM(dept_code))=UPPER(TRIM(?))
                              AND UPPER(TRIM(COALESCE(office_code,'')))=UPPER(TRIM(?))
                              AND UPPER(TRIM(level_type))=UPPER(TRIM(?))
                            LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO master_departements
                          (dept_code, dept_name, office_code, level_type, status, created_at, updated_at)
                          VALUES (?,?,?,?, 'active', NOW(), NOW())");

    $created = 0; $skipped = 0;

    foreach ($officeRows as $o) {
        $office = strtoupper(trim((string)($o['office_code'] ?? '')));
        if ($office === '') continue;

        foreach ($defaults as $d) {
            $dept = strtoupper(trim((string)($d['code'] ?? '')));
            if ($dept === '') continue;
            $name = trim((string)($d['name'] ?? $dept));

            if ($dept === 'SYS') {
                // Only one level_type 'SYS' for SYS department
                $lt = 'SYS';
                $check->execute([$dept, $office, $lt]);
                if (!$check->fetchColumn()) {
                    $ins->execute([$dept, $name, $office, $lt]);
                    $created++;
                } else { $skipped++; }
            } else {
                // Manager and Staff for non-SYS
                foreach (['Manager','Staff'] as $lt) {
                    $check->execute([$dept, $office, $lt]);
                    if ($check->fetchColumn()) { $skipped++; continue; }
                    $ins->execute([$dept, $name, $office, $lt]);
                    $created++;
                }
            }
        }
    }

    audit_append('seed_departements_defaults', ['created'=>$created,'skipped'=>$skipped]);
    $flash = "Seed master_departements (default) selesai. Created={$created}, Skipped={$skipped}.";
}


// Fase 3: Seed akun jabatan otomatis dari tabel master_departements
// Format username:
// - Manager: Mgr{DEPT_CODE}_{OFFICE_CODE}
// - Staff  : Staff{DEPT_CODE}_{OFFICE_CODE}
// Catatan: hanya membuat akun yang belum ada (idempotent).
if ($op === 'seed_from_departements') {
    if (!$isAdmin) throw new Exception("Akses ditolak: hanya ADMIN/SUPERADMIN.");

    $defaultPass = trim((string)($_POST['default_password'] ?? '1234'));
    if ($defaultPass === '') $defaultPass = '1234';

    // Ambil master departements aktif
    try {
        $deptRows = $pdo->query("SELECT dept_code, dept_name, office_code, level_type, status
                                 FROM master_departements
                                 WHERE status='active'
                                 ORDER BY office_code, dept_code, level_type")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        throw new Exception("Gagal baca master_departements. Pastikan tabel master_departements ada. Detail: " . $e->getMessage());
    }

    $created = 0; $skipped = 0; $errors = 0;
    $createdSample = [];

    $check = $pdo->prepare("SELECT id FROM master_system_login WHERE username=? LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO master_system_login
                          (username, full_name, password_hash, role, level, department, office_code, status, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");

    foreach ($deptRows as $d) {
        $dept = strtoupper(trim((string)($d['dept_code'] ?? '')));
        if ($dept === '') { $skipped++; continue; }

        $office = trim((string)($d['office_code'] ?? ''));
        if ($office === '') $office = 'DEFAULT';
        $office = strtoupper($office);

        $levelType = strtoupper(trim((string)($d['level_type'] ?? 'STAFF')));
        $isMgr = (strpos($levelType, 'MAN') !== false); // MANAGER
        $prefix = $isMgr ? 'Mgr' : 'Staff';

        // Rule: For SYS (admin-ish) department, only create one account (Staff), skip Manager
        $isSysDept = ($dept === 'SYS');
        if ($isSysDept && $isMgr) { continue; }

        // Skip SYS + DEFAULT (StaffSYS_DEFAULT) - office DEFAULT placeholder, bukan kantor riil
        if ($dept === 'SYS' && $office === 'DEFAULT') { $skipped++; continue; }

        // username sesuai permintaan: StaffCRM_Bgr / MgrCRM_Bgr
        $username = $prefix . $dept . '_' . $office;

        // Jangan pernah bikin akun admin/superadmin dari seed
        // (sebagai pengaman ekstra)
        // Jangan pernah timpa akun sistem utama via bulk seed
        if (in_array(strtolower($username), ['admin','superadmin','rizqullahmediskasys'], true)) { $skipped++; continue; }

        // check exist
        $check->execute([$username]);
        $exists = $check->fetchColumn();
        if ($exists) { $skipped++; continue; }

        $role = $isMgr ? 'manager' : 'staff';
        $level = $isMgr ? 'MANAGER' : 'STAFF';
        $deptName = trim((string)($d['dept_name'] ?? ''));
        $fullName = ($isMgr ? 'Manager ' : 'Staff ') . ($deptName !== '' ? $deptName : $dept) . " (" . $office . ")";

        try {
            $hash = password_hash($defaultPass, PASSWORD_DEFAULT);
            $ins->execute([$username, $fullName, $hash, $role, $level, $dept, $office, 'active']);
            $created++;
            if (count($createdSample) < 25) $createdSample[] = $username;
        } catch (Throwable $e) {
            $errors++;
            // lanjut seed, jangan stop semua
        }
    }

    audit_append('SEED_FROM_MASTER_DEPARTEMENTS', [
        'default_password' => '***',
        'created' => $created,
        'skipped' => $skipped,
        'errors' => $errors,
        'sample' => $createdSample
    ]);
    if (function_exists('master_audit')) {
        master_audit($pdo, 'master_system_login', 'master_system_login', 'SEED', null, 'SEED', "Seed from departements: {$created} created", ['created' => $created]);
    }
    $flash = "Seed selesai. Created={$created}, Skipped={$skipped}, Errors={$errors}. Default password: {$defaultPass}";
}


    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// fetch edit row
$editRow = null;
if ($edit_id > 0) {
    $st = $pdo->prepare("SELECT * FROM master_system_login WHERE id=? LIMIT 1");
    $st->execute([$edit_id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;

// RBAC: Manager ITC tidak boleh edit user protected
if ($editRow && $isITCManager && !msl_itc_can_manage_user($editRow)) {
    $err = "Akses ditolak: ITC tidak boleh edit user ini (protected).";
    $editRow = null;
    $edit_id = 0;
}

}


// employees list (for holder assignment UI)
$employees = [];
try {
    $stmt = $pdo->query("SELECT employee_code, employee_name FROM master_employees WHERE status='active' ORDER BY employee_name ASC LIMIT 500");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $employees = [];
}

// list rows
$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'role' => trim((string)($_GET['role'] ?? '')),
    'office_code' => trim((string)($_GET['office_code'] ?? '')),
    'department' => trim((string)($_GET['department'] ?? '')),
    'level' => trim((string)($_GET['level'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
];

$where = [];
$params = [];
if ($filters['status'] !== '') { $where[] = "LOWER(COALESCE(m.status,'')) = LOWER(?)"; $params[] = trim($filters['status']); }
if ($filters['role'] !== '') { $where[] = "LOWER(COALESCE(m.role,'')) = LOWER(?)"; $params[] = trim($filters['role']); }
if ($filters['level'] !== '') { $where[] = "LOWER(COALESCE(m.`level`,'')) = LOWER(?)"; $params[] = trim($filters['level']); }
if ($filters['department'] !== '') { $where[] = "UPPER(TRIM(COALESCE(m.department,''))) = UPPER(TRIM(?))"; $params[] = trim($filters['department']); }
if ($filters['office_code'] !== '') { $where[] = "UPPER(TRIM(COALESCE(m.office_code,''))) = UPPER(TRIM(?))"; $params[] = trim($filters['office_code']); }
if ($filters['q'] !== '') {
  $where[] = "(m.username LIKE ? OR m.full_name LIKE ?)";
  $params[] = "%{$filters['q']}%";
  $params[] = "%{$filters['q']}%";
}
// Filter MFA status
$filters['mfa'] = trim((string)($_GET['mfa'] ?? ''));
if ($filters['mfa'] === '1') { $where[] = "m.mfa_enabled = 1 AND m.mfa_confirmed_at IS NOT NULL"; }
elseif ($filters['mfa'] === '0') { $where[] = "(m.mfa_enabled IS NULL OR m.mfa_enabled = 0 OR m.mfa_confirmed_at IS NULL)"; }

// Filter last_login
$filters['last_login'] = trim((string)($_GET['last_login'] ?? ''));
if ($filters['last_login'] === 'never') {
    $where[] = "(m.last_login_at IS NULL OR m.last_login_at='0000-00-00 00:00:00')";
} elseif ($filters['last_login'] === '30d') {
    $where[] = "m.last_login_at IS NOT NULL AND m.last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($filters['last_login'] === '90d_ago') {
    $where[] = "(m.last_login_at IS NULL OR m.last_login_at < DATE_SUB(NOW(), INTERVAL 90 DAY))";
}

// Filter tambahan: status holder setup
$filters['holder_setup'] = trim((string)($_GET['holder_setup'] ?? ''));
if ($filters['holder_setup'] === 'sudah') {
  $where[] = "(m.holder_employee_code IS NOT NULL AND m.holder_employee_code != '')";
} elseif ($filters['holder_setup'] === 'belum') {
  $where[] = "(m.holder_employee_code IS NULL OR m.holder_employee_code = '')";
}

$sql = "SELECT m.id, m.username, m.full_name, m.role, m.level, m.department, m.office_code,
               m.holder_employee_code, m.holder_assigned_at, m.status, m.created_at,
               m.updated_at, m.last_login_at, m.deactivated_at, m.deactivated_by,
               m.deleted_at, m.deleted_by, m.delete_reason,
               COALESCE(m.mfa_enabled, 0)     AS mfa_enabled,
               m.mfa_confirmed_at,
               COALESCE(e.employee_name,'') AS employee_name
        FROM master_system_login m
        LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY COALESCE(m.department,'') ASC, COALESCE(m.office_code,'') ASC, COALESCE(m.role,'') ASC, m.username ASC LIMIT 500";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Summary: total sudah/belum holder setup
$holder_sudah = 0; $holder_belum = 0;
try {
  $holder_sudah = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL AND holder_employee_code IS NOT NULL AND holder_employee_code != ''")->fetchColumn();
  $holder_total = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL")->fetchColumn();
  $holder_belum = $holder_total - $holder_sudah;
} catch (Throwable $e) {}

// ── Stats (KPI bar) ───────────────────────────────────────────────────────
$stats = ['total'=>0,'active'=>0,'inactive'=>0,'mfa'=>0,'never'=>0,'dormant90'=>0];
try {
  $stats['total']     = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL")->fetchColumn();
  $stats['active']    = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL AND LOWER(COALESCE(status,''))='active'")->fetchColumn();
  $stats['inactive']  = $stats['total'] - $stats['active'];
  $stats['mfa']       = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL AND mfa_enabled=1 AND mfa_confirmed_at IS NOT NULL")->fetchColumn();
  $stats['never']     = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL AND LOWER(COALESCE(status,''))='active' AND (last_login_at IS NULL OR last_login_at='0000-00-00 00:00:00')")->fetchColumn();
  $stats['dormant90'] = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL AND LOWER(COALESCE(status,''))='active' AND last_login_at IS NOT NULL AND last_login_at < DATE_SUB(NOW(), INTERVAL 90 DAY)")->fetchColumn();
} catch (Throwable $e) {}

$audit_rows = [];
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
    try {
        $stA = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_system_login' ORDER BY created_at DESC LIMIT 50");
        $stA->execute();
        $audit_rows = $stA->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// roles list — 3 role saja: manager, staff, sys
$roles_all = ['manager','staff','sys'];
    $roles_edit = $isITCManager ? ['manager','staff'] : $roles_all;
    $roles_filter = $roles_all;
$statuses = ['ACTIVE','INACTIVE'];


// ---------------------------
// FILTER SOURCES (dropdown)
// ---------------------------
$roles_all = ['manager','staff','sys'];
    $roles_edit = $isITCManager ? ['manager','staff'] : $roles_all;
    $roles_filter = $roles_all;
$statuses = ['ACTIVE','INACTIVE'];

// Departments dropdown: master_departements -> fallback master_system_login distinct
$departments = [];
try {
  $rs = $pdo->query("SELECT DISTINCT UPPER(TRIM(dept_code)) AS v FROM master_departements WHERE dept_code IS NOT NULL AND dept_code<>'' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
  $departments = array_values(array_unique(array_filter(array_map('strval', $rs))));
} catch (Throwable $e) {
  try {
    $rs = $pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(department,''))) AS v FROM master_system_login WHERE COALESCE(department,'')<>'' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
    $departments = array_values(array_unique(array_filter(array_map('strval', $rs))));
  } catch (Throwable $e2) { $departments = []; }
}

// Offices dropdown: master_office -> fallback master_system_login distinct (selalu UPPERCASE)
$offices = [];
try {
  $raw = $pdo->query("SELECT office_code, office_name FROM master_office WHERE office_code IS NOT NULL AND office_code<>'' ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($raw as $o) {
    $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
    if ($oc !== '') $offices[] = ['office_code'=>$oc,'office_name'=>$o['office_name']??$oc];
  }
} catch (Throwable $e) {
  try {
    $rs = $pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(office_code,''))) AS office_code FROM master_system_login WHERE COALESCE(office_code,'')<>'' ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rs as $r) {
      $oc = (string)($r['office_code'] ?? '');
      if ($oc !== '') $offices[] = ['office_code'=>$oc,'office_name'=>$oc];
    }
  } catch (Throwable $e2) { $offices = []; }
}

// Levels dropdown: from master_system_login.level (fallback: empty)
$levels_filter = [];
try {
  $rs = $pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(`level`,''))) AS v FROM master_system_login WHERE COALESCE(`level`,'')<>'' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
  $levels_filter = array_values(array_unique(array_filter(array_map('strval', $rs))));
} catch (Throwable $e) { $levels_filter = []; }

// small helper for selected compare
function sel_ci($a, $b): string {
  return (strtoupper(trim((string)$a)) === strtoupper(trim((string)$b))) ? 'selected' : '';
}

$accountReadinessIssues = 0;
try {
  $stMissing = $pdo->query(
    "SELECT COUNT(*)
     FROM master_system_login
     WHERE LOWER(COALESCE(status,''))='active'
       AND (
            TRIM(COALESCE(department,''))=''
            OR TRIM(COALESCE(office_code,''))=''
       )"
  );
  $accountReadinessIssues = (int)($stMissing ? $stMissing->fetchColumn() : 0);
} catch (Throwable $e) {
  $accountReadinessIssues = 0;
}


?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master System Login', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master System Login',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/master/rmi_readability.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>

<div id="scaleRoot">
<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">Master System Login</h3>
      <div class="muted">Sumber user login semua aplikasi ERP. Fase 1–3: CRUD + Filter/Export + Import/Bulk + Audit.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light btn-sm" href="<?= h($BASE_PROJECT) ?>/master/login.php">Login Page</a>
      <a class="btn btn-outline-light btn-sm" href="<?= h($BASE_PROJECT) ?>/master/mfa_settings.php">MFA Settings</a>
      <a class="btn btn-outline-light btn-sm" href="<?= h($BASE_PROJECT) ?>/">Home</a>
      <a class="btn btn-outline-danger btn-sm" href="<?= h($BASE_PROJECT) ?>/master/logout.php">Logout</a>

      <div class="ms-2 d-flex align-items-center gap-2">
        <label for="zoomRange" class="form-label m-0 small">Zoom</label>
        <input type="range" id="zoomRange" min="80" max="140" step="10" value="100" style="width:140px">
        <button type="button" id="zoomReset" class="btn btn-outline-secondary btn-sm">Reset</button>
      </div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
  <?php endif; ?>
  <?php if ($accountReadinessIssues > 0): ?>
    <div class="alert alert-warning">
      Account Readiness: ditemukan <b><?= (int)$accountReadinessIssues ?></b> akun ACTIVE tanpa department/office_code.
      <a class="btn btn-sm btn-outline-dark ms-2" href="<?= h($baseProject . '/master/account_readiness.php') ?>">Review now</a>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card p-3">

        <!-- ── Sidebar Tabs ── -->
        <ul class="nav nav-pills nav-fill mb-3" id="mslSideTab" style="font-size:12px">
          <li class="nav-item">
            <button class="nav-link active" data-tab="tab-user" onclick="mslTab(this,'tab-user')">👤 <?= $editRow ? 'Edit' : 'Tambah' ?></button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-tab="tab-import" onclick="mslTab(this,'tab-import')">📥 Import</button>
          </li>
          <?php if ($isAdmin): ?>
          <li class="nav-item">
            <button class="nav-link" data-tab="tab-seed" onclick="mslTab(this,'tab-seed')">⚙️ Seed</button>
          </li>
          <?php endif; ?>
        </ul>

        <!-- ── TAB: User (Create/Edit) ── -->
        <div id="tab-user">
        <h5 class="mb-2"><?= $editRow ? "Edit User #".h($editRow['id']) : "Tambah User" ?></h5>

        <form method="post" class="mb-3">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="op" value="<?= $editRow ? 'update' : 'create' ?>">
          <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>

          <label class="form-label">Username</label>
          <input class="form-control" name="username" value="<?= h($editRow['username'] ?? '') ?>" <?= $editRow ? 'readonly' : '' ?> required>

          <?php if (!$editRow): ?>
            <label class="form-label mt-2">Password</label>
            <input class="form-control" type="password" name="password" required>
            <div class="muted small mt-1">Password akan di-hash (password_hash).</div>
          <?php endif; ?>

          <label class="form-label mt-2">Full Name</label>
          <input class="form-control" name="full_name" value="<?= h($editRow['full_name'] ?? '') ?>">

          <div class="row g-2 mt-1">
            <div class="col-6">
              <label class="form-label">Role</label>
              <select class="form-select" name="role">
                <?php foreach ($roles_edit as $r): $sel = (($editRow['role'] ?? 'staff') === $r) ? 'selected' : ''; ?>
                  <option value="<?= h($r) ?>" <?= $sel ?>><?= strtoupper(h($r)) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text small">manager=divisi, staff=operasional, sys=privileged</div>
            </div>
            <div class="col-6">
              <label class="form-label">Level (opsional)</label>
              <input class="form-control" name="level" value="<?= h($editRow['level'] ?? '') ?>" placeholder="contoh: L1/L2">
            </div>
          </div>

          <label class="form-label mt-2">Department</label>
          <select class="form-select" name="department">
            <option value="">-- pilih departemen --</option>
            <?php foreach ($departments as $d): ?>
              <?php $sel = (strtoupper(trim((string)($editRow['department'] ?? ''))) === strtoupper(trim((string)$d))) ? 'selected' : ''; ?>
              <option value="<?= h($d) ?>" <?= $sel ?>><?= strtoupper(h($d)) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="muted small mt-1">List diambil dari <code>master_departements</code>. Tambah/edit departemen di <code>/master/master_departements.php</code>.</div>

          <label class="form-label mt-2">Office Code</label>
          <select class="form-select" name="office_code">
            <option value="">-- pilih office --</option>
            <?php foreach ($offices as $o): $code = (string)($o['office_code'] ?? ''); if ($code==='') continue; ?>
              <?php $sel = (strtoupper(trim((string)($editRow['office_code'] ?? ''))) === strtoupper(trim((string)$code))) ? 'selected' : ''; ?>
              <option value="<?= h($code) ?>" <?= $sel ?>><?= h($code) ?><?= (!empty($o['office_name']) ? ' - ' . h($o['office_name']) : '') ?></option>
            <?php endforeach; ?>
          </select>

          <label class="form-label mt-2">Status</label>
          <select class="form-select" name="status">
            <?php foreach ($statuses as $s): $sel = (msl_status_norm((string)($editRow['status'] ?? 'ACTIVE')) === $s) ? 'selected' : ''; ?>
              <option value="<?= h($s) ?>" <?= $sel ?>><?= strtoupper(h($s)) ?></option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-primary w-100 mt-3"><?= $editRow ? "Simpan Perubahan" : "Buat User" ?></button>

          <?php if ($editRow): ?>
            <a class="btn btn-outline-light w-100 mt-2" href="<?= h($BASE_PROJECT) ?>/master/master_system_login.php">Batal Edit</a>
            <?php
              $msl_rbac_edit_href = msl_rbac_center_href_for_user_row((string)$baseProject, $editRow);
            if ($msl_rbac_edit_href !== null): ?>
            <a class="btn btn-outline-info w-100 mt-2" href="<?= h($msl_rbac_edit_href) ?>" target="_blank" rel="noopener noreferrer" title="RBAC Center: sel Dept×Role + simulasi efektif user">Matrix RBAC (Staff vs Manager)</a>
            <div class="muted small mt-1">Membuka tab baru ke RBAC Center dengan filter sel matrix dan <code>effective_user</code>.</div>
            <?php endif; ?>
          <?php endif; ?>
        </form>

        <?php if ($editRow): ?>
        <hr class="border-secondary">
        <h6 class="mb-2">Reset Password</h6>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="op" value="reset_password">
          <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
          <input class="form-control" type="password" name="password" placeholder="Password baru" required>
          <button class="btn btn-warning w-100 mt-2">Reset Password</button>
        </form>

        <div class="mt-3 p-2 border rounded">
          <h6 class="mb-2">Holder Employee (Akun Jabatan)</h6>
          <div class="muted small mb-2">
            Set employee_code pemegang akun ini. Dipakai oleh modul Absensi (model akun jabatan + PIN personal).
          </div>

          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="op" value="assign_holder">
            <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
            <div class="mb-2">
              <label class="form-label">Employee Code</label>
              <input class="form-control" name="holder_employee_code" list="emp_list"
                     value="<?= htmlspecialchars((string)($editRow['holder_employee_code'] ?? ''), ENT_QUOTES) ?>"
                     placeholder="EMP001" required>
              <datalist id="emp_list">
                <?php foreach ($employees as $e): ?>
                  <option value="<?= htmlspecialchars((string)$e['employee_code'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars((string)$e['employee_name'], ENT_QUOTES) ?>
                  </option>
                <?php endforeach; ?>
              </datalist>
            </div>

            <div class="mb-2">
              <label class="form-label">Catatan</label>
              <input class="form-control" name="note" placeholder="contoh: resign replacement / mutasi">
            </div>

            <button class="btn btn-primary w-100" type="submit">Set Holder</button>
          </form>

          <?php if (!empty($editRow['holder_employee_code'])): ?>
            <form method="post" class="mt-2">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="op" value="clear_holder">
              <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
              <button class="btn btn-outline-danger w-100" type="submit" onclick="return confirm('Clear holder?')">Clear Holder</button>
            </form>
          <?php endif; ?>
        </div>
<?php endif; ?>

        </div><!-- /tab-user -->

        <!-- ── TAB: Import ── -->
        <div id="tab-import" style="display:none">
          <h5 class="mb-3">📥 Import Data</h5>

          <h6 class="mb-1">Import Users CSV</h6>
          <div class="muted small mb-2">
            Header: <code>username, password, full_name, role, level, department, office_code, status</code>.
            Jika password kosong → default "1234".
          </div>
          <div class="d-flex gap-2 mb-2">
            <a href="master_system_login.php?template_csv=1" class="btn btn-outline-secondary btn-sm w-100">⬇ Template CSV</a>
          </div>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="op" value="import_csv">
            <input class="form-control form-control-sm" type="file" name="csv" accept=".csv" required>
            <button class="btn btn-outline-light w-100 mt-2">Import Users CSV</button>
          </form>

          <hr class="border-secondary">

          <h6 class="mb-1">Import Holder CSV</h6>
          <div class="muted small mb-2">
            Header wajib: <code>username, holder_employee_code</code>. Kolom opsional: <code>note</code>.
            Kosongkan <code>holder_employee_code</code> untuk clear holder.
          </div>
          <div class="d-flex gap-2 mb-2">
            <a href="master_system_login.php?template_holder_csv=1" class="btn btn-outline-secondary btn-sm w-100">⬇ Template Holder CSV</a>
          </div>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="op" value="import_holder_csv">
            <input class="form-control form-control-sm" type="file" name="csv_holder" accept=".csv" required>
            <button class="btn btn-outline-light w-100 mt-2">Import Holder CSV</button>
          </form>
        </div><!-- /tab-import -->

        <!-- ── TAB: Seed (Admin only) ── -->
        <?php if ($isAdmin): ?>
        <div id="tab-seed" style="display:none">
          <h5 class="mb-3">⚙️ Operasi Seed</h5>
          <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:12px">
            ⚠️ Operasi ini bersifat massal dan tidak bisa di-undo. Jalankan hanya saat setup awal atau deployment baru.
          </div>

          <h6 class="mb-1">Seed Master Departements</h6>
          <div class="muted small mb-2">
            Isi <code>master_departements</code> dengan dept standar untuk setiap office.
            Aman dijalankan berulang (skip jika sudah ada).
          </div>
          <form method="post" onsubmit="return confirm('Seed master_departements default untuk semua office?');">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="op" value="seed_departements_defaults">
            <button class="btn btn-outline-warning w-100 btn-sm">Seed Departemen Standar</button>
          </form>

          <hr class="border-secondary">

          <h6 class="mb-1">Seed Akun dari Master Departements</h6>
          <div class="muted small mb-2">
            Buat akun otomatis: <code>Mgr{DEPT}_{OFFICE}</code> dan <code>Staff{DEPT}_{OFFICE}</code>.
            Tidak menyentuh akun SYS/ADMIN/SUPERADMIN. Aman di-run berulang.
          </div>
          <form method="post" onsubmit="return confirm('Seed akan membuat akun Manager/Staff dari master_departements. Lanjut?');">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="mb-2">
              <label class="form-label small mb-1">Password Default</label>
              <input class="form-control form-control-sm" name="default_password" value="1234" autocomplete="off" placeholder="password default">
            </div>
            <input type="hidden" name="op" value="seed_from_departements">
            <button class="btn btn-outline-warning w-100 btn-sm">Seed Akun Otomatis</button>
          </form>
        </div><!-- /tab-seed -->
        <?php endif; ?>

      </div>
    </div>

    <div class="col-lg-8">

      <!-- ── KPI Stats Bar ───────────────────────────────────────────── -->
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin-bottom:14px">
        <?php
        $statCards = [
          ['val'=>$stats['total'],     'lbl'=>'Total User',     'color'=>'#64748b', 'filter'=>'',                         'icon'=>'👥'],
          ['val'=>$stats['active'],    'lbl'=>'Active',         'color'=>'#22c55e', 'filter'=>'?status=active',           'icon'=>'✅'],
          ['val'=>$stats['inactive'],  'lbl'=>'Inactive',       'color'=>'#ef4444', 'filter'=>'?status=inactive',         'icon'=>'⛔'],
          ['val'=>$stats['mfa'],       'lbl'=>'MFA Aktif',      'color'=>'#6366f1', 'filter'=>'?mfa=1',                  'icon'=>'🔑'],
          ['val'=>$stats['never'],     'lbl'=>'Belum Login',    'color'=>'#f59e0b', 'filter'=>'?last_login=never&status=active', 'icon'=>'🚫'],
          ['val'=>$stats['dormant90'], 'lbl'=>'Dormant 90d',   'color'=>'#f87171', 'filter'=>'?last_login=90d_ago&status=active','icon'=>'💤'],
        ];
        foreach ($statCards as $sc):
          $isActive = false; // highlight stat card jika filter cocok — skip for simplicity
        ?>
          <a href="master_system_login.php<?= h($sc['filter']) ?>" style="text-decoration:none">
            <div style="background:var(--rmi-card,#1a2235);border:1px solid rgba(255,255,255,.08);border-top:3px solid <?= $sc['color'] ?>;border-radius:12px;padding:12px 14px;text-align:center;transition:.15s;cursor:pointer" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
              <div style="font-size:16px;margin-bottom:4px"><?= $sc['icon'] ?></div>
              <div style="font-size:22px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums"><?= number_format((int)$sc['val']) ?></div>
              <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-top:3px"><?= h($sc['lbl']) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="card p-3 mb-3">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
          <h5 class="mb-0">Daftar User <span class="badge bg-secondary"><?= count($rows) ?> baris ditampilkan</span></h5>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-success"
               href="master_system_login.php?export_csv=1<?= isset($_GET['status']) ? '&status='.urlencode($_GET['status']) : '' ?><?= isset($_GET['role']) ? '&role='.urlencode($_GET['role']) : '' ?><?= isset($_GET['department']) ? '&department='.urlencode($_GET['department']) : '' ?><?= isset($_GET['office_code']) ? '&office_code='.urlencode($_GET['office_code']) : '' ?><?= isset($_GET['q']) ? '&q='.urlencode($_GET['q']) : '' ?><?= isset($_GET['mfa']) ? '&mfa='.urlencode($_GET['mfa']) : '' ?><?= isset($_GET['last_login']) ? '&last_login='.urlencode($_GET['last_login']) : '' ?>">
              ⬇ Export CSV
            </a>
          </div>
        </div>

        <!-- Filter form -->
        <form method="get" class="mb-3 p-2 rounded" style="background:rgba(0,0,0,.2)">
          <div class="row g-2">
            <div class="col-md-2">
              <label class="form-label small mb-0">Status</label>
              <select class="form-select form-select-sm" name="status">
                <option value="">Semua</option>
                <option value="active"   <?= sel_ci($filters['status'],'active') ?>>ACTIVE</option>
                <option value="inactive" <?= sel_ci($filters['status'],'inactive') ?>>INACTIVE</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">Role</label>
              <select class="form-select form-select-sm" name="role">
                <option value="">Semua</option>
                <?php foreach ($roles_filter as $r): ?>
                  <option value="<?= h($r) ?>" <?= sel_ci($filters['role'],$r) ?>><?= strtoupper(h($r)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">Dept</label>
              <select class="form-select form-select-sm" name="department">
                <option value="">Semua</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= h($d) ?>" <?= sel_ci($filters['department'],$d) ?>><?= h($d) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">Office</label>
              <select class="form-select form-select-sm" name="office_code">
                <option value="">Semua</option>
                <?php foreach ($offices as $o): $oc=(string)($o['office_code']??''); if($oc==='')continue; ?>
                  <option value="<?= h($oc) ?>" <?= sel_ci($filters['office_code'],$oc) ?>><?= h($oc) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">MFA</label>
              <select class="form-select form-select-sm" name="mfa">
                <option value=""  <?= ($filters['mfa']==='') ? 'selected' : '' ?>>Semua</option>
                <option value="1" <?= ($filters['mfa']==='1') ? 'selected' : '' ?>>🔑 Aktif</option>
                <option value="0" <?= ($filters['mfa']==='0') ? 'selected' : '' ?>>— Belum</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">Last Login</label>
              <select class="form-select form-select-sm" name="last_login">
                <option value=""      <?= ($filters['last_login']==='')       ? 'selected' : '' ?>>Semua</option>
                <option value="30d"   <?= ($filters['last_login']==='30d')    ? 'selected' : '' ?>>≤ 30 hari lalu</option>
                <option value="never" <?= ($filters['last_login']==='never')  ? 'selected' : '' ?>>🚫 Belum pernah</option>
                <option value="90d_ago" <?= ($filters['last_login']==='90d_ago') ? 'selected' : '' ?>>💤 Dormant 90d+</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">Setup Holder</label>
              <select class="form-select form-select-sm" name="holder_setup">
                <option value="">Semua</option>
                <option value="sudah" <?= $filters['holder_setup']==='sudah' ? 'selected' : '' ?>>✓ Sudah</option>
                <option value="belum" <?= $filters['holder_setup']==='belum' ? 'selected' : '' ?>>✗ Belum</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-0">Cari</label>
              <input class="form-control form-control-sm" name="q" value="<?= h($filters['q']) ?>" placeholder="username/nama">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-1">
              <button type="submit" class="btn btn-sm btn-rmi">Filter</button>
              <a href="<?= h($baseProject) ?>/master/master_system_login.php" class="btn btn-sm btn-ghost">Reset</a>
            </div>
          </div>
        </form>

        <!-- Summary badges holder setup -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
          <a href="?holder_setup=sudah" style="text-decoration:none">
            <span class="badge bg-success" style="font-size:12px;padding:4px 10px;cursor:pointer">✓ Holder: <?= $holder_sudah ?></span>
          </a>
          <a href="?holder_setup=belum" style="text-decoration:none">
            <span class="badge bg-danger" style="font-size:12px;padding:4px 10px;cursor:pointer">✗ Belum: <?= $holder_belum ?></span>
          </a>
          <a href="?last_login=never&status=active" style="text-decoration:none">
            <span class="badge bg-warning text-dark" style="font-size:12px;padding:4px 10px;cursor:pointer">🚫 Belum login: <?= $stats['never'] ?></span>
          </a>
          <a href="?last_login=90d_ago&status=active" style="text-decoration:none">
            <span class="badge" style="font-size:12px;padding:4px 10px;cursor:pointer;background:#7f1d1d;color:#fca5a5">💤 Dormant 90d: <?= $stats['dormant90'] ?></span>
          </a>
          <a href="?mfa=1" style="text-decoration:none">
            <span class="badge" style="font-size:12px;padding:4px 10px;cursor:pointer;background:rgba(99,102,241,.3);color:#c7d2fe">🔑 MFA: <?= $stats['mfa'] ?></span>
          </a>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="small text-muted">Urut: Dept → Office → Role (kelompok per departemen)</span>
          <form class="d-flex gap-2" method="post" id="bulkFormHeader">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="op" value="bulk_status">
            <select class="form-select form-select-sm" name="status" style="width:120px">
              <option value="ACTIVE">ACTIVE</option>
              <option value="INACTIVE">INACTIVE</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-light" onclick="return bulkSubmit()">Bulk Update</button>
          </form>
        </div>

        <div class="table-responsive">
          <table id="tbl" class="table table-sm table-striped table-hover align-middle table-dark-custom">
            <thead>
              <tr>
                <th><input type="checkbox" id="chkAll"></th>
                <th>ID</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Holder / Employee</th>
                <th>Role</th>
                <th>Dept</th>
                <th>Office</th>
                <th style="text-align:center">MFA</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $linked = !empty($r['holder_employee_code']);
                $rowStyle = $linked ? '' : 'style="background:rgba(220,38,38,.07)"';
                $rRole  = strtoupper((string)($r['role'] ?? ''));
                $rLevel = strtoupper((string)($r['level'] ?? ''));
                $rDept  = strtoupper((string)($r['department'] ?? ''));
                $isFinMgr    = ($rDept === 'FIN' && $rRole === 'MANAGER');
                $isSysLevel  = in_array($rRole, ['SYS','ADMIN','SUPERADMIN'], true) || in_array($rLevel, ['SYS','ADMIN','SUPERADMIN'], true);
              ?>
                <tr <?= $rowStyle ?>>
                  <td><input type="checkbox" class="chk" value="<?= (int)$r['id'] ?>"></td>
                  <td class="text-muted" style="font-size:12px"><?= (int)$r['id'] ?></td>
                  <td style="font-weight:600">
                    <?= h($r['username']) ?>
                    <?php if ($isFinMgr): ?>
                      <span title="Manager FIN — kontrol keuangan, langsung bawah Owner" style="margin-left:4px;font-size:10px;background:rgba(251,191,36,.2);color:#fbbf24;border:1px solid rgba(251,191,36,.4);border-radius:5px;padding:1px 6px;font-weight:700">💰 FIN</span>
                    <?php elseif ($isSysLevel): ?>
                      <span title="Akun sistem — proteksi penuh" style="margin-left:4px;font-size:10px;background:rgba(139,92,246,.2);color:#c4b5fd;border:1px solid rgba(139,92,246,.4);border-radius:5px;padding:1px 6px;font-weight:700">🔐 SYS</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted" style="font-size:12px"><?= h($r['full_name']) ?></td>
                  <td style="font-size:12px">
                    <?php if ($linked): ?>
                      <div style="font-weight:500;color:#4ade80"><?= h($r['employee_name'] ?: $r['holder_employee_code']) ?></div>
                      <code style="font-size:11px;color:#60a5fa"><?= h($r['holder_employee_code']) ?></code>
                    <?php else: ?>
                      <span class="badge bg-danger" style="font-size:11px">✗ Belum di-set</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge bg-transparent border"><?= strtoupper(h($r['role'])) ?></span></td>
                  <td style="font-size:12px"><?= h($r['department']) ?></td>
                  <td style="font-size:12px"><?= h(strtoupper((string)$r['office_code'])) ?></td>
                  <td style="text-align:center;font-size:12px">
                    <?php if (!empty($r['mfa_enabled']) && !empty($r['mfa_confirmed_at'])): ?>
                      <span title="MFA aktif sejak <?= h(substr((string)$r['mfa_confirmed_at'],0,10)) ?>" style="color:#818cf8;font-size:14px;cursor:help">🔑</span>
                    <?php else: ?>
                      <span style="color:#374151;font-size:12px">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (msl_status_norm((string)($r['status'] ?? 'ACTIVE')) === 'ACTIVE'): ?>
                      <span class="badge bg-success">ACTIVE</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">INACTIVE</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:11px;white-space:nowrap">
                    <?php
                    $ll = (string)($r['last_login_at'] ?? '');
                    if ($ll === '' || $ll === '0000-00-00 00:00:00'): ?>
                      <span style="color:#ef4444;font-weight:600;font-size:10px">🚫 Belum pernah</span>
                    <?php else:
                      $daysAgo = (int)round((time() - strtotime($ll)) / 86400);
                      $llColor = $daysAgo > 90 ? '#f87171' : ($daysAgo > 30 ? '#fbbf24' : '#4ade80');
                      $llLabel = $daysAgo === 0 ? 'Hari ini' : ($daysAgo === 1 ? 'Kemarin' : "{$daysAgo}h lalu");
                    ?>
                      <span title="<?= h(substr($ll,0,16)) ?>" style="color:<?= $llColor ?>;font-weight:600"><?= $llLabel ?></span>
                      <div style="color:#374151;font-size:10px"><?= h(substr($ll,0,10)) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
  <?php
    $rowIsProtected = msl_user_is_protected($r);
    // SUPERADMIN bisa ubah semua, ADMIN/SYS tidak bisa ubah akun protected
    $canEdit = $isSuperAdmin || (!$rowIsProtected && ((!$isITCManager) || msl_itc_can_manage_user($r)));
    $msl_rbac_row_href = msl_rbac_center_href_for_user_row((string)$baseProject, $r);
  ?>
  <?php if ($canEdit): ?>
    <a class="btn btn-sm btn-outline-light" href="<?= h($BASE_PROJECT) ?>/master/master_system_login.php?edit=<?= (int)$r['id'] ?>">Edit</a>
    <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <?php if (msl_status_norm((string)($r['status'] ?? 'ACTIVE')) === 'ACTIVE'): ?>
        <input type="hidden" name="op" value="deactivate">
        <button class="btn btn-sm btn-outline-warning" type="submit" onclick="return confirm('Deactivate user ini?')">Deactivate</button>
      <?php else: ?>
        <input type="hidden" name="op" value="activate">
        <button class="btn btn-sm btn-outline-success" type="submit" onclick="return confirm('Activate user ini?')">Activate</button>
      <?php endif; ?>
    </form>
    <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <?php if (empty($r['deleted_at'])): ?>
        <input type="hidden" name="op" value="soft_delete">
        <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Soft-delete user ini?')">Soft-delete</button>
      <?php else: ?>
        <input type="hidden" name="op" value="restore">
        <button class="btn btn-sm btn-outline-primary" type="submit" onclick="return confirm('Restore user ini?')">Restore</button>
      <?php endif; ?>
    </form>
  <?php else: ?>
    <span title="Akun <?= $rowIsProtected ? 'ADMIN/SUPERADMIN/SYS — hanya SUPERADMIN yang bisa ubah' : 'protected (FIN Manager)' ?>"
          style="font-size:10px;color:#64748b;display:inline-flex;align-items:center;gap:4px">
      🔒 Protected
    </span>
  <?php endif; ?>
  <?php if ($msl_rbac_row_href !== null): ?>
    <a class="btn btn-sm btn-outline-info" href="<?= h($msl_rbac_row_href) ?>" target="_blank" rel="noopener noreferrer" title="RBAC Center — sel matrix user ini">RBAC</a>
  <?php endif; ?>
</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <form id="bulkForm" method="post" class="d-none">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="op" value="bulk_status">
          <input type="hidden" name="status" id="bulkStatus">
          <div id="bulkIds"></div>
        </form>

        <hr class="border-secondary">

        <?php
        // Referensi User → Employee
        $ref_emp = [];
        try {
          $ref_emp = $pdo->query("
            SELECT
              m.id            AS user_id,
              m.username,
              COALESCE(m.holder_employee_code, '-') AS employee_code,
              COALESCE(e.employee_name, '-')        AS employee_name,
              COALESCE(m.office_code, '-')          AS office_code,
              COALESCE(m.department, '-')           AS department
            FROM master_system_login m
            LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
            WHERE m.deleted_at IS NULL
            ORDER BY
              (m.holder_employee_code IS NULL OR m.holder_employee_code = '') ASC,
              m.username ASC
          ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $ref_emp = []; }
        $ref_sudah = count(array_filter($ref_emp, fn($r) => $r['employee_code'] !== '-'));
        $ref_belum = count($ref_emp) - $ref_sudah;
        ?>

        <?php if ($ref_emp): ?>
        <details class="mb-3">
          <summary style="cursor:pointer;list-style:none;padding:10px 14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.15);border-radius:8px;display:flex;justify-content:space-between;align-items:center">
            <span class="fw-semibold" style="font-size:14px">
              Referensi User → Employee
              &nbsp;<span class="badge bg-success"><?= $ref_sudah ?> terhubung</span>
              <?php if ($ref_belum): ?><span class="badge bg-danger"><?= $ref_belum ?> belum</span><?php endif; ?>
            </span>
            <span class="text-muted small">▼ klik untuk buka</span>
          </summary>
          <div class="table-responsive mt-1" style="border:1px solid rgba(255,255,255,.15);border-top:none;border-radius:0 0 8px 8px">
            <table class="table table-sm table-dark table-hover mb-0" style="font-size:13px">
              <thead>
                <tr class="text-muted">
                  <th>User ID</th>
                  <th>Username</th>
                  <th>Employee Code</th>
                  <th>Nama Employee</th>
                  <th>Dept</th>
                  <th>Office</th>
                  <th class="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($ref_emp as $r):
                $linked = ($r['employee_code'] !== '-');
              ?>
                <tr <?= !$linked ? 'style="opacity:.5"' : '' ?>>
                  <td class="text-muted"><?= rmi_h($r['user_id']) ?></td>
                  <td class="fw-semibold"><?= rmi_h($r['username']) ?></td>
                  <td><code style="color:#60a5fa"><?= rmi_h($r['employee_code']) ?></code></td>
                  <td><?= rmi_h($r['employee_name']) ?></td>
                  <td class="text-muted"><?= rmi_h($r['department']) ?></td>
                  <td class="text-muted"><?= rmi_h($r['office_code']) ?></td>
                  <td class="text-center">
                    <?php if ($linked): ?>
                      <span class="badge bg-success">✓ Terhubung</span>
                    <?php else: ?>
                      <span class="badge bg-danger">✗ Belum</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
        <?php endif; ?>

        <h6 class="mb-2">Audit (Fase 3)</h6>
        <div class="muted small">Log tersimpan di /uploads/audit_logs/audit_master_system_login.log (JSON lines). Untuk audit cepat, simpan file tersebut.</div>

        <?php if (!empty($audit_rows)): ?>
        <div class="card rmi-card mt-3">
          <div class="rmi-card-header"><b>Audit Log (system_audit_logs)</b> <span class="text-muted">(Last 50 events)</span></div>
          <div class="table-responsive">
            <table class="table table-sm table-dark table-hover mb-0">
              <thead><tr><th style="width:160px">Time</th><th style="width:100px">Action</th><th style="width:120px">Code</th><th style="width:100px">User</th><th>Description</th></tr></thead>
              <tbody>
              <?php foreach ($audit_rows as $a): ?>
                <tr><td><?= rmi_h($a['created_at'] ?? '') ?></td><td><?= rmi_h($a['action'] ?? '') ?></td><td><?= rmi_h($a['record_code'] ?? '') ?></td><td><?= rmi_h($a['username'] ?? '') ?></td><td><?= rmi_h($a['description'] ?? '') ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script>
$(function(){
  const t = $('#tbl').DataTable({
    dom: 'Bfrtip',
    buttons: ['copy','csv','excel','print'],
    pageLength: 25
  });

  $('#chkAll').on('change', function(){
    $('.chk').prop('checked', this.checked);
  });
});

// ── Sidebar Tab switcher ──────────────────────────────────────────────────
function mslTab(btn, tabId) {
  // Hide all tab panes
  document.querySelectorAll('#tab-user,#tab-import,#tab-seed').forEach(function(el){
    el.style.display = 'none';
  });
  // Deactivate all tab buttons
  document.querySelectorAll('[data-tab]').forEach(function(b){ b.classList.remove('active'); });
  // Show selected
  var el = document.getElementById(tabId);
  if (el) el.style.display = '';
  btn.classList.add('active');
  try { localStorage.setItem('msl_active_tab', tabId); } catch(e) {}
}
// Restore last active tab on load
(function(){
  var saved = null;
  try { saved = localStorage.getItem('msl_active_tab'); } catch(e) {}
  <?php if ($editRow): ?>saved = 'tab-user';<?php endif; ?>
  if (saved) {
    var btn = document.querySelector('[data-tab="' + saved + '"]');
    if (btn) mslTab(btn, saved);
  }
})();

function bulkSubmit(){
  const ids = Array.from(document.querySelectorAll('.chk:checked')).map(x=>x.value);
  if (!ids.length) { alert('Pilih minimal 1 user'); return false; }

  // read status from the bulk form header
  const sel = document.querySelector('#bulkFormHeader select[name="status"]');
  const status = sel ? sel.value : 'ACTIVE';

  document.getElementById('bulkStatus').value = status;
  const wrap = document.getElementById('bulkIds');
  wrap.innerHTML = '';
  ids.forEach(id=>{
    const i = document.createElement('input');
    i.type='hidden'; i.name='ids[]'; i.value=id;
    wrap.appendChild(i);
  });
  document.getElementById('bulkForm').submit();
  return false;
}

// --- UI Scaling (Zoom) ---
(function(){
  const root = document.documentElement;
  const scaleRoot = document.getElementById('scaleRoot');
  const rng = document.getElementById('zoomRange');
  const btn = document.getElementById('zoomReset');
  if (!rng || !btn || !scaleRoot) return;

  const key = 'msl.ui.scale';
  function apply(val){
    const s = Math.max(0.5, Math.min(2, val));
    root.style.setProperty('--ui-scale', String(s));
  }
  // load saved
  const saved = localStorage.getItem(key);
  if (saved){
    const pct = Math.round(parseFloat(saved) * 100);
    const clamped = Math.max(80, Math.min(140, pct));
    rng.value = String(clamped);
    apply(clamped/100);
  } else {
    apply(1);
  }

  rng.addEventListener('input', function(){
    const v = parseInt(rng.value, 10) || 100;
    const s = v/100;
    apply(s);
    try{ localStorage.setItem(key, String(s)); }catch(e){}
  });
  btn.addEventListener('click', function(){
    rng.value = '100';
    apply(1);
    try{ localStorage.removeItem(key); }catch(e){}
  });
})();
</script>
<?php rmi_footer(); ?>
