<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_lib/bootstrap.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_access_helpers.php';

tools_require_access('backup_manager.php');
require_login();
require_once __DIR__ . '/../_shared/rbac.php';
// Backup manager is SYS-only (TOOLS.BACKUP_MANAGE is not granted to ITC).
// Defense-in-depth: enforce here even though rbac_policy.php already blocks non-SYS via /tools/* rule.
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.BACKUP_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function bm_actor(): string {
    return function_exists('current_actor_username') ? current_actor_username() : (trim((string)($_SESSION['username'] ?? '')) ?: 'SYSTEM');
}

function bm_list_backups(string $backupsDir): array {
    $out = [];
    foreach (glob($backupsDir . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [] as $dir) {
        $manifest = $dir . '/manifest.json';
        $checksum = $dir . '/checksums.sha256';
        $createdAt = @filemtime($dir) ?: 0;
        $size = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) $size += (int)@filesize($f);
        }
        $status = is_file($manifest) ? 'OK' : 'NO_MANIFEST';
        $out[] = [
            'name' => basename($dir),
            'path' => $dir,
            'created_at' => $createdAt > 0 ? gmdate('c', $createdAt) : '-',
            'size' => $size,
            'status' => $status,
            'manifest' => $manifest,
            'checksum_ok' => is_file($checksum),
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string)$b['name'], (string)$a['name']));
    return $out;
}

function bm_fmt_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

$root = dirname(__DIR__);
$backupsDir = $root . '/storage/backups';
$logDir = $root . '/storage/logs';
$logFile = $logDir . '/backup_manager.log';
$backupScript = $root . '/tools/backup_now.sh';
$flash = '';
$err = '';

if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
if (!is_file($logFile)) @touch($logFile);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $op = trim((string)($_POST['op'] ?? ''));
    if ($op === 'backup_now') {
        $label = trim((string)($_POST['label'] ?? ''));
        $safeLabel = preg_replace('/[^A-Za-z0-9._-]+/', '_', $label) ?: 'manual_ui';
        $cmd = 'bash ' . escapeshellarg($backupScript) . ' --label ' . escapeshellarg($safeLabel);
        $lines = [];
        $exitCode = 1;
        @exec($cmd . ' 2>&1', $lines, $exitCode);
        $msg = implode("\n", $lines);
        @file_put_contents($logFile, '[' . date('c') . '] backup_now actor=' . bm_actor() . ' exit=' . $exitCode . "\n" . tools_mask_sensitive($msg) . "\n", FILE_APPEND);
        ts_append_run_history('backup_run', $exitCode === 0 ? 'ok' : 'fail', ['actor_username' => bm_actor(), 'source' => 'backup_manager.php', 'exit_code' => $exitCode]);
        try {
            $pdo = rmi_db_pdo();
            erp_audit_ensure($pdo);
            audit_event($pdo, 'BACKUP_CREATED', 'TOOLS_BACKUP', 'BACKUP', $safeLabel, 'Backup created from Backup Manager', [
                'actor_username' => bm_actor(),
                'exit_code' => $exitCode,
                'label' => $safeLabel,
            ]);
        } catch (Throwable $e) {
            // no-op
        }
        if ($exitCode === 0) {
            $flash = 'Backup berhasil dijalankan.';
        } else {
            $err = 'Backup gagal. Lihat log Backup Manager.';
        }
    }
}

$rows = bm_list_backups($backupsDir);
$baseProject = rmi_layout_base_project();
$cutoverLink = tools_cutover_one_pager_link($baseProject);
rmi_header('Backup Manager', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Backup Manager',
    ],
]);
?>
<div class="row g-3">
  <div class="col-12">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h5 class="mb-0">One-Click Backup (DB + Files)</h5>
          <div class="muted">Engine: <code>tools/backup_now.sh</code> (sama dengan scheduler Run Test Now).</div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-light" href="<?= h($baseProject) ?>/tools/backup_schedule.php">Scheduler 23:00 WIB</a>
          <a class="btn btn-sm btn-outline-light" href="<?= h($cutoverLink) ?>">Cutover One Pager</a>
        </div>
      </div>
      <?php if ($flash !== ''): ?><div class="alert alert-success mt-3 mb-0"><?= h($flash) ?></div><?php endif; ?>
      <?php if ($err !== ''): ?><div class="alert alert-danger mt-3 mb-0"><?= h($err) ?></div><?php endif; ?>
      <form method="post" class="row g-2 mt-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="op" value="backup_now">
        <div class="col-md-4">
          <input class="form-control" name="label" placeholder="Label backup (opsional), contoh before_cutover">
        </div>
        <div class="col-md-3">
          <button class="btn btn-primary w-100" type="submit" onclick="return confirm('Jalankan backup sekarang?')">Backup Now (DB + Files)</button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <h6 class="mb-2">List Backups</h6>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom">
          <thead>
            <tr>
              <th>Package</th>
              <th>Created</th>
              <th>Size</th>
              <th>Status</th>
              <th>Checksum</th>
              <th>Restore Command (CLI)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><code><?= h((string)$r['name']) ?></code></td>
              <td><?= h(tools_fmt_ts((string)$r['created_at'])) ?></td>
              <td><?= h(bm_fmt_size((int)$r['size'])) ?></td>
              <td>
                <?php if ($r['status'] === 'OK'): ?>
                  <?= tools_badge('HEALTHY', 'OK') ?>
                <?php else: ?>
                  <?= tools_badge('ATTENTION', 'ATTENTION') ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($r['checksum_ok'])): ?>
                  <?= tools_badge('HEALTHY', 'SHA256 OK') ?>
                <?php else: ?>
                  <?= tools_badge('UNKNOWN', 'UNKNOWN') ?>
                <?php endif; ?>
              </td>
              <td class="small">
                <code>bash tools/restore_now.sh --from <?= h(tools_mask_sensitive((string)$r['path'])) ?> --restore-db --restore-files --dry-run</code>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="muted">Belum ada backup package.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="muted small mt-2">
        Restore apply contoh:
        <code>bash tools/restore_now.sh --from storage/backups/&lt;package&gt; --restore-db --restore-files --apply</code>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <h6>Backup Manager Log</h6>
      <pre class="small mb-0" style="max-height:220px; overflow:auto;"><?php
        $tail = @file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
        echo h(tools_mask_sensitive(implode("\n", array_slice($tail, -80))));
      ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
