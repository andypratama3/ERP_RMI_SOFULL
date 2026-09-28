<?php
declare(strict_types=1);

require_once __DIR__ . '/tools_remote_check.php';

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.BACKUP_MANAGE', 'TOOLS.VIEW']);
} else {
    require_admin_critical();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $csrfToken = function_exists('csrf_token') ? (string)csrf_token() : '';
    rmi_header('Backup Now', [
        'active'      => 'tools',
        'breadcrumbs' => [
            ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
            'Backup Now',
        ],
    ]);
    ?>
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Backup Now (Manual Trigger)</div>
      <div class="rmi-muted mb-3">Halaman ini menjalankan backup manual sekali klik dengan audit trail.</div>
      <form method="post" class="d-flex gap-2 flex-wrap">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Run Backup Now</button>
        <a class="btn btn-outline-light btn-sm" href="index.php">Kembali ke Tools</a>
      </form>
    </div>
    <?php
    rmi_footer();
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method Not Allowed';
    exit;
}

if (function_exists('verify_csrf')) {
    verify_csrf();
}

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$script = $projectRoot . '/tools/backup_now.sh';

if (!is_file($script)) {
    http_response_code(500);
    echo 'Backup script not found.';
    exit;
}
if (!is_executable($script)) {
    @chmod($script, 0755);
}

$cmd = '/bin/bash ' . escapeshellarg($script) . ' --label web_trigger';
$output = [];
$exitCode = 1;
exec($cmd . ' 2>&1', $output, $exitCode);
if ($exitCode !== 0) {
    $joined = strtolower(implode("\n", $output));
    // Fallback for environments that block shebang/env execution.
    if (str_contains($joined, 'bad interpreter') || str_contains($joined, 'operation not permitted')) {
        $output = [];
        $exitCode = 1;
        $cmd2 = 'bash ' . escapeshellarg($script) . ' --label web_trigger';
        exec($cmd2 . ' 2>&1', $output, $exitCode);
    }
}

$latestPath = '';
$latestFile = $projectRoot . '/storage/backups/LATEST_BACKUP.txt';
if (is_file($latestFile)) {
    $latestPath = trim((string)@file_get_contents($latestFile));
}

// Artifact standar: storage/logs/backup_last.json (roadmap Tahap 4)
$logsDir = $projectRoot . '/storage/logs';
@mkdir($logsDir, 0775, true);
$backupLast = [
    'ok' => ($exitCode === 0),
    'run_at' => date(DateTimeInterface::ATOM),
    'exit_code' => $exitCode,
    'latest_package' => $latestPath !== '' ? basename($latestPath) : null,
    'output_lines' => count($output),
];
@file_put_contents($logsDir . '/backup_last.json', json_encode($backupLast, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

rmi_header('Backup Result', [
    'active'      => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Backup Result',
    ],
]);
?>
<div class="rmi-card p-3">
  <div class="fw-semibold mb-2"><?= $exitCode === 0 ? 'Backup berhasil' : 'Backup gagal' ?></div>
  <?php if ($latestPath !== ''): ?>
    <div class="rmi-muted mb-2">Latest package: <code><?= h(tools_mask_sensitive($latestPath)) ?></code></div>
  <?php endif; ?>
  <pre style="white-space:pre-wrap"><?= h(tools_mask_sensitive(implode("\n", $output))) ?></pre>
  <div class="mt-3 d-flex gap-2">
    <a class="btn btn-rmi btn-sm" href="index.php">Kembali ke Tools</a>
  </div>
</div>
<?php rmi_footer(); ?>
