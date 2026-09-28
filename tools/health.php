<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_lib/bootstrap.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/tools_access_helpers.php';

tools_require_access('health.php');
require_login();
require_role(['ADMIN', 'SUPERADMIN', 'SYS']);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function health_check_writable(string $path): array {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    return ['path' => tools_mask_sensitive($path), 'exists' => $exists, 'writable' => $writable];
}

function health_level_badge(bool $dbOk, bool $storageOk, bool $cronEnabled): string {
    if ($dbOk && $storageOk && $cronEnabled) {
        return tools_badge('HEALTHY');
    }
    if ($dbOk && $storageOk) {
        return tools_badge('ATTENTION');
    }
    return tools_badge('CRITICAL');
}

$root = dirname(__DIR__);
$storageLogs = $root . '/storage/logs';
$storageBackups = $root . '/storage/backups';
$storageUploads = $root . '/storage/uploads';
$stateFile = $storageBackups . '/autobackup_schedule_2300.state';
$latestPointer = $storageBackups . '/LATEST_BACKUP.txt';

$dbOk = false;
$dbLatencyMs = 0;
$dbError = '';
$pendingJobs = null;
$failedJobs = null;
$workerHeartbeat = null;
$workerNa = false;

try {
    $t0 = microtime(true);
    $pdo = rmi_db_pdo();
    $dbOk = (bool)$pdo->query('SELECT 1')->fetchColumn();
    $dbLatencyMs = (int)round((microtime(true) - $t0) * 1000);
    try {
        $pendingJobs = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE UPPER(status) IN ('PENDING','RETRY')")->fetchColumn();
        $failedJobs = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE UPPER(status) IN ('FAILED','ERROR')")->fetchColumn();
        $hbRaw = $pdo->query("SELECT MAX(updated_at) FROM jobs")->fetchColumn();
        $workerHeartbeat = $hbRaw !== false ? (string)$hbRaw : null;
    } catch (Throwable $e) {
        $workerNa = true;
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$cronEnabled = false;
$lastRunAt = '';
$nextRunAt = '';
/** Pesan jika .state ada tapi tidak terbaca (permission) — hindari Warning dari file() */
$backupStateReadNote = '';
if (is_file($stateFile)) {
    if (!is_readable($stateFile)) {
        $backupStateReadNote = 'File state autobackup ada tetapi tidak terbaca oleh user web (permission denied). '
            . 'Di NAS: pastikan storage/backups bisa dibaca PHP-FPM/Apache (mis. chmod 640 + group www-data, atau chown ke user web). '
            . 'Cron backup boleh menulis sebagai user lain; yang penting file .state world-readable atau group sama dengan web server.';
    } else {
        $raw = @file_get_contents($stateFile);
        if ($raw === false) {
            $backupStateReadNote = 'Gagal membaca state file (bukan masalah permission).';
        } else {
            foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $k = trim($k);
                $v = trim($v);
                if ($k === 'enabled') {
                    $cronEnabled = ($v === '1');
                }
                if ($k === 'last_run_at') {
                    $lastRunAt = $v;
                }
            }
        }
    }
}
if ($cronEnabled) {
    $tz = new DateTimeZone('Asia/Jakarta');
    $now = new DateTimeImmutable('now', $tz);
    $next = $now->setTime(23, 0, 0);
    if ($next <= $now) $next = $next->modify('+1 day');
    $nextRunAt = $next->format('Y-m-d H:i:s T');
}

$latestBackup = '';
 $latestBackupMeta = ['name' => '-', 'size' => '-', 'created_at' => '-'];
 $latestBackupAge = '-';
if (is_file($latestPointer)) {
    $latestBackup = trim((string)@file_get_contents($latestPointer));
}
$backupDirs = glob($storageBackups . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [];
rsort($backupDirs, SORT_STRING);
foreach ($backupDirs as $dir) {
    $size = 0;
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f)) $size += (int)@filesize($f);
    }
    $latestBackupMeta = [
        'name' => basename($dir),
        'size' => number_format($size),
        'created_at' => date('Y-m-d H:i:s', (int)@filemtime($dir)),
    ];
    $mtime = (int)@filemtime($dir);
    if ($mtime > 0) {
        $latestBackupAge = (string)floor((time() - $mtime) / 60) . ' minutes';
    }
    break;
}

$rowsStorage = [
    'storage/logs' => health_check_writable($storageLogs),
    'storage/backups' => health_check_writable($storageBackups),
    'storage/uploads' => health_check_writable($storageUploads),
];
$storageOk = true;
foreach ($rowsStorage as $it) {
    if (empty($it['exists']) || empty($it['writable'])) {
        $storageOk = false;
        break;
    }
}
$healthOverall = ($dbOk && $storageOk);
ts_write_json(ts_storage_logs_dir() . '/health.last.json', [
    'ok' => $healthOverall,
    'checked_at' => date(DateTimeInterface::ATOM),
    'error' => ts_mask($dbError),
    'summary' => $healthOverall ? 'Health OK' : 'Health check has failures',
    'meta' => [
        'db_ok' => $dbOk,
        'storage_ok' => $storageOk,
        'cron_enabled' => $cronEnabled,
        'worker_na' => $workerNa,
    ],
]);
$allChecksState = tools_read_state_json(ts_storage_logs_dir() . '/all_checks.last.json', ['overall_ok', 'summary', 'steps']);
$allChecksData = is_array($allChecksState['data'] ?? null) ? (array)$allChecksState['data'] : [];
$allChecksSummary = is_array($allChecksData['summary'] ?? null) ? (array)$allChecksData['summary'] : [];
$allChecksSteps = is_array($allChecksData['steps'] ?? null) ? (array)$allChecksData['steps'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Go-Live Health', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Go-Live Health',
    ],
]);
?>
<div class="row g-3">
  <div class="col-12">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Health Summary</h5>
        <div class="d-flex gap-2 align-items-center">
          <div><?= health_level_badge($dbOk, $storageOk, $cronEnabled) ?></div>
          <a class="btn btn-sm btn-outline-light" href="<?= h($baseProject) ?>/api/v1/health.php" target="_blank" rel="noopener">Open JSON API</a>
        </div>
      </div>
      <div class="muted mt-2">Endpoint ini dipakai untuk verifikasi go-live tanpa membocorkan secret/path sensitif.</div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6>Database</h6>
      <div class="mb-2"><?= tools_badge($dbOk ? 'OK' : 'FAIL') ?> koneksi DB</div>
      <div class="muted">Latency: <b><?= (int)$dbLatencyMs ?> ms</b></div>
      <?php if ($dbError !== ''): ?><div class="text-danger small mt-2"><?= h(tools_mask_sensitive($dbError)) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6>Cron / Backup Scheduler</h6>
      <div class="mb-2"><?= tools_badge($cronEnabled ? 'OK' : 'FAIL') ?> scheduler <?= $cronEnabled ? 'ENABLED' : 'DISABLED' ?></div>
      <div class="muted">Last run: <b><?= h(tools_fmt_ts($lastRunAt)) ?></b></div>
      <div class="muted">Next run: <b><?= h(tools_fmt_ts($nextRunAt)) ?></b></div>
      <div class="muted">Latest backup pointer: <b><?= h($latestBackup !== '' ? tools_mask_sensitive($latestBackup) : '-') ?></b></div>
      <div class="muted">Latest backup package: <b><?= h((string)$latestBackupMeta['name']) ?></b></div>
      <div class="muted">Latest backup size: <b><?= h((string)$latestBackupMeta['size']) ?> bytes</b></div>
      <div class="muted">Latest backup created_at: <b><?= h(tools_fmt_ts((string)$latestBackupMeta['created_at'])) ?></b></div>
      <div class="muted">Latest backup age: <b><?= h($latestBackupAge) ?></b></div>
      <?php if ($backupStateReadNote !== ''): ?>
        <div class="text-warning small mt-2"><?= h($backupStateReadNote) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <h6>Storage Permissions</h6>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom">
          <thead><tr><th>Path</th><th>Exists</th><th>Writable</th></tr></thead>
          <tbody>
          <?php foreach ($rowsStorage as $label => $info): ?>
            <tr>
              <td><code><?= h($info['path']) ?></code></td>
              <td><?= tools_badge((bool)$info['exists'] ? 'OK' : 'FAIL') ?></td>
              <td><?= tools_badge((bool)$info['writable'] ? 'OK' : 'FAIL') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <h6>All Checks</h6>
      <?php if (!$allChecksState['ok']): ?>
        <div class="mb-2"><?= tools_badge('ATTENTION') ?>
          <?php
            $err = (string)$allChecksState['error'];
            echo h(match ($err) {
                'missing' => 'History belum tersedia. Jalankan: php tools/qa/run_all_checks.php',
                'invalid_json' => 'State file invalid/corrupt.',
                'schema_mismatch' => 'State schema tidak dikenali, butuh migrate.',
                default => 'State all checks bermasalah.',
            });
          ?>
        </div>
      <?php else: ?>
        <div class="mb-2"><?= !empty($allChecksData['overall_ok']) ? tools_badge('HEALTHY', 'ALL CHECKS PASS') : tools_badge('CRITICAL', 'ALL CHECKS FAIL') ?></div>
        <div class="muted">Run at: <b><?= h(tools_fmt_ts((string)($allChecksData['run_at'] ?? ''))) ?></b></div>
        <div class="muted">Score: <b><?= (int)($allChecksSummary['score'] ?? 0) ?>/100</b> | Fail count: <b><?= (int)($allChecksSummary['fail_count'] ?? 0) ?></b></div>
        <?php if ($allChecksSteps): ?>
          <div class="small mt-2">
            <?php foreach ($allChecksSteps as $step): ?>
              <div class="border-bottom py-1">
                <code><?= h((string)($step['name'] ?? '-')) ?></code> · <?= !empty($step['ok']) ? tools_badge('OK') : tools_badge('FAIL') ?> · <?= h((string)($step['duration_ms'] ?? 0)) ?>ms
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="muted small mt-2"><code>php tools/qa/run_all_checks.php</code></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <h6>Worker Queue</h6>
      <?php if ($workerNa): ?>
        <div><?= tools_badge('N/A') ?> jobs table belum tersedia.</div>
      <?php else: ?>
        <div><?= tools_badge('OK') ?> queue table tersedia.</div>
        <div class="muted">Pending: <b><?= (int)$pendingJobs ?></b> | Failed: <b><?= (int)$failedJobs ?></b></div>
        <div class="muted">Last heartbeat: <b><?= h($workerHeartbeat !== null && $workerHeartbeat !== '' ? tools_fmt_ts($workerHeartbeat) : 'N/A') ?></b></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
