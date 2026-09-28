<?php
// rbac/index.php — RBAC Center (matrix Dept×Role + registry permission).
// Mutasi: SYSTEM.RBAC_MANAGE. Baca: + SYSTEM.RBAC_VIEW.
//
// Prinsip: SYS privileged = allow all (_shared/rbac.php). STAFF/MANAGER ikut matrix;
// optional override per user (rbac_user_permissions). Tabel RBAC dijamin via rbac_ensure_tables().

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../master/_audit_master.php';

function u(string $path): string {
  $bp = $GLOBALS['BASE_PROJECT'] ?? '';
  $path = '/' . ltrim($path, '/');
  return rtrim($bp, '/') . $path;
}

// ------------------------- AUTH GUARD -------------------------
if (function_exists('auth_require_login')) {
  auth_require_login();
} else {
  require_login();
}

// Guard: kelola matrix = SYSTEM.RBAC_MANAGE; baca saja = SYSTEM.RBAC_VIEW (selaras panduan).
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.RBAC_MANAGE', 'SYSTEM.RBAC_VIEW']);
} else {
    // Fallback jika require_any_permission belum tersedia.
    // Periksa role + level + department (bukan hanya level) agar konsisten dengan auth_is_admin().
    $isSysUser = (function_exists('auth_is_admin') && auth_is_admin())
              || strtoupper(trim((string)($_SESSION['role']       ?? ''))) === 'SYS'
              || strtoupper(trim((string)($_SESSION['level']      ?? ''))) === 'SYS'
              || strtoupper(trim((string)($_SESSION['department'] ?? ''))) === 'SYS';
    if (!$isSysUser) {
        if (function_exists('auth_deny')) auth_deny('RBAC Center hanya untuk SYS.', 403);
        http_response_code(403); die('Forbidden');
    }
}

// ------------------------- DB -------------------------
$pdo = null;
$db_error = null;
try {
  if (function_exists('auth_pdo')) {
    $pdo = auth_pdo();
  } elseif (function_exists('db_pdo')) {
    $pdo = db_pdo();
  }
} catch (Throwable $e) {
  $db_error = $e->getMessage();
  $pdo = null;
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

if (!$pdo) {
  http_response_code(500);
  rmi_header('RBAC Center - Error', [
    'active' => 'rbac',
    'breadcrumbs' => [
      ['label' => 'RBAC', 'url' => $baseProject . '/rbac/index.php'],
      'Error',
    ],
  ]);
  echo '<div class="alert alert-danger">Database tidak tersedia' . ($db_error ? ': ' . rmi_h($db_error) : '') . '</div>';
  rmi_footer();
  exit;
}

// ------------------------- RBAC DATA/STATE -------------------------
$flash = $_SESSION['_rbac_flash'] ?? null;
if (isset($_SESSION['_rbac_flash'])) {
  unset($_SESSION['_rbac_flash']);
}
if (!is_array($flash)) {
  $flash = null;
}

try {
  rbac_ensure_tables($pdo);
} catch (Throwable $e) {
  $flash = ['type' => 'warn', 'msg' => 'RBAC init warning: ' . $e->getMessage()];
}

if (!function_exists('rbac_norm')) {
  function rbac_norm(string $v): string {
    return strtoupper(trim($v));
  }
}
if (!function_exists('rbac_set_flash')) {
  function rbac_set_flash(string $type, string $msg): void {
    $_SESSION['_rbac_flash'] = ['type' => $type, 'msg' => $msg];
  }
}
if (!function_exists('rbac_redirect')) {
  function rbac_redirect(string $url): void {
    rmi_redirect($url);
  }
}
if (!function_exists('rbac_load_departements')) {
  /**
   * Dept kanonik per konvensi: SYS + ACT, CRM, MPR, SCM, WQS, ITC, PQP, HRL, FIN, BRANCH.
   * Selalu tampilkan semua, gabung dengan data dari DB jika ada.
   */
  function rbac_load_departements(PDO $pdo): array {
    $canonical = ['SYS' => 'System', 'ACT' => 'Accounting', 'CRM' => 'Sales', 'MPR' => 'Management', 'SCM' => 'Supply Chain', 'WQS' => 'Warehouse', 'ITC' => 'IT', 'PQP' => 'Purchasing', 'HRL' => 'HR/Legal', 'FIN' => 'Finance', 'BRANCH' => 'Branch'];
    $out = [];
    foreach ($canonical as $dc => $dn) {
      $out[$dc] = ['dept_code' => $dc, 'dept_name' => $dn];
    }
    $queries = [
      "SELECT dept_code, dept_name FROM master_departements WHERE dept_code IS NOT NULL AND dept_code<>''",
      "SELECT dept_code, departement_name AS dept_name FROM master_departements WHERE dept_code IS NOT NULL AND dept_code<>''",
      "SELECT DISTINCT department AS dept_code, department AS dept_name FROM master_system_login WHERE department IS NOT NULL AND department<>''",
    ];
    foreach ($queries as $sql) {
      try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
          $dc = rbac_norm((string)($r['dept_code'] ?? ''));
          if ($dc === '') continue;
          $dn = trim((string)($r['dept_name'] ?? $dc));
          $out[$dc] = ['dept_code' => $dc, 'dept_name' => ($dn !== '' ? $dn : $dc)];
        }
        break;
      } catch (Throwable $e) {
      }
    }
    ksort($out);
    $rows = array_values($out);
    // SYS pertama di UI RBAC Center (mudah dijangkau untuk pengaturan system).
    usort($rows, static function (array $a, array $b): int {
      $ac = rbac_norm((string)($a['dept_code'] ?? ''));
      $bc = rbac_norm((string)($b['dept_code'] ?? ''));
      if ($ac === 'SYS' && $bc !== 'SYS') {
        return -1;
      }
      if ($bc === 'SYS' && $ac !== 'SYS') {
        return 1;
      }
      return strcmp($ac, $bc);
    });
    return $rows;
  }
}
if (!function_exists('rbac_load_roles')) {
  /**
   * Role kanonik per konvensi RBAC RMI:
   * - SYS = system admin (ADMIN/SUPERADMIN setara, selalu ALLOW ALL)
   * - MANAGER, STAFF = untuk dept ACT, CRM, MPR, SCM, WQS, ITC, PQP, HRL, FIN, BRANCH
   * Tidak menampilkan ADMIN/SUPERADMIN sebagai role terpisah — mereka map ke SYS.
   *
   * @param string|null $dept_code Filter role by dept: SYS→[SYS], lainnya→[MANAGER,STAFF]
   */
  function rbac_load_roles(PDO $pdo, ?string $dept_code = null): array {
    $dept = $dept_code ? rbac_norm($dept_code) : '';
    if ($dept === 'SYS') {
      return ['SYS'];
    }
    return ['MANAGER', 'STAFF'];
  }
}
if (!function_exists('rbac_perm_exists_map')) {
  function rbac_perm_exists_map(PDO $pdo): array {
    $map = [];
    try {
      $rows = $pdo->query("SELECT perm_code FROM rbac_permissions WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $pc = rbac_norm((string)($r['perm_code'] ?? ''));
        if ($pc !== '') {
          $map[$pc] = true;
        }
      }
    } catch (Throwable $e) {
    }

    return $map;
  }
}
if (!function_exists('rbac_perm_category')) {
  function rbac_perm_category(string $permCode): string {
    $pc = strtoupper(trim($permCode));
    if ($pc === '') return 'OTHER';
    if (str_ends_with($pc, '.VIEW')    || str_ends_with($pc, '_VIEW'))    return 'VIEW';
    if (str_ends_with($pc, '.CREATE')  || str_ends_with($pc, '_CREATE'))  return 'CREATE';
    if (str_ends_with($pc, '.EDIT')    || str_ends_with($pc, '_EDIT'))    return 'EDIT';
    if (str_ends_with($pc, '.DELETE')  || str_ends_with($pc, '_DELETE'))  return 'DELETE';
    if (str_ends_with($pc, '.APPROVE') || str_ends_with($pc, '_APPROVE')) return 'APPROVE';
    if (str_ends_with($pc, '.IMPORT')  || str_ends_with($pc, '_IMPORT'))  return 'IMPORT';
    if (str_ends_with($pc, '.EXPORT')  || str_ends_with($pc, '_EXPORT'))  return 'EXPORT';
    if (str_ends_with($pc, '.AUDIT')   || str_ends_with($pc, '_AUDIT'))   return 'AUDIT';
    if (str_contains($pc, '.SETTINGS') || str_contains($pc, '.CONFIG'))   return 'SETTINGS';
    if (str_starts_with($pc, 'API.')   || str_ends_with($pc, '.API'))     return 'API';
    if (str_contains($pc, 'PROCESS')   || str_ends_with($pc, '.PROCESS')) return 'PROCESS';
    return 'OTHER';
  }
}

/**
 * Kelompok UI "Staff vs Manager" untuk RBAC Center (bukan kolom DB).
 * Heuristik dari suffix kode + kategori teknis — tetap tinjau manual untuk OTHER/API.
 */
if (!function_exists('rbac_perm_staff_mgr_tier')) {
  function rbac_perm_staff_mgr_tier(string $permCode, string $technicalCat): string {
    $pc = strtoupper(trim($permCode));
    if ($pc === '') {
      return 'TECH_OTHER';
    }
    // Risiko tinggi / pusat — selalu blok Manager (setujui, pembayaran, RBAC, user system)
    if (str_contains($pc, 'AP_PAYMENT_APPROVE')
        || str_contains($pc, 'GL_REVERSAL_APPROVE')
        || str_contains($pc, 'RBAC_MANAGE')
        || str_contains($pc, 'USER_MANAGE')
        || str_contains($pc, 'CONFIG_MANAGE')
        || str_contains($pc, 'ADMIN_CENTER')) {
      return 'MANAGER_PRIV';
    }
    return match ($technicalCat) {
      'VIEW', 'CREATE', 'EDIT', 'IMPORT', 'EXPORT', 'PROCESS' => 'STAFF_OPS',
      'APPROVE', 'DELETE', 'SETTINGS' => 'MANAGER_PRIV',
      'AUDIT', 'API', 'OTHER' => 'TECH_OTHER',
      default => 'TECH_OTHER',
    };
  }
}

$departements = rbac_load_departements($pdo);
$dept_sel = rbac_norm((string)($_REQUEST['dept_code'] ?? ''));
$role_sel = rbac_norm((string)($_REQUEST['role_code'] ?? ''));
if ($dept_sel === '' && !empty($departements)) {
  $dept_sel = (string)$departements[0]['dept_code'];
}
$validDeptCodes = [];
foreach ($departements as $d) {
  $dc = rbac_norm((string)($d['dept_code'] ?? ''));
  if ($dc !== '') {
    $validDeptCodes[$dc] = true;
  }
}
// Cegah URL dept_code sembarangan (tidak mengubah hak; hanya menghindari matrix “hantu”).
if ($dept_sel !== '' && !isset($validDeptCodes[$dept_sel])) {
  $dept_sel = !empty($departements) ? rbac_norm((string)$departements[0]['dept_code']) : '';
}
$roles = rbac_load_roles($pdo, $dept_sel);
if ($role_sel === '' && !empty($roles)) {
  $role_sel = (string)$roles[0];
} elseif ($role_sel !== '' && !in_array($role_sel, $roles, true)) {
  $role_sel = (string)$roles[0];
}
/** Dua tampilan saja: matrix Dept+Role (per modul) | lihat/override per user. Layout lama (per tipe / staff-mgr / per halaman) dialihkan ke matrix modul. */
$rbac_valid_matrix_layouts = ['module', 'user'];
$rbac_layout_raw = (string)($_GET['rbac_layout'] ?? 'user');
$rbac_legacy_matrix_layouts = ['category', 'staff_mgr', 'halaman'];
if (in_array($rbac_layout_raw, $rbac_legacy_matrix_layouts, true)) {
  if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'GET') {
    $redirQ = $_GET;
    $redirQ['rbac_layout'] = 'module';
    rbac_set_flash('warn', 'Tampilan matrix tersebut sudah tidak dipakai. Membuka matrix standar (per modul).');
    rbac_redirect(u('/rbac/index.php?' . http_build_query($redirQ)));
  }
  $rbac_layout_raw = 'module';
}
if (!in_array($rbac_layout_raw, $rbac_valid_matrix_layouts, true)) {
  $rbac_layout_raw = 'user';
}
$rbac_matrix_layout = $rbac_layout_raw;

/** True jika user boleh mengubah RBAC (bukan hanya SYSTEM.RBAC_VIEW). */
$rbacManage = !function_exists('can') || can('SYSTEM.RBAC_MANAGE');

if (!function_exists('rbac_index_valid_dept_role')) {
  /**
   * @param list<array{dept_code?:string}> $deptRows
   * @param list<string> $roleList
   */
  function rbac_index_valid_dept_role(string $dept, string $role, array $deptRows, array $roleList): bool {
    $d = rbac_norm($dept);
    $r = rbac_norm($role);
    if ($d === '' || $r === '') {
      return false;
    }
    $okD = false;
    foreach ($deptRows as $row) {
      if (rbac_norm((string)($row['dept_code'] ?? '')) === $d) {
        $okD = true;
        break;
      }
    }
    return $okD && in_array($r, $roleList, true);
  }
}

if (!function_exists('rbac_index_compute_effective')) {
  /**
   * Simulasi permission efektif untuk satu baris user (tanpa session).
   *
   * @return array{privileged:bool,username:string,dept:string,role:string,rows:list<array{perm:string,source:string}>,matrix_count:int,override_count:int}
   */
  function rbac_index_compute_effective(PDO $pdo, array $ur): array {
    $username = (string)($ur['username'] ?? '');
    $role = strtoupper(trim((string)($ur['role'] ?? '')));
    $level = strtoupper(trim((string)($ur['level'] ?? '')));
    if ($level === '') {
      $level = $role;
    }
    $dept = strtoupper(trim((string)($ur['department'] ?? '')));
    $priv = in_array($role, ['SYS', 'ADMIN', 'SUPERADMIN'], true)
      || in_array($level, ['SYS', 'ADMIN', 'SUPERADMIN'], true);
    if ($priv) {
      return [
        'privileged' => true,
        'username' => $username,
        'dept' => $dept,
        'role' => $role,
        'rows' => [],
        'matrix_count' => 0,
        'override_count' => 0,
      ];
    }
    $uid = (int)($ur['id'] ?? 0);
    $matrix = [];
    if ($dept !== '' && $role !== '') {
      $st = $pdo->prepare("SELECT perm_code FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=? AND allow_flag=1");
      $st->execute([$dept, $role]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pc = rbac_norm((string)($row['perm_code'] ?? ''));
        if ($pc !== '') {
          $matrix[$pc] = true;
        }
      }
    }
    $overrides = [];
    if ($uid > 0) {
      $st = $pdo->prepare("SELECT perm_code, allow_flag FROM rbac_user_permissions WHERE user_id=?");
      $st->execute([$uid]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pc = rbac_norm((string)($row['perm_code'] ?? ''));
        if ($pc === '') {
          continue;
        }
        $overrides[$pc] = ((int)($row['allow_flag'] ?? 0) === 1) ? 1 : 0;
      }
    }
    $effective = $matrix;
    foreach ($overrides as $pc => $flag) {
      if ($flag === 1) {
        $effective[$pc] = true;
      } else {
        unset($effective[$pc]);
      }
    }
    $rows = [];
    $pkeys = array_keys($effective);
    sort($pkeys, SORT_STRING);
    foreach ($pkeys as $pc) {
      $inM = isset($matrix[$pc]);
      $ov = $overrides[$pc] ?? null;
      if (!$inM && ($ov === 1)) {
        $src = 'Override';
      } elseif ($inM && $ov === 1) {
        $src = 'Matrix + override';
      } else {
        $src = 'Matrix';
      }
      $rows[] = ['perm' => $pc, 'source' => $src];
    }
    return [
      'privileged' => false,
      'username' => $username,
      'dept' => $dept,
      'role' => $role,
      'rows' => $rows,
      'matrix_count' => count($matrix),
      'override_count' => count($overrides),
    ];
  }
}

$loginUsersPick = [];
try {
  $loginUsersPick = $pdo->query("SELECT id, username, department, role, level, full_name FROM master_system_login ORDER BY username ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $loginUsersPick = [];
}

$effectiveUserId = (int)($_GET['effective_user'] ?? 0);
$effectiveBundle = null;
if ($effectiveUserId > 0) {
  try {
    $stU = $pdo->prepare("SELECT id, username, department, role, level, full_name FROM master_system_login WHERE id=? LIMIT 1");
    $stU->execute([$effectiveUserId]);
    $urow = $stU->fetch(PDO::FETCH_ASSOC);
    if (is_array($urow)) {
      $effectiveBundle = rbac_index_compute_effective($pdo, $urow);
    }
  } catch (Throwable $e) {
    $effectiveBundle = null;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postDept = rbac_norm((string)($_POST['dept_code'] ?? $dept_sel));
  $postRole = rbac_norm((string)($_POST['role_code'] ?? $role_sel));
  /** Tetap di tampilan matrix setelah POST (sidebar kirim hidden yang sama). */
  $rbac_post_layout_raw = (string)($_POST['rbac_layout'] ?? $_GET['rbac_layout'] ?? 'user');
  if (in_array($rbac_post_layout_raw, ['category', 'staff_mgr', 'halaman'], true)) {
    $rbac_post_layout_raw = 'module';
  }
  $rbac_post_layout = in_array($rbac_post_layout_raw, $rbac_valid_matrix_layouts, true) ? $rbac_post_layout_raw : 'user';
  $hl_dept_post = strtoupper(trim((string)($_GET['hl_dept'] ?? $_POST['hl_dept'] ?? '')));
  $rbac_back = function (string $d, string $r) use ($rbac_post_layout, $hl_dept_post): string {
    $params = ['dept_code'=>$d,'role_code'=>$r,'rbac_layout'=>$rbac_post_layout];
    if ($hl_dept_post !== '') $params['hl_dept'] = $hl_dept_post;
    return u('/rbac/index.php?' . http_build_query($params));
  };
  // rmi_csrf_verify() memanggil exit — tidak bisa ditangkap try/catch. Validasi manual agar flash + redirect.
  $csrfPost = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? $_POST['csrf'] ?? '');
  if ($csrfPost === '' || !function_exists('rmi_csrf_token') || !hash_equals(rmi_csrf_token(), $csrfPost)) {
    rbac_set_flash('bad', 'CSRF token tidak valid.');
    rbac_redirect(u('/rbac/index.php'));
  }

  if (!$rbacManage) {
    rbac_set_flash('bad', 'Akun Anda hanya punya akses baca (SYSTEM.RBAC_VIEW). Perubahan tidak diizinkan.');
    rbac_redirect($rbac_back($postDept, $postRole));
  }

  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'toggle_user_perm') {
      // Override permission satu user via rbac_user_permissions
      $tUid  = (int)($_POST['user_id'] ?? 0);
      $tPerm = strtoupper(trim((string)($_POST['perm_code'] ?? '')));
      $tCur  = (int)($_POST['current'] ?? 0);  // 1=currently allowed, 0=not allowed
      $actor = (string)($_SESSION['username'] ?? 'system');

      if ($tUid > 0 && $tPerm !== '') {
        // Cek apakah sudah ada di user_permissions
        $stChk = $pdo->prepare("SELECT allow_flag FROM rbac_user_permissions WHERE user_id=? AND perm_code=? LIMIT 1");
        $stChk->execute([$tUid, $tPerm]);
        $existing = $stChk->fetch(PDO::FETCH_ASSOC);

        if ($tCur) {
          // Saat ini ALLOWED → revoke: cek dari mana asalnya
          if ($existing !== false) {
            // Ada di user_permissions → hapus override
            $pdo->prepare("DELETE FROM rbac_user_permissions WHERE user_id=? AND perm_code=?")->execute([$tUid, $tPerm]);
          } else {
            // Dari matrix → tambah deny override
            $pdo->prepare("INSERT INTO rbac_user_permissions (user_id,perm_code,allow_flag) VALUES (?,?,0) ON DUPLICATE KEY UPDATE allow_flag=0")->execute([$tUid, $tPerm]);
          }
        } else {
          // Saat ini NOT ALLOWED → grant: tambah allow override
          $pdo->prepare("INSERT INTO rbac_user_permissions (user_id,perm_code,allow_flag) VALUES (?,?,1) ON DUPLICATE KEY UPDATE allow_flag=1")->execute([$tUid, $tPerm]);
        }

        master_audit($pdo,'rbac','rbac_user_permissions','TOGGLE_USER_PERM',
          $tUid, $tPerm,
          ($tCur?"Revoke":"Grant").": {$tPerm} untuk user_id={$tUid} oleh {$actor}", []);
        rbac_set_flash('ok', ($tCur?'✗ Dicabut':'✓ Diberikan').": {$tPerm}");
      } else {
        rbac_set_flash('bad', 'Toggle user perm gagal: user_id atau perm_code tidak valid.');
      }
      $hlDeptBack = strtoupper(trim((string)($_GET['hl_dept'] ?? $_POST['hl_dept'] ?? $postDept)));
      rbac_redirect(u('/rbac/index.php?'.http_build_query([
          'rbac_layout'=>'user',
          'hl_user'=>$tUid,
          'dept_code'=>$postDept,
          'hl_dept'=>$hlDeptBack,
      ])));
    }

    if ($action === 'save_matrix') {
      $rolesPost = rbac_load_roles($pdo, $postDept);
      if (!rbac_index_valid_dept_role($postDept, $postRole, $departements, $rolesPost)) {
        rbac_set_flash('bad', 'Dept atau role tidak valid untuk penyimpanan matrix.');
        rbac_redirect($rbac_back($dept_sel, $role_sel));
      }
      $allow = $_POST['allow'] ?? [];
      if (!is_array($allow)) {
        $allow = [];
      }
      $permSet = rbac_perm_exists_map($pdo);

      $stBefore = $pdo->prepare("SELECT perm_code FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=? AND allow_flag=1 ORDER BY perm_code");
      $stBefore->execute([$postDept, $postRole]);
      $beforeList = [];
      foreach ($stBefore->fetchAll(PDO::FETCH_ASSOC) ?: [] as $br) {
        $pc = rbac_norm((string)($br['perm_code'] ?? ''));
        if ($pc !== '') {
          $beforeList[] = $pc;
        }
      }
      $beforeSet = array_fill_keys($beforeList, true);

      $pdo->beginTransaction();
      $del = $pdo->prepare("DELETE FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=?");
      $del->execute([$postDept, $postRole]);

      $ins = $pdo->prepare("INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES (?,?,?,1)");
      $newList = [];
      foreach ($allow as $pcRaw) {
        $pc = rbac_norm((string)$pcRaw);
        if ($pc === '' || !isset($permSet[$pc])) {
          continue;
        }
        $ins->execute([$postDept, $postRole, $pc]);
        $newList[] = $pc;
      }
      $pdo->commit();

      $newSet = array_fill_keys($newList, true);
      $addedCodes = array_values(array_diff(array_keys($newSet), array_keys($beforeSet)));
      $removedCodes = array_values(array_diff(array_keys($beforeSet), array_keys($newSet)));
      sort($addedCodes, SORT_STRING);
      sort($removedCodes, SORT_STRING);
      $added = count($addedCodes);
      $removed = count($removedCodes);
      $totalAllow = count($newList);

      if (function_exists('master_audit')) {
        master_audit($pdo, 'rbac', 'rbac_dept_role_permissions', 'SAVE_MATRIX', null, "{$postDept}/{$postRole}",
          "RBAC matrix {$postDept}/{$postRole}: allow={$totalAllow}, +{$added}, -{$removed}",
          [
            'dept' => $postDept,
            'role' => $postRole,
            'allow_total' => $totalAllow,
            'added_count' => $added,
            'removed_count' => $removed,
            'added_sample' => array_slice($addedCodes, 0, 40),
            'removed_sample' => array_slice($removedCodes, 0, 40),
          ]);
      }
      $sum = "Rules tersimpan untuk {$postDept} / {$postRole}. Total allow: {$totalAllow}. Ditambah: {$added}, dihapus: {$removed}.";
      rbac_set_flash('ok', $sum);
      rbac_redirect($rbac_back($postDept, $postRole));
    }

    if ($action === 'add_perm') {
      $perm_code = rbac_norm((string)($_POST['perm_code'] ?? ''));
      $perm_name = trim((string)($_POST['perm_name'] ?? ''));
      $module = rbac_norm((string)($_POST['module'] ?? ''));
      $description = trim((string)($_POST['description'] ?? ''));
      if ($perm_code === '' || $perm_name === '' || $module === '') {
        rbac_set_flash('bad', 'perm_code, perm_name, dan module wajib diisi.');
      } else {
        $sql = "INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
                VALUES (?,?,?,?,1)
                ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1";
        $st = $pdo->prepare($sql);
        $st->execute([$perm_code, $perm_name, $module, ($description !== '' ? $description : null)]);
        if (function_exists('master_audit')) {
          master_audit($pdo, 'rbac', 'rbac_permissions', 'ADD_PERM', null, $perm_code, "Permission added: {$perm_code}", ['module' => $module]);
        }
        rbac_set_flash('ok', "Permission {$perm_code} berhasil disimpan.");
      }
      rbac_redirect($rbac_back($postDept, $postRole));
    }

    if ($action === 'sync_permissions') {
      rbac_seed_permissions($pdo, true);
      $src = $GLOBALS['_rbac_seed_source'] ?? '?';
      $cntFile = $GLOBALS['_rbac_seed_count'] ?? 0;
      $cntDb = 0;
      try { $cntDb = (int)$pdo->query("SELECT COUNT(*) FROM rbac_permissions WHERE is_active=1")->fetchColumn(); } catch (Throwable $e) {}
      $srcLabel = $src === 'config' ? 'config/rbac_permissions.php' : 'fallback rbac.php';
      $msg = "Permission registry disinkronkan dari {$srcLabel} ({$cntFile} entries di file → {$cntDb} total aktif di database). Sync bersifat ADDITIVE — tidak ada permission yang dihapus dari database.";
      if (function_exists('master_audit')) {
        master_audit($pdo, 'rbac', 'rbac_permissions', 'SYNC_PERMISSIONS', null, 'REGISTRY', $msg, ['file_count' => $cntFile, 'db_total' => $cntDb]);
      }
      rbac_set_flash('ok', $msg);
      rbac_redirect($rbac_back($postDept, $postRole));
    }

    if ($action === 'prune_orphan_permissions') {
      try {
        $r = rbac_prune_permissions_not_in_config($pdo, false);
        $n = (int)($r['deleted'] ?? 0);
        $list = $r['codes'] ?? [];
        $sample = array_slice($list, 0, 30);
        $tail = count($list) > 30 ? ' … +' . (count($list) - 30) . ' lainnya' : '';
        $detail = $sample !== [] ? ' Kode: ' . implode(', ', $sample) . $tail . '.' : '';
        $msg = "Prune registry: {$n} permission dihapus dari database (tidak ada di config/rbac_permissions.php). Referensi matrix & override terkait ikut terhapus (FK CASCADE)." . $detail;
        if (function_exists('master_audit')) {
          master_audit($pdo, 'rbac', 'rbac_permissions', 'PRUNE_ORPHAN_PERMISSIONS', null, 'REGISTRY', $msg, ['deleted' => $n, 'codes_sample' => $sample]);
        }
        rbac_set_flash('ok', $msg);
      } catch (Throwable $e) {
        rbac_set_flash('bad', 'Prune gagal: ' . $e->getMessage());
      }
      rbac_redirect($rbac_back($postDept, $postRole));
    }

    if ($action === 'grant_all_sys') {
      $permSet = rbac_perm_exists_map($pdo);
      $del = $pdo->prepare("DELETE FROM rbac_dept_role_permissions WHERE dept_code='SYS' AND role_code='SYS'");
      $del->execute();
      $ins = $pdo->prepare("INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES ('SYS','SYS',?,1)");
      $n = 0;
      foreach (array_keys($permSet) as $pc) {
        if ($pc === '') continue;
        try { $ins->execute([$pc]); $n++; } catch (Throwable $e) {}
      }
      if (function_exists('master_audit')) {
        master_audit($pdo, 'rbac', 'rbac_dept_role_permissions', 'GRANT_ALL_SYS', null, 'SYS/SYS', "SYS/SYS granted all {$n} permissions", ['count' => $n]);
      }
      rbac_set_flash('ok', "SYS/SYS diberi semua {$n} permission dari registry.");
      rbac_redirect($rbac_back('SYS', 'SYS'));
    }

    // ── COPY FROM dept/role ──────────────────────────────────────────────
    if ($action === 'copy_from') {
      $srcDept = rbac_norm((string)($_POST['src_dept_code'] ?? ''));
      $srcRole = rbac_norm((string)($_POST['src_role_code'] ?? ''));
      $rolesTarget = rbac_load_roles($pdo, $postDept);
      if (!rbac_index_valid_dept_role($postDept, $postRole, $departements, $rolesTarget)) {
        rbac_set_flash('bad', 'Target dept/role tidak valid.');
        rbac_redirect($rbac_back($dept_sel, $role_sel));
      }
      if ($srcDept === '' || $srcRole === '') {
        rbac_set_flash('bad', 'Pilih sumber dept dan role untuk salin.');
        rbac_redirect($rbac_back($postDept, $postRole));
      }
      if ($srcDept === $postDept && $srcRole === $postRole) {
        rbac_set_flash('warn', 'Sumber sama dengan target — tidak ada yang disalin.');
        rbac_redirect($rbac_back($postDept, $postRole));
      }
      $stSrc = $pdo->prepare("SELECT perm_code FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=? AND allow_flag=1");
      $stSrc->execute([$srcDept, $srcRole]);
      $srcPerms = array_column($stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [], 'perm_code');
      if (!empty($_POST['copy_replace'])) {
        $pdo->prepare("DELETE FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=?")->execute([$postDept, $postRole]);
      }
      $ins = $pdo->prepare("INSERT INTO rbac_dept_role_permissions (dept_code,role_code,perm_code,allow_flag) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE allow_flag=1");
      $n = 0;
      foreach ($srcPerms as $pc) {
        $pc = rbac_norm((string)$pc);
        if ($pc === '') continue;
        try { $ins->execute([$postDept, $postRole, $pc]); $n++; } catch (Throwable $e) {}
      }
      if (function_exists('master_audit')) {
        master_audit($pdo, 'rbac', 'rbac_dept_role_permissions', 'COPY_FROM', null, "{$postDept}/{$postRole}", "Salin dari {$srcDept}/{$srcRole}: {$n} permission", ['src' => "{$srcDept}/{$srcRole}", 'target' => "{$postDept}/{$postRole}", 'replace' => !empty($_POST['copy_replace']), 'count' => $n]);
      }
      rbac_set_flash('ok', "Disalin dari {$srcDept}/{$srcRole} → {$postDept}/{$postRole}: {$n} permission." . (!empty($_POST['copy_replace']) ? ' (replace)' : ' (merge)'));
      rbac_redirect($rbac_back($postDept, $postRole));
    }

    // ── APPLY PRESET (paket cepat VIEW / OPS / PANDUAN) ─────────────────
    if ($action === 'apply_preset') {
      $preset = (string)($_POST['preset_name'] ?? '');
      $rolesTarget = rbac_load_roles($pdo, $postDept);
      if (!rbac_index_valid_dept_role($postDept, $postRole, $departements, $rolesTarget)) {
        rbac_set_flash('bad', 'Dept/role tidak valid.');
        rbac_redirect($rbac_back($dept_sel, $role_sel));
      }
      $allRegistryPerms = $pdo->query("SELECT perm_code FROM rbac_permissions WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN) ?: [];

      $matchFn = match ($preset) {
        'view_only' => static fn(string $p): bool =>
          str_ends_with($p, '.VIEW') || str_ends_with($p, '_VIEW'),

        'ops_standard' => static fn(string $p): bool =>
          preg_match('/\.(VIEW|CREATE|EDIT|EXPORT|IMPORT|PRINT|PROCESS)$/', $p) === 1
          && !preg_match('/\.(APPROVE|DELETE|ADMIN|GRANT|MANAGE|CONFIG|USER_MANAGE|RBAC|PAYMENT_APPROVE|GL_REVERSAL|AP_PAYMENT_APPROVE)/', $p),

        'ops_full' => static fn(string $p): bool =>
          !preg_match('/\.(MANAGE|GRANT|RBAC|USER_MANAGE|ADMIN_CENTER|CONFIG_MANAGE|PAYMENT_APPROVE|GL_REVERSAL|AP_PAYMENT_APPROVE_POST)/', $p),

        'panduan_only' => static fn(string $p): bool =>
          str_starts_with($p, 'PANDUAN.'),

        'hrl_process' => static fn(string $p): bool =>
          str_starts_with($p, 'HRL.') || str_starts_with($p, 'HRL_PROCESS.') || str_starts_with($p, 'PANDUAN.'),

        'absensi_kpi' => static fn(string $p): bool =>
          str_starts_with($p, 'ABSENSI.') || str_starts_with($p, 'KPI.') || str_starts_with($p, 'CHAT.'),

        default => null,
      };
      if ($matchFn === null) {
        rbac_set_flash('bad', 'Preset tidak dikenal.');
        rbac_redirect($rbac_back($postDept, $postRole));
      }
      $toAdd = array_filter($allRegistryPerms, $matchFn);
      if (!empty($_POST['preset_replace'])) {
        $pdo->prepare("DELETE FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=?")->execute([$postDept, $postRole]);
      }
      $ins = $pdo->prepare("INSERT INTO rbac_dept_role_permissions (dept_code,role_code,perm_code,allow_flag) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE allow_flag=1");
      $n = 0;
      foreach ($toAdd as $pc) {
        $pc = rbac_norm((string)$pc);
        if ($pc === '') continue;
        try { $ins->execute([$postDept, $postRole, $pc]); $n++; } catch (Throwable $e) {}
      }
      if (function_exists('master_audit')) {
        master_audit($pdo, 'rbac', 'rbac_dept_role_permissions', 'APPLY_PRESET', null, "{$postDept}/{$postRole}", "Preset '{$preset}' diterapkan: {$n} permission", ['preset' => $preset, 'dept' => $postDept, 'role' => $postRole, 'count' => $n, 'replace' => !empty($_POST['preset_replace'])]);
      }
      rbac_set_flash('ok', "Preset '{$preset}' diterapkan ke {$postDept}/{$postRole}: {$n} permission." . (!empty($_POST['preset_replace']) ? ' (replace)' : ' (merge)'));
      rbac_redirect($rbac_back($postDept, $postRole));
    }

    if ($action === 'apply_baseline') {
      if (!empty($_POST['replace_rules'])) {
        $pdo->exec("DELETE FROM rbac_dept_role_permissions");
      }
      if (!empty($_POST['replace_user_overrides'])) {
        $pdo->exec("DELETE FROM rbac_user_permissions");
      }
      rbac_apply_baseline($pdo);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'rbac', 'rbac_dept_role_permissions', 'APPLY_BASELINE', null, 'BASELINE', 'RBAC baseline applied', ['replace_rules' => !empty($_POST['replace_rules']), 'replace_user_overrides' => !empty($_POST['replace_user_overrides'])]);
      }
      rbac_set_flash('ok', 'RBAC Default berhasil diterapkan.');
      rbac_redirect(u('/rbac/index.php?dept_code=' . urlencode($postDept) . '&role_code=' . urlencode($postRole)));
    }

    if ($action === 'import_json') {
      $rolesImport = rbac_load_roles($pdo, $postDept);
      if (!rbac_index_valid_dept_role($postDept, $postRole, $departements, $rolesImport)) {
        rbac_set_flash('bad', 'Dept atau role tidak valid untuk import JSON.');
        rbac_redirect(u('/rbac/index.php?dept_code=' . urlencode($dept_sel) . '&role_code=' . urlencode($role_sel)));
      }
      if (!isset($_FILES['import_file']) || (int)($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        rbac_set_flash('bad', 'File JSON belum dipilih / upload gagal.');
        rbac_redirect(u('/rbac/index.php?dept_code=' . urlencode($postDept) . '&role_code=' . urlencode($postRole)));
      }
      $raw = @file_get_contents((string)($_FILES['import_file']['tmp_name'] ?? ''));
      $data = json_decode((string)$raw, true);
      if (!is_array($data)) {
        rbac_set_flash('bad', 'Format JSON tidak valid.');
        rbac_redirect(u('/rbac/index.php?dept_code=' . urlencode($postDept) . '&role_code=' . urlencode($postRole)));
      }
      $allow = $data['allow'] ?? $data;
      if (!is_array($allow)) $allow = [];
      $permSet = rbac_perm_exists_map($pdo);

      $pdo->beginTransaction();
      if (!empty($_POST['import_replace'])) {
        $del = $pdo->prepare("DELETE FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=?");
        $del->execute([$postDept, $postRole]);
      }
      $ins = $pdo->prepare("INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag)
                            VALUES (?,?,?,1)
                            ON DUPLICATE KEY UPDATE allow_flag=1");
      $n = 0;
      foreach ($allow as $pcRaw) {
        $pc = rbac_norm((string)$pcRaw);
        if ($pc === '' || !isset($permSet[$pc])) continue;
        $ins->execute([$postDept, $postRole, $pc]);
        $n++;
      }
      $pdo->commit();
      if (function_exists('master_audit')) {
        master_audit($pdo, 'rbac', 'rbac_dept_role_permissions', 'IMPORT_JSON', null, "{$postDept}/{$postRole}", "RBAC JSON import: {$n} permissions", ['dept' => $postDept, 'role' => $postRole, 'count' => $n]);
      }
      rbac_set_flash('ok', "Import JSON selesai. Total permission diterapkan: {$n}");
      rbac_redirect(u('/rbac/index.php?dept_code=' . urlencode($postDept) . '&role_code=' . urlencode($postRole)));
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    if (function_exists('rmi_log_module_error')) {
        rmi_log_module_error('rbac', $e, ['action' => $action ?? '', 'dept' => $postDept ?? '', 'role' => $postRole ?? '']);
    }
    $rawMsg = $e->getMessage();
    $safeMsg = (stripos($rawMsg, 'SQLSTATE') !== false || stripos($rawMsg, 'PDO') !== false)
      ? 'Kesalahan database. Periksa log server atau hubungi IT.'
      : $rawMsg;
    rbac_set_flash('bad', 'Operasi gagal: ' . $safeMsg);
    rbac_redirect(u('/rbac/index.php?dept_code=' . urlencode($postDept) . '&role_code=' . urlencode($postRole)));
  }
}

if (($_GET['action'] ?? '') === 'export_json') {
  if (!$rbacManage) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: export JSON memerlukan SYSTEM.RBAC_MANAGE.';
    exit;
  }
  $qDept = rbac_norm((string)($_GET['dept_code'] ?? $dept_sel));
  $qRole = rbac_norm((string)($_GET['role_code'] ?? $role_sel));
  $st = $pdo->prepare("SELECT perm_code FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=? AND allow_flag=1 ORDER BY perm_code");
  $st->execute([$qDept, $qRole]);
  $allow = array_values(array_filter(array_map(static fn($r) => (string)($r['perm_code'] ?? ''), $st->fetchAll(PDO::FETCH_ASSOC) ?: [])));

  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="rbac_' . strtolower($qDept) . '_' . strtolower($qRole) . '.json"');
  echo json_encode(['dept_code' => $qDept, 'role_code' => $qRole, 'allow' => $allow], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

$rules_map = [];
if ($dept_sel !== '' && $role_sel !== '') {
  $stRules = $pdo->prepare("SELECT perm_code, allow_flag FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=?");
  $stRules->execute([$dept_sel, $role_sel]);
  foreach (($stRules->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    if ((int)($r['allow_flag'] ?? 0) === 1) {
      $rules_map[(string)$r['perm_code']] = 1;
    }
  }
}

$allPerms = [];
try {
  $allPerms = $pdo->query("SELECT perm_code, perm_name, module, description, is_active FROM rbac_permissions ORDER BY module, perm_code")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $allPerms = [];
}
$permCount = count($allPerms);
$permIncomplete = ($permCount < 40);
$byModule = [];
$byModuleMeta = [];
foreach ($allPerms as $p) {
  $m = rbac_norm((string)($p['module'] ?? 'OTHER'));
  if ($m === '') {
    $m = 'OTHER';
  }
  if (!isset($byModule[$m])) {
    $byModule[$m] = [];
  }
  if (!isset($byModuleMeta[$m])) {
    $byModuleMeta[$m] = [
      'view_count' => 0,
      'non_view_count' => 0,
      'categories' => [],
    ];
  }
  $cat = rbac_perm_category((string)($p['perm_code'] ?? ''));
  if ($cat === 'VIEW') {
    $byModuleMeta[$m]['view_count']++;
  } else {
    $byModuleMeta[$m]['non_view_count']++;
  }
  $byModuleMeta[$m]['categories'][$cat] = (int)($byModuleMeta[$m]['categories'][$cat] ?? 0) + 1;
  $p['category'] = $cat;
  $p['staff_mgr_tier'] = rbac_perm_staff_mgr_tier((string)($p['perm_code'] ?? ''), $cat);
  $byModule[$m][] = $p;
}

$csrf = rmi_csrf_token();
$matrixInitialJson = json_encode(array_keys($rules_map), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
// ── Per User Active: load global vars ────────────────────────────────────────
$hl_user_id     = (int)($_GET['hl_user'] ?? 0);
$hl_user_row    = null;
$hl_user_bundle = null;
$hl_effective_map = [];
$hl_is_privileged = false;
if ($hl_user_id > 0 && $pdo) {
    try {
        $stHU = $pdo->prepare("SELECT id,username,full_name,department,role,level FROM master_system_login WHERE id=? AND status='active' LIMIT 1");
        $stHU->execute([$hl_user_id]);
        $hl_user_row = $stHU->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($hl_user_row && function_exists('rbac_index_compute_effective')) {
            $hl_user_bundle = rbac_index_compute_effective($pdo, $hl_user_row);
        }
    } catch (Throwable $e) {}
}
if (is_array($hl_user_bundle)) {
    $hl_is_privileged = !empty($hl_user_bundle['privileged']);
    if (!$hl_is_privileged) {
        foreach ($hl_user_bundle['rows'] ?? [] as $er) {
            $hl_effective_map[(string)($er['perm'] ?? '')] = true;
        }
    }
}
$hl_override_map = [];
if ($hl_user_id > 0 && $pdo && is_array($hl_user_row) && is_array($hl_user_bundle) && empty($hl_user_bundle['privileged'])) {
    try {
        $stOv = $pdo->prepare('SELECT perm_code, allow_flag FROM rbac_user_permissions WHERE user_id=?');
        $stOv->execute([$hl_user_id]);
        foreach ($stOv->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $opc = rbac_norm((string)($row['perm_code'] ?? ''));
            if ($opc !== '') {
                $hl_override_map[$opc] = ((int)($row['allow_flag'] ?? 0) === 1) ? 1 : 0;
            }
        }
    } catch (Throwable $e) {
        $hl_override_map = [];
    }
}
// ─────────────────────────────────────────────────────────────────────────────

$rbacQueryNav = ['dept_code' => $dept_sel, 'role_code' => $role_sel, 'rbac_layout' => $rbac_matrix_layout];
if ($effectiveUserId > 0) {
  $rbacQueryNav['effective_user'] = $effectiveUserId;
}
if ($hl_user_id > 0) {
  $rbacQueryNav['hl_user'] = $hl_user_id;
}
$rbacUrlModule = u('/rbac/index.php?' . http_build_query(array_merge($rbacQueryNav, ['rbac_layout' => 'module'])));
$rbac_sys_matrix_url = u('/rbac/index.php?' . http_build_query([
  'dept_code' => 'SYS',
  'role_code' => 'SYS',
  'rbac_layout' => 'module',
]));
$rbac_is_sys_sys_context = ($dept_sel === 'SYS' && $role_sel === 'SYS');

$audit_rows = [];
if (function_exists('master_audit')) {
  try {
    $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='rbac' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {}
}

rmi_header('RBAC Center', [
  'active' => 'rbac',
  'breadcrumbs' => [
    ['label' => 'RBAC', 'url' => $baseProject . '/rbac/index.php'],
    'RBAC Center',
  ],
  'extra_head' => '
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<style>
    :root{
  --bg:#080d17;--panel:rgba(13,20,38,.9);--border:rgba(99,128,185,.18);
  --text:#e2e8f0;--muted:#64748b;--muted-lt:#94a3b8;
  --ok:#22c55e;--bad:#ef4444;--warn:#f59e0b;--info:#38bdf8;
  --blue:#3b82f6;--indigo:#6366f1;
  --r:12px;--shadow:0 12px 40px rgba(0,0,0,.4);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Plus Jakarta Sans",system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:13px}
.rc-wrap{max-width:1380px;margin:0 auto;padding:20px 18px 40px}

/* ── Page Header ── */
.rc-header{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;margin-bottom:16px}
.rc-title{font-size:21px;font-weight:800;letter-spacing:-.3px;color:#f1f5f9}
.rc-subtitle{color:var(--muted-lt);font-size:12px;margin-top:4px;line-height:1.5;max-width:700px}
.rc-topbtns{display:flex;gap:7px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid var(--border);text-decoration:none;color:var(--text);background:rgba(255,255,255,.05);font-size:12px;font-weight:600;cursor:pointer;transition:all .18s;font-family:inherit}
.btn:hover{background:rgba(255,255,255,.1);color:#fff}
.btn.primary{background:linear-gradient(135deg,#2563eb,#4f46e5);border-color:rgba(99,130,250,.4);color:#fff}
.btn.primary:hover{filter:brightness(1.1)}
.btn.danger{border-color:rgba(239,68,68,.35);color:#fca5a5}
.btn.danger:hover{background:rgba(239,68,68,.15)}
.btn.good{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.35);color:#86efac}
.btn.good:hover{background:rgba(34,197,94,.25)}
.btn.warn-btn{background:rgba(245,158,11,.13);border-color:rgba(245,158,11,.35);color:#fcd34d}
.btn.sm{padding:5px 10px;font-size:11px}

/* ── Flash ── */
.flash{padding:12px 16px;border-radius:10px;border:1px solid var(--border);margin-bottom:14px;font-size:13px}
.flash.ok{border-color:rgba(34,197,94,.4);background:rgba(34,197,94,.1);color:#86efac}
.flash.bad{border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.1);color:#fca5a5}
.flash.warn{border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08);color:#fcd34d}

/* ── Stats bar ── */
.rc-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.rc-stat{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:10px}
.rc-stat-val{font-size:22px;font-weight:800;color:#fff}
.rc-stat-lbl{font-size:11px;color:var(--muted-lt);font-weight:500}

/* ── Layout ── */
.rc-layout{display:grid;grid-template-columns:1fr;gap:14px}
@media(max-width:1000px){.rc-layout{grid-template-columns:1fr}}

/* ── Left sidebar (sticky) ── */
.rc-sidebar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:var(--r);margin-bottom:14px}
.rc-sidebar-hidden-scroll{display:none}

.rc-panel{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;flex:1 1 220px;min-width:200px;max-width:340px;backdrop-filter:blur(10px)}
.rc-panel-head{padding:12px 16px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02)}
.rc-panel-head h3{font-size:13px;font-weight:700;color:#f1f5f9}
.rc-panel-body{padding:14px 16px}

/* Tabs */
.rc-tabs{display:flex;border-bottom:1px solid var(--border);overflow-x:auto;scrollbar-width:none}
.rc-tabs::-webkit-scrollbar{display:none}
.rc-tab{padding:9px 14px;font-size:11px;font-weight:600;color:var(--muted-lt);cursor:pointer;white-space:nowrap;border-bottom:2px solid transparent;transition:all .18s;user-select:none}
.rc-tab:hover{color:#fff;background:rgba(255,255,255,.04)}
.rc-tab.active{color:#60a5fa;border-bottom-color:#3b82f6}
.rc-tab-panel{display:none;padding:14px 16px}
.rc-tab-panel.active{display:block}

/* Forms */
.field{margin-bottom:12px}
.field label{display:block;font-size:11px;font-weight:600;color:var(--muted-lt);text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.field select,.field input[type="text"],.field textarea,.field input[type="file"]{
  width:100%;background:rgba(8,13,23,.8);border:1px solid rgba(99,128,185,.22);
  color:var(--text);border-radius:8px;padding:9px 11px;font-size:12px;font-family:inherit;
  outline:none;transition:border-color .18s
}
.field select:focus,.field input:focus,.field textarea:focus{border-color:rgba(99,130,250,.5);box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.field textarea{min-height:72px;resize:vertical}
.field-note{font-size:11px;color:var(--muted);margin-top:5px;line-height:1.4}
.chk-row{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:12px;color:var(--muted-lt);cursor:pointer}
.chk-row input{width:14px;height:14px;accent-color:var(--blue);cursor:pointer}
.sep{height:1px;background:var(--border);margin:12px 0}
.info-box{background:rgba(99,128,185,.07);border:1px solid rgba(99,128,185,.18);border-radius:8px;padding:10px 12px;font-size:12px;color:var(--muted-lt);line-height:1.5}
.info-box .pc{color:#93c5fd;background:rgba(99,128,185,.14);padding:1px 5px;border-radius:4px;font-family:monospace;font-size:11px}
.rc-mini-head{font-size:11px;font-weight:700;color:var(--muted-lt);margin-bottom:6px}
.rc-flow{margin:10px 0 0;padding-left:18px;color:var(--muted-lt);font-size:11.5px;line-height:1.55;list-style:decimal}
.rc-flow li{margin:3px 0}
.rc-code-block{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;line-height:1.45;background:rgba(0,0,0,.28);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-top:8px;white-space:pre-wrap;word-break:break-all;color:#cbd5e1}
.rc-panel--spaced{margin-top:14px}
.rc-effective-scroll{max-height:340px;overflow:auto;border:1px solid var(--border);border-radius:8px}
.rc-effective-form .field select{max-width:520px}
/* ══ PERMISSION EFEKTIF & AUDIT LOG ══════════════════════ */

/* Section wrapper */
.rc-section {
  background:var(--panel);border:1px solid var(--border);border-radius:var(--r);
  overflow:hidden;margin-top:16px;
}
.rc-section-head {
  padding:12px 18px;border-bottom:1px solid var(--border);
  background:rgba(255,255,255,.02);
  display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.rc-section-head h3 {font-size:13px;font-weight:700;color:#f1f5f9;margin:0}
.rc-section-body {padding:16px 18px}

/* Permission efektif */
.eff-user-select {
  width:100%;max-width:560px;background:rgba(8,13,23,.8);
  border:1px solid rgba(99,128,185,.25);color:#e2e8f0;
  border-radius:8px;padding:8px 12px;font-size:12px;font-family:inherit;
}
.eff-meta {
  display:flex;gap:12px;flex-wrap:wrap;margin:12px 0 10px;
  padding:10px 14px;background:rgba(255,255,255,.03);
  border:1px solid var(--border);border-radius:8px;
}
.eff-meta-item {font-size:11px;color:var(--muted-lt)}
.eff-meta-item b {color:#f1f5f9}
.eff-scroll {max-height:360px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;scrollbar-width:thin;scrollbar-color:rgba(99,128,185,.25) transparent}
.eff-scroll::-webkit-scrollbar{width:4px}
.eff-scroll::-webkit-scrollbar-thumb{background:rgba(99,128,185,.25);border-radius:4px}
.eff-tbl {width:100%;border-collapse:collapse;font-size:12px}
.eff-tbl thead th {
  padding:8px 14px;text-align:left;font-size:10px;font-weight:700;
  color:var(--muted-lt);text-transform:uppercase;letter-spacing:.05em;
  background:rgba(0,0,0,.2);position:sticky;top:0;
}
.eff-tbl tbody tr {border-top:1px solid rgba(99,128,185,.08);transition:background .12s}
.eff-tbl tbody tr:hover {background:rgba(255,255,255,.03)}
.eff-tbl td {padding:7px 14px;vertical-align:middle}
.eff-mod-badge {
  display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700;
  background:rgba(99,128,185,.15);color:#94a3b8;font-family:ui-monospace,monospace;
}
/* Source badges */
.eff-src {display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap}
.eff-src-matrix   {background:rgba(59,130,246,.15);color:#93c5fd;border:1px solid rgba(59,130,246,.25)}
.eff-src-override {background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.25)}
.eff-src-both     {background:rgba(34,197,94,.15); color:#86efac;border:1px solid rgba(34,197,94,.25)}

/* Audit log */
.audit-tbl {width:100%;border-collapse:collapse;font-size:12px}
.audit-tbl thead th {
  padding:9px 14px;text-align:left;font-size:10px;font-weight:700;
  color:var(--muted-lt);text-transform:uppercase;letter-spacing:.05em;
  background:rgba(0,0,0,.2);position:sticky;top:0;
}
.audit-tbl tbody tr {border-top:1px solid rgba(99,128,185,.07);transition:background .12s}
.audit-tbl tbody tr:hover {background:rgba(255,255,255,.025)}
.audit-tbl td {padding:8px 14px;vertical-align:middle}
.audit-scroll {max-height:420px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;scrollbar-width:thin;scrollbar-color:rgba(99,128,185,.25) transparent}
.audit-scroll::-webkit-scrollbar{width:4px}
.audit-scroll::-webkit-scrollbar-thumb{background:rgba(99,128,185,.25);border-radius:4px}
.audit-time {font-size:11px;color:var(--muted);white-space:nowrap;font-family:ui-monospace,monospace}
.audit-user {font-size:11px;font-weight:600;color:#e2e8f0}
.audit-code {font-family:ui-monospace,monospace;font-size:11px;color:#c7d2fe}
.audit-desc {font-size:11px;color:var(--muted-lt);max-width:320px;line-height:1.4}
/* Action badges audit */
.audit-act {display:inline-block;padding:2px 9px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap}
.audit-act-SAVE,.audit-act-SAVE_MATRIX    {background:rgba(59,130,246,.18);color:#93c5fd;border:1px solid rgba(59,130,246,.3)}
.audit-act-SYNC                           {background:rgba(34,197,94,.15); color:#86efac;border:1px solid rgba(34,197,94,.25)}
.audit-act-DELETE,.audit-act-PRUNE        {background:rgba(239,68,68,.15); color:#fca5a5;border:1px solid rgba(239,68,68,.25)}
.audit-act-GRANT,.audit-act-GRANT_ALL     {background:rgba(168,85,247,.15);color:#d8b4fe;border:1px solid rgba(168,85,247,.25)}
.audit-act-IMPORT,.audit-act-COPY         {background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.25)}
.audit-act-BASELINE,.audit-act-PRESET     {background:rgba(6,182,212,.12); color:#67e8f9;border:1px solid rgba(6,182,212,.2)}
.audit-act-default                        {background:rgba(100,116,139,.15);color:#94a3b8;border:1px solid rgba(100,116,139,.2)}
.audit-empty {padding:32px;text-align:center;color:var(--muted-lt);font-size:13px}

.link-inline{color:#60a5fa;text-decoration:none}
.link-inline:hover{text-decoration:underline}
.eff-src-badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700}
.eff-src-matrix{background:rgba(59,130,246,.18);color:#93c5fd}
.eff-src-override{background:rgba(168,85,247,.2);color:#d8b4fe}
.eff-src-both{background:rgba(34,197,94,.15);color:#86efac}

/* Dept cards */
.dept-grid{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px}
.dept-card{padding:4px 10px;border-radius:7px;border:1px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;transition:all .18s;display:inline-flex;flex-direction:column;align-items:center;min-width:52px}
.dept-card:hover{background:rgba(255,255,255,.07);border-color:rgba(99,130,250,.4)}
.dept-card.active{background:linear-gradient(135deg,rgba(37,99,235,.25),rgba(79,70,229,.2));border-color:rgba(99,130,250,.6);color:#93c5fd}
.dept-card .dc{font-size:9px;font-weight:800;line-height:1.2}
.dept-card .dn{font-size:8px;color:var(--muted);margin-top:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dept-card--sys{border-color:rgba(251,191,36,.42);background:rgba(245,158,11,.09)}
.dept-card--sys:hover{border-color:rgba(252,211,77,.55);background:rgba(245,158,11,.14)}
.dept-card--sys.active{background:linear-gradient(135deg,rgba(245,158,11,.28),rgba(217,119,6,.2));border-color:rgba(252,211,77,.65);color:#fde68a}
.dept-card--sys .dc{color:#fcd34d}

/* SYS quick lane */
.rc-sys-lane{border-color:rgba(251,191,36,.28)}
.rc-sys-lane .rc-panel-head{background:rgba(245,158,11,.06);border-bottom-color:rgba(251,191,36,.22)}
.rc-sys-lane .rc-panel-head h3{color:#fde68a}
.rc-sys-lane .btn-row-sys{display:flex;flex-direction:row;gap:6px;flex-wrap:wrap}
.matrix-sys-banner{margin-top:12px;padding:10px 14px;border-radius:10px;border:1px solid rgba(251,191,36,.35);background:rgba(245,158,11,.1);font-size:12px;color:#fde68a;line-height:1.5}
.matrix-sys-banner b{color:#fff}
.matrix-sys-banner .pc{color:#fcd34d}
/* SYS bar di dalam panel Permission Matrix (sticky) */
.matrix-context-bar{margin-top:10px;padding:9px 12px;border-radius:10px;border:1px solid rgba(251,191,36,.32);background:rgba(245,158,11,.08)}
.matrix-context-bar-inner{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px}
.matrix-context-title{font-size:10px;font-weight:800;color:#fcd34d;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
.matrix-context-note{font-size:11px;color:var(--muted-lt);line-height:1.45;flex:1;min-width:min(100%,220px)}
/* Tab tampilan matrix (mirip ide Nav Manager: pilih kelompok) */
.rc-matrix-tabs{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:10px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(0,0,0,.18)}
.rc-matrix-tabs .lbl{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-right:4px}
.rc-matrix-tabs a{text-decoration:none;display:inline-flex;align-items:center;padding:6px 12px;border-radius:999px;font-size:11px;font-weight:700;border:1px solid var(--border);color:var(--muted-lt);background:rgba(255,255,255,.04);transition:all .18s}
.rc-matrix-tabs a:hover{color:#fff;border-color:rgba(99,130,250,.45);background:rgba(99,130,250,.1)}
.rc-matrix-tabs a.active{color:#93c5fd;border-color:rgba(99,130,250,.55);background:rgba(37,99,235,.2);box-shadow:0 0 0 1px rgba(99,130,250,.15)}
/* Kelompok per tipe (VIEW, DELETE, …) */
.cat-block{border:1px solid var(--border);border-radius:10px;margin-bottom:12px;overflow:hidden;background:rgba(0,0,0,.12)}
.cat-block-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 14px;cursor:pointer;user-select:none;background:rgba(255,255,255,.03);border-bottom:1px solid var(--border)}
.cat-block-head:hover{background:rgba(255,255,255,.06)}
.cat-block-title{font-size:12px;font-weight:800;color:#e2e8f0;flex:1}
.cat-block-count{font-size:11px;color:var(--muted-lt);font-weight:700}
.cat-block .mod-progress-wrap{width:72px}
.cat-chevron{font-size:11px;color:var(--muted);margin-left:auto;transition:transform .2s}
.cat-block.collapsed .cat-chevron{transform:rotate(-90deg)}
.cat-block.collapsed .cat-block-body{display:none}
.cat-block-body{padding:8px 10px 10px;background:rgba(0,0,0,.08)}
.mod-block--nested{margin-bottom:8px;border-radius:8px}
.mod-block--nested .mod-head{padding:8px 12px}
.mod-block--nested .mod-name{font-size:11px}

/* Role pills */
.role-pills{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px}
.role-pill{padding:3px 10px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.04);cursor:pointer;font-size:10px;font-weight:700;transition:all .18s;color:var(--muted-lt)}
.role-pill:hover{background:rgba(255,255,255,.09);color:#fff}
.role-pill.active{background:linear-gradient(135deg,#2563eb,#4f46e5);border-color:rgba(99,130,250,.5);color:#fff}

/* ── Right panel — Permission Matrix ── */
.rc-matrix{background:var(--panel);border:1px solid var(--border);border-radius:var(--r);backdrop-filter:blur(10px)}
.matrix-sticky{position:sticky;top:0;z-index:20;background:rgba(8,13,23,.97);border-bottom:1px solid var(--border);padding:14px 18px;backdrop-filter:blur(16px)}
.matrix-title-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.matrix-badge{display:inline-flex;align-items:center;gap:8px;padding:5px 12px;border-radius:8px;background:rgba(99,128,185,.1);border:1px solid var(--border);font-size:12px}
.matrix-badge b{color:#93c5fd}
.matrix-count{font-size:12px;color:var(--muted-lt);margin-left:auto}
.matrix-search-row{display:flex;gap:8px;margin-bottom:10px}
.matrix-search{display:flex;align-items:center;gap:7px;flex:1;background:rgba(8,13,23,.8);border:1px solid rgba(99,128,185,.22);border-radius:8px;padding:8px 12px;transition:border-color .18s}
.matrix-search:focus-within{border-color:rgba(99,130,250,.5)}
.matrix-search input{border:0;background:transparent;color:var(--text);font-size:12px;outline:none;width:100%;font-family:inherit}
.matrix-catsel{background:rgba(8,13,23,.8);border:1px solid rgba(99,128,185,.22);color:var(--text);border-radius:8px;padding:8px 10px;font-size:12px;font-family:inherit;outline:none}
.matrix-actions{display:flex;gap:7px;flex-wrap:wrap}

/* Module blocks */
.matrix-body{padding:14px 18px}
.mod-block{border:1px solid var(--border);border-radius:10px;margin-bottom:10px;overflow:hidden;transition:border-color .18s}
.mod-block:hover{border-color:rgba(99,128,185,.32)}
.mod-head{display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(255,255,255,.025);cursor:pointer;user-select:none;transition:background .18s}
.mod-head:hover{background:rgba(255,255,255,.05)}
.mod-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.mod-name{font-size:12px;font-weight:800;flex:1}
.mod-progress-wrap{width:80px}
.mod-progress{height:4px;background:rgba(99,128,185,.15);border-radius:999px;overflow:hidden;margin-top:3px}
.mod-progress-bar{height:100%;background:linear-gradient(90deg,#2563eb,#818cf8);border-radius:999px;transition:width .4s}
.mod-progress-bar.full{background:linear-gradient(90deg,#16a34a,#22c55e)}
.mod-progress-bar.none{background:rgba(99,128,185,.3)}
.mod-count-txt{font-size:10px;color:var(--muted-lt);text-align:right}
.mod-chevron{font-size:11px;color:var(--muted);transition:transform .2s;flex-shrink:0}
.mod-block.collapsed .mod-chevron{transform:rotate(-90deg)}
.mod-block.collapsed .mod-content{display:none}
.mod-actions{display:flex;gap:5px;padding:7px 14px;background:rgba(0,0,0,.12);border-bottom:1px solid var(--border)}
.mod-actions .btn{padding:4px 9px;font-size:10px}

/* Permission rows — toggle switch style */
.perm-table{width:100%;border-collapse:collapse}
.perm-table td{padding:9px 14px;border-bottom:1px solid rgba(99,128,185,.08);vertical-align:middle;font-size:12px}
.perm-table tr:last-child td{border-bottom:0}
.perm-table tr:hover td{background:rgba(99,128,185,.05)}
.perm-toggle-col{width:54px;text-align:center}

/* Toggle switch */
.toggle{position:relative;display:inline-block;width:36px;height:20px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0;position:absolute}
.toggle-slider{position:absolute;inset:0;background:rgba(99,128,185,.2);border-radius:999px;cursor:pointer;transition:all .2s;border:1px solid rgba(99,128,185,.3)}
.toggle input:checked+.toggle-slider{background:linear-gradient(135deg,#2563eb,#4f46e5);border-color:rgba(99,130,250,.5)}
.toggle-slider::after{content:"";position:absolute;width:14px;height:14px;background:#fff;border-radius:50%;top:2px;left:2px;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.4)}
.toggle input:checked+.toggle-slider::after{transform:translateX(16px)}
.toggle input:disabled+.toggle-slider{opacity:.4;cursor:not-allowed}

/* Category badges */
.cat-badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;margin-left:6px}
.cat-VIEW    {background:rgba(59,130,246,.2); color:#93c5fd}
.cat-CREATE  {background:rgba(34,197,94,.15); color:#86efac}
.cat-EDIT    {background:rgba(99,102,241,.2); color:#c4b5fd}
.cat-DELETE  {background:rgba(239,68,68,.2);  color:#fca5a5}
.cat-APPROVE {background:rgba(168,85,247,.2); color:#d8b4fe}
.cat-IMPORT,
.cat-EXPORT  {background:rgba(6,182,212,.15); color:#67e8f9}
.cat-AUDIT   {background:rgba(251,191,36,.15);color:#fde68a}
.cat-PROCESS {background:rgba(245,158,11,.15);color:#fbbf24}
.cat-API     {background:rgba(99,128,185,.2); color:#bfdbfe}
.cat-SETTINGS{background:rgba(239,68,68,.15); color:#fca5a5}
.cat-OTHER   {background:rgba(100,116,139,.2);color:#94a3b8}

.pc{font-family:ui-monospace,Menlo,monospace;font-size:11px;color:#c7d2fe}
.perm-name{font-weight:600;color:#e2e8f0}
.perm-desc{font-size:11px;color:var(--muted);margin-top:2px;line-height:1.4}
    .tiny{font-size:11px;color:var(--muted)}

/* ══ SIDEBAR ATAS — RAPIH ══════════════════════════════════
   Sidebar jadi 2 baris bersih:
   Baris 1: DEPT tabs (horizontal, pill style)
   Baris 2: ROLE pills + Tampilkan + Export + Tools + SYS shortcut
══════════════════════════════════════════════════════════ */

/* Sidebar container: kolom vertikal (2 baris) */
.rc-sidebar {
  flex-direction: column !important;
  gap: 0 !important;
  padding: 0 !important;
  border-radius: var(--r) !important;
  overflow: hidden !important;
}

/* Semua panel di dalam sidebar: full-width, tanpa border/background sendiri */
.rc-sidebar .rc-panel {
  flex: 0 0 100% !important;
  min-width: 100% !important;
  max-width: 100% !important;
  border: none !important;
  border-bottom: 1px solid var(--border) !important;
  border-radius: 0 !important;
  background: transparent !important;
  margin-bottom: 0 !important;
}

/* Sembunyikan panel yang bukan dept+role selector */
.rc-sidebar .rc-sys-lane { display: none !important; }

/* Sembunyikan panel info dan health/QA */
.rc-sidebar .rc-panel:not(:nth-child(2)) .rc-panel-head { display: none !important; }
.rc-sidebar .rc-panel:not(:nth-child(2)) .rc-panel-body { display: none !important; }

/* === BARIS 1: Dept selector === */
.rc-sidebar .rc-panel:nth-child(2) .rc-panel-head {
  padding: 0 14px !important;
  background: rgba(255,255,255,.02) !important;
  border-bottom: 1px solid var(--border) !important;
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  min-height: 44px !important;
}
.rc-sidebar .rc-panel:nth-child(2) .rc-panel-head h3 {
  font-size: 10px !important;
  font-weight: 700 !important;
  letter-spacing: .06em !important;
  text-transform: uppercase !important;
  color: var(--muted) !important;
  white-space: nowrap !important;
  margin-right: 4px !important;
}

/* Dept grid di dalam panel head area: tampilkan horizontal */
.dept-grid {
  display: flex !important;
  flex-wrap: nowrap !important;
  gap: 4px !important;
  overflow-x: auto !important;
  scrollbar-width: none !important;
  padding: 6px 0 !important;
}
.dept-grid::-webkit-scrollbar { display: none !important; }

/* Dept card: gaya pill/tab horizontal bersih */
.dept-card {
  padding: 4px 12px !important;
  border-radius: 20px !important;
  min-width: auto !important;
  white-space: nowrap !important;
  flex-direction: row !important;
  align-items: center !important;
  gap: 0 !important;
  flex-shrink: 0 !important;
}
.dept-card .dc {
  font-size: 11px !important;
  font-weight: 700 !important;
  line-height: 1 !important;
}
.dept-card .dn { display: none !important; } /* Sembunyikan nama panjang, cukup kode */

/* === BARIS 2: Role + Aksi === */
.rc-sidebar .rc-panel:nth-child(2) .rc-panel-body {
  padding: 0 14px !important;
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  flex-wrap: wrap !important;
  min-height: 46px !important;
}

/* Label ROLE */
.rc-sidebar .rc-panel:nth-child(2) .rc-panel-body > div:first-child {
  font-size: 9px !important;
  font-weight: 700 !important;
  color: var(--muted) !important;
  text-transform: uppercase !important;
  letter-spacing: .06em !important;
  white-space: nowrap !important;
}

/* Role pills */
.role-pills {
  display: flex !important;
  gap: 4px !important;
  flex-wrap: nowrap !important;
  margin-bottom: 0 !important;
}
.role-pill {
  padding: 4px 12px !important;
  font-size: 11px !important;
  border-radius: 20px !important;
  white-space: nowrap !important;
}

/* Action buttons row */
.rc-sidebar .rc-panel:nth-child(2) .rc-panel-body > div:last-child {
  display: flex !important;
  gap: 6px !important;
  align-items: center !important;
  flex-wrap: nowrap !important;
  padding: 6px 0 !important;
  margin-left: auto !important;
}

/* Sembunyikan info-box dan teks panjang di sidebar */
.rc-sidebar .info-box { display: none !important; }
.rc-sidebar .tiny { display: none !important; }
.rc-sidebar p.tiny { display: none !important; }
.rc-sidebar .sep { display: none !important; }

/* Tool tabs: compact & scrollable horizontal */
.rc-sidebar .rc-tabs {
  overflow-x: auto !important;
  scrollbar-width: none !important;
}
.rc-sidebar .rc-tabs::-webkit-scrollbar { display: none !important; }
.rc-sidebar .rc-tab { padding: 7px 12px !important; font-size: 11px !important; }

/* Quick links: compact */
.rc-sidebar .rc-code-block { display: none !important; }
/* ── Per Halaman ── */
.hl-special td { background: rgba(245,158,11,.04) !important; }
.hl-special td:first-child { border-left: 3px solid rgba(245,158,11,.5) !important; }

/* ── Antara top-bar dan matrix ── */
.rc-between-sections {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 4px;
}
.rc-between-sections .rc-section {
  margin-top: 0 !important;
}

/* ── Row 3: Simulasi ── */
.rc-sidebar-row3 {
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  padding: 0 14px !important;
  min-height: 44px !important;
  border-top: 1px solid var(--border) !important;
  background: rgba(168,85,247,.04) !important;
  flex-wrap: nowrap !important;
  width: 100% !important;
  box-sizing: border-box !important;
}
.rc-sidebar-row3 .r3-label {
  font-size: 10px !important;
  font-weight: 700 !important;
  color: #a78bfa !important;
  text-transform: uppercase !important;
  letter-spacing: .06em !important;
  white-space: nowrap !important;
  flex-shrink: 0 !important;
}
.rc-sidebar-row3 .r3-select {
  flex: 1 !important;
  background: rgba(8,13,23,.7) !important;
  border: 1px solid rgba(168,85,247,.3) !important;
  color: #e2e8f0 !important;
  border-radius: 7px !important;
  padding: 5px 10px !important;
  font-size: 11px !important;
  font-family: inherit !important;
  min-width: 0 !important;
}
.rc-sidebar-row3 .r3-badge {
  padding: 3px 9px !important;
  border-radius: 12px !important;
  font-size: 10px !important;
  font-weight: 700 !important;
  white-space: nowrap !important;
  flex-shrink: 0 !important;
}
.rc-sidebar-row3 .r3-badge.privileged {
  background: rgba(245,158,11,.15) !important;
  color: #fcd34d !important;
  border: 1px solid rgba(245,158,11,.25) !important;
}
.rc-sidebar-row3 .r3-badge.count {
  background: rgba(59,130,246,.15) !important;
  color: #93c5fd !important;
  border: 1px solid rgba(59,130,246,.25) !important;
}

/* ── Row 4: Tools toggle ── */
.rc-sidebar-row4 {
  border-top: 1px solid var(--border) !important;
  width: 100% !important;
}
.rc-tools-toggle {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  padding: 8px 14px !important;
  cursor: pointer !important;
  background: rgba(255,255,255,.02) !important;
  width: 100% !important;
  border: none !important;
  text-align: left !important;
  color: var(--muted-lt) !important;
  font-size: 11px !important;
  font-weight: 600 !important;
  font-family: inherit !important;
  transition: background .15s !important;
}
.rc-tools-toggle:hover { background: rgba(255,255,255,.05) !important; }
.rc-tools-toggle .tt-arrow { margin-left: auto !important; font-size: 10px !important; transition: transform .2s !important; }
.rc-tools-toggle.open .tt-arrow { transform: rotate(180deg) !important; }
.rc-tools-body {
  display: none !important;
  border-top: 1px solid var(--border) !important;
}
.rc-tools-body.open { display: block !important; }


  </style>',
]);
?>

<?php
// Module icon map
$modIcons = [
    'SYSTEM'     => '⚙️',
    'MASTER'     => '🗂️',
    'SALES'      => '💼',
    'PURCHASES'  => '🛒',
    'STOCK'      => '📦',
    'WQS'        => '🏗️',
    'HRL'        => '👥',
    'HRL_PROCESS'=> '📋',
    'PAYROLL'    => '💵',
    'ABSENSI'    => '📅',
    'FIXED_ASSET'=> '🏛️',
    'PQP'        => '🔍',
    'MPR'        => '📋',
    'KPI'        => '🎯',
    'DASHBOARD'  => '📊',
    'PANDUAN'    => '📖',
    'TOOLS'      => '🔧',
    'CHAT'       => '💬',
];

// Registry halaman ↔ permission (untuk tampilan per user / dokumentasi)
$pageRegistry = [];
if (file_exists(__DIR__ . '/../config/page_registry.php')) {
    $pageRegistry = require __DIR__ . '/../config/page_registry.php';
}
$halaman_dept = strtoupper(trim((string)($_GET['hl_dept'] ?? $dept_sel)));
if (!in_array($halaman_dept, array_column($departements,'dept_code'), true)) {
    $halaman_dept = $dept_sel ?: 'CRM';
}
// Query umum untuk link tampilan (pertahankan user terpilih + simulasi).
$rbac_matrix_tab_base = ['dept_code' => $dept_sel, 'role_code' => $role_sel, 'hl_dept' => $halaman_dept];
if ($effectiveUserId > 0) {
  $rbac_matrix_tab_base['effective_user'] = $effectiveUserId;
}
if ($hl_user_id > 0) {
  $rbac_matrix_tab_base['hl_user'] = $hl_user_id;
}
// ──────────────────────────────────────────────────────────────────────

// Count enabled per module
$modEnabled = [];
foreach ($byModule as $mod => $plist) {
  $en = 0;
  foreach ($plist as $p) { if ((int)($rules_map[(string)$p['perm_code']] ?? 0) === 1) $en++; }
  $modEnabled[$mod] = $en;
}
$totalEnabled = array_sum($modEnabled);
?>

<div class="rc-wrap">

  <!-- Header -->
  <div class="rc-header">
      <div>
      <div class="rc-title">🔐 RBAC Center</div>
      <div class="rc-subtitle">
        <b>SYS</b> privileged = Allow All. Lainnya ikut matrix <b>Dept + Role</b> + override per user bila ada.
        <ol class="rc-flow">
          <li>Pilih <b>Dept</b> &amp; <b>Role</b> di sidebar → <b>Tampilkan matrix</b></li>
          <li>Centang permission → <b>Simpan perubahan</b> (perlu <span class="pc">SYSTEM.RBAC_MANAGE</span>)</li>
        </ol>
        <p class="tiny" style="margin-top:10px;max-width:820px">Pintasan <b>SYS/SYS</b> hanya navigasi (GET). <b>Tidak menambah izin</b> sampai Anda simpan matrix; server memvalidasi dept/role + CSRF. User <b>privileged</b> tetap allow-all di runtime terpisah dari tampilan ini.</p>
        </div>
        </div>
    <div class="rc-topbtns">
      <a class="btn primary" href="<?= rmi_h(u('/dashboards/index.php')) ?>">🏠 Dashboard</a>
      <a class="btn" href="<?= rmi_h(u('/master/master_system_login.php')) ?>">👤 System Login</a>

      <a class="btn good sm" href="<?= rmi_h($rbac_sys_matrix_url) ?>" title="Langsung ke matrix Dept SYS + role SYS">⚙️ SYS / SYS</a>
      <a class="btn sm" href="<?= rmi_h(u('/rbac/panduan.php')) ?>">📖 Panduan</a>
      <a class="btn sm" href="<?= rmi_h(u('/rbac/nav_parallel_report.php')) ?>" title="Sidebar vs Page Registry vs bootstrap vs mod_card">🧭 Nav audit</a>
      </div>
    </div>

    <?php if ($db_error): ?>
    <div class="flash warn"><b>⚠ Warning:</b> <?= rmi_h($db_error) ?></div>
    <?php endif; ?>
    <?php if ($permIncomplete): ?>
    <div class="flash warn"><b>⚠ Permission registry belum lengkap</b> (<?= (int)$permCount ?> permissions). Gunakan <b>Sync Permissions</b> di sidebar.</div>
    <?php endif; ?>
    <?php if ($flash): ?>
    <div class="flash <?= rmi_h($flash['type'] === 'ok' ? 'ok' : ($flash['type']==='bad'?'bad':'warn')) ?>"><?= rmi_h($flash['msg'] ?? '') ?></div>
  <?php endif; ?>
  <?php if (!$rbacManage): ?>
    <div class="flash warn"><b>👁 Mode baca saja:</b> Anda memiliki <span class="pc">SYSTEM.RBAC_VIEW</span> (tanpa <span class="pc">SYSTEM.RBAC_MANAGE</span>). Matrix ditampilkan tetapi tidak bisa disimpan, sinkron, import, atau export JSON.</div>
  <?php endif; ?>

  <!-- Stats Bar -->
  <div class="rc-stats">
    <div class="rc-stat">
      <div>
        <div class="rc-stat-val"><?= (int)$permCount ?></div>
        <div class="rc-stat-lbl">Total Permission</div>
      </div>
    </div>
    <div class="rc-stat">
      <div>
        <div class="rc-stat-val" id="stat-enabled" style="color:#60a5fa"><?= $totalEnabled ?></div>
        <div class="rc-stat-lbl">Enabled (<?= rmi_h($dept_sel) ?>/<?= rmi_h($role_sel) ?>)</div>
      </div>
    </div>
    <div class="rc-stat">
      <div>
        <div class="rc-stat-val" style="color:#4ade80"><?= count($byModule) ?></div>
        <div class="rc-stat-lbl">Modul</div>
      </div>
    </div>
    <div class="rc-stat" style="flex:1">
      <div style="width:100%">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
          <span style="font-size:11px;color:var(--muted-lt)">Coverage</span>
          <span style="font-size:11px;color:#60a5fa" id="stat-pct"><?= $permCount > 0 ? round($totalEnabled/$permCount*100) : 0 ?>%</span>
        </div>
        <div style="height:6px;background:rgba(99,128,185,.15);border-radius:999px;overflow:hidden">
          <div id="stat-bar" style="height:100%;background:linear-gradient(90deg,#2563eb,#818cf8);border-radius:999px;width:<?= $permCount > 0 ? round($totalEnabled/$permCount*100) : 0 ?>%;transition:width .4s"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="rc-layout">

    <!-- ── LEFT SIDEBAR ── -->
    <div class="rc-sidebar">

      <!-- Jalur cepat SYS (dept SYS = satu-satunya dengan role sys di matrix) -->
      <div class="rc-panel rc-sys-lane">
        <div class="rc-panel-head"><h3>⚙️ Pengaturan System (SYS)</h3></div>
        <div class="rc-panel-body" style="padding-top:10px;padding-bottom:12px">
          <div class="info-box" style="margin-bottom:10px;border-color:rgba(251,191,36,.25);background:rgba(245,158,11,.06)">
            Matrix <span class="pc">SYS</span> / <span class="pc">SYS</span> = izin di registry untuk akun dept System. User <b>privileged</b> (session SYS/ADMIN/SUPERADMIN) di app tetap <b>allow all</b> tanpa membaca matrix ini.
          </div>
          <div class="btn-row-sys">
            <?php if ($rbac_is_sys_sys_context): ?>
            <span class="btn good sm" style="opacity:.95;cursor:default;justify-content:center">✓ Sedang: SYS / SYS</span>
            

<?php else: ?>
            <a class="btn good" style="width:100%;justify-content:center;text-decoration:none" href="<?= rmi_h($rbac_sys_matrix_url) ?>">↪ Buka matrix SYS / SYS</a>
    <?php endif; ?>
            <?php if ($rbacManage): ?>
            <p class="tiny" style="margin:0;line-height:1.45;color:var(--muted-lt)">Setelah <b>Sync Permissions</b>, di tab <b>Sync</b> gunakan <b>Grant All → SYS/SYS</b> agar baris matrix mengikuti seluruh registry.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Dept + Role Selector -->
      <div class="rc-panel">
        <div class="rc-panel-head"><h3>🎯 Pilih Dept + Role</h3></div>
        <div style="padding:8px 10px 0">
          <div style="font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">Department</div>
          <div class="dept-grid">
            <?php foreach ($departements as $d):
              $dc = strtoupper((string)$d['dept_code']); ?>
            <div class="dept-card <?= $dc==='SYS'?'dept-card--sys ':'' ?><?= $dc===$dept_sel?'active':'' ?>" data-dept-code="<?= rmi_h($dc) ?>" onclick="selectDept('<?= rmi_h($dc) ?>')" title="<?= $dc==='SYS'?'Dept System — role matrix hanya SYS':'' ?>">
              <div class="dc"><?= rmi_h($dc) ?></div>
              <div class="dn"><?= rmi_h((string)$d['dept_name']) ?></div>
        </div>
            <?php endforeach; ?>
          </div>
          <div style="font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin:6px 0 5px">Role</div>
          <div class="role-pills">
            <?php foreach ($roles as $r): ?>
            <div class="role-pill <?= $r===$role_sel?'active':'' ?>" data-role-code="<?= rmi_h($r) ?>" onclick="selectRole('<?= rmi_h($r) ?>')">
              <?= $r==='SYS'?'⚙️':'('.($r==='MANAGER'?'👔':'👤').')' ?> <?= rmi_h($r) ?>
            </div>
            <?php endforeach; ?>
          </div>
          <form method="get" action="<?= rmi_h(u('/rbac/index.php')) ?>" id="dept-form" style="display:none">
            <input type="hidden" name="dept_code" id="f-dept" value="<?= rmi_h($dept_sel) ?>">
            <input type="hidden" name="role_code" id="f-role" value="<?= rmi_h($role_sel) ?>">
            <input type="hidden" name="rbac_layout" value="<?= rmi_h($rbac_matrix_layout) ?>">
            <?php if ($effectiveUserId > 0): ?>
            <input type="hidden" name="effective_user" value="<?= (int)$effectiveUserId ?>">
            <?php endif; ?>
        </form>
          <div style="display:flex;gap:5px;padding:8px 0 6px;flex-wrap:wrap;align-items:center">
            <?php if ($rbacManage): ?>
            <a class="btn sm" href="<?= rmi_h(u('/rbac/index.php?action=export_json&dept_code='.urlencode($dept_sel).'&role_code='.urlencode($role_sel).'&rbac_layout='.urlencode($rbac_matrix_layout))) ?>">⬇ Export JSON</a>
            

<?php else: ?>
            <span class="tiny" style="color:var(--muted)">Export JSON memerlukan RBAC_MANAGE</span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($rbacManage): ?>
        <!-- Tabbed actions -->
        <div class="rc-tabs" id="sidebar-tabs">
          <div class="rc-tab active" onclick="switchTab(this,'tab-sync')">🔄 Sync</div>
          <div class="rc-tab" onclick="switchTab(this,'tab-copy')">📋 Salin</div>
          <div class="rc-tab" onclick="switchTab(this,'tab-preset')">📦 Paket</div>
          <div class="rc-tab" onclick="switchTab(this,'tab-baseline')">🛡 Default</div>
          <div class="rc-tab" onclick="switchTab(this,'tab-import')">📥 Import</div>
          <div class="rc-tab" onclick="switchTab(this,'tab-addperm')">➕ Add Perm</div>
        </div>

        <!-- Tab: Sync -->
        <div class="rc-tab-panel active" id="tab-sync">
          <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
            <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
          <input type="hidden" name="action" value="sync_permissions">
            <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
            <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
            <div class="info-box">Sinkronkan permission dari <span class="pc">config/rbac_permissions.php</span> atau fallback <span class="pc">_shared/rbac.php</span> ke database.</div>
            <button class="btn good" type="submit" style="margin-top:10px;width:100%">🔄 Sync Full Permissions</button>
          </form>
          <div class="sep"></div>
          <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>" onsubmit="return confirm('Hapus dari database semua permission yang TIDAK ada di config/rbac_permissions.php?\n\nBaris matrix Dept+Role dan user override yang mereferensi kode tersebut ikut terhapus (FK CASCADE).\n\nPastikan sudah Sync + migrasi 161/162 bila perlu.');">
            <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="prune_orphan_permissions">
            <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
            <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
            <div class="info-box">Hapus <b>orphan registry</b>: baris di <span class="pc">rbac_permissions</span> yang tidak tercantum di config (mengatasi selisih “file → DB” setelah sync additive). CLI: <span class="pc">php tools/rbac/prune_rbac_orphan_permissions.php</span></div>
            <button class="btn warn-btn" type="submit" style="margin-top:10px;width:100%">🧹 Hapus orphan registry (selisih DB vs config)</button>
          </form>
          <div class="sep"></div>
          <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
            <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="grant_all_sys">
            <div class="info-box">Beri <b>SYS/SYS</b> semua permission dari registry. Jalankan setelah Sync.</div>
            <button class="btn primary" type="submit" style="margin-top:10px;width:100%">⚡ Grant All ke SYS/SYS</button>
          </form>
          </div>

        <!-- Tab: Salin dari Dept/Role ── BARU -->
        <div class="rc-tab-panel" id="tab-copy">
          <div class="info-box" style="margin-bottom:10px">
            Salin <b>semua permission</b> dari satu Dept+Role ke <b><?= rmi_h($dept_sel) ?> / <?= rmi_h($role_sel) ?></b>. Pilih <em>replace</em> untuk hapus yang lama, atau <em>merge</em> (default) untuk tambah saja.
          </div>
          <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
            <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="copy_from">
            <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
            <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
            <input type="hidden" name="rbac_layout" value="<?= rmi_h($rbac_matrix_layout) ?>">
            <div class="field">
              <label>Sumber Dept</label>
              <select name="src_dept_code">
                <?php foreach ($departements as $d):
                  $dc = strtoupper((string)$d['dept_code']);
                  if ($dc === $dept_sel) continue;
                ?>
                <option value="<?= rmi_h($dc) ?>"><?= rmi_h($dc) ?> — <?= rmi_h((string)$d['dept_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Sumber Role</label>
              <select name="src_role_code">
                <option value="STAFF">STAFF</option>
                <option value="MANAGER">MANAGER</option>
                <option value="SYS">SYS</option>
              </select>
            </div>
            <label class="chk-row" style="margin-bottom:10px">
              <input type="checkbox" name="copy_replace" value="1">
              Replace (hapus rules <?= rmi_h($dept_sel) ?>/<?= rmi_h($role_sel) ?> dulu)
            </label>
            <button class="btn primary" type="submit" style="width:100%"
              onclick="return confirm('Salin permission dari dept/role yang dipilih ke <?= rmi_h($dept_sel) ?>/<?= rmi_h($role_sel) ?>?')">
              📋 Salin Sekarang
            </button>
        </form>
        </div>

        <!-- Tab: Paket Cepat ── BARU -->
        <div class="rc-tab-panel" id="tab-preset">
          <div class="info-box" style="margin-bottom:10px">
            Terapkan <b>paket cepat</b> ke <b><?= rmi_h($dept_sel) ?> / <?= rmi_h($role_sel) ?></b>. Cocok untuk onboarding dept baru atau reset permission dengan pola standar.
          </div>
          <?php
          $presets = [
            ['name' => 'view_only',      'icon' => '👁',  'label' => 'VIEW Saja',          'desc' => 'Hanya semua permission *.VIEW — baca tanpa mutasi'],
            ['name' => 'ops_standard',   'icon' => '👤',  'label' => 'Ops Standar (Staff)', 'desc' => 'VIEW + CREATE + EDIT + EXPORT (tanpa DELETE/APPROVE/finance)'],
            ['name' => 'ops_full',       'icon' => '👔',  'label' => 'Ops Lengkap (Mgr)',   'desc' => 'Semua kecuali RBAC/USER MANAGE/finance approval'],
            ['name' => 'panduan_only',   'icon' => '📖',  'label' => 'Panduan Saja',        'desc' => 'Hanya PANDUAN.* — baca panduan modul'],
            ['name' => 'hrl_process',    'icon' => '🔁',  'label' => 'HRL Process',         'desc' => 'HRL.* + HRL_PROCESS.* + PANDUAN.* — untuk user lintas dept'],
            ['name' => 'absensi_kpi',    'icon' => '⏱️',  'label' => 'Absensi + KPI',       'desc' => 'ABSENSI.* + KPI.* + CHAT.* — akses dasar semua user'],
          ];
          ?>
          <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
            <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="apply_preset">
            <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
            <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
            <input type="hidden" name="rbac_layout" value="<?= rmi_h($rbac_matrix_layout) ?>">
            <div class="field">
              <label>Pilih Paket</label>
              <select name="preset_name" id="sel-preset">
                <?php foreach ($presets as $pr): ?>
                <option value="<?= rmi_h($pr['name']) ?>"><?= $pr['icon'] ?> <?= rmi_h($pr['label']) ?></option>
                <?php endforeach; ?>
              </select>
          </div>
            <div style="font-size:11px;color:var(--muted-lt);margin:-6px 0 10px;line-height:1.45;min-height:32px" id="preset-desc-box">
              <?= rmi_h($presets[0]['desc']) ?>
            </div>
            <label class="chk-row" style="margin-bottom:10px">
              <input type="checkbox" name="preset_replace" value="1">
              Replace (hapus rules <?= rmi_h($dept_sel) ?>/<?= rmi_h($role_sel) ?> dulu)
            </label>
            <button class="btn primary" type="submit" style="width:100%"
              onclick="return confirm('Terapkan paket ke <?= rmi_h($dept_sel) ?>/<?= rmi_h($role_sel) ?>?')">
              📦 Terapkan Paket
            </button>
        </form>
          <script>
          (function(){
            var descs = <?= json_encode(array_column($presets, 'desc', 'name'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            var sel = document.getElementById('sel-preset');
            var box = document.getElementById('preset-desc-box');
            if (sel && box) {
              sel.addEventListener('change', function(){ box.textContent = descs[this.value] || ''; });
            }
          })();
          </script>
        </div>

        <!-- Tab: Baseline -->
        <div class="rc-tab-panel" id="tab-baseline">
          <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
            <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
          <input type="hidden" name="action" value="apply_baseline">
            <div class="info-box">Terapkan RBAC Default (aman) untuk STAFF/MANAGER. Gunakan hanya untuk setup awal.</div>
            <label class="chk-row" style="margin-top:10px"><input type="checkbox" name="replace_rules" value="1"> Hapus semua rules lama</label>
            <label class="chk-row"><input type="checkbox" name="replace_user_overrides" value="1"> Hapus user overrides</label>
            <button class="btn warn-btn" type="submit" style="margin-top:10px;width:100%">🛡 Apply RBAC Default</button>
          </form>
            </div>

        <!-- Tab: Import -->
        <div class="rc-tab-panel" id="tab-import">
          <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="import_json">
            <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
            <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
            <div class="field">
              <label>File JSON</label>
              <input type="file" name="import_file" accept="application/json">
          </div>
            <label class="chk-row"><input type="checkbox" name="import_replace" value="1" checked> Replace rules untuk <?= rmi_h($dept_sel) ?>/<?= rmi_h($role_sel) ?></label>
            <button class="btn primary" type="submit" style="margin-top:10px;width:100%">📥 Import JSON</button>
            <div class="field-note" style="margin-top:8px">Format: <span class="pc">{"allow":["A","B"]}</span> atau <span class="pc">["A","B"]</span></div>
          </form>
          </div>

        <!-- Tab: Add Perm -->
        <div class="rc-tab-panel" id="tab-addperm">
          <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
            <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="add_perm">
            <div class="field"><label>perm_code</label><input type="text" name="perm_code" placeholder="STOCK.ADJUST"></div>
            <div class="field"><label>perm_name</label><input type="text" name="perm_name" placeholder="Stock - Adjustment"></div>
            <div class="field"><label>module</label><input type="text" name="module" placeholder="STOCK"></div>
            <div class="field"><label>description</label><textarea name="description" placeholder="keterangan singkat (opsional)"></textarea></div>
            <button class="btn good" type="submit" style="width:100%">➕ Simpan Permission</button>
        </form>
        </div>
        

<?php else: ?>
        <div class="rc-panel-body">
          <div class="info-box">Aksi <b>Sync</b>, <b>Baseline</b>, <b>Import</b>, dan <b>Add Perm</b> hanya untuk user dengan <span class="pc">SYSTEM.RBAC_MANAGE</span>.</div>
        </div>
        <?php endif; ?>

          </div>

      <!-- Quick Access Links ── BARU -->
      <div class="rc-panel">
        <div class="rc-panel-head"><h3>⚡ Akses Cepat per Dept</h3></div>
        <div class="rc-panel-body" style="padding:8px 10px">
          <div style="font-size:10px;color:var(--muted);margin-bottom:6px">Klik untuk buka matrix dept + role langsung:</div>
          <?php
          $quickLinks = [
            ['dept'=>'HRL',    'role'=>'STAFF',   'icon'=>'👥', 'label'=>'HRL Staff'],
            ['dept'=>'HRL',    'role'=>'MANAGER',  'icon'=>'👥', 'label'=>'HRL Manager'],
            ['dept'=>'CRM',    'role'=>'STAFF',   'icon'=>'💼', 'label'=>'CRM Staff'],
            ['dept'=>'CRM',    'role'=>'MANAGER',  'icon'=>'💼', 'label'=>'CRM Manager'],
            ['dept'=>'PQP',    'role'=>'STAFF',   'icon'=>'🛒', 'label'=>'PQP Staff'],
            ['dept'=>'FIN',    'role'=>'STAFF',   'icon'=>'💰', 'label'=>'FIN Staff'],
            ['dept'=>'FIN',    'role'=>'MANAGER',  'icon'=>'💰', 'label'=>'FIN Manager'],
            ['dept'=>'ACT',    'role'=>'STAFF',   'icon'=>'📝', 'label'=>'ACT Staff'],
            ['dept'=>'WQS',    'role'=>'STAFF',   'icon'=>'📦', 'label'=>'WQS Staff'],
            ['dept'=>'SCM',    'role'=>'STAFF',   'icon'=>'🚢', 'label'=>'SCM Staff'],
            ['dept'=>'ITC',    'role'=>'STAFF',   'icon'=>'💻', 'label'=>'ITC Staff'],
            ['dept'=>'BRANCH', 'role'=>'STAFF',   'icon'=>'🏢', 'label'=>'Branch Staff'],
            ['dept'=>'BRANCH', 'role'=>'MANAGER',  'icon'=>'🏢', 'label'=>'Branch Manager'],
          ];
          foreach ($quickLinks as $ql):
            $isActive = ($ql['dept'] === $dept_sel && $ql['role'] === $role_sel);
            $href = u('/rbac/index.php?' . http_build_query(['dept_code' => $ql['dept'], 'role_code' => $ql['role'], 'rbac_layout' => $rbac_matrix_layout]));
          ?>
          <a href="<?= rmi_h($href) ?>" style="display:flex;align-items:center;gap:7px;padding:5px 8px;border-radius:7px;text-decoration:none;font-size:11px;font-weight:600;margin-bottom:2px;border:1px solid <?= $isActive ? 'rgba(99,130,250,.55)' : 'transparent' ?>;background:<?= $isActive ? 'rgba(37,99,235,.18)' : 'rgba(255,255,255,.03)' ?>;color:<?= $isActive ? '#93c5fd' : 'var(--muted-lt)' ?>;transition:all .15s"
             onmouseover="this.style.background='rgba(99,128,185,.1)';this.style.color='#fff'"
             onmouseout="this.style.background='<?= $isActive ? 'rgba(37,99,235,.18)' : 'rgba(255,255,255,.03)' ?>'; this.style.color='<?= $isActive ? '#93c5fd' : 'var(--muted-lt)' ?>'">
            <span><?= $ql['icon'] ?></span>
            <span><?= rmi_h($ql['label']) ?></span>
            <?php if ($isActive): ?><span style="margin-left:auto;font-size:9px;opacity:.7">← aktif</span><?php endif; ?>
          </a>
          <?php endforeach; ?>
          </div>
      </div>

      <!-- Info box -->
      <div class="rc-panel">
        <div class="rc-panel-body">
          <div class="rc-mini-head">ℹ Tentang RBAC</div>
          <div class="info-box">Assign <b>Role</b>, <b>Dept</b>, <b>Office</b> per user di <a class="link-inline" href="<?= rmi_h(u('/master/master_system_login.php')) ?>">Master System Login</a>.<br>Master data departemen: <a class="link-inline" href="<?= rmi_h(u('/master/master_departements.php')) ?>">Master Dept</a>.</div>
        </div>
      </div>

      <!-- Health / QA (CLI utama di NAS; link web butuh akses Tools = SYS) -->
      <div class="rc-panel">
        <div class="rc-panel-head"><h3>🩺 Health / QA</h3></div>
        <div class="rc-panel-body">
          <p class="tiny" style="margin-bottom:4px"><strong>CLI (disarankan di NAS):</strong></p>
          <div class="rc-code-block">cd /volume4/web/ERP_RMI_SOFULL
php tools/rbac_diff_config_db.php
php tools/rbac/prune_rbac_orphan_permissions.php --dry-run
php tools/qa/rbac_coverage_check.php</div>
          <p class="tiny" style="margin-top:10px">Lihat juga <span class="pc">README.md</span> → bagian RBAC / Final gate.</p>
          <div class="sep"></div>
          <p class="tiny" style="margin-bottom:8px"><code>rbac_diff_config_db.php</code> bisa dibuka di browser jika session <b>privileged</b> (policy <span class="pc">/tools/*</span> = SYS):</p>
          <a class="btn sm" href="<?= rmi_h(u('/tools/rbac_diff_config_db.php')) ?>" target="_blank" rel="noopener">↗ Diff config ↔ DB (web)</a>
          <p class="tiny" style="margin-top:10px;color:var(--muted)"><code>tools/qa/rbac_coverage_check.php</code> = <b>CLI only</b> (tidak ada halaman web). Gunakan perintah di kotak di atas.</p>
          </div>
          </div>


      <!-- ── ROW 3: 🧪 Simulasi Permission Efektif ── -->
      <div class="rc-sidebar-row3">
        <span class="r3-label">🧪 Simulasi</span>
        <form method="get" action="<?= rmi_h(u('/rbac/index.php')) ?>" style="display:contents">
          <input type="hidden" name="dept_code"   value="<?= rmi_h($dept_sel) ?>">
          <input type="hidden" name="role_code"   value="<?= rmi_h($role_sel) ?>">
          <input type="hidden" name="rbac_layout" value="<?= rmi_h($rbac_matrix_layout) ?>">
          <select name="effective_user" class="r3-select" onchange="this.form.submit()">
            <option value="">— pilih user —</option>
            <?php foreach ($loginUsersPick as $lu):
              $lid = (int)($lu['id'] ?? 0);
              if ($lid <= 0) continue;
            ?>
            <option value="<?= $lid ?>" <?= ($effectiveUserId === $lid) ? 'selected' : '' ?>>
              <?= rmi_h((string)($lu['username'] ?? '')) ?>
              · <?= rmi_h((string)($lu['department'] ?? '')) ?>/<?= rmi_h((string)($lu['role'] ?? '')) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php if (is_array($effectiveBundle)): ?>
          <?php if (!empty($effectiveBundle['privileged'])): ?>
            <span class="r3-badge privileged">⭐ Privileged</span>
          

<?php else: ?>
            <span class="r3-badge count"><?= count($effectiveBundle['rows'] ?? []) ?> perm</span>
          <?php endif; ?>
        <?php endif; ?>
            </div>

      <?php if ($rbacManage): ?>
      <!-- ── ROW 4: ⚙️ Tools (toggle) ── -->
      <div class="rc-sidebar-row4">
        <button type="button" class="rc-tools-toggle" id="tools-toggle" onclick="
          this.classList.toggle('open');
          document.getElementById('tools-body').classList.toggle('open');
        ">
          <span>⚙️ Tools</span>
          <span style="font-size:10px;color:var(--muted);margin-left:4px">Sync · Salin · Paket · Baseline · Import · Add Perm</span>
          <span class="tt-arrow">▼</span>
        </button>
        <div class="rc-tools-body" id="tools-body">
          <!-- Tool tabs dari panel dept+role dipindahkan ke sini -->
          <div class="rc-tabs" id="sidebar-tabs2">
            <div class="rc-tab active" onclick="switchTab2(this,'t2-sync')">🔄 Sync</div>
            <div class="rc-tab" onclick="switchTab2(this,'t2-copy')">📋 Salin</div>
            <div class="rc-tab" onclick="switchTab2(this,'t2-preset')">📦 Paket</div>
            <div class="rc-tab" onclick="switchTab2(this,'t2-baseline')">🛡 Default</div>
            <div class="rc-tab" onclick="switchTab2(this,'t2-import')">📥 Import</div>
            </div>
          <div class="rc-tab-panel active" id="t2-sync" style="max-height:220px;overflow-y:auto">
            <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
              <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
              <input type="hidden" name="action" value="sync_permissions">
              <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
              <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
              <div class="info-box" style="margin-bottom:8px">Sync permission dari <span class="pc">config/rbac_permissions.php</span> ke DB.</div>
              <button class="btn good sm" type="submit" style="width:100%">🔄 Sync Full Permissions</button>
            </form>

          </div>
          <div class="rc-tab-panel" id="t2-copy" style="max-height:220px;overflow-y:auto">
            <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
              <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
              <input type="hidden" name="action" value="copy_from">
              <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
              <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
              <input type="hidden" name="rbac_layout" value="<?= rmi_h($rbac_matrix_layout) ?>">
              <div class="field"><label>Sumber Dept</label>
                <select name="src_dept_code">
                  <?php foreach ($departements as $d): $dc=strtoupper((string)$d['dept_code']); if($dc===$dept_sel) continue; ?>
                  <option value="<?= rmi_h($dc) ?>"><?= rmi_h($dc) ?></option>
                  <?php endforeach; ?>
              </select>
            </div>
              <div class="field"><label>Sumber Role</label>
                <select name="src_role_code">
                  <option value="STAFF">STAFF</option><option value="MANAGER">MANAGER</option><option value="SYS">SYS</option>
                </select>
              </div>
              <button class="btn primary sm" type="submit" style="width:100%" onclick="return confirm('Salin permission ke <?= rmi_h($dept_sel) ?>/<?= rmi_h($role_sel) ?>?')">📋 Salin Sekarang</button>
            </form>
          </div>
          <div class="rc-tab-panel" id="t2-preset" style="max-height:220px;overflow-y:auto">
            <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
              <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
              <input type="hidden" name="action" value="apply_preset">
              <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
              <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
              <div class="field"><label>Paket</label>
                <select name="preset_name">
                  <option value="view_only">👁 VIEW Saja</option>
                  <option value="ops_standard">👤 Ops Standar (Staff)</option>
                  <option value="ops_full">👔 Ops Lengkap (Mgr)</option>
                  <option value="panduan_only">📖 Panduan Saja</option>
                  <option value="absensi_kpi">⏱️ Absensi + KPI</option>
                </select>
              </div>
              <button class="btn primary sm" type="submit" style="width:100%" onclick="return confirm('Terapkan paket ke <?= rmi_h($dept_sel) ?>/<?= rmi_h($role_sel) ?>?')">📦 Terapkan</button>
            </form>
          </div>
          <div class="rc-tab-panel" id="t2-baseline" style="max-height:220px;overflow-y:auto">
            <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>">
              <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
              <input type="hidden" name="action" value="apply_baseline">
              <div class="info-box" style="margin-bottom:8px">RBAC Default aman untuk STAFF/MANAGER.</div>
              <label class="chk-row"><input type="checkbox" name="replace_rules" value="1"> Hapus rules lama</label>
              <button class="btn warn-btn sm" type="submit" style="margin-top:8px;width:100%">🛡 Apply Default</button>
            </form>
          </div>
          <div class="rc-tab-panel" id="t2-import" style="max-height:220px;overflow-y:auto">
            <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
              <input type="hidden" name="action" value="import_json">
              <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
              <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
              <div class="field"><label>File JSON</label>
                <input type="file" name="import_file" accept="application/json">
              </div>
              <button class="btn primary sm" type="submit" style="width:100%">📥 Import JSON</button>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /sidebar -->

    <!-- ── SIMULASI + AUDIT (antara top bar dan matrix) ── -->
    <div class="rc-between-sections">
<div class="rc-section">
    <div class="rc-section-head">
      <h3>🧪 Simulasi Permission Efektif</h3>
      <span style="font-size:11px;color:var(--muted-lt)">Matrix Dept+Role + override per-user</span>
            </div>
    <div class="rc-section-body">
      <form method="get" action="<?= rmi_h(u('/rbac/index.php')) ?>" class="rc-effective-form">
        <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
        <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
        <input type="hidden" name="rbac_layout" value="<?= rmi_h($rbac_matrix_layout) ?>">
        <select name="effective_user" class="eff-user-select" onchange="this.form.submit()">
          <option value="">— Pilih user untuk simulasi —</option>
          <?php foreach ($loginUsersPick as $lu):
            $lid = (int)($lu['id'] ?? 0);
            if ($lid <= 0) continue;
          ?>
          <option value="<?= $lid ?>" <?= ($effectiveUserId === $lid) ? 'selected' : '' ?>>
            <?= rmi_h((string)($lu['username'] ?? '')) ?>
            · <?= rmi_h((string)($lu['department'] ?? '')) ?>/<?= rmi_h((string)($lu['role'] ?? '')) ?>
            <?= !empty($lu['full_name']) ? ' — ' . rmi_h((string)$lu['full_name']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>

      <?php if ($effectiveUserId > 0 && $effectiveBundle === null): ?>
        <div class="flash bad" style="margin-top:12px">User tidak ditemukan.</div>
      <?php elseif (is_array($effectiveBundle)): ?>
        <?php if (!empty($effectiveBundle['privileged'])): ?>
          <div class="flash ok" style="margin-top:12px">
            <b><?= rmi_h((string)$effectiveBundle['username']) ?></b> adalah <b>Privileged</b> (SYS/ADMIN/SUPERADMIN) — allow all di runtime.
          </div>


<?php else: ?>
          <?php
            $effRows = $effectiveBundle['rows'] ?? [];
            $effSlice = array_slice($effRows, 0, 800);
            // Group by module
            $byMod = [];
            foreach ($effSlice as $er) {
              $pc = (string)($er['perm'] ?? '');
              $mod = strstr($pc, '.', true) ?: 'OTHER';
              $byMod[$mod][] = $er;
            }
            ksort($byMod);
          ?>
          <div class="eff-meta">
            <div class="eff-meta-item">Dept: <b><?= rmi_h((string)$effectiveBundle['dept']) ?></b></div>
            <div class="eff-meta-item">Role: <b><?= rmi_h((string)$effectiveBundle['role']) ?></b></div>
            <div class="eff-meta-item">Matrix: <b><?= (int)($effectiveBundle['matrix_count'] ?? 0) ?></b></div>
            <div class="eff-meta-item">Override: <b><?= (int)($effectiveBundle['override_count'] ?? 0) ?></b></div>
            <div class="eff-meta-item">Total efektif: <b style="color:#60a5fa"><?= count($effRows) ?></b></div>
            <div class="eff-meta-item">Modul: <b><?= count($byMod) ?></b></div>
              </div>
          <div class="eff-scroll">
            <table class="eff-tbl">
                <thead>
                  <tr>
                  <th style="width:120px">Modul</th>
                  <th>Permission Code</th>
                  <th style="width:140px">Sumber</th>
                  </tr>
                </thead>
                <tbody>
              <?php foreach ($byMod as $mod => $perms): ?>
                <?php foreach ($perms as $er):
                  $srcLabel = (string)($er['source'] ?? 'Matrix');
                  $srcClass = match(true) {
                    str_contains($srcLabel, 'override') || $srcLabel === 'Override' => 'eff-src-override',
                    str_contains($srcLabel, 'Matrix + override') || $srcLabel === 'Matrix + override' => 'eff-src-both',
                    default => 'eff-src-matrix',
                  };
                  $pc = (string)($er['perm'] ?? '');
                  $short = strstr($pc, '.') !== false ? substr(strstr($pc, '.'), 1) : $pc;
                ?>
                <tr>
                  <td><span class="eff-mod-badge"><?= rmi_h($mod) ?></span></td>
                  <td class="pc"><?= rmi_h($short) ?></td>
                  <td><span class="eff-src <?= rmi_h($srcClass) ?>"><?= rmi_h($srcLabel) ?></span></td>
                </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (count($effRows) > 800): ?>
            <p class="tiny" style="margin-top:8px;padding:0 4px">Menampilkan 800 dari <?= count($effRows) ?> permission.</p>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>


    </div>


    <!-- ── RIGHT — PERMISSION MATRIX ── -->
    <div class="rc-matrix">
      <!-- Sticky header -->
        <div class="matrix-sticky">
          <div class="matrix-title-row">
            <div style="font-size:15px;font-weight:800;color:#f1f5f9">Permission Matrix</div>
            <div class="matrix-badge">Dept: <b><?= rmi_h($dept_sel) ?></b> &nbsp;·&nbsp; Role: <b><?= rmi_h($role_sel) ?></b><?= $rbac_is_sys_sys_context ? ' &nbsp;<span style="color:#fcd34d;font-weight:800">· SYS</span>' : '' ?></div>
          </div>
          <?php if (!$rbac_is_sys_sys_context): ?>
          <div class="matrix-context-bar" role="region" aria-label="Pintasan System SYS">
            <div class="matrix-context-bar-inner">
              <span class="matrix-context-title">⚙️ System (SYS)</span>
              <a class="btn sm good" href="<?= rmi_h($rbac_sys_matrix_url) ?>" style="text-decoration:none;flex-shrink:0">Buka matrix SYS / SYS</a>
              <span class="matrix-context-note">Pindah ke tabel matrix untuk dept <span class="pc">SYS</span> + role <span class="pc">SYS</span>. Isi semua centang cepat: <b>Sidebar → tab Sync → Grant All ke SYS/SYS</b> (setelah Sync registry).</span>
            </div>
          </div>
          

<?php else: ?>
          <div class="matrix-sys-banner" role="status">
            <b>Anda di matrix SYS / SYS.</b> User privileged di runtime tetap allow all tanpa baris ini. <b>Isi cepat:</b> Sidebar <b>Sync</b> → <b>Grant All ke SYS/SYS</b>. Tabel di bawah = daftar permission per modul (centang = allow untuk akun non-privileged ber-dept System).
          </div>
          <?php endif; ?>
          <div class="matrix-search-row">
            <div class="matrix-search">🔎 <input id="permSearch" type="text" placeholder="Cari permission, nama, modul..."></div>
            <select class="matrix-catsel" id="permCategory">
              <option value="ALL">Semua tipe</option>
              <?php foreach (['VIEW','CREATE','EDIT','DELETE','APPROVE','IMPORT','EXPORT','PROCESS','AUDIT','SETTINGS','API','OTHER'] as $c): ?>
              <option value="<?= rmi_h($c) ?>"><?= rmi_h($c) ?></option>
              <?php endforeach; ?>
            </select>
            <select class="matrix-catsel" id="permStaffMgrTier" title="Filter kelompok Staff vs Manager">
              <option value="ALL">Semua kelompok S/M</option>
              <option value="STAFF_OPS">👤 Hanya Staff (ops)</option>
              <option value="MANAGER_PRIV">👔 Hanya Manager</option>
              <option value="TECH_OTHER">🔧 Hanya Audit/API/lain</option>
            </select>
          </div>
          <div class="rc-matrix-tabs" role="tablist" aria-label="Tampilan RBAC">
            <span class="lbl">Tampilan</span>
            <a class="<?= $rbac_matrix_layout === 'user' ? 'active' : '' ?>" href="<?= rmi_h(u('/rbac/index.php?' . http_build_query(array_merge($rbac_matrix_tab_base, ['rbac_layout' => 'user'])))) ?>" style="<?= $rbac_matrix_layout === 'user' ? '' : 'background:rgba(168,85,247,.08);border-color:rgba(168,85,247,.25);color:#c084fc' ?>">Per user</a>
            <a class="<?= $rbac_matrix_layout === 'module' ? 'active' : '' ?>" href="<?= rmi_h($rbacUrlModule) ?>" style="<?= $rbac_matrix_layout === 'module' ? '' : 'background:rgba(59,130,246,.08);border-color:rgba(59,130,246,.28);color:#93c5fd' ?>">Matrix Dept + Role</a>
          </div>
          <p class="tiny" style="margin:8px 0 0;color:var(--muted-lt);line-height:1.45"><b>Per user</b> = ringkasan hak efektif (matrix + override). <b>Matrix</b> = centang permission untuk <b>Dept + Role</b> yang dipilih di sidebar lalu <b>Simpan</b>.</p>
          <div class="matrix-actions">
            <?php if (!$rbac_is_sys_sys_context): ?>
            <a class="btn sm good" href="<?= rmi_h($rbac_sys_matrix_url) ?>" style="text-decoration:none" title="Buka matrix Dept SYS + role SYS">⚙ SYS/SYS</a>
            <?php endif; ?>
            <?php if ($rbacManage && $rbac_matrix_layout === 'module'): ?>
            <button class="btn good" type="submit" form="rbac-matrix-form">💾 Save Rules</button>
            <?php endif; ?>
          </div>
        </div>

        <div class="matrix-body">
        <?php if ($rbac_matrix_layout === 'user'): ?>
          <?php
          $ACTION_KEYS   = ['access','create','approve','edit','view','delete','import','export','print'];
          $ACTION_LABELS = ['ACCESS','CREATE','APPROVE','EDIT','VIEW','DELETE','IMPORT','EXPORT','PRINT'];
          $ACTION_COL_HINTS = [
            'access'  => 'Akses: boleh membuka halaman/file (URL/route). Gate runtime tetap di file .php (can/policy).',
            'create'  => 'Membuat data baru.',
            'approve' => 'Menyetujui alur.',
            'edit'    => 'Mengubah data.',
            'view'    => 'Lihat: boleh melihat konten (baca/tampil). Boleh kode beda dari ACCESS.',
            'delete'  => 'Menghapus data.',
            'import'  => 'Impor bulk.',
            'export'  => 'Ekspor data.',
            'print'   => 'Cetak / PDF.',
          ];
          $userPickAll = [];
          try {
              $stUP = $pdo->prepare("SELECT id,username,full_name,department,role,level FROM master_system_login WHERE status='active' ORDER BY department,username LIMIT 500");
              $stUP->execute();
              $userPickAll = $stUP->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $e) {}
          ?>
          <!-- Per User Active -->
          <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;padding:12px 14px;background:rgba(168,85,247,.06);border-radius:10px;border:1px solid rgba(168,85,247,.2);align-items:center">
            <span style="font-size:11px;font-weight:700;color:#c084fc;white-space:nowrap">👤 Pilih User:</span>
            <form method="get" action="<?= rmi_h(u('/rbac/index.php')) ?>" style="display:flex;gap:8px;flex:1;align-items:center;flex-wrap:wrap">
              <input type="hidden" name="rbac_layout" value="user">
              <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
              <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
              <input type="hidden" name="hl_dept" value="<?= rmi_h($halaman_dept) ?>">
              <?php if ($effectiveUserId > 0): ?>
              <input type="hidden" name="effective_user" value="<?= (int)$effectiveUserId ?>">
              <?php endif; ?>
              <select name="hl_user" onchange="this.form.submit()" style="flex:1;min-width:260px;background:rgba(8,13,23,.7);border:1px solid rgba(168,85,247,.3);color:#e2e8f0;border-radius:8px;padding:7px 12px;font-size:12px;font-family:inherit">
                <option value="">— Pilih user aktif —</option>
                <?php $lastDept=''; foreach ($userPickAll as $upu):
                  $udept=strtoupper(trim((string)($upu['department']??'')));
                  if($udept!==$lastDept){ if($lastDept!=='') echo '</optgroup>'; echo '<optgroup label="'.htmlspecialchars($udept,ENT_QUOTES).'">'; $lastDept=$udept; }
                  $sel=($hl_user_id===(int)$upu['id'])?'selected':'';
                  echo "<option value='{$upu['id']}' {$sel}>".htmlspecialchars($upu['username'].' — '.$upu['full_name'].' ('.$upu['role'].')',ENT_QUOTES)."</option>";
                endforeach; if($lastDept!=='') echo '</optgroup>'; ?>
              </select>
              <?php if ($hl_user_row): ?>
              <span style="font-size:11px;color:#c084fc;white-space:nowrap">
                <b><?= rmi_h((string)($hl_user_row['department']??'')) ?></b> / <b><?= rmi_h((string)($hl_user_row['role']??'')) ?></b>
                <?php if ($hl_is_privileged): ?>&nbsp;· <span style="color:#fcd34d;font-weight:700">⭐ Privileged</span>
                <?php else: ?>&nbsp;· <span style="color:#6ee7b7"><?= count($hl_effective_map) ?> perm efektif</span>
                <?php endif; ?>
              </span>
              <?php endif; ?>
        </form>
      </div>

          <?php if (!$hl_user_row): ?>
            <div style="padding:32px;text-align:center;color:var(--muted-lt);font-size:13px">👆 Pilih user aktif di atas untuk melihat permission efektifnya per halaman.</div>
          <?php elseif (empty($pageRegistry)): ?>
            <div class="flash warn">config/page_registry.php tidak ditemukan.</div>
          <?php else: ?>
          <div style="overflow-x:auto">
          <table style="width:100%;border-collapse:collapse;font-size:11px;min-width:680px">
            <thead>
              <tr style="background:rgba(0,0,0,.3)">
                <th rowspan="2" style="padding:8px 14px;text-align:left;font-size:10px;color:var(--muted-lt);font-weight:700;position:sticky;left:0;background:rgba(8,13,23,.98);z-index:3;min-width:220px;border-right:1px solid var(--border)">HALAMAN / URL</th>
                <?php if ($hl_is_privileged): ?>
                <th colspan="9" style="padding:6px 8px;text-align:center;font-size:10px;font-weight:700;color:#fcd34d;border-left:2px solid rgba(245,158,11,.4);border-bottom:1px solid var(--border)">⭐ PRIVILEGED — ALLOW ALL DI RUNTIME</th>
                <?php else: ?>
                <th colspan="9" style="padding:6px 8px;text-align:center;font-size:10px;font-weight:700;color:#c084fc;border-left:2px solid rgba(168,85,247,.4);border-bottom:1px solid var(--border)"><?= rmi_h((string)($hl_user_row['username']??'')) ?> · <?= rmi_h((string)($hl_user_row['department']??'')) ?>/<?= rmi_h((string)($hl_user_row['role']??'')) ?></th>
                <?php endif; ?>
              </tr>
              <tr style="background:rgba(0,0,0,.2)">
                <?php foreach ($ACTION_LABELS as $li=>$lbl):
                  $akHint = $ACTION_KEYS[$li] ?? '';
                  $hint   = $ACTION_COL_HINTS[$akHint] ?? '';
                ?>
                <th title="<?= rmi_h($hint) ?>" style="padding:5px 6px;text-align:center;font-size:9px;font-weight:700;color:var(--muted);width:60px;border-left:<?= $li===0?'2px solid rgba(168,85,247,.3)':'none' ?>"><?= rmi_h($lbl) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($pageRegistry as $modName => $pages):
              echo "<tr style='background:rgba(255,255,255,.025);border-top:1px solid rgba(255,255,255,.07)'><td colspan='10' style='padding:7px 14px;font-size:10px;font-weight:800;color:var(--muted-lt);text-transform:uppercase;letter-spacing:.08em;position:sticky;left:0;background:rgba(8,13,23,.92);z-index:1;border-right:1px solid var(--border)'>".htmlspecialchars($modName,ENT_QUOTES)."</td></tr>";
              foreach ($pages as $pg):
                $isSpecial=!empty($pg['special']); $isSysOnly=!empty($pg['sys_only']);
                $note=htmlspecialchars((string)($pg['note']??''),ENT_QUOTES);
                $perms=$pg['perms']??[];
                $label=htmlspecialchars((string)($pg['label']??''),ENT_QUOTES);
                $url=htmlspecialchars((string)($pg['url']??''),ENT_QUOTES);
                echo "<tr style='border-top:1px solid rgba(255,255,255,.04)'>";
                echo "<td style='padding:6px 14px;position:sticky;left:0;background:rgba(8,13,23,.95);z-index:1;border-right:1px solid rgba(255,255,255,.06)'>";
                echo "<div style='font-weight:600;font-size:12px;color:".($isSpecial?'#fcd34d':($isSysOnly?'#6b7280':'#e2e8f0'))."'>{$label}</div>";
                if($url) echo "<div style='font-size:9px;color:#374151;font-family:ui-monospace,monospace;margin-top:1px'>{$url}</div>";
                if($note) echo "<div style='font-size:9px;color:#6b7280;margin-top:2px'>{$note}</div>";
                echo "</td>";
                foreach ($ACTION_KEYS as $ai=>$ak):
                  $pc=$perms[$ak]??null; $bl=$ai===0?'border-left:2px solid rgba(168,85,247,.2);':'';
                  if($pc===null): echo "<td style='{$bl}text-align:center;padding:5px 4px'><span style='font-size:10px;color:#2d3748'>-</span></td>";
                  elseif($isSpecial): echo "<td style='{$bl}text-align:center;padding:5px 4px'><span style='font-size:12px;color:#fcd34d'>⭐</span></td>";
                  elseif($hl_is_privileged): echo "<td style='{$bl}text-align:center;padding:5px 4px'><span style='font-size:14px;font-weight:700;color:#fcd34d'>✓</span></td>";
                  else:
                    $hasIt  = isset($hl_effective_map[$pc]);
                    $ovFlag = $hl_override_map[$pc] ?? -1; // -1=no override, 0=deny, 1=allow
                    $icon   = $hasIt ? '✓' : '✗';
                    // Warna: hijau=ok (matrix), teal=ok (override+), merah=no, oranye=override deny
                    $color  = $hasIt
                        ? ($ovFlag===1 ? '#34d399' : '#6ee7b7')   // teal jika dari override grant
                        : ($ovFlag===0 ? '#f59e0b' : '#ef444455'); // oranye jika override deny
                    $title  = htmlspecialchars($pc,ENT_QUOTES);
                    $srcLbl = $ovFlag===1 ? ' [override+]' : ($ovFlag===0 ? ' [override-]' : '');
                    if ($rbacManage):
                      $btnId  = 'ub-'.md5($hl_user_id.$pc);
                      echo "<td style='{$bl}text-align:center;padding:4px 3px'>";
                      echo "<button id='{$btnId}' type='button' title='{$title}{$srcLbl}'
                               data-uid='".rmi_h((string)$hl_user_id)."'
                               data-perm='".rmi_h($pc)."'
                               data-allowed='".($hasIt?1:0)."'
                               data-csrf='".rmi_h($csrf)."'
                               onclick='hlToggleUser(this)'
                               style='background:none;border:none;cursor:pointer;font-size:14px;font-weight:700;color:{$color};padding:1px 3px;border-radius:4px;transition:color .15s'>{$icon}</button>";
                      echo "</td>";
                    else:
                      echo "<td style='{$bl}text-align:center;padding:5px 4px' title='{$title}{$srcLbl}'><span style='font-size:14px;font-weight:700;color:{$color}'>{$icon}</span></td>";
                    endif;
                  endif;
                endforeach;
                echo "</tr>";
              endforeach;
            endforeach; ?>
            </tbody>
          </table>
    </div>
          <div style="margin-top:8px;padding:7px 14px;font-size:10px;color:var(--muted-lt);background:rgba(0,0,0,.15);border-radius:8px">
            <span style="color:#6ee7b7;font-weight:700">✓</span> Dari matrix &nbsp;·&nbsp;
            <span style="color:#34d399;font-weight:700">✓</span> Override grant &nbsp;·&nbsp;
            <span style="color:#f59e0b;font-weight:700">✗</span> Override deny &nbsp;·&nbsp;
            <span style="color:#ef444455;font-weight:700">✗</span> Tidak punya &nbsp;·&nbsp;
            <span style="color:#2d3748">-</span> N/A &nbsp;·&nbsp;
            <span style="color:#fcd34d">✓</span> Privileged (Allow All)
            <br><b>ACCESS</b> vs <b>VIEW</b>: ACCESS = buka URL/route; VIEW = lihat konten — <span class="pc">config/page_registry.php</span>. Di kode pakai <span class="pc">require_route_access()</span> / <span class="pc">require_content_view()</span> (<span class="pc">master/auth.php</span>); hindari OR dengan *.VIEW lebar di gate route. Matrix + override per user; hover header = keterangan.
          </div>
          <?php endif; ?>

        <?php else: ?>
      <form method="post" action="<?= rmi_h(u('/rbac/index.php')) ?>" id="rbac-matrix-form">
        <input type="hidden" name="csrf" value="<?= rmi_h($csrf) ?>">
        <input type="hidden" name="action" value="save_matrix">
        <input type="hidden" name="dept_code" value="<?= rmi_h($dept_sel) ?>">
        <input type="hidden" name="role_code" value="<?= rmi_h($role_sel) ?>">
        <input type="hidden" name="rbac_layout" value="<?= rmi_h($rbac_matrix_layout) ?>">
        <?php if ($rbacManage): ?>
        <input type="hidden" name="matrix_confirmed" value="0">
        <script type="application/json" id="rbac-matrix-initial"><?= $matrixInitialJson ?></script>
        <?php endif; ?>
        <?php
        $expandFirst = ['DASHBOARD', 'MASTER', 'SALES'];
        foreach ($byModule as $mod => $plist):
          $cnt = count($plist);
          $en = (int)($modEnabled[$mod] ?? 0);
          $pct = $cnt > 0 ? round($en / $cnt * 100) : 0;
          $collapsed = !in_array($mod, $expandFirst, true);
          $icon = $modIcons[$mod] ?? '📁';
          $barClass = $pct >= 100 ? 'full' : ($pct === 0 ? 'none' : '');
          ?>
          <div class="mod-block <?= $collapsed ? 'collapsed' : '' ?>" data-module="<?= rmi_h($mod) ?>">
            <div class="mod-head" onclick="this.closest('.mod-block').classList.toggle('collapsed')">
              <div class="mod-icon"><?= $icon ?></div>
              <div class="mod-name"><?= rmi_h($mod) ?></div>
              <div class="mod-progress-wrap">
                <div class="mod-count-txt" data-mod="<?= rmi_h($mod) ?>"><?= $en ?>/<?= $cnt ?></div>
                <div class="mod-progress">
                  <div class="mod-progress-bar <?= $barClass ?>" style="width:<?= $pct ?>%" data-mod-bar="<?= rmi_h($mod) ?>"></div>
    </div>
              </div>
              <div class="mod-chevron">▼</div>
            </div>
            <?php if ($rbacManage): ?>
            <div class="mod-actions mod-content" onclick="event.stopPropagation()">
              <button class="btn sm" type="button" onclick="toggleModulePerms(this,true,null)">✓ All</button>
              <button class="btn sm" type="button" onclick="toggleModulePerms(this,false,null)">✕ Clear</button>
              <button class="btn sm" type="button" onclick="toggleModulePerms(this,true,'VIEW')">👁 VIEW</button>
              <button class="btn sm" type="button" onclick="toggleModulePerms(this,true,'CRUD')">✏ CRUD</button>
            </div>
            <?php endif; ?>
            <table class="perm-table mod-content">
              <tbody>
              <?php foreach ($plist as $p):
                $pc = (string)$p['perm_code'];
                $isChecked = ((int)($rules_map[$pc] ?? 0) === 1);
                $active = (int)($p['is_active'] ?? 1);
                $cat = (string)($p['category'] ?? 'OTHER');
                ?>
                <tr class="permRow" data-search="<?= rmi_h(strtolower($mod.' '.$pc.' '.$p['perm_name'].' '.($p['description'] ?? ''))) ?>" data-category="<?= rmi_h($cat) ?>" data-mod="<?= rmi_h($mod) ?>" data-staff-mgr-tier="<?= rmi_h((string)($p['staff_mgr_tier'] ?? 'TECH_OTHER')) ?>">
                  <td class="perm-toggle-col">
                    <label class="toggle">
                      <input class="permChk" type="checkbox" name="allow[]" value="<?= rmi_h($pc) ?>" <?= $isChecked ? 'checked' : '' ?> <?= ($active && $rbacManage) ? '' : ' disabled' ?> onchange="updateModCountForBlock(this)">
                      <span class="toggle-slider"></span>
                    </label>
                  </td>
                  <td style="min-width:200px">
                    <div class="pc"><?= rmi_h($pc) ?></div>
                    <?php if (!$active): ?><div class="tiny" style="color:var(--warn)">inactive</div><?php endif; ?>
                  </td>
                  <td>
                    <span class="perm-name"><?= rmi_h((string)$p['perm_name']) ?></span>
                    <span class="cat-badge cat-<?= rmi_h($cat) ?>"><?= rmi_h($cat) ?></span>
                    <?php if (!empty($p['description'])): ?>
                      <div class="perm-desc"><?= rmi_h((string)$p['description']) ?></div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      </form>
        <?php endif; ?>
  </div>

    </div><!-- /matrix -->

  <div class="rc-section">
    <div class="rc-section-head">
      <h3>📋 Audit Log RBAC</h3>
      <?php if (!empty($audit_rows)): ?>
        <span style="font-size:11px;color:var(--muted-lt)"><?= count($audit_rows) ?> entry terbaru</span>
      <?php endif; ?>
    </div>
    <?php if (empty($audit_rows)): ?>
      <div class="audit-empty">Belum ada audit log RBAC.</div>
    

<?php else: ?>
    <div class="audit-scroll">
      <table class="audit-tbl">
        <thead>
          <tr>
            <th style="width:140px">Waktu</th>
            <th style="width:130px">Action</th>
            <th style="width:160px">Record Code</th>
            <th style="width:120px">User</th>
            <th>Keterangan</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($audit_rows as $a):
            $act = strtoupper(trim((string)($a['action'] ?? '')));
            $actClass = match(true) {
              in_array($act, ['SAVE','SAVE_MATRIX','UPDATE'], true)     => 'audit-act-SAVE',
              in_array($act, ['SYNC','SYNC_PERMISSIONS'], true)          => 'audit-act-SYNC',
              in_array($act, ['DELETE','PRUNE','PRUNE_ORPHAN'], true)    => 'audit-act-DELETE',
              in_array($act, ['GRANT','GRANT_ALL','GRANT_ALL_SYS'], true)=> 'audit-act-GRANT',
              in_array($act, ['IMPORT','COPY','COPY_FROM'], true)        => 'audit-act-IMPORT',
              in_array($act, ['BASELINE','PRESET','APPLY_PRESET'], true) => 'audit-act-BASELINE',
              default                                                    => 'audit-act-default',
            };
          ?>
          <tr>
            <td><span class="audit-time"><?= rmi_h($a['created_at'] ?? '') ?></span></td>
            <td><span class="audit-act <?= rmi_h($actClass) ?>"><?= rmi_h($act) ?></span></td>
            <td><span class="audit-code"><?= rmi_h($a['record_code'] ?? '—') ?></span></td>
            <td><span class="audit-user"><?= rmi_h($a['username'] ?? '—') ?></span></td>
            <td><span class="audit-desc"><?= rmi_h($a['description'] ?? '') ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  </div><!-- /layout -->

</div><!-- /rc-wrap -->

  <script>
// ── Dept / Role selector

function switchTab2(el, panelId) {
  const tabs = el.closest('.rc-tabs').querySelectorAll('.rc-tab');
  tabs.forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  const body = el.closest('.rc-tools-body');
  if (!body) return;
  body.querySelectorAll('.rc-tab-panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById(panelId);
  if (panel) panel.classList.add('active');
}

function selectDept(code) {
  document.getElementById('f-dept').value = code;
  document.querySelectorAll('.dept-card').forEach(c => {
    c.classList.toggle('active', (c.getAttribute('data-dept-code') || '') === code);
  });
  var fr = document.getElementById('f-role');
  if (!fr) return;
  if (code === 'SYS') {
    fr.value = 'SYS';
    document.querySelectorAll('.role-pill').forEach(p => {
      p.classList.toggle('active', (p.getAttribute('data-role-code') || '') === 'SYS');
    });
  } else {
    // Keluar dari dept SYS: role sys tidak valid — default MANAGER sampai user klik Tampilkan (server menyelaraskan).
    if (fr.value === 'SYS') fr.value = 'MANAGER';
    document.querySelectorAll('.role-pill').forEach(p => {
      p.classList.toggle('active', (p.getAttribute('data-role-code') || '') === fr.value);
    });
  }
}
function selectRole(code) {
  document.getElementById('f-role').value = code;
  document.querySelectorAll('.role-pill').forEach(p => {
    p.classList.toggle('active', (p.getAttribute('data-role-code') || '') === code);
  });
}

// ── Tabs
function switchTab(tab, panelId) {
  tab.closest('.rc-panel').querySelectorAll('.rc-tab').forEach(t => t.classList.remove('active'));
  tab.closest('.rc-panel').querySelectorAll('.rc-tab-panel').forEach(p => p.classList.remove('active'));
  tab.classList.add('active');
  document.getElementById(panelId)?.classList.add('active');
}

// ── Filters
const search   = document.getElementById('permSearch');
    const category = document.getElementById('permCategory');
const tierSel  = document.getElementById('permStaffMgrTier');
const permRows = Array.from(document.querySelectorAll('.permRow'));

function applyFilters() {
      const q = (search.value || '').trim().toLowerCase();
      const cat = (category?.value || 'ALL').toUpperCase();
  const tierF = (tierSel?.value || 'ALL').toUpperCase();
  permRows.forEach(r => {
        const hay = r.getAttribute('data-search') || '';
    const rc  = (r.getAttribute('data-category') || '').toUpperCase();
    const rt  = (r.getAttribute('data-staff-mgr-tier') || '').toUpperCase();
    const okTier = tierF === 'ALL' || rt === tierF;
    r.style.display = ((q === '' || hay.includes(q)) && (cat === 'ALL' || rc === cat) && okTier) ? '' : 'none';
  });
  document.querySelectorAll('.mod-block').forEach(m => {
    const any = Array.from(m.querySelectorAll('.permRow')).some(r => r.style.display !== 'none');
    m.style.display = any ? '' : 'none';
  });
  document.querySelectorAll('.cat-block').forEach(cb => {
    const any = Array.from(cb.querySelectorAll('.mod-block')).some(m => m.style.display !== 'none');
    cb.style.display = any ? '' : 'none';
  });
    }
    search?.addEventListener('input', applyFilters);
    category?.addEventListener('change', applyFilters);
tierSel?.addEventListener('change', applyFilters);

// ── Toggle helpers
function toggleModulePerms(btn, flag, cat) {
  const mod = btn.closest('.mod-block');
  if (!mod) return;
  mod.querySelectorAll('.permRow').forEach(r => {
    if (r.style.display === 'none') return;
    if (cat && (r.getAttribute('data-category') || '').toUpperCase() !== cat.toUpperCase()) return;
    const c = r.querySelector('.permChk');
    if (c && !c.disabled) c.checked = !!flag;
  });
  const chk = mod.querySelector('.permChk');
  if (chk) updateModCountForBlock(chk);
}

// ── Live stats counters (per blok modul — aman untuk layout Per modul & Per tipe)
function updateModCountForBlock(el) {
  const block = el.closest('.mod-block');
  if (!block) return;
  const all = block.querySelectorAll('.permChk:not([disabled])');
  const en = block.querySelectorAll('.permChk:not([disabled]):checked');
  const pct = all.length > 0 ? Math.round(en.length / all.length * 100) : 0;
  const txt = block.querySelector('.mod-count-txt');
  const bar = block.querySelector('.mod-progress-bar');
  if (txt) txt.textContent = en.length + '/' + all.length;
  if (bar) {
    bar.style.width = pct + '%';
    bar.className = 'mod-progress-bar' + (pct >= 100 ? ' full' : pct === 0 ? ' none' : '');
  }
  updateGlobalStats();
}
function updateAllModCounts() {
  const seen = new Set();
  document.querySelectorAll('.permChk').forEach(c => {
    const block = c.closest('.mod-block');
    if (!block || seen.has(block)) return;
    seen.add(block);
    updateModCountForBlock(c);
  });
}
function updateGlobalStats() {
  const total = document.querySelectorAll('.permChk:not([disabled])').length;
  const en    = document.querySelectorAll('.permChk:not([disabled]):checked').length;
  const pct   = total > 0 ? Math.round(en / total * 100) : 0;
  const el = document.getElementById('stat-enabled');
  const bar = document.getElementById('stat-bar');
  const pctEl = document.getElementById('stat-pct');
  if (el) el.textContent = en;
  if (bar) bar.style.width = pct + '%';
  if (pctEl) pctEl.textContent = pct + '%';
}

applyFilters();
// fetch + redirect:'manual' → browser sering mengembalikan type "opaqueredirect" + status 0 (Location tidak bisa dibaca).
function rbacFetchAfterToggle(r) {
  // Lihat: https://fetch.spec.whatwg.org/#atomic-http-redirect-handling — respons redirect terfilter sering status 0
  if (r.type === 'opaqueredirect' || r.status === 0) {
    window.location.reload();
    return true;
  }
  if (r.status >= 300 && r.status < 400) {
    var loc = r.headers.get('Location');
    window.location.href = loc || window.location.href;
    return true;
  }
  return false;
}
// ── Per Halaman: toggle permission via fetch (tanpa nested form) ───────────
function hlToggleUser(btn) {
  var uid     = btn.dataset.uid;
  var perm    = btn.dataset.perm;
  var csrf    = btn.dataset.csrf;
  var allowed = parseInt(btn.dataset.allowed, 10);

  btn.disabled = true;
  btn.style.opacity = '0.5';

  var fd = new FormData();
  fd.append('action',    'toggle_user_perm');
  fd.append('user_id',   uid);
  fd.append('perm_code', perm);
  fd.append('current',   String(allowed));
  fd.append('csrf',      csrf);

  var params = new URLSearchParams(window.location.search);
  var url = window.location.pathname
    + '?rbac_layout=user'
    + '&hl_user=' + encodeURIComponent(uid)
    + '&dept_code=' + encodeURIComponent(params.get('dept_code') || '')
    + '&hl_dept=' + encodeURIComponent(params.get('hl_dept') || params.get('dept_code') || '');

  // redirect: 'manual' — jangan ikuti 302 otomatis. Jika ikuti, r.ok jadi true walau CSRF/validasi gagal
  // (server redirect + flash error), sehingga UI ter-update padahal DB tidak berubah.
  fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual' })
    .then(function(r) {
      if (rbacFetchAfterToggle(r)) return;
      if (!r.ok) {
        alert('Toggle gagal: HTTP ' + r.status + (r.status === 403 ? ' (CSRF atau akses ditolak)' : ''));
        return;
      }
      var newAllowed = allowed ? 0 : 1;
      btn.dataset.allowed = String(newAllowed);
      btn.textContent = newAllowed ? '✓' : '✗';
      btn.style.color = newAllowed ? '#34d399' : '#ef444455';
      btn.title = perm + (newAllowed ? ' [override+]' : ' [override-]');
      btn.style.transform = 'scale(1.4)';
      setTimeout(function(){ btn.style.transform = 'scale(1)'; }, 200);
    })
    .catch(function(e) { alert('Error: ' + e.message); })
    .finally(function() { btn.disabled = false; btn.style.opacity = '1'; });
}

<?php if ($rbacManage): ?>
(function(){
  var form = document.getElementById('rbac-matrix-form');
  if (!form) return;
  var initialEl = document.getElementById('rbac-matrix-initial');
  if (!initialEl) return;
  var initial = [];
  try { initial = JSON.parse(initialEl.textContent || '[]'); } catch (e) {}
  if (!Array.isArray(initial)) initial = [];
  form.addEventListener('submit', function(e) {
    var confirmed = form.querySelector('input[name="matrix_confirmed"]');
    if (!confirmed || confirmed.value === '1') return;
    e.preventDefault();
    var checked = [];
    form.querySelectorAll('.permChk:checked:not([disabled])').forEach(function(c) { checked.push(c.value); });
    checked.sort();
    var before = initial.slice().sort();
    var added = checked.filter(function(x) { return before.indexOf(x) < 0; });
    var removed = before.filter(function(x) { return checked.indexOf(x) < 0; });
    if (added.length === 0 && removed.length === 0) {
      confirmed.value = '1';
      form.submit();
      return;
    }
    var msg = 'Simpan perubahan matrix?\n\n';
    if (added.length) msg += '+ Ditambah (' + added.length + '): ' + added.slice(0, 14).join(', ') + (added.length > 14 ? ' …' : '') + '\n';
    if (removed.length) msg += '− Dihapus (' + removed.length + '): ' + removed.slice(0, 14).join(', ') + (removed.length > 14 ? ' …' : '') + '\n';
    if (!window.confirm(msg)) return;
    confirmed.value = '1';
    form.submit();
  });
})();
<?php endif; ?>
</script>

<?php rmi_footer(); ?>
