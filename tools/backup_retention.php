<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../_shared/erp_audit.php';

require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function br_mask_path(string $path, string $root): string {
    $r = rtrim(str_replace('\\', '/', $root), '/');
    $p = str_replace('\\', '/', $path);
    if (str_starts_with($p, $r)) return '[APP_ROOT]' . substr($p, strlen($r));
    return $p;
}

function br_actor(): string {
    return function_exists('current_actor_username') ? current_actor_username() : ((string)($_SESSION['username'] ?? 'SYSTEM'));
}

function br_rm_tree(string $path): bool {
    if (!is_dir($path)) return false;
    $items = scandir($path);
    if (!is_array($items)) return false;
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') continue;
        $p = $path . DIRECTORY_SEPARATOR . $i;
        if (is_dir($p)) {
            br_rm_tree($p);
        } else {
            @unlink($p);
        }
    }
    return @rmdir($path);
}

function br_prune(string $backupsDir, int $days, string $skipDir = ''): array {
    $removed = [];
    $errors = [];
    if ($days <= 0 || !is_dir($backupsDir)) return [$removed, $errors];
    $now = time();
    foreach (glob($backupsDir . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [] as $dir) {
        if ($skipDir !== '' && realpath($dir) === realpath($skipDir)) continue;
        $mtime = @filemtime($dir) ?: 0;
        $ageDays = (int)floor(($now - $mtime) / 86400);
        if ($ageDays <= $days) continue;
        if (br_rm_tree($dir)) {
            $removed[] = basename($dir);
        } else {
            $errors[] = basename($dir);
        }
    }
    return [$removed, $errors];
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$backupsDir = $root . '/storage/backups';
$logsDir = $root . '/storage/logs';
$runtimeEnv = $backupsDir . '/backup_runtime.env';
$logFile = $logsDir . '/backup_retention.log';
$stateFile = $backupsDir . '/LATEST_BACKUP.txt';

@mkdir($backupsDir, 0775, true);
@mkdir($logsDir, 0775, true);
@touch($logFile);

$runtime = [];
if (is_file($runtimeEnv)) {
    $lines = @file($runtimeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (trim((string)$line) === '' || str_starts_with(trim((string)$line), '#')) continue;
        [$k, $v] = array_pad(explode('=', (string)$line, 2), 2, '');
        $runtime[trim((string)$k)] = trim((string)$v, "\"'");
    }
}

$retentionDays = (int)($runtime['BACKUP_RETENTION_DAYS'] ?? 0);
$flash = '';
$err = '';
$output = [];
$isProd = in_array(strtolower((string)(getenv('APP_ENV') ?: 'local')), ['prod', 'production'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'save') {
        $daysRaw = trim((string)($_POST['retention_days'] ?? '0'));
        if (!preg_match('/^[0-9]+$/', $daysRaw)) {
            $err = 'Retention days harus angka >= 0.';
        } else {
            $retentionDays = (int)$daysRaw;
            $runtime['BACKUP_RETENTION_DAYS'] = (string)$retentionDays;
            $lines = ['# Managed by tools/backup_retention.php'];
            foreach ($runtime as $k => $v) {
                $lines[] = $k . '="' . addcslashes((string)$v, "\\\"") . '"';
            }
            if (@file_put_contents($runtimeEnv, implode("\n", $lines) . "\n") === false) {
                $err = 'Gagal menyimpan runtime config retention.';
            } else {
                @chmod($runtimeEnv, 0600);
                $flash = 'Retention policy tersimpan.';
                $output[] = "BACKUP_RETENTION_DAYS={$retentionDays}";
                try {
                    if (function_exists('rmi_db_pdo')) {
                        $pdo = rmi_db_pdo();
                        erp_audit_ensure($pdo);
                        audit_event($pdo, 'BACKUP_RETENTION_SAVED', 'TOOLS_BACKUP', 'RETENTION', 'POLICY', 'Retention policy saved', [
                            'actor_username' => br_actor(),
                            'retention_days' => $retentionDays,
                        ]);
                    }
                } catch (Throwable $e) {}
            }
        }
    } elseif ($action === 'run_cleanup') {
        if ($isProd && trim((string)($_POST['prod_confirm'] ?? '')) !== 'I_UNDERSTAND') {
            $err = 'Production safeguard aktif: isi konfirmasi I_UNDERSTAND untuk cleanup.';
        } else {
        $latestDir = '';
        if (is_file($stateFile)) {
            $latestDir = trim((string)@file_get_contents($stateFile));
        }
        [$removed, $errors] = br_prune($backupsDir, $retentionDays, $latestDir);
        $output[] = 'Cleanup run: removed=' . count($removed) . ', failed=' . count($errors) . ', days=' . $retentionDays;
        if ($removed) $output[] = 'Removed: ' . implode(', ', $removed);
        if ($errors) $output[] = 'Failed: ' . implode(', ', $errors);
        @file_put_contents($logFile, '[' . gmdate('c') . '] actor=' . br_actor() . ' ' . implode(' | ', $output) . "\n", FILE_APPEND);
        $flash = 'Cleanup selesai. Removed=' . count($removed) . ', Failed=' . count($errors) . '.';
        try {
            if (function_exists('rmi_db_pdo')) {
                $pdo = rmi_db_pdo();
                erp_audit_ensure($pdo);
                audit_event($pdo, 'BACKUP_RETENTION_CLEANUP', 'TOOLS_BACKUP', 'RETENTION', 'CLEANUP', 'Retention cleanup run', [
                    'actor_username' => br_actor(),
                    'retention_days' => $retentionDays,
                    'removed_count' => count($removed),
                    'failed_count' => count($errors),
                ]);
            }
        } catch (Throwable $e) {}
        }
    }
}

$countBackups = count(glob($backupsDir . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: []);
$logTail = @file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
$logTail = array_slice($logTail, -80);

$baseProject = rmi_layout_base_project();
rmi_header('Backup Retention Policy', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Backup Retention',
    ],
]);
?>
<div class="row g-3">
  <div class="col-12">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">Backup Retention Policy</h5>
        <a class="btn btn-sm btn-outline-light" href="<?= h($baseProject) ?>/tools/backup_manager.php">Backup Manager</a>
      </div>
      <div class="muted mt-2">Policy ini menghapus package backup lama lebih dari N hari. Tetap simpan backup terakhir (`LATEST_BACKUP.txt`).</div>
      <?php if ($flash !== ''): ?><div class="alert alert-success mt-3 mb-0"><?= h($flash) ?></div><?php endif; ?>
      <?php if ($err !== ''): ?><div class="alert alert-danger mt-3 mb-0"><?= h($err) ?></div><?php endif; ?>
      <form method="post" class="row g-2 mt-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="col-md-3">
          <label class="form-label">Retention days (0 = off)</label>
          <input class="form-control" name="retention_days" value="<?= h((string)$retentionDays) ?>" inputmode="numeric" pattern="[0-9]+">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit">Simpan Retention</button>
        </div>
      </form>
      <form method="post" class="mt-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="action" value="run_cleanup">
        <?php if ($isProd): ?>
          <input type="text" class="form-control form-control-sm mb-2" name="prod_confirm" placeholder="I_UNDERSTAND">
        <?php endif; ?>
        <button class="btn btn-outline-danger btn-sm" type="submit" onclick="return confirm('Jalankan cleanup sekarang?')">Run Cleanup Now</button>
      </form>
      <div class="muted small mt-2">Backup packages saat ini: <b><?= (int)$countBackups ?></b> | Runtime env: <code><?= h(br_mask_path($runtimeEnv, $root)) ?></code></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <h6>Retention Log</h6>
      <pre class="small mb-0" style="max-height:240px; overflow:auto;"><?= h(implode("\n", $logTail)) ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>

