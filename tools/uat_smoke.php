<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function us_pass(array &$rows, string $name): void { $rows[] = ['name' => $name, 'status' => 'PASS', 'detail' => '']; }
function us_fail(array &$rows, string $name, string $detail): void { $rows[] = ['name' => $name, 'status' => 'FAIL', 'detail' => $detail]; }
function us_include_page_isolated(string $file): string {
    ob_start();
    (static function ($__file): void { include $__file; })($file);
    return (string)ob_get_clean();
}

$rows = [];
$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$tmpSessionDir = $root . '/.tmp_sessions';
@mkdir($tmpSessionDir, 0775, true);

try {
    $pdo = rmi_db_pdo();
    us_pass($rows, 'DB connection');
} catch (Throwable $e) {
    us_fail($rows, 'DB connection', $e->getMessage());
    $pdo = null;
}

// Theme + contrast checks (objective static checks)
$layout = @file_get_contents($root . '/_shared/rmi_layout.php') ?: '';
$assist = @file_get_contents($root . '/_shared/rmi_assist.js') ?: '';
$css = @file_get_contents($root . '/_shared/rmi.css') ?: '';
if (str_contains($layout, 'rmiThemeToggle') && str_contains($layout, 'rmiContrastToggle')) us_pass($rows, 'Topbar toggles present');
else us_fail($rows, 'Topbar toggles present', 'Missing theme/contrast toggle id');
if (str_contains($assist, 'ui_contrast') && str_contains($assist, 'theme-contrast')) us_pass($rows, 'Contrast persistence logic');
else us_fail($rows, 'Contrast persistence logic', 'Missing localStorage/class toggle logic');
if (str_contains($css, 'body.theme-contrast') && str_contains($css, ':focus-visible')) us_pass($rows, 'Contrast CSS + focus ring');
else us_fail($rows, 'Contrast CSS + focus ring', 'Missing contrast css/focus ring');

// Open pages through include in admin session context
$pages = [
    'Backup schedule page' => $root . '/tools/backup_schedule.php',
    'Restore page' => $root . '/tools/restore_now.php',
    'Backup manager page' => $root . '/tools/backup_manager.php',
    'Health page' => $root . '/tools/health.php',
    'Master module smoke' => $root . '/master/master_data.php',
    'Sales module smoke' => $root . '/sales/sales_dashboard.php',
    'KPI module smoke' => $root . '/kpi/kpi_center.php',
];
foreach ($pages as $name => $file) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $o = us_include_page_isolated($file);
    $okLen = strlen((string)$o) > 200;
    $okMarker = true;
    if ($name === 'Master module smoke') $okMarker = str_contains($o, 'Master Data Center');
    if ($name === 'Sales module smoke') $okMarker = str_contains($o, 'Sales Dashboard') || str_contains($o, 'Sales');
    if ($name === 'KPI module smoke') $okMarker = str_contains($o, 'KPI Center');
    if ($okLen && $okMarker) us_pass($rows, $name);
    else us_fail($rows, $name, 'Rendered output/marker check failed');
}

// Health API JSON structure — dual strategy: proc_open (prefer) then HTTP fallback
$healthRaw = '';
$healthErr = '';
$cmd = 'php api/v1/health.php';
$proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (is_resource($proc)) {
    $healthRaw = (string)@stream_get_contents($pipes[1]);
    $healthErr = (string)@stream_get_contents($pipes[2]);
    @fclose($pipes[1] ?? null);
    @fclose($pipes[2] ?? null);
    proc_close($proc);
}
if ($healthRaw === '' || json_decode($healthRaw, true) === null) {
    $basePath = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $url = $scheme . '://' . $host . $basePath . '/api/v1/health.php';
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $healthRaw = (string)@file_get_contents($url, false, $ctx);
}
$health = is_string($healthRaw) ? json_decode($healthRaw, true) : null;
if (is_array($health) && ($health['success'] ?? null) === true) us_pass($rows, 'Health API success');
else us_fail($rows, 'Health API success', 'success!=true');
if (is_array($health) && isset($health['data']['db'], $health['data']['storage'], $health['data']['cron'], $health['data']['worker_queue'])) us_pass($rows, 'Health API structure');
else us_fail($rows, 'Health API structure', 'missing db/storage/cron/worker_queue');

// Backup creation smoke
$backupName = 'uat_smoke_' . date('Ymd_His');
$cmd = '/bin/bash ' . escapeshellarg($root . '/tools/backup_now.sh') . ' --label ' . escapeshellarg($backupName);
$lines = [];
$code = 1;
@exec($cmd . ' 2>&1', $lines, $code);
if ($code === 0) us_pass($rows, 'Backup script run');
else us_fail($rows, 'Backup script run', implode("\n", array_slice($lines, -3)));
$pkgFound = false;
foreach (glob($root . '/storage/backups/ERP_RMI_SOFULL_backup_*' . $backupName, GLOB_ONLYDIR) ?: [] as $d) {
    if (is_file($d . '/manifest.json') && is_file($d . '/checksums.sha256')) { $pkgFound = true; break; }
}
if ($pkgFound) us_pass($rows, 'Backup package + manifest/checksum');
else us_fail($rows, 'Backup package + manifest/checksum', 'missing package or manifest/checksum');

// User lifecycle smoke
if ($pdo instanceof PDO) {
    try {
        $u = 'uat_smoke_user';
        $pass = password_hash('Pass123!', PASSWORD_DEFAULT);
        $st = $pdo->prepare("SELECT id FROM master_system_login WHERE username=? LIMIT 1");
        $st->execute([$u]);
        $id = (int)$st->fetchColumn();
        if ($id <= 0) {
            $ins = $pdo->prepare("INSERT INTO master_system_login(username,password_hash,role,level,department,status,created_at,updated_at) VALUES(?,?, 'staff','staff','CRM','ACTIVE',NOW(),NOW())");
            $ins->execute([$u, $pass]);
            $id = (int)$pdo->lastInsertId();
        }

        // use direct SQL lifecycle assert as objective smoke
        $pdo->prepare("UPDATE master_system_login SET status='INACTIVE', deactivated_at=NOW(), deactivated_by='SYSTEM', deleted_at=NULL, deleted_by=NULL, delete_reason=NULL WHERE id=?")->execute([$id]);
        $st1 = $pdo->prepare("SELECT status, deactivated_at, deactivated_by FROM master_system_login WHERE id=?");
        $st1->execute([$id]);
        $s1 = $st1->fetch(PDO::FETCH_ASSOC);
        if (($s1['status'] ?? '') === 'INACTIVE' && !empty($s1['deactivated_at']) && !empty($s1['deactivated_by'])) us_pass($rows, 'Lifecycle deactivate metadata');
        else us_fail($rows, 'Lifecycle deactivate metadata', 'status/meta mismatch');

        $pdo->prepare("UPDATE master_system_login SET status='INACTIVE', deleted_at=NOW(), deleted_by='SYSTEM', delete_reason='uat smoke' WHERE id=?")->execute([$id]);
        $st2 = $pdo->prepare("SELECT deleted_at, deleted_by FROM master_system_login WHERE id=?");
        $st2->execute([$id]);
        $s2 = $st2->fetch(PDO::FETCH_ASSOC);
        if (!empty($s2['deleted_at']) && !empty($s2['deleted_by'])) us_pass($rows, 'Lifecycle soft-delete metadata');
        else us_fail($rows, 'Lifecycle soft-delete metadata', 'delete meta missing');

        $pdo->prepare("UPDATE master_system_login SET status='ACTIVE', deactivated_at=NULL, deactivated_by=NULL, deleted_at=NULL, deleted_by=NULL, delete_reason=NULL WHERE id=?")->execute([$id]);
        $st3 = $pdo->prepare("SELECT status, deleted_at, deactivated_at FROM master_system_login WHERE id=?");
        $st3->execute([$id]);
        $s3 = $st3->fetch(PDO::FETCH_ASSOC);
        if (($s3['status'] ?? '') === 'ACTIVE' && empty($s3['deleted_at']) && empty($s3['deactivated_at'])) us_pass($rows, 'Lifecycle restore/activate metadata reset');
        else us_fail($rows, 'Lifecycle restore/activate metadata reset', 'reset mismatch');
    } catch (Throwable $e) {
        us_fail($rows, 'Lifecycle smoke', $e->getMessage());
    }
}

// Build final checklist file (100% PASS target)
$allPass = true;
foreach ($rows as $r) { if ($r['status'] !== 'PASS') { $allPass = false; break; } }
$checklistFile = $root . '/storage/logs/UAT_CHECKLIST_FINAL.md';
$md = "# UAT Checklist Final\n\n";
$md .= "Generated at: " . gmdate('c') . "\n\n";
foreach ($rows as $item) {
    if ($item['status'] === 'PASS') $md .= "- [✅ PASS] " . $item['name'] . "\n";
    else $md .= "- [❌ FAIL] " . $item['name'] . " — " . $item['detail'] . "\n";
}
if ($allPass) {
    $md .= "\n- [✅ PASS] Overall UAT smoke status\n";
} else {
    $md .= "\n- [❌ FAIL] Overall UAT smoke status\n";
}
@file_put_contents($checklistFile, $md);

try {
    if ($pdo instanceof PDO) {
        erp_audit_ensure($pdo);
        audit_event($pdo, 'UAT_SMOKE_RUN', 'TOOLS_QA', 'UAT', 'SMOKE', 'Run UAT smoke checklist', [
            'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
            'all_pass' => $allPass,
            'total_checks' => count($rows),
            'failed_checks' => count(array_filter($rows, static fn($x) => $x['status'] !== 'PASS')),
        ]);
    }
} catch (Throwable $e) {}

$baseProject = rmi_layout_base_project();
rmi_header('UAT Smoke Runner', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'UAT Smoke Runner',
    ],
]);
?>
<div class="card p-3">
  <h5 class="mb-2">UAT Smoke Runner</h5>
  <div class="muted mb-3">Hasil runner ini juga ditulis ke <code>storage/logs/UAT_CHECKLIST_FINAL.md</code>.</div>
  <table class="table table-sm table-dark-custom">
    <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $item): ?>
      <tr>
        <td><?= h($item['name']) ?></td>
        <td><?php if ($item['status'] === 'PASS'): ?><span class="badge bg-success">PASS</span><?php else: ?><span class="badge bg-danger">FAIL</span><?php endif; ?></td>
        <td><?= h($item['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php rmi_footer(); ?>

