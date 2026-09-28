<?php
/**
 * ERP Enterprise Audit (Static Scan) - v3
 * Adds:
 * - DB Hardcode Scan (non-config/business pages)  + evidence
 * - display_errors Scan (non-config/business pages) + evidence
 * Keeps:
 * - Gate (Missing Login, Upload NO SAFE, h() unguarded, mysqli drift)
 * - Coverage X/Y for CSRF + Audit on write-actions
 * Hardening:
 * - Excludes helper/include files from missing login, coverage, mysqli drift, db hardcode, display_errors
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/../master/auth.php';

if (PHP_SAPI !== 'cli') {
  require_login();
  require_once __DIR__ . '/../_shared/rbac.php';
  if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.ENTERPRISE_AUDIT_VIEW', 'TOOLS.VIEW']);
  } else {
    require_role(['SYS', 'ADMIN','SUPERADMIN']);
  }
}

$ROOT = realpath(__DIR__ . '/..');
if (!$ROOT) $ROOT = dirname(__DIR__);
$ROOT = rtrim((string)$ROOT, DIRECTORY_SEPARATOR);
$ROOT_MASKED = '[APP_ROOT]';

$SKIP_DIRS = ['.git','node_modules','vendor','storage','cache','tmp','logs','log','uploads','upload','assets','.idea','.vscode'];

$HELPER_PATH_PATTERNS = [
  '#^_shared/#',
  '#/(_inc|inc)/#',
  '#/_audit_helper\.php$#',
  '#/(bootstrap|helpers|db|config)\.php$#',
  '#^tools/#',
];

$PUBLIC_ENTRYPOINTS = ['index.php','login.php','logout.php'];

// config/db files allowed to contain credentials (we still can list separately if needed)
$ALLOWED_DB_CONFIG_FILES = [
  'config.php',
  'db.php',
  '_shared/db.php',
  '_shared/bootstrap.php',
  'bootstrap.php',
];

function is_helper_file(string $rel): bool {
  global $HELPER_PATH_PATTERNS;
  foreach ($HELPER_PATH_PATTERNS as $pat) if (preg_match($pat, $rel)) return true;
  return false;
}
function is_public_entrypoint(string $rel): bool {
  global $PUBLIC_ENTRYPOINTS;
  return in_array(basename($rel), $PUBLIC_ENTRYPOINTS, true);
}
function is_allowed_db_config_file(string $rel): bool {
  global $ALLOWED_DB_CONFIG_FILES;
  $rel = str_replace('\\', '/', $rel);
  return in_array($rel, $ALLOWED_DB_CONFIG_FILES, true);
}
function module_of(string $rel): string {
  $rel = ltrim(str_replace('\\', '/', $rel), '/');
  if (strpos($rel, '/') === false) return 'root';
  return explode('/', $rel, 2)[0];
}
function read_file_safe(string $path): string { $c=@file_get_contents($path); return $c===false?'':$c; }

function has_login_guard(string $code): bool {
  return (bool)(
    preg_match('#\brequire_login\s*\(#', $code) ||
    preg_match('#\brequire_once\b.*bootstrap\.php#', $code) ||
    preg_match('#\benterprise_guard\b#', $code)
  );
}
function is_upload_page(string $code): bool {
  return (bool)(preg_match('#\$_FILES\s*\[#', $code) || preg_match('#\bmove_uploaded_file\s*\(#', $code));
}
function upload_missing_safe_filename(string $code): bool {
  if (!preg_match('#\bmove_uploaded_file\s*\(#', $code)) return false;
  return !preg_match('#\b(safe_filename|rmi_safe_filename)\s*\(#', $code);
}
function defines_h(string $code): int { return preg_match_all('#\bfunction\s+h\s*\(#', $code); }
function defines_h_unguarded(string $code): bool {
  if (!preg_match('#\bfunction\s+h\s*\(#', $code)) return false;
  return !preg_match("#function_exists\s*\(\s*['\"]h['\"]\s*\)#", $code);
}
function mysqli_usage(string $rel, string $code): bool {
  if (is_helper_file($rel)) return false;
  return (bool)(
    preg_match('#\bnew\s+mysqli\s*\(#i', $code) ||
    preg_match('#\bmysqli_connect\s*\(#i', $code) ||
    preg_match('#\bmysqli_query\s*\(#i', $code) ||
    preg_match('#\bmysqli_\w+\s*\(#i', $code)
  );
}
function detect_write_action(string $rel, string $code): bool {
  if (is_helper_file($rel)) return false;
  $sqlMut = preg_match('#\b(INSERT\s+INTO|UPDATE\s+\w+|DELETE\s+FROM|REPLACE\s+INTO)\b#i', $code);
  $postLike = preg_match('#\$_POST\s*\[#', $code) || preg_match('#\bREQUEST_METHOD\b#', $code);
  $actionNames = preg_match('#\b(save|update|delete|hapus|simpan|import|approve|submit|create|upload)\b#i', $code);
  $fileMove = preg_match('#\bmove_uploaded_file\s*\(#', $code);
  if ($sqlMut || $fileMove) return true;
  if ($postLike && $actionNames) return true;
  return false;
}
function has_csrf_verify(string $code): bool {
  return (bool)(preg_match('#\b(csrf_verify_or_die|csrf_verify|rmi_csrf_verify|check_csrf)\b#', $code));
}
function has_audit_log(string $code): bool {
  return (bool)(preg_match('#\b(audit_log|rmi_audit|log_audit)\s*\(#', $code));
}
function pct_str(int $x, int $y): string {
  if ($y <= 0) return "0/0 (0%)";
  $p = (int)round(100.0 * ($x / $y));
  return "{$x}/{$y} ({$p}%)";
}

// --- New: DB hardcode + display_errors scan (business pages only)
function has_display_errors_on(string $code): bool {
  return (bool)(
    preg_match("#ini_set\s*\(\s*['\"]display_errors['\"]\s*,\s*['\"]1['\"]\s*\)#i", $code) ||
    preg_match("#ini_set\s*\(\s*['\"]display_errors['\"]\s*,\s*['\"]on['\"]\s*\)#i", $code) ||
    preg_match("#display_errors\s*=\s*1#i", $code) ||
    preg_match("#display_errors\s*=\s*on#i", $code)
  );
}
function has_db_hardcode(string $rel, string $code): bool {
  if (is_helper_file($rel)) return false;
  if (is_allowed_db_config_file($rel)) return false;

  // strong indicators: root user / non-standard port 8889 / explicit credentials arrays/vars
  $rootUser = preg_match("#(['\"]DB_USER['\"]\s*=>\s*['\"]root['\"]|DB_USER\s*=\s*['\"]root['\"]|['\"]root['\"]\s*,\s*['\"][^'\"]*['\"]\s*,\s*['\"][^'\"]*['\"])#i", $code);
  $mampPort = preg_match("#\b8889\b#", $code);
  $pdoDsn   = preg_match("#mysql:\s*host\s*=\s*[^;]+;\s*(port\s*=\s*\d+;)?#i", $code) && preg_match("#\b(user|pass|password)\b#i", $code);
  $mysqliInline = preg_match("#mysqli_connect\s*\(\s*['\"][^'\"]+['\"]\s*,\s*['\"][^'\"]+['\"]\s*,\s*['\"][^'\"]*['\"]#i", $code);

  // also detect common inline vars used in business pages
  $inlineVars = preg_match("#\$(DB_HOST|DB_NAME|DB_USER|DB_PASS)\s*=\s*['\"][^'\"]+['\"]#i", $code);

  return (bool)($rootUser || $mampPort || $mysqliInline || $inlineVars || $pdoDsn);
}

// ---- Collect PHP files
$all_files = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
  if (!$file->isFile()) continue;
  $path = $file->getPathname();
  $rel = ltrim(str_replace($ROOT, '', $path), DIRECTORY_SEPARATOR);
  $rel = str_replace('\\', '/', $rel);
  if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'php') continue;

  $skip = false;
  foreach ($SKIP_DIRS as $d) {
    if (strpos($rel, $d . '/') !== false) { $skip = true; break; }
  }
  if ($skip) continue;

  $all_files[] = [$path, $rel];
}

$modules = [];
$totals = [
  'files_scanned'=>0,
  'missing_login_guard'=>0,
  'upload_pages'=>0,
  'upload_pages_no_safe_filename'=>0,
  'h_unguarded'=>0,
  'mysqli'=>0,

  'write_actions'=>0,
  'csrf_verified'=>0,
  'audit_logged'=>0,

  'db_hardcode'=>0,
  'display_errors_on'=>0,
];

$evidence = [
  'missing_login'=>[],
  'mysqli'=>[],
  'write_missing_csrf'=>[],
  'write_missing_audit'=>[],
  'upload_no_safe'=>[],
  'h_unguarded'=>[],
  'db_hardcode'=>[],
  'display_errors'=>[],
];

foreach ($all_files as [$path,$rel]) {
  $code = read_file_safe($path);
  $mod = module_of($rel);
  if (!isset($modules[$mod])) {
    $modules[$mod] = [
      'files'=>0,
      'missing_login_guard'=>0,
      'file_upload_pages'=>0,
      'upload_pages_no_safe_filename'=>0,
      'defines_h_unguarded'=>0,
      'mysqli'=>0,

      'write_actions'=>0,
      'csrf_verified'=>0,
      'audit_logged'=>0,

      'db_hardcode'=>0,
      'display_errors_on'=>0,
    ];
  }
  $modules[$mod]['files']++;
  $totals['files_scanned']++;

  // Missing login: only for non-helper & non-public entrypoints
  if (!is_helper_file($rel) && !is_public_entrypoint($rel)) {
    if (!has_login_guard($code)) {
      $modules[$mod]['missing_login_guard']++;
      $totals['missing_login_guard']++;
      if (count($evidence['missing_login']) < 25) $evidence['missing_login'][] = $rel;
    }
  }

  // Upload (non-helper)
  if (!is_helper_file($rel) && is_upload_page($code)) {
    $modules[$mod]['file_upload_pages']++;
    $totals['upload_pages']++;
    if (upload_missing_safe_filename($code)) {
      $modules[$mod]['upload_pages_no_safe_filename']++;
      $totals['upload_pages_no_safe_filename']++;
      if (count($evidence['upload_no_safe']) < 25) $evidence['upload_no_safe'][] = $rel;
    }
  }

  // h() unguarded
  if (defines_h($code) > 0 && defines_h_unguarded($code)) {
    $modules[$mod]['defines_h_unguarded']++;
    $totals['h_unguarded']++;
    if (count($evidence['h_unguarded']) < 25) $evidence['h_unguarded'][] = $rel;
  }

  // mysqli drift (non-helper)
  if (mysqli_usage($rel, $code)) {
    $modules[$mod]['mysqli']++;
    $totals['mysqli']++;
    if (count($evidence['mysqli']) < 25) $evidence['mysqli'][] = $rel;
  }

  // Write-action coverage (non-helper)
  if (detect_write_action($rel, $code)) {
    $modules[$mod]['write_actions']++;
    $totals['write_actions']++;

    if (has_csrf_verify($code)) {
      $modules[$mod]['csrf_verified']++;
      $totals['csrf_verified']++;
    } else {
      if (count($evidence['write_missing_csrf']) < 25) $evidence['write_missing_csrf'][] = $rel;
    }

    if (has_audit_log($code)) {
      $modules[$mod]['audit_logged']++;
      $totals['audit_logged']++;
    } else {
      if (count($evidence['write_missing_audit']) < 25) $evidence['write_missing_audit'][] = $rel;
    }
  }

  // NEW: db hardcode + display_errors ON (business pages only)
  if (!is_helper_file($rel) && !is_allowed_db_config_file($rel)) {
    if (has_display_errors_on($code)) {
      $modules[$mod]['display_errors_on']++;
      $totals['display_errors_on']++;
      if (count($evidence['display_errors']) < 25) $evidence['display_errors'][] = $rel;
    }
    if (has_db_hardcode($rel, $code)) {
      $modules[$mod]['db_hardcode']++;
      $totals['db_hardcode']++;
      if (count($evidence['db_hardcode']) < 25) $evidence['db_hardcode'][] = $rel;
    }
  }
}

$gate = 'PASS';
if ($totals['missing_login_guard'] > 0 || $totals['upload_pages_no_safe_filename'] > 0 || $totals['h_unguarded'] > 0 || $totals['mysqli'] > 0) {
  $gate = 'FAIL';
} else {
  if ($totals['write_actions'] > 0) {
    $csrf_pct = (int)round(100.0 * ($totals['csrf_verified'] / max(1,$totals['write_actions'])));
    $audit_pct = (int)round(100.0 * ($totals['audit_logged'] / max(1,$totals['write_actions'])));
    if ($csrf_pct < 100 || $audit_pct < 100) $gate = 'WARN';
  }
}

$csrf_cov = pct_str((int)$totals['csrf_verified'], (int)$totals['write_actions']);
$audit_cov = pct_str((int)$totals['audit_logged'], (int)$totals['write_actions']);
$generated_at = date('Y-m-d H:i:s');
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('ERP Enterprise Audit (Static Scan)', [
  'active' => 'tools',
  'breadcrumbs' => [
    ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
    'ERP Enterprise Audit (Static Scan)',
  ],
  'extra_head' => '<style>
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0b1220;color:#e7eefc;}
  .wrap{max-width:1120px;margin:24px auto;padding:0 16px;}
  .card{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,0.25);}
  .h1{font-size:18px;font-weight:700;margin:0 0 6px;}
  .sub{opacity:.8;font-size:12px;margin:0 0 10px;}
  .meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:12px;opacity:.85;margin:8px 0 14px;}
  .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px;}
  .grid2{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:10px;}
  .kpi{padding:10px;border-radius:12px;border:1px solid rgba(255,255,255,0.08);background:rgba(255,255,255,0.03);}
  .kpi .k{font-size:11px;opacity:.85}
  .kpi .v{font-size:20px;font-weight:800;margin-top:4px}
  .kpi .d{font-size:11px;opacity:.7;margin-top:2px}
  table{width:100%;border-collapse:collapse;margin-top:12px;font-size:12px;}
  th,td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,0.08);text-align:left;vertical-align:top;}
  th{opacity:.8;font-weight:700}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.10);font-size:11px}
  .bad{color:#ff9aa2}
  .warn{color:#ffd6a5}
  .good{color:#b7f7c1}
  pre{white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,0.35);padding:10px;border-radius:12px;border:1px solid rgba(255,255,255,0.08);font-size:12px}
  details{margin-top:10px}
  summary{cursor:pointer}
</style>',
]);
?>

<div class="wrap">
  <div class="h1">ERP Enterprise Audit (Static Scan)</div>
  <div class="sub">v3: Coverage X/Y + DB hardcode + display_errors scan (helper/include di-skip).</div>

  <div class="meta card">
    <span class="pill">Generated: <?php echo htmlspecialchars($generated_at); ?></span>
    <span class="pill">Scanned: <?php echo (int)$totals['files_scanned']; ?> PHP files</span>
    <span class="pill">Root: <?php echo htmlspecialchars($ROOT_MASKED); ?></span>
    <span class="pill">Gate: <b class="<?php echo $gate==='FAIL'?'bad':($gate==='WARN'?'warn':'good'); ?>"><?php echo $gate; ?></b></span>
  </div>

  <div class="grid">
    <div class="kpi"><div class="k">Missing require_login</div><div class="v"><?php echo (int)$totals['missing_login_guard']; ?></div><div class="d">entrypoint bisnis saja</div></div>
    <div class="kpi"><div class="k">Upload (NO SAFE)</div><div class="v"><?php echo (int)$totals['upload_pages_no_safe_filename']; ?></div><div class="d">harus 0</div></div>
    <div class="kpi"><div class="k">h() unguarded</div><div class="v"><?php echo (int)$totals['h_unguarded']; ?></div><div class="d">harus 0</div></div>
    <div class="kpi"><div class="k">mysqli usage</div><div class="v"><?php echo (int)$totals['mysqli']; ?></div><div class="d">business pages saja</div></div>
  </div>

  <div class="grid2">
    <div class="kpi"><div class="k">Write-actions</div><div class="v"><?php echo (int)$totals['write_actions']; ?></div><div class="d">indikasi CRUD/import/upload</div></div>
    <div class="kpi"><div class="k">CSRF Coverage</div><div class="v"><?php echo htmlspecialchars($csrf_cov); ?></div><div class="d">csrf_verified / write_actions</div></div>
    <div class="kpi"><div class="k">Audit Coverage</div><div class="v"><?php echo htmlspecialchars($audit_cov); ?></div><div class="d">audit_logged / write_actions</div></div>
    <div class="kpi"><div class="k">DB hardcode (non-config)</div><div class="v"><?php echo (int)$totals['db_hardcode']; ?></div><div class="d">indikasi root/8889/cred inline</div></div>
  </div>

  <div class="grid2">
    <div class="kpi"><div class="k">display_errors ON (non-config)</div><div class="v"><?php echo (int)$totals['display_errors_on']; ?></div><div class="d">production harus 0</div></div>
    <div class="kpi"><div class="k">Upload endpoints</div><div class="v"><?php echo (int)$totals['upload_pages']; ?></div><div class="d">$_FILES / move_uploaded_file</div></div>
    <div class="kpi"><div class="k">Upload OK</div><div class="v"><?php echo (int)$totals['upload_pages'] - (int)$totals['upload_pages_no_safe_filename']; ?></div><div class="d">safe_filename terdeteksi</div></div>
    <div class="kpi"><div class="k">Gate rule</div><div class="v"><?php echo $gate; ?></div><div class="d">FAIL: login/upload/h/mysqli</div></div>
  </div>

  <div class="card" style="margin-top:14px">
    <div class="h1">Module Summary</div>
    <table>
      <thead>
        <tr>
          <th>Module</th><th>Files</th><th>Missing Login</th><th>Upload</th><th>h() unguarded</th><th>Write</th><th>CSRF Cov</th><th>Audit Cov</th><th>mysqli</th><th>DB hardcode</th><th>display_errors</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($modules as $m=>$s): ?>
        <tr>
          <td><span class="pill"><?php echo htmlspecialchars($m); ?></span></td>
          <td><?php echo (int)$s['files']; ?></td>
          <td><?php echo (int)$s['missing_login_guard']; ?></td>
          <td><?php echo (int)$s['file_upload_pages']; ?><?php if((int)$s['upload_pages_no_safe_filename']>0) echo ' <span class="pill bad">NO SAFE: '.(int)$s['upload_pages_no_safe_filename'].'</span>'; ?></td>
          <td><?php echo (int)$s['defines_h_unguarded']; ?></td>
          <td><?php echo (int)$s['write_actions']; ?></td>
          <td><?php echo pct_str((int)$s['csrf_verified'], (int)$s['write_actions']); ?></td>
          <td><?php echo pct_str((int)$s['audit_logged'], (int)$s['write_actions']); ?></td>
          <td><?php echo (int)$s['mysqli']; ?></td>
          <td><?php echo (int)$s['db_hardcode']; ?></td>
          <td><?php echo (int)$s['display_errors_on']; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <details>
      <summary><b>Evidence (top files)</b></summary>
      <div class="sub">Static scan — gunakan untuk prioritas review manual. Helper/include sudah di-skip.</div>

      <div class="card" style="margin-top:10px"><b>Missing require_login</b><pre><?php echo htmlspecialchars(implode("\n", $evidence['missing_login'])); ?></pre></div>
      <div class="card" style="margin-top:10px"><b>Write-action missing CSRF verify</b><pre><?php echo htmlspecialchars(implode("\n", $evidence['write_missing_csrf'])); ?></pre></div>
      <div class="card" style="margin-top:10px"><b>Write-action missing audit_log</b><pre><?php echo htmlspecialchars(implode("\n", $evidence['write_missing_audit'])); ?></pre></div>
      <div class="card" style="margin-top:10px"><b>Upload missing safe_filename</b><pre><?php echo htmlspecialchars(implode("\n", $evidence['upload_no_safe'])); ?></pre></div>
      <div class="card" style="margin-top:10px"><b>mysqli usage (business pages)</b><pre><?php echo htmlspecialchars(implode("\n", $evidence['mysqli'])); ?></pre></div>
      <div class="card" style="margin-top:10px"><b>h() unguarded</b><pre><?php echo htmlspecialchars(implode("\n", $evidence['h_unguarded'])); ?></pre></div>

      <div class="card" style="margin-top:10px"><b>DB hardcode (non-config)</b><pre><?php echo htmlspecialchars(implode("\n", $evidence['db_hardcode'])); ?></pre></div>
      <div class="card" style="margin-top:10px"><b>display_errors ON (non-config)</b><pre><?php echo htmlspecialchars(implode("\n", $evidence['display_errors'])); ?></pre></div>
    </details>
  </div>
</div>
<?php rmi_footer(); ?>
