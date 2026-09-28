<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// master/itc_reset_password.php
// ITC tools: reset password user lain (kecuali akun protected: SUPERADMIN/ADMIN)

require_once __DIR__ . '/auth.php';
require_login();
// Selaras config/page_registry.php (route_any): ITC atau SYS user-management.
require_any_permission(['TOOLS.ITC_RESET_PASSWORD', 'SYSTEM.USER_MANAGE']);

$pdo = db_pdo();

// --- ensure schema minimal (non-breaking) ---
function _msl_cols(PDO $pdo, string $table): array {
  $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $st->execute([$table]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['COLUMN_NAME']] = true;
  return $out;
}
function _msl_add_col(PDO $pdo, string $table, string $col, string $ddl): void {
  $cols = _msl_cols($pdo, $table);
  if (!isset($cols[$col])) {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
  }
}
function _msl_ensure(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS `master_system_login` (
    `id` int NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL,
    `password_hash` varchar(255) NOT NULL,
    `status` varchar(20) NOT NULL DEFAULT 'active',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  _msl_add_col($pdo, 'master_system_login', 'full_name',   "varchar(120) NULL AFTER `username`");
  _msl_add_col($pdo, 'master_system_login', 'role',        "varchar(20) NOT NULL DEFAULT 'staff' AFTER `password_hash`");
  _msl_add_col($pdo, 'master_system_login', 'level',       "varchar(20) NULL AFTER `role`");
  _msl_add_col($pdo, 'master_system_login', 'department',  "varchar(50) NULL AFTER `level`");
  _msl_add_col($pdo, 'master_system_login', 'office_code', "varchar(30) NULL AFTER `department`");
  _msl_add_col($pdo, 'master_system_login', 'updated_at',  "datetime NULL AFTER `created_at`");
  _msl_add_col($pdo, 'master_system_login', 'last_login_at',"datetime NULL AFTER `updated_at`");
}
_msl_ensure($pdo);

if (!function_exists('h')) {

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

}


function _audit_append(string $action, array $meta, ?PDO $pdo = null): void {
  // 1) Tulis ke file (legacy — tetap dipertahankan)
  $dir = dirname(__DIR__) . '/uploads/audit_logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $path = $dir . '/audit_itc_password_reset.log';
  $row = [
    'ts'     => date('c'),
    'by'     => $_SESSION['username'] ?? '',
    'action' => $action,
    'meta'   => $meta,
  ];
  @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

  // 2) Tulis ke system_audit_logs (DB) agar muncul di UI Audit Log
  if ($pdo !== null) {
    try {
      $actor    = (string)($_SESSION['username'] ?? 'SYSTEM');
      $userId   = (int)($_SESSION['user_id'] ?? 0);
      $role     = (string)($_SESSION['role'] ?? '');
      $level    = (string)($_SESSION['level'] ?? '');
      $ip       = (string)($_SERVER['REMOTE_ADDR'] ?? '');
      $ua       = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
      $target   = (string)($meta['target_username'] ?? '');
      $descr    = "ITC Reset Password: {$target} oleh {$actor}";
      $details  = json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $recCode  = $target !== '' ? "USER#{$target}" : 'ITC_RESET_PASSWORD';

      if (function_exists('master_audit_ensure_table')) {
        master_audit_ensure_table($pdo);
      }
      $st = $pdo->prepare("
        INSERT INTO system_audit_logs
          (module, action, record_table, record_id, record_code,
           description, details, user_id, username, role, level,
           ip, user_agent, created_at)
        VALUES
          ('itc','PASSWORD_CHANGED','master_system_login',?,?,
           ?,?,?,?,?,?,
           ?,?,NOW())
      ");
      $st->execute([
        (int)($meta['target_id'] ?? 0),
        $recCode,
        $descr,
        $details,
        $userId,
        $actor,
        strtoupper($role),
        strtoupper($level),
        substr($ip, 0, 45),
        $ua,
      ]);
    } catch (Throwable $e) {
      // fail-soft — jangan gagalkan operasi karena audit error
    }
  }
}

function _fetch_user_by_username(PDO $pdo, string $username): ?array {
  $st = $pdo->prepare("SELECT id, username, full_name, role, level, department, office_code, status FROM master_system_login WHERE username=? LIMIT 1");
  $st->execute([$username]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

function _is_superadmin(array $u): bool {
  $r = strtoupper((string)($u['role'] ?? ''));
  $l = strtoupper((string)($u['level'] ?? ''));
  // SYS = ADMIN = SUPERADMIN (semua privileged, tidak ada perbedaan level)
  return in_array($r, ['SYS','ADMIN','SUPERADMIN'], true) || in_array($l, ['SYS','ADMIN','SUPERADMIN'], true);
}
function _is_fin(array $u): bool {
  $dept = strtoupper(trim((string)($u['department'] ?? '')));
  return $dept === 'FIN';
}
function _is_protected(array $u): bool {
  $uname = strtolower((string)($u['username'] ?? ''));
  if (in_array($uname, ['admin','superadmin','rizqullahmediskasys'], true)) return true;
  if (_is_superadmin($u)) return true;
  if (_is_fin($u)) return true;
  return false;
}
function _protected_reason(array $u): string {
  $uname = strtolower((string)($u['username'] ?? ''));
  if (in_array($uname, ['admin','superadmin','rizqullahmediskasys'], true)) return 'Akun sistem utama';
  if (_is_superadmin($u)) return 'SYS';
  if (_is_fin($u))        return 'Dept FIN — proteksi keuangan';
  return 'Protected';
}

// ---- resolve current user from DB (biar robust walau session role kosong) ----
$meUsername = (string)($_SESSION['username'] ?? '');
$meRole  = strtoupper((string)($_SESSION['role'] ?? ''));
$meLevel = strtoupper((string)($_SESSION['level'] ?? ''));
$meDept  = strtoupper((string)($_SESSION['department'] ?? ''));

$meDb = $meUsername ? _fetch_user_by_username($pdo, $meUsername) : null;
if ($meDb) {
  if ($meRole === '')  $meRole  = strtoupper((string)($meDb['role'] ?? ''));
  if ($meLevel === '') $meLevel = strtoupper((string)($meDb['level'] ?? ''));
  if ($meDept === '')  $meDept  = strtoupper((string)($meDb['department'] ?? ''));
}

// SYS = ADMIN = SUPERADMIN. Tidak ada lagi pemisahan ADMIN vs SUPERADMIN.
$isSuperAdmin = in_array($meRole, ['SYS','ADMIN','SUPERADMIN'], true) || in_array($meLevel, ['SYS','ADMIN','SUPERADMIN'], true);
$isITC        = ($meDept === 'ITC');

// CSRF — pakai standard csrf_token() agar konsisten dengan require_login() → verify_csrf()
// Bug lama: $_SESSION['_csrf_itc_reset'] ≠ $_SESSION['_csrf'] → setiap POST 403 Forbidden
$csrf = function_exists('csrf_token') ? csrf_token() : (function(){
    if (empty($_SESSION['_csrf']) || strlen((string)$_SESSION['_csrf']) < 20) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
})();

$flash = $_SESSION['_itc_flash'] ?? '';
$err = $_SESSION['_itc_err'] ?? '';
unset($_SESSION['_itc_flash'], $_SESSION['_itc_err']);

$reset_id = isset($_GET['reset']) ? (int)$_GET['reset'] : 0;
$resetRow = null;
if ($reset_id > 0) {
  $st = $pdo->prepare("SELECT id, username, full_name, role, level, department, office_code, status FROM master_system_login WHERE id=? LIMIT 1");
  $st->execute([$reset_id]);
  $resetRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $op = $_POST['op'] ?? '';
    // CSRF sudah diverifikasi oleh require_login() → verify_csrf().
    // Ini secondary check untuk defense-in-depth.
    $csrfPosted = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $csrfPosted)) {
      throw new Exception('CSRF invalid. Refresh halaman lalu coba lagi.');
    }

    if ($op === 'reset_password') {
      $id = (int)($_POST['id'] ?? 0);
      $password = (string)($_POST['password'] ?? '');
      if ($id <= 0) throw new Exception('User ID tidak valid.');
      if ($password === '') throw new Exception('Password baru wajib diisi.');

      $st = $pdo->prepare("SELECT id, username, role, level, department FROM master_system_login WHERE id=? LIMIT 1");
      $st->execute([$id]);
      $target = $st->fetch(PDO::FETCH_ASSOC);
      if (!$target) throw new Exception('User tidak ditemukan.');

      // Proteksi: ITC tidak boleh reset akun protected (SYS/ADMIN/SUPERADMIN/FIN)
      if ($isITC && _is_protected($target)) {
        $reason = _protected_reason($target);
        throw new Exception("Tidak diizinkan: ITC tidak boleh reset password akun ini ({$reason}).");
      }

      // Proteksi tambahan: SUPERADMIN hanya bisa direset oleh SUPERADMIN
      if (_is_superadmin($target) && !$isSuperAdmin) {
        throw new Exception('Tidak diizinkan: akun SYS/SUPERADMIN hanya bisa direset oleh SUPERADMIN.');
      }

      // Proteksi FIN: hanya ADMIN/SUPERADMIN yang boleh reset password user FIN
      if (_is_fin($target) && !$isSuperAdmin) {
        throw new Exception('Tidak diizinkan: akun Dept FIN hanya bisa direset oleh ADMIN/SUPERADMIN.');
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $st = $pdo->prepare("UPDATE master_system_login SET password_hash=?, updated_at=NOW() WHERE id=?");
      $st->execute([$hash, $id]);

      _audit_append('reset_password', ['target_id'=>$id,'target_username'=>$target['username'] ?? null,'by_dept'=>$meDept,'by_role'=>$meRole,'by_level'=>$meLevel], $pdo);

      $_SESSION['_itc_flash'] = 'Password berhasil direset untuk user: ' . ($target['username'] ?? '');
      rmi_redirect('itc_reset_password.php');
    }

    throw new Exception('Operasi tidak dikenal.');

  } catch (Throwable $e) {
    $_SESSION['_itc_err'] = $e->getMessage();
    rmi_redirect('itc_reset_password.php' . ($reset_id ? ('?reset='.$reset_id) : ''));
  }
}

// filters
$filters = [
  'q' => trim((string)($_GET['q'] ?? '')),
  'department' => trim((string)($_GET['department'] ?? '')),
  'office_code' => trim((string)($_GET['office_code'] ?? '')),
  'status' => trim((string)($_GET['status'] ?? '')),
];

$where = [];
$params = [];
if ($filters['q'] !== '') {
  $where[] = "(username LIKE ? OR full_name LIKE ?)";
  $params[] = "%{$filters['q']}%";
  $params[] = "%{$filters['q']}%";
}
if ($filters['department'] !== '') {
  $where[] = "UPPER(TRIM(COALESCE(department,''))) = UPPER(TRIM(?))";
  $params[] = trim($filters['department']);
}
if ($filters['office_code'] !== '') {
  $where[] = "UPPER(TRIM(COALESCE(office_code,''))) = UPPER(TRIM(?))";
  $params[] = trim($filters['office_code']);
}
if ($filters['status'] !== '') {
  $where[] = "status = ?";
  $params[] = $filters['status'];
}

$sql = "SELECT id, username, full_name, role, level, department, office_code, status, created_at,
               COALESCE(mfa_enabled, 0) AS mfa_enabled,
               mfa_confirmed_at
        FROM master_system_login";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY COALESCE(department,'') ASC, COALESCE(office_code,'') ASC, username ASC LIMIT 500";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

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

// Filter dropdowns: departments & offices
$departments = [];
$offices = [];
try {
  $departments = $pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(department,''))) AS v FROM master_system_login WHERE COALESCE(department,'')<>'' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
  $departments = array_values(array_filter(array_map('strval', $departments)));
} catch (Throwable $e) {}
try {
  $raw = $pdo->query("SELECT office_code, office_name FROM master_office WHERE office_code IS NOT NULL AND office_code<>'' ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($raw as $o) {
    $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
    if ($oc !== '') $offices[] = ['office_code'=>$oc,'office_name'=>$o['office_name']??$oc];
  }
  if (empty($offices)) {
    $rs = $pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(office_code,''))) AS office_code FROM master_system_login WHERE COALESCE(office_code,'')<>'' ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rs as $r) {
      $oc = (string)($r['office_code'] ?? '');
      if ($oc !== '') $offices[] = ['office_code'=>$oc,'office_name'=>$oc];
    }
  }
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('ITC • Reset Password User', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'ITC • Reset Password User',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/master/rmi_readability.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>.muted,.muted.small{color:#cbd5e1 !important}
    .card{background:var(--rmi-panel, rgba(255,255,255,.06));border:1px solid rgba(255,255,255,.12)}</style>',
]);
?>

<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">ITC • Reset Password User</h3>
      <div class="muted">Mode aman: ITC hanya boleh reset password user lain. Akun <b>ADMIN/SUPERADMIN</b> terkunci.</div>
      <div class="muted small mt-1">Login: <b><?= h($meUsername) ?></b> • Dept: <b><?= h($meDept) ?></b> • Role: <b><?= h($meRole ?: '-') ?></b> • Level: <b><?= h($meLevel ?: '-') ?></b></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light btn-sm" href="<?= h($BASE_PROJECT) ?>/">🏠 Home</a>
      <a class="btn btn-outline-danger btn-sm" href="<?= h($BASE_PROJECT) ?>/master/logout.php">Logout</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card p-3">
        <h5 class="mb-2">Reset Password</h5>
        <?php if (!$resetRow): ?>
          <div class="muted">Klik tombol <b>Reset</b> pada tabel user di kanan, lalu isi password baru.</div>
        <?php else: ?>
          <?php $locked = _is_protected($resetRow) && $isITC; ?>
          <div class="mb-2">
            Target: <b><?= h($resetRow['username']) ?></b>
            <span class="muted">(Role: <?= h(strtoupper($resetRow['role'] ?? '')) ?> • Dept: <?= h(strtoupper($resetRow['department'] ?? '')) ?> • Office: <?= h(strtoupper($resetRow['office_code'] ?? '')) ?>)</span>
          </div>

          <?php if ($locked): ?>
            <div class="alert alert-warning">Akun ini <b>protected</b>. ITC tidak boleh reset password ADMIN/SUPERADMIN.</div>
          <?php endif; ?>

          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="op" value="reset_password">
            <input type="hidden" name="id" value="<?= (int)$resetRow['id'] ?>">
            <label class="form-label">Password baru</label>
            <input class="form-control" type="password" name="password" required <?= $locked ? 'disabled' : '' ?>>
            <button class="btn btn-warning w-100 mt-2" <?= $locked ? 'disabled' : '' ?>>Reset Password</button>
            <a class="btn btn-outline-light w-100 mt-2" href="itc_reset_password.php">Batal</a>
          </form>
        <?php endif; ?>

        <hr class="border-secondary">
        <div class="muted small">
          <b>Catatan:</b>
          <ul class="mb-0">
            <li>ITC hanya boleh reset password — tidak dapat mengubah role/level/status.</li>
            <li>Akun <b>ADMIN/SUPERADMIN</b> terkunci, tidak bisa direset dari sini.</li>
            <li>Setiap reset dicatat di audit log otomatis.</li>
          </ul>
        </div>
      </div>

    </div>

    <div class="col-lg-8">
      <!-- Filter bar — di atas tabel -->
      <div class="card p-3 mb-3">
        <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
          <div style="flex:2;min-width:160px">
            <label class="form-label small mb-1" style="color:#94a3b8">🔍 Cari</label>
            <input class="form-control form-control-sm" name="q" value="<?= h($filters['q']) ?>" placeholder="Username / Full Name">
          </div>
          <div style="flex:1;min-width:110px">
            <label class="form-label small mb-1" style="color:#94a3b8">Dept</label>
            <select class="form-select form-select-sm" name="department">
              <option value="">Semua</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= h($d) ?>" <?= (strtoupper($filters['department']??'')===strtoupper($d))?'selected':'' ?>><?= h($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1;min-width:110px">
            <label class="form-label small mb-1" style="color:#94a3b8">Office</label>
            <select class="form-select form-select-sm" name="office_code">
              <option value="">Semua</option>
              <?php foreach ($offices as $o): $oc=(string)($o['office_code']??''); if($oc==='')continue; ?>
                <option value="<?= h($oc) ?>" <?= (strtoupper($filters['office_code']??'')===$oc)?'selected':'' ?>><?= h($oc) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1;min-width:110px">
            <label class="form-label small mb-1" style="color:#94a3b8">Status</label>
            <select class="form-select form-select-sm" name="status">
              <option value="">Semua</option>
              <option value="active" <?= ($filters['status']==='active'?'selected':'') ?>>ACTIVE</option>
              <option value="inactive" <?= ($filters['status']==='inactive'?'selected':'') ?>>INACTIVE</option>
            </select>
          </div>
          <div class="d-flex gap-2" style="padding-bottom:1px">
            <button type="submit" class="btn btn-sm btn-outline-light">Filter</button>
            <?php if (array_filter($filters)): ?>
              <a class="btn btn-sm btn-outline-secondary" href="itc_reset_password.php">✕ Reset</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="card p-3">
        <h5 class="mb-2">Daftar User <span class="text-muted" style="font-size:13px;font-weight:400">(<?= count($rows) ?> ditampilkan)</span></h5>
        <div class="table-responsive">
          <table id="tbl" class="table table-sm table-striped table-hover align-middle table-dark-custom">
            <thead>
              <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Role</th>
                <th>Dept</th>
                <th>Office</th>
                <th>Status</th>
                <th>MFA</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <?php
                  $prot     = _is_protected($r);
                  $protWhy  = $prot ? _protected_reason($r) : '';
                  // ITC tidak boleh reset akun protected; ADMIN/SUPERADMIN tetap bisa
                  $canReset = !$prot || $isSuperAdmin;
                  $dept     = strtoupper((string)($r['department'] ?? ''));
                  $role     = strtoupper((string)($r['role'] ?? ''));
                  $isFinMgr = ($dept === 'FIN' && $role === 'MANAGER');
                  $isSysLvl = in_array($role, ['SYS','ADMIN','SUPERADMIN'], true);
                ?>
                <tr <?= $prot ? 'style="opacity:.7"' : '' ?>>
                  <td><?= (int)$r['id'] ?></td>
                  <td>
                    <?= h($r['username']) ?>
                    <?php if ($isFinMgr): ?>
                      <span style="font-size:10px;background:rgba(251,191,36,.2);color:#fbbf24;border:1px solid rgba(251,191,36,.4);border-radius:4px;padding:1px 5px;margin-left:3px">💰 FIN</span>
                    <?php elseif ($isSysLvl): ?>
                      <span style="font-size:10px;background:rgba(139,92,246,.2);color:#c4b5fd;border:1px solid rgba(139,92,246,.4);border-radius:4px;padding:1px 5px;margin-left:3px">🔐 SYS</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($r['full_name']) ?></td>
                  <td><?= h(strtoupper((string)$r['role'])) ?></td>
                  <td><?= h($dept) ?></td>
                  <td><?= h(strtoupper((string)($r['office_code'] ?? ''))) ?></td>
                  <td>
                    <?php if (strtolower((string)($r['status'] ?? '')) === 'active'): ?>
                      <span class="badge bg-success">ACTIVE</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">INACTIVE</span>
                    <?php endif; ?>
                    <?php if ($prot): ?>
                      <span class="badge bg-warning text-dark" title="<?= h($protWhy) ?>">🔒 Protected</span>
                    <?php endif; ?>
                  </td>
                  <td style="white-space:nowrap">
                    <?php if ($prot && $isITC): ?>
                      <span style="color:#334155;font-size:11px" title="Status MFA tidak ditampilkan untuk akun ini">—</span>
                    <?php elseif ((int)($r['mfa_enabled'] ?? 0) === 1): ?>
                      <span style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3);border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700">✅ ON</span>
                      <?php if (!empty($r['mfa_confirmed_at'])): ?>
                        <div style="color:#475569;font-size:10px;margin-top:1px"><?= h(substr((string)$r['mfa_confirmed_at'], 0, 10)) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span style="background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.25);border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700">✗ OFF</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($canReset): ?>
                      <a class="btn btn-sm btn-outline-light" href="itc_reset_password.php?reset=<?= (int)$r['id'] ?>">Reset</a>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-secondary" disabled
                              title="<?= h($protWhy) ?> — tidak dapat direset oleh ITC">🔒 Locked</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>

  <?php if ($ref_emp): ?>
  <div class="mt-3">
    <details>
      <summary style="cursor:pointer;list-style:none;padding:10px 14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.15);border-radius:8px;display:flex;justify-content:space-between;align-items:center">
        <span class="fw-semibold" style="font-size:14px">
          Referensi User → Employee
          &nbsp;<span class="badge bg-success"><?= $ref_sudah ?> terhubung</span>
          <?php if ($ref_belum): ?><span class="badge bg-danger"><?= $ref_belum ?> belum</span><?php endif; ?>
        </span>
        <span class="text-muted small">▼ klik untuk buka</span>
      </summary>
      <div class="table-responsive mt-0" style="border:1px solid rgba(255,255,255,.15);border-top:none;border-radius:0 0 8px 8px">
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
              <td class="text-muted"><?= h($r['user_id']) ?></td>
              <td class="fw-semibold"><?= h($r['username']) ?></td>
              <td><code style="color:#60a5fa"><?= h($r['employee_code']) ?></code></td>
              <td><?= h($r['employee_name']) ?></td>
              <td class="text-muted"><?= h($r['department']) ?></td>
              <td class="text-muted"><?= h($r['office_code']) ?></td>
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
  </div>
  <?php endif; ?>

  <?php
  // --- Audit Log section: baca dari system_audit_logs ---
  $auditRows = [];
  try {
    $stAudit = $pdo->prepare("
      SELECT created_at, username, ip, description, details
      FROM system_audit_logs
      WHERE module = 'itc' AND action = 'ITC_RESET_PASSWORD'
      ORDER BY created_at DESC
      LIMIT 50
    ");
    $stAudit->execute();
    $auditRows = $stAudit->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { /* tabel mungkin belum ada entri */ }
  ?>
  <div class="mt-3">
    <details <?= empty($auditRows) ? '' : 'open' ?>>
      <summary style="cursor:pointer;list-style:none;padding:10px 14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.15);border-radius:8px;display:flex;justify-content:space-between;align-items:center">
        <span class="fw-semibold" style="font-size:14px">
          📋 Audit Log — Reset Password
          <span class="badge bg-secondary ms-2"><?= count($auditRows) ?> entri terakhir</span>
        </span>
        <span class="text-muted small">▼ klik untuk buka/tutup</span>
      </summary>
      <div class="table-responsive mt-0" style="border:1px solid rgba(255,255,255,.15);border-top:none;border-radius:0 0 8px 8px">
        <?php if (empty($auditRows)): ?>
          <div class="p-3 text-muted" style="font-size:13px">Belum ada riwayat reset password.</div>
        <?php else: ?>
        <table class="table table-sm table-dark table-hover mb-0" style="font-size:12px">
          <thead>
            <tr class="text-muted">
              <th style="width:150px">Waktu</th>
              <th>Oleh (Actor)</th>
              <th>Target User</th>
              <th>IP</th>
              <th>Keterangan</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($auditRows as $al):
            $alMeta = json_decode((string)($al['details'] ?? ''), true) ?: [];
            $targetUser = (string)($alMeta['target_username'] ?? '-');
            $byDept     = strtoupper((string)($alMeta['by_dept'] ?? ''));
            $byRole     = strtoupper((string)($alMeta['by_role'] ?? ''));
          ?>
            <tr>
              <td style="white-space:nowrap;color:#94a3b8"><?= rmi_h(substr((string)($al['created_at'] ?? ''), 0, 19)) ?></td>
              <td>
                <span style="font-weight:600;color:#e2e8f0"><?= rmi_h((string)($al['username'] ?? '-')) ?></span>
                <?php if ($byDept || $byRole): ?>
                  <span class="text-muted" style="font-size:10px">(<?= rmi_h("{$byDept}/{$byRole}") ?>)</span>
                <?php endif; ?>
              </td>
              <td style="color:#fbbf24;font-weight:600"><?= rmi_h($targetUser) ?></td>
              <td style="color:#64748b;font-size:11px"><?= rmi_h((string)($al['ip'] ?? '-')) ?></td>
              <td style="color:#94a3b8"><?= rmi_h((string)($al['description'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </details>
  </div>

</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script>
$(function(){
  $('#tbl').DataTable({
    pageLength: 25
  });
});
</script>
<?php rmi_footer(); ?>
