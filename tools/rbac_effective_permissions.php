<?php
declare(strict_types=1);

/**
 * RBAC Effective Permissions — diagnostik total: katalog config, cek satu, scan batch session/target.
 */

@set_time_limit(180);

require_once __DIR__ . '/tools_remote_check.php';

require_once __DIR__ . '/../master/auth.php';
require_login();
require_any_permission(['SYSTEM.RBAC_MANAGE', 'SYSTEM.USER_MANAGE']);
require_once __DIR__ . '/../_shared/rmi_layout.php';

if (!function_exists('rpe_h')) {
    function rpe_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * @return array{allow:bool, matrix_role:string, candidates:list<string>, steps:list<string>, source:string, privileged:bool}
 */
function rpe_evaluate_rbac_detailed(
    PDO $pdo,
    string $permInput,
    string $targetDept,
    string $targetRoleRaw,
    string $targetLevelRaw,
    int $targetUserId,
    bool $rbacReady
): array {
    $steps = [];
    $candidates = function_exists('rbac_alias_candidates') ? rbac_alias_candidates($permInput) : [];
    if ($permInput === '' || $candidates === []) {
        return [
            'allow' => false,
            'matrix_role' => '',
            'candidates' => $candidates,
            'steps' => ['Permission code kosong atau tidak menghasilkan kandidat SQL (cek penulisan, contoh: PURCHASES.VIEW).'],
            'source' => 'invalid_perm',
            'privileged' => false,
        ];
    }

    $steps[] = 'Kandidat kode untuk query DB (termasuk alias legacy): <code>' . implode('</code>, <code>', array_slice($candidates, 0, 12)) . (count($candidates) > 12 ? '</code> …' : '</code>');

    $r = function_exists('rbac_norm_code') ? rbac_norm_code($targetRoleRaw) : strtoupper(trim($targetRoleRaw));
    $l = function_exists('rbac_norm_code') ? rbac_norm_code($targetLevelRaw) : strtoupper(trim($targetLevelRaw));
    $privileged = in_array($r, ['SYS', 'ADMIN', 'SUPERADMIN'], true)
        || in_array($l, ['SYS', 'ADMIN', 'SUPERADMIN'], true);

    if ($privileged) {
        $mr = function_exists('rbac_matrix_role_from_role_level_strings')
            ? rbac_matrix_role_from_role_level_strings($targetRoleRaw, $targetLevelRaw)
            : 'SYS';
        $steps[] = 'Role/level target mengandung SYS / ADMIN / SUPERADMIN → <strong>bypass matrix</strong> (setara <code>rbac_is_privileged_session()</code>).';

        return [
            'allow' => true,
            'matrix_role' => $mr,
            'candidates' => $candidates,
            'steps' => $steps,
            'source' => 'privileged',
            'privileged' => true,
        ];
    }

    $matrixRole = function_exists('rbac_matrix_role_from_role_level_strings')
        ? rbac_matrix_role_from_role_level_strings($targetRoleRaw, $targetLevelRaw)
        : 'STAFF';
    $steps[] = 'Role matrix efektif (join ke <code>rbac_dept_role_permissions.role_code</code>): <strong>' . $matrixRole . '</strong> ← dari role=<code>' . rpe_h($r) . '</code> + level=<code>' . rpe_h($l) . '</code>.';

    $dept = function_exists('rbac_norm_code') ? rbac_norm_code($targetDept) : strtoupper(trim($targetDept));
    if ($dept === '') {
        $steps[] = 'Department kosong → matrix tidak bisa di-query → <span class="badge text-bg-danger">DENY</span>.';

        return [
            'allow' => false,
            'matrix_role' => $matrixRole,
            'candidates' => $candidates,
            'steps' => $steps,
            'source' => 'no_dept',
            'privileged' => false,
        ];
    }

    if (!$rbacReady) {
        $steps[] = 'Tabel RBAC belum ready (<code>auth_rbac_ready()</code>=false) → simulasi matrix tidak dipakai.';

        return [
            'allow' => false,
            'matrix_role' => $matrixRole,
            'candidates' => $candidates,
            'steps' => $steps,
            'source' => 'rbac_not_ready',
            'privileged' => false,
        ];
    }

    if ($targetUserId > 0) {
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $sql = "SELECT perm_code, allow_flag FROM rbac_user_permissions WHERE user_id=? AND perm_code IN ($in) ORDER BY allow_flag DESC LIMIT 1";
        try {
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$targetUserId], $candidates));
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row = false;
            $steps[] = 'Error query rbac_user_permissions: ' . $e->getMessage();
        }
        if (is_array($row)) {
            $af = (int)($row['allow_flag'] ?? 0);
            $pc = (string)($row['perm_code'] ?? '');
            $allow = $af === 1;
            $steps[] = 'Baris <strong>rbac_user_permissions</strong>: user_id=' . (int)$targetUserId . ', perm_code=<code>' . rpe_h($pc) . '</code>, allow_flag=<strong>' . $af . '</strong> → '
                . ($allow ? '<span class="badge text-bg-success">ALLOW</span>' : '<span class="badge text-bg-danger">DENY</span>') . ' (override mengalahkan matrix).';

            return [
                'allow' => $allow,
                'matrix_role' => $matrixRole,
                'candidates' => $candidates,
                'steps' => $steps,
                'source' => 'user_override',
                'privileged' => false,
            ];
        }
        $steps[] = 'Tidak ada baris di <code>rbac_user_permissions</code> untuk user_id=' . (int)$targetUserId . ' + kandidat permission → lanjut ke matrix dept×role.';
    } else {
        $steps[] = 'Target user_id = 0 (tidak dicek override per user) → langsung matrix dept×role.';
    }

    $in = implode(',', array_fill(0, count($candidates), '?'));
    $sql = "SELECT perm_code, allow_flag FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code=? AND perm_code IN ($in) ORDER BY allow_flag DESC LIMIT 1";
    try {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$dept, $matrixRole], $candidates));
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
        $steps[] = 'Error query rbac_dept_role_permissions: ' . $e->getMessage();
    }

    if (!is_array($row)) {
        $steps[] = 'Query matrix: dept=<code>' . rpe_h($dept) . '</code>, role=<code>' . rpe_h($matrixRole) . '</code>, perm IN (kandidat) → <strong>tidak ada baris</strong> → <span class="badge text-bg-danger">DENY</span>.';

        return [
            'allow' => false,
            'matrix_role' => $matrixRole,
            'candidates' => $candidates,
            'steps' => $steps,
            'source' => 'matrix_miss',
            'privileged' => false,
        ];
    }

    $af = (int)($row['allow_flag'] ?? 0);
    $pc = (string)($row['perm_code'] ?? '');
    $allow = $af === 1;
    $steps[] = 'Baris <strong>rbac_dept_role_permissions</strong>: dept=<code>' . rpe_h($dept) . '</code>, role=<code>' . rpe_h($matrixRole) . '</code>, perm_code=<code>' . rpe_h($pc) . '</code>, allow_flag=<strong>' . $af . '</strong> → '
        . ($allow ? '<span class="badge text-bg-success">ALLOW</span>' : '<span class="badge text-bg-danger">DENY</span>') . '.';

    return [
        'allow' => $allow,
        'matrix_role' => $matrixRole,
        'candidates' => $candidates,
        'steps' => $steps,
        'source' => 'matrix',
        'privileged' => false,
    ];
}

/**
 * Map perm_code => allow_flag (ambil allow_flag tertinggi jika duplikat).
 *
 * @return array<string,int>
 */
function rpe_load_user_perm_map(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $out = [];
    try {
        $st = $pdo->prepare('SELECT perm_code, allow_flag FROM rbac_user_permissions WHERE user_id = ?');
        $st->execute([$userId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pc = function_exists('rbac_norm_code') ? rbac_norm_code((string)($row['perm_code'] ?? '')) : strtoupper(trim((string)($row['perm_code'] ?? '')));
            if ($pc === '') {
                continue;
            }
            $af = (int)($row['allow_flag'] ?? 0);
            if (!isset($out[$pc]) || $af > $out[$pc]) {
                $out[$pc] = $af;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $out;
}

/**
 * @return array<string,int>
 */
function rpe_load_matrix_perm_map(PDO $pdo, string $dept, string $matrixRole): array
{
    $out = [];
    $dept = function_exists('rbac_norm_code') ? rbac_norm_code($dept) : strtoupper(trim($dept));
    $matrixRole = function_exists('rbac_norm_code') ? rbac_norm_code($matrixRole) : strtoupper(trim($matrixRole));
    if ($dept === '' || $matrixRole === '') {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT perm_code, allow_flag FROM rbac_dept_role_permissions WHERE dept_code = ? AND role_code = ?');
        $st->execute([$dept, $matrixRole]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pc = function_exists('rbac_norm_code') ? rbac_norm_code((string)($row['perm_code'] ?? '')) : strtoupper(trim((string)($row['perm_code'] ?? '')));
            if ($pc === '') {
                continue;
            }
            $af = (int)($row['allow_flag'] ?? 0);
            if (!isset($out[$pc]) || $af > $out[$pc]) {
                $out[$pc] = $af;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $out;
}

/**
 * @return array{allow:bool, source:string, matrix_role:string}
 */
function rpe_evaluate_fast(
    string $permCode,
    string $targetDept,
    string $targetRoleRaw,
    string $targetLevelRaw,
    int $targetUserId,
    bool $rbacReady,
    array $userMap,
    array $matrixMap
): array {
    $candidates = function_exists('rbac_alias_candidates') ? rbac_alias_candidates($permCode) : [];
    if ($permCode === '' || $candidates === []) {
        return ['allow' => false, 'source' => 'invalid_perm', 'matrix_role' => ''];
    }

    $r = function_exists('rbac_norm_code') ? rbac_norm_code($targetRoleRaw) : strtoupper(trim($targetRoleRaw));
    $l = function_exists('rbac_norm_code') ? rbac_norm_code($targetLevelRaw) : strtoupper(trim($targetLevelRaw));
    if (in_array($r, ['SYS', 'ADMIN', 'SUPERADMIN'], true) || in_array($l, ['SYS', 'ADMIN', 'SUPERADMIN'], true)) {
        $mr = function_exists('rbac_matrix_role_from_role_level_strings')
            ? rbac_matrix_role_from_role_level_strings($targetRoleRaw, $targetLevelRaw)
            : 'SYS';

        return ['allow' => true, 'source' => 'privileged', 'matrix_role' => $mr];
    }

    $matrixRole = function_exists('rbac_matrix_role_from_role_level_strings')
        ? rbac_matrix_role_from_role_level_strings($targetRoleRaw, $targetLevelRaw)
        : 'STAFF';
    $dept = function_exists('rbac_norm_code') ? rbac_norm_code($targetDept) : strtoupper(trim($targetDept));
    if ($dept === '') {
        return ['allow' => false, 'source' => 'no_dept', 'matrix_role' => $matrixRole];
    }
    if (!$rbacReady) {
        return ['allow' => false, 'source' => 'rbac_not_ready', 'matrix_role' => $matrixRole];
    }

    if ($targetUserId > 0 && $userMap !== []) {
        $bestAf = null;
        foreach ($candidates as $c) {
            $c = function_exists('rbac_norm_code') ? rbac_norm_code((string)$c) : strtoupper(trim((string)$c));
            if ($c === '' || !array_key_exists($c, $userMap)) {
                continue;
            }
            $af = (int)$userMap[$c];
            if ($bestAf === null || $af > $bestAf) {
                $bestAf = $af;
            }
        }
        if ($bestAf !== null) {
            return ['allow' => $bestAf === 1, 'source' => 'user_override', 'matrix_role' => $matrixRole];
        }
    }

    $bestM = null;
    foreach ($candidates as $c) {
        $c = function_exists('rbac_norm_code') ? rbac_norm_code((string)$c) : strtoupper(trim((string)$c));
        if ($c === '' || !array_key_exists($c, $matrixMap)) {
            continue;
        }
        $af = (int)$matrixMap[$c];
        if ($bestM === null || $af > $bestM) {
            $bestM = $af;
        }
    }
    if ($bestM !== null) {
        return ['allow' => $bestM === 1, 'source' => 'matrix', 'matrix_role' => $matrixRole];
    }

    return ['allow' => false, 'source' => 'matrix_miss', 'matrix_role' => $matrixRole];
}

// ── Katalog dari config (SSOT) ─────────────────────────────────────────────
$catalogPath = dirname(__DIR__) . '/config/rbac_permissions.php';
$catalog = (is_file($catalogPath) && is_readable($catalogPath)) ? require $catalogPath : [];
if (!is_array($catalog)) {
    $catalog = [];
}
$catalogRows = [];
foreach ($catalog as $row) {
    if (!is_array($row) || !isset($row[0]) || !is_string($row[0]) || $row[0] === '') {
        continue;
    }
    $catalogRows[] = [
        'code' => strtoupper(trim($row[0])),
        'name' => (string)($row[1] ?? ''),
        'module' => strtoupper(trim((string)($row[2] ?? '?'))),
        'desc' => (string)($row[3] ?? ''),
    ];
}
usort($catalogRows, static function (array $a, array $b): int {
    $m = strcmp($a['module'], $b['module']);

    return $m !== 0 ? $m : strcmp($a['code'], $b['code']);
});

$modulesInCatalog = [];
foreach ($catalogRows as $cr) {
    $modulesInCatalog[$cr['module']] = true;
}
$moduleList = array_keys($modulesInCatalog);
sort($moduleList);

$pdo = auth_pdo();
$permInput = strtoupper(trim((string)($_GET['perm'] ?? 'PURCHASES.VIEW')));
$deptInput = strtoupper(trim((string)($_GET['dept'] ?? auth_dept())));
$targetUserId = (int)($_GET['target_user_id'] ?? 0);
$targetRole = strtoupper(trim((string)($_GET['target_role'] ?? '')));
$targetLevel = strtoupper(trim((string)($_GET['target_level'] ?? '')));
$targetDept = strtoupper(trim((string)($_GET['target_dept'] ?? '')));
$catalogFilterModule = strtoupper(trim((string)($_GET['cat_mod'] ?? '')));
$syncFromLogin = isset($_GET['sync_from_login']) && $_GET['sync_from_login'] === '1';
$action = (string)($_GET['action'] ?? '');
$exportScan = isset($_GET['export_scan']) && $_GET['export_scan'] === '1';

$rbacReady = auth_rbac_ready();

$dbPermCodes = [];
if ($pdo && $rbacReady) {
    try {
        $st = $pdo->query('SELECT perm_code FROM rbac_permissions WHERE is_active=1');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pc = strtoupper(trim((string)($row['perm_code'] ?? '')));
            if ($pc !== '') {
                $dbPermCodes[$pc] = true;
            }
        }
    } catch (Throwable $e) {
        $dbPermCodes = [];
    }
}

$current = [
    'username' => auth_username(),
    'role' => auth_role(),
    'department' => auth_dept(),
    'level' => auth_level(),
    'user_id' => (int)(auth_user()['user_id'] ?? 0),
];

$matrixRoleSession = function_exists('rbac_matrix_role_for_dept_permissions')
    ? rbac_matrix_role_for_dept_permissions()
    : '';

if ($targetDept === '') {
    $targetDept = $current['department'];
}
if ($targetRole === '') {
    $targetRole = $current['role'];
}
if ($targetLevel === '') {
    $targetLevel = $current['level'];
}

$loadedUserRow = null;
if ($syncFromLogin && $targetUserId > 0 && $pdo) {
    try {
        $st = $pdo->prepare('SELECT id, username, full_name, department, role, level, status FROM master_system_login WHERE id = ? LIMIT 1');
        $st->execute([$targetUserId]);
        $loadedUserRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (is_array($loadedUserRow)) {
            $targetDept = strtoupper(trim((string)($loadedUserRow['department'] ?? '')));
            $targetRole = strtoupper(trim((string)($loadedUserRow['role'] ?? '')));
            $targetLevel = strtoupper(trim((string)($loadedUserRow['level'] ?? $loadedUserRow['role'] ?? '')));
        }
    } catch (Throwable $e) {
        $loadedUserRow = null;
    }
}

$permResult = $permInput !== '' ? can($permInput) : false;
$deptResult = $deptInput !== '' ? can_dept($deptInput) : false;

$sessionDetail = null;
$targetDetail = null;
$scanSessionRows = null;
$scanTargetRows = null;
$scanSessionSummary = null;
$scanTargetSummary = null;

if ($permInput !== '' && $action === 'session') {
    if (function_exists('auth_is_admin') && auth_is_admin()) {
        $sessionDetail = [
            'allow' => true,
            'matrix_role' => $matrixRoleSession,
            'candidates' => function_exists('rbac_alias_candidates') ? rbac_alias_candidates($permInput) : [],
            'steps' => [
                '<code>auth_is_admin()</code> = true (SYS tier) → <code>can()</code> mengembalikan <strong>ALLOW</strong> sebelum matrix DB.',
            ],
            'source' => 'auth_is_admin',
            'privileged' => true,
        ];
    } elseif ($pdo) {
        $sessionDetail = rpe_evaluate_rbac_detailed(
            $pdo,
            $permInput,
            $current['department'],
            $current['role'],
            $current['level'],
            $current['user_id'],
            $rbacReady
        );
    } else {
        $sessionDetail = [
            'allow' => false,
            'matrix_role' => $matrixRoleSession,
            'candidates' => [],
            'steps' => ['Koneksi DB tidak tersedia.'],
            'source' => 'no_pdo',
            'privileged' => false,
        ];
    }
    $sessionDetail['can_runtime'] = $permResult;
}

if ($permInput !== '' && $action === 'target' && $pdo) {
    $targetDetail = rpe_evaluate_rbac_detailed(
        $pdo,
        $permInput,
        $targetDept,
        $targetRole,
        $targetLevel,
        $targetUserId,
        $rbacReady
    );
} elseif ($permInput !== '' && $action === 'target' && !$pdo) {
    $targetDetail = [
        'allow' => false,
        'matrix_role' => '',
        'candidates' => [],
        'steps' => ['Koneksi DB tidak tersedia.'],
        'source' => 'no_pdo',
        'privileged' => false,
    ];
}

// ── Batch scan session (can() per katalog) ───────────────────────────────────
if ($action === 'scan_session' && $catalogRows === []) {
    $scanSessionRows = [];
    $scanSessionSummary = ['total' => 0, 'allow' => 0, 'deny' => 0, 'error' => 'Katalog kosong.'];
} elseif ($action === 'scan_session' && $catalogRows !== []) {
    $scanSessionRows = [];
    $nAllow = 0;
    $nDeny = 0;
    foreach ($catalogRows as $cr) {
        $code = $cr['code'];
        $ok = can($code);
        if ($ok) {
            ++$nAllow;
        } else {
            ++$nDeny;
        }
        $src = '';
        if (function_exists('auth_is_admin') && auth_is_admin()) {
            $src = 'auth_is_admin';
        } elseif (function_exists('rbac_is_privileged_session') && rbac_is_privileged_session()) {
            $src = 'privileged_session';
        } else {
            $src = 'rbac_can';
        }
        $scanSessionRows[] = ['code' => $code, 'module' => $cr['module'], 'name' => $cr['name'], 'allow' => $ok, 'source' => $src];
    }
    $scanSessionSummary = ['total' => count($scanSessionRows), 'allow' => $nAllow, 'deny' => $nDeny, 'error' => ''];
}

// ── Batch scan target (simulasi cepat) ─────────────────────────────────────
$targetMatrixRoleForScan = '';
$scanTargetError = '';
if ($action === 'scan_target' && !$pdo) {
    $scanTargetError = 'Koneksi database tidak tersedia (auth_pdo).';
} elseif ($action === 'scan_target' && $catalogRows === []) {
    $scanTargetError = 'Katalog config/rbac_permissions.php kosong atau tidak terbaca.';
} elseif ($pdo && $catalogRows !== [] && $action === 'scan_target') {
    $targetMatrixRoleForScan = function_exists('rbac_matrix_role_from_role_level_strings')
        ? rbac_matrix_role_from_role_level_strings($targetRole, $targetLevel)
        : '';
    $userMap = rpe_load_user_perm_map($pdo, $targetUserId);
    $matrixMap = rpe_load_matrix_perm_map($pdo, $targetDept, $targetMatrixRoleForScan);
    $scanTargetRows = [];
    $nAllow = 0;
    $nDeny = 0;
    $bySource = [];
    foreach ($catalogRows as $cr) {
        $ev = rpe_evaluate_fast(
            $cr['code'],
            $targetDept,
            $targetRole,
            $targetLevel,
            $targetUserId,
            $rbacReady,
            $userMap,
            $matrixMap
        );
        if ($ev['allow']) {
            ++$nAllow;
        } else {
            ++$nDeny;
        }
        $src = $ev['source'];
        $bySource[$src] = ($bySource[$src] ?? 0) + 1;
        $scanTargetRows[] = [
            'code' => $cr['code'],
            'module' => $cr['module'],
            'name' => $cr['name'],
            'allow' => $ev['allow'],
            'source' => $src,
        ];
    }
    $scanTargetSummary = [
        'total' => count($scanTargetRows),
        'allow' => $nAllow,
        'deny' => $nDeny,
        'by_source' => $bySource,
        'matrix_role' => $targetMatrixRoleForScan,
        'user_override_rows' => count($userMap),
        'matrix_rows_slice' => count($matrixMap),
    ];
}

// ── Export TSV ───────────────────────────────────────────
if ($exportScan && $action === 'scan_session' && is_array($scanSessionRows)) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="rbac_scan_session_' . date('Ymd_His') . '.tsv"');
    echo "perm_code\tmodule\tperm_name\tcan()\tsource_hint\n";
    foreach ($scanSessionRows as $r) {
        echo $r['code'] . "\t" . $r['module'] . "\t" . str_replace(["\t", "\n", "\r"], ' ', $r['name']) . "\t" . ($r['allow'] ? '1' : '0') . "\t" . $r['source'] . "\n";
    }
    exit;
}
if ($exportScan && $action === 'scan_target' && is_array($scanTargetRows)) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="rbac_scan_target_' . date('Ymd_His') . '.tsv"');
    echo "perm_code\tmodule\tperm_name\tsimulated_allow\tsource\ttarget_uid\ttarget_dept\ttarget_role\ttarget_level\tmatrix_role\n";
    foreach ($scanTargetRows as $r) {
        echo $r['code'] . "\t" . $r['module'] . "\t" . str_replace(["\t", "\n", "\r"], ' ', $r['name']) . "\t" . ($r['allow'] ? '1' : '0') . "\t" . $r['source']
            . "\t" . $targetUserId . "\t" . $targetDept . "\t" . $targetRole . "\t" . $targetLevel . "\t" . $targetMatrixRoleForScan . "\n";
    }
    exit;
}

$base = rmi_layout_base_project();
$selfUrl = $base . '/tools/rbac_effective_permissions.php';

rmi_header('RBAC Effective Permissions', [
    'active' => 'tools',
    'subtitle' => 'Katalog permission, cek satu, scan penuh session & target.',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $base . '/tools/index.php'],
        'RBAC Effective Permissions',
    ],
    'extra_head' => '<style>#rpeCatalogTable tbody tr:hover{background:rgba(13,110,253,.06)} .rpe-desc{max-width:28rem;font-size:.8rem}</style>',
]);

$catalogTotal = count($catalogRows);
$dbCatalogTotal = count($dbPermCodes);
$catalogMismatch = $catalogTotal > 0 && $dbCatalogTotal > 0 && $dbCatalogTotal !== $catalogTotal;

?>
<p class="small text-muted mb-3">
  <a href="#rpe-form">Cek satu</a> ·
  <a href="#rpe-batch">Scan batch</a> ·
  <a href="#rpe-catalog">Katalog (<?= (int)$catalogTotal ?>)</a>
</p>

<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Session Anda (aktif)</div>
  <div class="small row g-2">
    <div class="col-md-6">
      <table class="table table-sm table-borderless mb-0">
        <tr><td class="text-muted">User</td><td><b><?= rpe_h($current['username']) ?></b> · id <code><?= (int)$current['user_id'] ?></code></td></tr>
        <tr><td class="text-muted">Dept</td><td><code><?= rpe_h($current['department']) ?></code></td></tr>
        <tr><td class="text-muted">Role / Level</td><td><code><?= rpe_h($current['role']) ?></code> / <code><?= rpe_h($current['level']) ?></code></td></tr>
        <tr><td class="text-muted">Matrix role</td><td><strong><?= rpe_h($matrixRoleSession) ?></strong></td></tr>
      </table>
    </div>
    <div class="col-md-6">
      <table class="table table-sm table-borderless mb-0">
        <tr><td class="text-muted">RBAC DB</td><td><?= $rbacReady ? '<span class="badge text-bg-success">ready</span>' : '<span class="badge text-bg-warning">not ready</span>' ?></td></tr>
        <tr><td class="text-muted">Katalog file</td><td><code>config/rbac_permissions.php</code> → <b><?= (int)$catalogTotal ?></b> kode</td></tr>
        <tr><td class="text-muted">Baris aktif DB</td><td><b><?= (int)$dbCatalogTotal ?></b> <code>rbac_permissions</code><?php if ($catalogMismatch): ?> <span class="badge text-bg-warning">≠ katalog</span><?php endif; ?></td></tr>
      </table>
    </div>
  </div>
</div>

<div class="rmi-card p-3 mb-3" id="rpe-form">
  <div class="fw-semibold mb-3">Cek satu permission + target simulasi</div>
  <form method="get" action="<?= rpe_h($selfUrl) ?>" class="vstack gap-3">
    <input type="hidden" name="cat_mod" value="<?= rpe_h($catalogFilterModule) ?>">
    <div class="row g-2">
      <div class="col-lg-6">
        <label class="form-label">Permission code</label>
        <input list="permList" name="perm" class="form-control" value="<?= rpe_h($permInput) ?>" placeholder="Pilih dari katalog atau ketik" required>
        <datalist id="permList">
          <?php foreach ($catalogRows as $cr): ?>
            <option value="<?= rpe_h($cr['code']) ?>"><?= rpe_h($cr['module'] . ' — ' . $cr['name']) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-lg-3">
        <label class="form-label">can_dept(…)</label>
        <input name="dept" class="form-control" value="<?= rpe_h($deptInput) ?>" placeholder="FIN">
      </div>
    </div>

    <div class="border rounded p-3 bg-light bg-opacity-25">
      <div class="fw-semibold mb-2">Target untuk <em>Evaluate target</em> &amp; <em>Scan target</em></div>
      <div class="row g-2">
        <div class="col-md-3">
          <label class="form-label">Target user ID</label>
          <input type="number" min="0" name="target_user_id" class="form-control" value="<?= (int)$targetUserId ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Target dept</label>
          <input name="target_dept" class="form-control" value="<?= rpe_h($targetDept) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Target role</label>
          <input name="target_role" class="form-control" value="<?= rpe_h($targetRole) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Target level</label>
          <input name="target_level" class="form-control" value="<?= rpe_h($targetLevel) ?>">
        </div>
      </div>
      <?php if (is_array($loadedUserRow)): ?>
        <div class="alert alert-success py-2 small mt-2 mb-0">DB: <?= rpe_h((string)($loadedUserRow['username'] ?? '')) ?> — <?= rpe_h((string)($loadedUserRow['status'] ?? '')) ?></div>
      <?php elseif ($syncFromLogin && $targetUserId > 0): ?>
        <div class="alert alert-warning py-2 small mt-2 mb-0">User id <?= (int)$targetUserId ?> tidak ditemukan.</div>
      <?php endif; ?>
    </div>

    <div class="d-flex flex-wrap gap-2">
      <button type="submit" name="action" value="session" class="btn btn-primary">Check — session saya</button>
      <button type="submit" name="action" value="target" class="btn btn-success">Evaluate target</button>
      <button type="submit" name="action" value="scan_session" class="btn btn-outline-primary">Scan semua — session</button>
      <button type="submit" name="action" value="scan_target" class="btn btn-outline-success">Scan semua — target</button>
      <?php if ($targetUserId > 0): ?>
        <button type="submit" name="sync_from_login" value="1" class="btn btn-outline-secondary">Isi target dari DB</button>
      <?php endif; ?>
      <a class="btn btn-outline-secondary" href="<?= rpe_h($selfUrl) ?>">Reset</a>
    </div>
    <p class="small text-muted mb-0">
      <strong>Scan semua — session</strong> memanggil <code>can()</code> untuk setiap kode di <code>rbac_permissions.php</code> (<?= (int)$catalogTotal ?> baris).
      <strong>Scan semua — target</strong> mensimulasikan matrix + override user (1× load map) — sama aturan dengan Evaluate target.
    </p>
  </form>
</div>

<?php if ($action === 'scan_session' && is_array($scanSessionSummary) && (($scanSessionSummary['error'] ?? '') !== '')): ?>
<div class="alert alert-warning"><?= rpe_h((string)$scanSessionSummary['error']) ?></div>
<?php endif; ?>

<?php if ($action === 'scan_session' && is_array($scanSessionRows) && is_array($scanSessionSummary) && ($scanSessionSummary['total'] ?? 0) > 0): ?>
<div class="rmi-card p-3 mb-3 border-primary" id="rpe-batch">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div class="fw-semibold">Hasil scan — session</div>
    <div>
      <a class="btn btn-sm btn-outline-secondary" href="<?= rpe_h($selfUrl . '?action=scan_session&export_scan=1&perm=' . urlencode($permInput) . '&dept=' . urlencode($deptInput) . '&target_user_id=' . (int)$targetUserId . '&target_dept=' . urlencode($targetDept) . '&target_role=' . urlencode($targetRole) . '&target_level=' . urlencode($targetLevel) . '&cat_mod=' . urlencode($catalogFilterModule)) ?>">Unduh TSV</a>
    </div>
  </div>
  <p class="small mb-2">Total <b><?= (int)$scanSessionSummary['total'] ?></b> · ALLOW <span class="badge text-bg-success"><?= (int)$scanSessionSummary['allow'] ?></span>
    · DENY <span class="badge text-bg-danger"><?= (int)$scanSessionSummary['deny'] ?></span>
    <?php if (function_exists('auth_is_admin') && auth_is_admin()): ?>
      <span class="text-muted">(SYS tier: semua biasanya ALLOW lewat <code>auth_is_admin</code>)</span>
    <?php elseif (function_exists('rbac_is_privileged_session') && rbac_is_privileged_session()): ?>
      <span class="text-muted">(privileged session: semua ALLOW lewat <code>rbac_can</code>)</span>
    <?php endif; ?>
  </p>
  <div class="table-responsive" style="max-height:420px;overflow:auto">
    <table class="table table-sm table-striped mb-0">
      <thead class="table-light sticky-top"><tr><th>Code</th><th>Modul</th><th>Nama</th><th>can()</th><th>Sumber</th></tr></thead>
      <tbody>
        <?php foreach ($scanSessionRows as $r): ?>
        <tr>
          <td><code class="small"><?= rpe_h($r['code']) ?></code></td>
          <td class="small"><?= rpe_h($r['module']) ?></td>
          <td class="small"><?= rpe_h($r['name']) ?></td>
          <td><?= $r['allow'] ? '<span class="badge text-bg-success">ALLOW</span>' : '<span class="badge text-bg-danger">DENY</span>' ?></td>
          <td class="small text-muted"><code><?= rpe_h($r['source']) ?></code></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($action === 'scan_target' && $scanTargetError !== ''): ?>
<div class="alert alert-danger" id="rpe-batch-target"><?= rpe_h($scanTargetError) ?></div>
<?php endif; ?>

<?php if ($action === 'scan_target' && is_array($scanTargetRows) && is_array($scanTargetSummary)): ?>
<div class="rmi-card p-3 mb-3 border-success" id="rpe-batch-target">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div class="fw-semibold">Hasil scan — target</div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= rpe_h($selfUrl . '?action=scan_target&export_scan=1&perm=' . urlencode($permInput) . '&dept=' . urlencode($deptInput) . '&target_user_id=' . (int)$targetUserId . '&target_dept=' . urlencode($targetDept) . '&target_role=' . urlencode($targetRole) . '&target_level=' . urlencode($targetLevel) . '&cat_mod=' . urlencode($catalogFilterModule)) ?>">Unduh TSV</a>
  </div>
  <p class="small mb-1">UID <code><?= (int)$targetUserId ?></code> · dept <code><?= rpe_h($targetDept) ?></code> · role <code><?= rpe_h($targetRole) ?></code> · level <code><?= rpe_h($targetLevel) ?></code>
    · matrix role <strong><?= rpe_h((string)$scanTargetSummary['matrix_role']) ?></strong></p>
  <p class="small mb-2">Total <b><?= (int)$scanTargetSummary['total'] ?></b> · ALLOW <span class="badge text-bg-success"><?= (int)$scanTargetSummary['allow'] ?></span>
    · DENY <span class="badge text-bg-danger"><?= (int)$scanTargetSummary['deny'] ?></span>
    · map override user: <?= (int)$scanTargetSummary['user_override_rows'] ?> kode · slice matrix: <?= (int)$scanTargetSummary['matrix_rows_slice'] ?> kode</p>
  <?php if (!empty($scanTargetSummary['by_source'])): ?>
    <p class="small text-muted mb-2"><?php foreach ($scanTargetSummary['by_source'] as $sk => $sv): ?><span class="me-2"><code><?= rpe_h((string)$sk) ?></code>=<?= (int)$sv ?></span><?php endforeach; ?></p>
  <?php endif; ?>
  <div class="table-responsive" style="max-height:420px;overflow:auto">
    <table class="table table-sm table-striped mb-0">
      <thead class="table-light sticky-top"><tr><th>Code</th><th>Modul</th><th>Nama</th><th>Simulasi</th><th>Sumber</th></tr></thead>
      <tbody>
        <?php foreach ($scanTargetRows as $r): ?>
        <tr>
          <td><code class="small"><?= rpe_h($r['code']) ?></code></td>
          <td class="small"><?= rpe_h($r['module']) ?></td>
          <td class="small"><?= rpe_h($r['name']) ?></td>
          <td><?= $r['allow'] ? '<span class="badge text-bg-success">ALLOW</span>' : '<span class="badge text-bg-danger">DENY</span>' ?></td>
          <td class="small"><code><?= rpe_h($r['source']) ?></code></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($action === 'session' && $permInput !== ''): ?>
<div class="rmi-card p-3 mb-3 border-primary">
  <div class="fw-semibold mb-2">Detail — session</div>
  <div class="mb-2"><code>can(<?= rpe_h($permInput) ?>)</code> = <?= $permResult ? '<span class="badge text-bg-success">ALLOW</span>' : '<span class="badge text-bg-danger">DENY</span>' ?>
    · <code>can_dept(<?= rpe_h($deptInput) ?>)</code> = <?= $deptResult ? '<span class="badge text-bg-success">ALLOW</span>' : '<span class="badge text-bg-danger">DENY</span>' ?></div>
  <?php if (is_array($sessionDetail)): ?>
    <ol class="small mb-2 ps-3"><?php foreach ($sessionDetail['steps'] as $st): ?><li class="mb-1"><?= $st ?></li><?php endforeach; ?></ol>
    <div class="small">Sumber: <code><?= rpe_h((string)$sessionDetail['source']) ?></code> · matrix: <strong><?= rpe_h((string)$sessionDetail['matrix_role']) ?></strong></div>
    <?php if (isset($sessionDetail['can_runtime']) && $sessionDetail['can_runtime'] !== $sessionDetail['allow']): ?>
      <div class="alert alert-warning py-2 mt-2 mb-0 small"><code>can()</code> runtime ≠ langkah (cek privileged ADMIN vs <code>auth_is_admin</code>).</div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($action === 'target' && $permInput !== ''): ?>
<div class="rmi-card p-3 mb-3 border-success">
  <div class="fw-semibold mb-2">Detail — target</div>
  <div class="small mb-2">UID <?= (int)$targetUserId ?> · <code><?= rpe_h($targetDept) ?></code> / <code><?= rpe_h($targetRole) ?></code> / <code><?= rpe_h($targetLevel) ?></code></div>
  <?php if (is_array($targetDetail)): ?>
    <div class="mb-2"><?= $targetDetail['allow'] ? '<span class="badge text-bg-success">ALLOW</span>' : '<span class="badge text-bg-danger">DENY</span>' ?>
      <?php if ($targetDetail['privileged']): ?><span class="badge text-bg-secondary">privileged</span><?php endif; ?></div>
    <ol class="small mb-2 ps-3"><?php foreach ($targetDetail['steps'] as $st): ?><li class="mb-1"><?= $st ?></li><?php endforeach; ?></ol>
    <div class="small text-muted"><code><?= rpe_h((string)$targetDetail['source']) ?></code> · <?= rpe_h((string)$targetDetail['matrix_role']) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3" id="rpe-catalog">
  <div class="fw-semibold mb-2">Katalog permission (<code>config/rbac_permissions.php</code>)</div>
  <p class="small text-muted mb-2"><?= (int)$catalogTotal ?> kode · filter di bawah (client-side). Klik <em>Check</em> / <em>Eval</em> membuka form di atas dengan parameter terisi.</p>
  <div class="row g-2 mb-3">
    <div class="col-md-4">
      <label class="form-label small">Filter modul</label>
      <select class="form-select form-select-sm" id="rpeCatMod">
        <option value="">(semua modul)</option>
        <?php foreach ($moduleList as $mod): ?>
          <option value="<?= rpe_h($mod) ?>" <?= $catalogFilterModule === $mod ? ' selected' : '' ?>><?= rpe_h($mod) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-8">
      <label class="form-label small">Cari (kode / nama / deskripsi)</label>
      <input type="search" class="form-control form-control-sm" id="rpeCatQ" placeholder="ketik untuk menyaring…" autocomplete="off">
    </div>
  </div>
  <div class="table-responsive" style="max-height:560px;overflow:auto">
    <table class="table table-sm table-hover mb-0" id="rpeCatalogTable">
      <thead class="table-light sticky-top">
        <tr><th>Permission code</th><th>Modul</th><th>Nama</th><th>Deskripsi</th><th class="text-nowrap">DB</th><th></th></tr>
      </thead>
      <tbody>
        <?php
        $qBase = $selfUrl
            . '?dept=' . rawurlencode($deptInput)
            . '&target_user_id=' . (int)$targetUserId
            . '&target_dept=' . rawurlencode($targetDept)
            . '&target_role=' . rawurlencode($targetRole)
            . '&target_level=' . rawurlencode($targetLevel)
            . '&cat_mod=' . rawurlencode($catalogFilterModule);
        foreach ($catalogRows as $cr):
            $inDb = isset($dbPermCodes[$cr['code']]);
            $modEsc = rpe_h($cr['module']);
            $rowMod = $cr['module'];
            ?>
        <tr data-module="<?= rpe_h($rowMod) ?>" data-search="<?= rpe_h(strtolower($cr['code'] . ' ' . $cr['name'] . ' ' . $cr['desc'])) ?>">
          <td><code class="small"><?= rpe_h($cr['code']) ?></code></td>
          <td class="small"><?= $modEsc ?></td>
          <td class="small"><?= rpe_h($cr['name']) ?></td>
          <td class="rpe-desc text-muted"><?= rpe_h($cr['desc']) ?></td>
          <td><?= $inDb ? '<span class="badge text-bg-success">ada</span>' : '<span class="badge text-bg-secondary">—</span>' ?></td>
          <td class="text-nowrap small">
            <a class="btn btn-sm btn-outline-primary py-0" href="<?= rpe_h($qBase . '&perm=' . rawurlencode($cr['code']) . '&action=session') ?>">Check</a>
            <a class="btn btn-sm btn-outline-success py-0" href="<?= rpe_h($qBase . '&perm=' . rawurlencode($cr['code']) . '&action=target') ?>">Eval</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  var modSel = document.getElementById('rpeCatMod');
  var q = document.getElementById('rpeCatQ');
  var table = document.getElementById('rpeCatalogTable');
  if (!table) return;
  function apply() {
    var m = (modSel && modSel.value) ? modSel.value.toUpperCase() : '';
    var qq = (q && q.value) ? q.value.toLowerCase().trim() : '';
    var rows = table.querySelectorAll('tbody tr');
    rows.forEach(function (tr) {
      var dm = (tr.getAttribute('data-module') || '').toUpperCase();
      var s = (tr.getAttribute('data-search') || '');
      var okMod = !m || dm === m;
      var okQ = !qq || s.indexOf(qq) !== -1;
      tr.style.display = (okMod && okQ) ? '' : 'none';
    });
  }
  if (modSel) modSel.addEventListener('change', function () {
    var v = modSel.value || '';
    var u = new URL(window.location.href);
    u.searchParams.set('cat_mod', v);
    history.replaceState(null, '', u.toString());
    apply();
  });
  if (q) q.addEventListener('input', apply);
  apply();
})();
</script>

<?php rmi_footer(); ?>
