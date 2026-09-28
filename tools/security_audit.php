<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.SECURITY_AUDIT', 'TOOLS.VIEW']);
} else {
    require_admin_critical();
}
require_once __DIR__ . '/../_shared/rmi_layout.php';

function sa_read(string $path): string
{
    return is_file($path) ? (string)file_get_contents($path) : '';
}

function sa_collect_php_files(string $root, array $dirs): array
{
    $out = [];
    // Include top-level PHP entrypoints.
    foreach (glob($root . '/*.php') ?: [] as $topPhp) {
        if (!is_file($topPhp)) continue;
        $relTop = ltrim(str_replace(str_replace('\\', '/', $root), '', str_replace('\\', '/', (string)$topPhp)), '/');
        $out[$relTop] = true;
    }
    foreach ($dirs as $dirRel) {
        $dir = $root . '/' . trim($dirRel, '/');
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f instanceof SplFileInfo || !$f->isFile()) {
                continue;
            }
            if (strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', (string)$f->getPathname());
            if (str_contains($path, '/vendor/') || str_contains($path, '/storage/') || str_contains($path, '/uploads/')) {
                continue;
            }
            $rel = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');
            $out[$rel] = true;
        }
    }
    $files = array_keys($out);
    sort($files);
    return $files;
}

function sa_collect_full_dirs(string $root): array
{
    $exclude = [
        '.git', '.github', '.cursor', '.vscode', 'vendor', 'storage', 'uploads',
        'node_modules', 'docs', 'exports', 'temp', 'tmp',
    ];
    $dirs = [];
    foreach (glob($root . '/*') ?: [] as $path) {
        if (!is_dir($path)) continue;
        $name = basename((string)$path);
        if ($name === '' || str_starts_with($name, '.')) continue;
        if (in_array($name, $exclude, true)) continue;
        $dirs[] = $name;
    }
    sort($dirs);
    return $dirs;
}

function sa_is_cli_only(string $code): bool
{
    return (bool)preg_match("/PHP_SAPI\\s*!==\\s*'cli'/", $code) && (bool)preg_match('/Method Not Allowed/i', $code);
}

function sa_has_auth_guard(string $code): bool
{
    $guards = [
        'require_login(',
        'require_admin_critical(',
        'tools_require_access(',
        'require_role(',
        'require_any_permission(',
        'internal_api_require_write_guard(',
        'internal_api_require_read_guard(',
        'api_require_auth(',
        'auth_require(',
    ];
    foreach ($guards as $g) {
        if (stripos($code, $g) !== false) return true;
    }
    return false;
}

function sa_is_non_web_entry(string $rel, string $code): bool
{
    $p = str_replace('\\', '/', $rel);
    if (str_starts_with($p, '_shared/')) return true;
    if (str_contains($p, '/_lib/')) return true;
    if (str_contains($p, '/_bootstrap')) return true;
    if (str_ends_with($p, '_bootstrap.php')) return true;
    if (str_ends_with($p, '_helpers.php')) return true;
    if (str_contains($p, '/vendor/')) return true;
    if (sa_is_cli_only($code)) return true;
    return false;
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$coreTargetDirs = [
    'master', 'sales', 'purchases', 'stock', 'chat',
    'hrl', 'hrl_process', 'hrl_reg_alkes', 'kpi', 'mpr',
    'payroll', 'Fixed_Asset', 'dashboards', 'rbac', 'api/v1/internal',
];
$scope = strtolower(trim((string)($_GET['scope'] ?? 'full')));
if (!in_array($scope, ['core', 'full'], true)) {
    $scope = 'full';
}
$targetDirs = $scope === 'core' ? $coreTargetDirs : sa_collect_full_dirs($root);

$files = sa_collect_php_files($root, $targetDirs);
$allowNoLogin = [
    'master/login.php', 'master/logout.php', 'master/mfa_verify.php',
];

$missingLogin = [];
$missingCsrf = [];

foreach ($files as $rel) {
    $c = sa_read($root . '/' . $rel);
    if ($c === '') {
        continue;
    }

    if (sa_is_non_web_entry($rel, $c)) {
        continue;
    }

    if (!in_array($rel, $allowNoLogin, true)) {
        if (!sa_has_auth_guard($c)) {
            $missingLogin[] = $rel;
        }
    }

    $hasPostHandling = stripos($c, "REQUEST_METHOD']") !== false || stripos($c, '$_POST') !== false || stripos($c, 'require_post(') !== false;
    $looksMutation = stripos($c, 'INSERT INTO') !== false || stripos($c, 'UPDATE ') !== false || stripos($c, 'DELETE FROM') !== false;
    $hasCsrf = stripos($c, 'verify_csrf') !== false || stripos($c, 'rmi_csrf_validate') !== false || stripos($c, 'internal_api_require_write_guard') !== false;
    $inheritsCsrfViaAuth = (stripos($c, 'require_login(') !== false);
    if ($hasPostHandling && $looksMutation && !$hasCsrf && !$inheritsCsrfViaAuth) {
        $missingCsrf[] = $rel;
    }
}

$base = rmi_layout_base_project();
rmi_header('Security Audit (Regex)', [
    'active' => 'tools',
    'subtitle' => 'Quick verification for auth guard and CSRF',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $base . '/tools/index.php'],
        'Security Audit',
    ],
]);
?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold">Audit Summary</div>
  <form method="get" class="d-flex gap-2 align-items-center mt-2 mb-2">
    <label class="small">Scope</label>
    <select name="scope" class="form-select form-select-sm" style="max-width:220px;">
      <option value="full" <?= $scope === 'full' ? 'selected' : '' ?>>Full System (default)</option>
      <option value="core" <?= $scope === 'core' ? 'selected' : '' ?>>Core Critical Modules</option>
    </select>
    <button class="btn btn-sm btn-outline-light">Rescan</button>
  </form>
  <div class="small rmi-muted">
    Scope: <b><?= htmlspecialchars(strtoupper($scope), ENT_QUOTES, 'UTF-8') ?></b> ·
    Directories: <?= (int)count($targetDirs) ?> ·
    Files: <?= (int)count($files) ?> ·
    Missing require_login: <?= (int)count($missingLogin) ?> ·
    Missing CSRF (heuristic): <?= (int)count($missingCsrf) ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Missing require_login()</div>
      <pre class="small mb-0" style="white-space:pre-wrap;"><?= htmlspecialchars(implode("\n", $missingLogin), ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Missing verify_csrf()/rmi_csrf_validate()</div>
      <pre class="small mb-0" style="white-space:pre-wrap;"><?= htmlspecialchars(implode("\n", $missingCsrf), ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
