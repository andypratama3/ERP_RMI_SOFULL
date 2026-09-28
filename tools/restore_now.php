<?php
declare(strict_types=1);

require_once __DIR__ . '/tools_remote_check.php';

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_login();

// Restore Now — HANYA SUPERADMIN/SYS
// Restore database = bisa hapus semua transaksi. Tidak boleh ada yang bisa lakukan ini kecuali Owner/Sistem.
$_rnRole  = strtoupper(trim((string)($_SESSION['role']  ?? '')));
$_rnLevel = strtoupper(trim((string)($_SESSION['level'] ?? '')));
$_rnIsSuperAdmin = in_array($_rnRole,  ['SYS','SUPERADMIN'], true)
                || in_array($_rnLevel, ['SYS','SUPERADMIN'], true);

if (!$_rnIsSuperAdmin) {
    http_response_code(403);
    echo '<h3>Akses Ditolak</h3><p>Restore database hanya dapat dilakukan oleh <b>SUPERADMIN / SYS</b>.</p>';
    exit;
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function rn_mask_path(string $path, string $root): string {
    $r = rtrim(str_replace('\\', '/', $root), '/');
    $p = str_replace('\\', '/', $path);
    if (str_starts_with($p, $r)) return '[APP_ROOT]' . substr($p, strlen($r));
    return $p;
}

function rn_mask_sensitive(string $text, string $root): string {
    $out = $text;
    $out = preg_replace('/--password=\\S+/i', '--password=***', $out) ?? $out;
    $out = preg_replace('/\\b(password|pass|db_pass|erp_db_pass)\\s*=\\s*[^\\s]+/i', '$1=***', $out) ?? $out;
    return rn_mask_path($out, $root);
}

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$backupRoot = $projectRoot . '/storage/backups';
$script = $projectRoot . '/tools/restore_now.sh';

$out = [];
$exitCode = null;
$selected = '';
$mode = 'dry-run';
$restoreDb = false;
$restoreFiles = true;
$requestId = function_exists('erp_request_id') ? erp_request_id() : substr(sha1((string)microtime(true)), 0, 24);
$isProd = in_array(strtolower((string)(getenv('APP_ENV') ?: 'local')), ['prod', 'production'], true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('require_post')) {
        require_post();
    }
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }

    $selected = trim((string)($_POST['backup_package'] ?? ''));
    $mode = trim((string)($_POST['mode'] ?? 'dry-run'));
    $restoreDb = isset($_POST['restore_db']) && $_POST['restore_db'] === '1';
    $restoreFiles = isset($_POST['restore_files']) && $_POST['restore_files'] === '1';

    if ($selected !== '' && str_starts_with($selected, 'ERP_RMI_SOFULL_backup_')) {
        $pkgDir = $backupRoot . '/' . $selected;
        $realPkg = realpath($pkgDir);
        if ($realPkg !== false && str_starts_with($realPkg, realpath($backupRoot) ?: $backupRoot) && is_dir($realPkg) && is_file($script)) {
            $args = [];
            $args[] = '--from ' . escapeshellarg($realPkg);
            if ($restoreDb) $args[] = '--restore-db';
            if ($restoreFiles) $args[] = '--restore-files';
            if ($mode === 'apply') $args[] = '--apply';
            else $args[] = '--dry-run';

            if (!$restoreDb && !$restoreFiles) {
                $out[] = 'ERROR: pilih minimal restore DB atau Files.';
                $exitCode = 1;
            } elseif ($isProd && $mode === 'apply' && trim((string)($_POST['prod_confirm'] ?? '')) !== 'I_UNDERSTAND') {
                $out[] = 'ERROR: production safeguard aktif. Isi konfirmasi I_UNDERSTAND.';
                $exitCode = 2;
            } else {
                if (!is_executable($script)) {
                    @chmod($script, 0755);
                }
                $cmd = '/bin/bash ' . escapeshellarg($script) . ' ' . implode(' ', $args);
                exec($cmd . ' 2>&1', $out, $exitCode);
                // Artifact standar (roadmap Tahap 4): restore_dry_run_last.json + restore_last.json
                $logsDir = $projectRoot . '/storage/logs';
                @mkdir($logsDir, 0775, true);
                $restoreArtifact = [
                    'ok' => ($exitCode === 0),
                    'run_at' => date(DateTimeInterface::ATOM),
                    'mode' => $mode,
                    'package' => $selected,
                    'restore_db' => $restoreDb,
                    'restore_files' => $restoreFiles,
                    'exit_code' => $exitCode,
                    'output_lines' => count($out),
                ];
                @file_put_contents($logsDir . '/restore_last.json', json_encode($restoreArtifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                if ($mode === 'dry-run') {
                    @file_put_contents($logsDir . '/restore_dry_run_last.json', json_encode($restoreArtifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
                try {
                    if (function_exists('rmi_db_pdo')) {
                        $pdo = rmi_db_pdo();
                        erp_audit_ensure($pdo);
                        audit_event($pdo, 'RESTORE_' . ($mode === 'apply' ? 'FINISHED' : 'DRY_RUN'), 'TOOLS_BACKUP', 'RESTORE', $selected, 'Restore command executed from web UI', [
                            'request_id' => $requestId,
                            'actor_username' => function_exists('current_actor_username') ? current_actor_username() : ((string)($_SESSION['username'] ?? 'SYSTEM')),
                            'status' => $exitCode === 0 ? 'ok' : 'failed',
                            'mode' => $mode,
                            'restore_db' => $restoreDb,
                            'restore_files' => $restoreFiles,
                        ]);
                    }
                } catch (Throwable $e) {}
            }
        } else {
            $out[] = 'ERROR: backup package/script tidak valid.';
            $exitCode = 1;
        }
    } else {
        $out[] = 'ERROR: backup package tidak valid.';
        $exitCode = 1;
    }
}

$packages = [];
foreach (glob($backupRoot . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [] as $d) {
    $packages[] = basename($d);
}
rsort($packages);

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
rmi_header('Restore Backup (Web)', [
    'active'      => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Restore Backup',
    ],
]);
?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Restore Backup Package</div>
  <div class="rmi-muted mb-3">Disarankan mulai dari mode <b>dry-run</b>, lalu lanjut <b>apply</b> saat sudah yakin. Untuk rollback production gunakan CLI-first: <code>php tools/restore.php --from=&lt;package&gt; --confirm=RESTORE</code>.</div>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token') ? (string)csrf_token() : '') ?>">
    <div class="col-12 col-md-8">
      <label class="form-label">Backup Package</label>
      <select name="backup_package" class="form-select" required>
        <option value="">-- pilih package --</option>
        <?php foreach ($packages as $p): ?>
          <option value="<?= h($p) ?>" <?= $selected === $p ? 'selected' : '' ?>><?= h($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label">Mode</label>
      <select name="mode" class="form-select">
        <option value="dry-run" <?= $mode === 'dry-run' ? 'selected' : '' ?>>Dry Run (safe)</option>
        <option value="apply" <?= $mode === 'apply' ? 'selected' : '' ?>>Apply (execute)</option>
      </select>
    </div>
    <div class="col-12 d-flex gap-3">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="restore_files" value="1" id="restore_files" <?= $restoreFiles ? 'checked' : '' ?>>
        <label class="form-check-label" for="restore_files">Restore Files</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="restore_db" value="1" id="restore_db" <?= $restoreDb ? 'checked' : '' ?>>
        <label class="form-check-label" for="restore_db">Restore DB</label>
      </div>
    </div>
    <div class="col-12">
      <?php if ($isProd): ?>
      <div class="mb-2">
        <label class="form-label">Production confirmation (wajib saat apply)</label>
        <input class="form-control form-control-sm" type="text" name="prod_confirm" placeholder="I_UNDERSTAND">
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-rmi btn-sm">Jalankan Restore</button>
      <a class="btn btn-sm btn-outline-light" href="index.php">Kembali</a>
    </div>
  </form>
</div>

<?php if ($exitCode !== null): ?>
<div class="rmi-card p-3">
  <div class="fw-semibold mb-2"><?= $exitCode === 0 ? 'Restore command selesai' : 'Restore command gagal' ?></div>
  <pre style="white-space:pre-wrap"><?= h(rn_mask_sensitive(implode("\n", $out), $projectRoot)) ?></pre>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
