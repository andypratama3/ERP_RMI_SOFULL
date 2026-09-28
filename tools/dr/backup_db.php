<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function dr_backup_native_dump(string $sqlPath): array
{
    try {
        $pdo = rmi_db_pdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($tables) || !$tables) {
            return ['ok' => false, 'message' => 'Tidak ada tabel untuk dibackup.'];
        }

        $fh = @fopen($sqlPath, 'wb');
        if (!$fh) {
            return ['ok' => false, 'message' => 'Tidak bisa menulis file backup SQL.'];
        }
        fwrite($fh, "-- ERP_RMI_SOFULL native backup\n");
        fwrite($fh, "-- generated_at: " . date(DateTimeInterface::ATOM) . "\n\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $tbl) {
            $table = (string)$tbl;
            if ($table === '') continue;
            $qTable = '`' . str_replace('`', '``', $table) . '`';

            $row = $pdo->query("SHOW CREATE TABLE {$qTable}")->fetch(PDO::FETCH_ASSOC);
            $create = (string)($row['Create Table'] ?? '');
            if ($create !== '') {
                fwrite($fh, "DROP TABLE IF EXISTS {$qTable};\n");
                fwrite($fh, $create . ";\n\n");
            }

            $stmt = $pdo->query("SELECT * FROM {$qTable}", PDO::FETCH_ASSOC);
            while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols = array_keys($data);
                $colSql = implode(', ', array_map(
                    static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`',
                    $cols
                ));
                $valSql = implode(', ', array_map(static function ($v) use ($pdo): string {
                    if ($v === null) return 'NULL';
                    return $pdo->quote((string)$v);
                }, array_values($data)));
                fwrite($fh, "INSERT INTO {$qTable} ({$colSql}) VALUES ({$valSql});\n");
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Native dump gagal: ' . opsgov_mask($e->getMessage())];
    }
}

function dr_backup_readiness(array $dbCfg, string $backupDir, string $dumpBin): array
{
    $dbOk = false;
    $dbErr = '';
    try {
        $pdo = rmi_db_pdo();
        $dbOk = (bool)$pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $e) {
        $dbOk = false;
        $dbErr = opsgov_mask($e->getMessage());
    }

    @mkdir($backupDir, 0775, true);
    $isWritable = is_dir($backupDir) && is_writable($backupDir);
    $locksDir = opsgov_root() . '/storage/locks';
    @mkdir($locksDir, 0775, true);
    $locksWritable = is_dir($locksDir) && is_writable($locksDir);
    $probeOut = []; $probeCode = 1;
    @exec(escapeshellcmd($dumpBin) . ' --version 2>&1', $probeOut, $probeCode);
    $dumpAvailable = ($probeCode === 0);

    return [
        'db_ok' => $dbOk,
        'db_error_masked' => $dbErr,
        'backup_dir_masked' => opsgov_mask($backupDir),
        'backup_dir_writable' => $isWritable,
        'locks_dir_writable' => $locksWritable,
        'locks_dir_masked' => opsgov_mask($locksDir),
        'mysqldump_available' => $dumpAvailable,
        'backup_mode' => $dumpAvailable ? 'mysqldump (primary)' : 'native PDO fallback',
    ];
}

function dr_backup_latest_meta(string $backupDir): array
{
    $metaFiles = glob(rtrim($backupDir, '/') . '/*.meta.json') ?: [];
    rsort($metaFiles);
    if (!$metaFiles) return [];
    $latest = (string)$metaFiles[0];
    $data = opsgov_read_json($latest);
    if (!$data) return [];
    $data['meta_file_masked'] = opsgov_mask($latest);
    return $data;
}

function dr_backup_lock_path(): string
{
    $dir = opsgov_root() . '/storage/locks';
    @mkdir($dir, 0775, true);
    return $dir . '/backup_now.lock';
}

function dr_backup_lock_acquire(): array
{
    $lock = dr_backup_lock_path();
    if (is_file($lock)) {
        $age = time() - (int)@filemtime($lock);
        if ($age < 1800) {
            return ['ok' => false, 'message' => 'Backup sedang berjalan (lock aktif). Coba beberapa menit lagi.'];
        }
        @unlink($lock);
    }
    $payload = ['ts' => date(DateTimeInterface::ATOM), 'actor' => (string)($_SESSION['username'] ?? 'SYSTEM')];
    $w = @file_put_contents($lock, json_encode($payload, JSON_UNESCAPED_SLASHES));
    if ($w !== false) {
        return ['ok' => true, 'message' => ''];
    }
    $dir = dirname($lock);
    $detail = '';
    if (!is_dir($dir)) {
        $detail = ' Folder `storage/locks` tidak ada atau gagal dibuat.';
    } elseif (!is_writable($dir)) {
        $detail = ' Folder `storage/locks` tidak writable oleh user web server (set ownership/permission).';
    } else {
        $detail = ' Cek quota disk atau permission file `backup_now.lock`.';
    }
    return ['ok' => false, 'message' => 'Gagal membuat lock backup.' . $detail];
}

function dr_backup_lock_release(): void
{
    $lock = dr_backup_lock_path();
    if (is_file($lock)) @unlink($lock);
}

function dr_backup_resolve_dir(string $root): string
{
    $primary = opsgov_env('BACKUP_PATH', $root . '/exports/evidence/backups');
    @mkdir($primary, 0775, true);
    if (is_dir($primary) && is_writable($primary)) {
        return $primary;
    }
    $fallback = $root . '/storage/backups/dr_exports';
    @mkdir($fallback, 0775, true);
    return is_dir($fallback) && is_writable($fallback) ? $fallback : $primary;
}

function dr_backup_run(string $label = 'manual'): array
{
    $root = opsgov_root();
    $ts = date('Ymd_His');
    $startedAt = date(DateTimeInterface::ATOM);
    $dbCfg = opsgov_db_runtime_config();
    $backupDir = dr_backup_resolve_dir($root);
    $env = [
        'host' => (string)$dbCfg['host'],
        'port' => (int)$dbCfg['port'],
        'user' => (string)$dbCfg['user'],
        'pass' => (string)$dbCfg['pass'],
        'name' => (string)$dbCfg['name'],
        'dump' => opsgov_env('MYSQLDUMP_BIN', 'mysqldump'),
        'backup' => $backupDir,
    ];
    @mkdir($env['backup'], 0775, true);
    @mkdir($root . '/exports', 0775, true);
    @mkdir($root . '/storage/backups/dr_packages', 0775, true);
    @file_put_contents($root . '/exports/.htaccess', "Deny from all\n");
    @file_put_contents($env['backup'] . '/.htaccess', "Deny from all\n");

    $lock = dr_backup_lock_acquire();
    if (empty($lock['ok'])) {
        ts_append_run_history('dr_backup_run_lock', 'WARN', [
            'module' => 'tools.dr',
            'action' => 'backup_run',
            'result' => 'WARN',
            'source' => 'tools/dr/backup_db.php',
            'reason' => (string)($lock['message'] ?? 'lock_failed'),
        ]);
        return ['ok' => false, 'level' => 'warn', 'message' => (string)($lock['message'] ?? 'Lock backup gagal')];
    }

    $base = 'erp_backup_' . $ts . '_' . preg_replace('/[^a-z0-9_-]/i', '_', strtolower($label));
    $sql = rtrim($env['backup'], '/') . '/' . $base . '.sql';
    $gz = $sql . '.gz';
    $log = rtrim($env['backup'], '/') . '/' . $base . '.log';
    $cmd = escapeshellcmd($env['dump']) . ' --version';
    $probeOut = []; $probeCode = 1;
    @exec($cmd . ' 2>&1', $probeOut, $probeCode);

    $usedNativeFallback = false;
    if ($probeCode === 0) {
        $dumpCmd = escapeshellcmd($env['dump'])
            . ' --single-transaction --quick --lock-tables=false'
            . ' -h ' . escapeshellarg($env['host'])
            . ' -P ' . (int)$env['port']
            . ' -u ' . escapeshellarg($env['user'])
            . ' --password=' . escapeshellarg($env['pass'])
            . ' ' . escapeshellarg($env['name'])
            . ' > ' . escapeshellarg($sql) . ' 2>> ' . escapeshellarg($log);
        $o = []; $code = 1;
        @exec($dumpCmd, $o, $code);
        if ($code !== 0 || !is_file($sql) || (int)@filesize($sql) <= 0) {
            $native = dr_backup_native_dump($sql);
            $usedNativeFallback = true;
            if (empty($native['ok']) || !is_file($sql) || (int)@filesize($sql) <= 0) {
                dr_backup_lock_release();
                return ['ok' => false, 'message' => 'Backup gagal (mysqldump + native fallback).', 'log' => opsgov_mask($log)];
            }
        }
    } else {
        $native = dr_backup_native_dump($sql);
        $usedNativeFallback = true;
        if (empty($native['ok']) || !is_file($sql) || (int)@filesize($sql) <= 0) {
            dr_backup_lock_release();
            return ['ok' => false, 'message' => 'Backup gagal. mysqldump tidak tersedia dan native fallback gagal.', 'log' => opsgov_mask($log)];
        }
    }

    @exec('gzip -f ' . escapeshellarg($sql));
    $final = is_file($gz) ? $gz : $sql;
    $sha = (string)@hash_file('sha256', $final);
    $finishedAt = date(DateTimeInterface::ATOM);
    $meta = [
        'state_version' => 1,
        'created_at' => $finishedAt,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
        'label' => $label,
        'file_masked' => opsgov_mask($final),
        'size_bytes' => (int)@filesize($final),
        'sha256' => $sha,
        'log_masked' => opsgov_mask($log),
        'native_fallback' => $usedNativeFallback,
        'mode' => $usedNativeFallback ? 'pdo_fallback' : 'mysqldump',
        'status' => 'OK',
        'actor_username' => (string)($_SESSION['username'] ?? 'SYSTEM'),
        'request_id' => 'req-' . date('YmdHis') . '-' . substr(sha1($label . microtime(true)), 0, 10),
    ];
    opsgov_safe_write_json($final . '.meta.json', $meta);
    $pkgDir = $root . '/storage/backups/dr_packages/' . $base;
    @mkdir($pkgDir, 0775, true);
    opsgov_safe_write_json($pkgDir . '/backup.meta.json', $meta);
    @copy($final, $pkgDir . '/' . basename($final));
    ts_append_run_history('dr_backup_run', 'OK', [
        'module' => 'tools.dr',
        'action' => 'backup_run',
        'result' => 'OK',
        'source' => 'tools/dr/backup_db.php',
        'file' => $meta['file_masked'],
        'mode' => $meta['mode'],
        'request_id' => $meta['request_id'],
    ]);
    $drillPath = opsgov_root() . '/storage/logs/dr_drill.jsonl';
    @file_put_contents($drillPath, json_encode([
        'state_version' => 1,
        'type' => 'backup_run',
        'result' => 'OK',
        'rto_minutes' => 0,
        'rpo_minutes' => 0,
        'actor_username' => (string)($_SESSION['username'] ?? 'SYSTEM'),
        'request_id' => $meta['request_id'],
        'ts' => $finishedAt,
        'notes_masked' => opsgov_mask('backup_mode=' . $meta['mode']),
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    dr_backup_lock_release();
    return ['ok' => true, 'meta' => $meta];
}

if (PHP_SAPI === 'cli') {
    $label = 'manual';
    foreach (($_SERVER['argv'] ?? []) as $a) {
        if (strpos((string)$a, '--label=') === 0) $label = substr((string)$a, 8);
    }
    echo json_encode(dr_backup_run($label), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$msg = ''; $type = 'info';
$dbViewCfg = opsgov_db_runtime_config();
$backupViewDir = dr_backup_resolve_dir(opsgov_root());
$readiness = dr_backup_readiness($dbViewCfg, $backupViewDir, (string)opsgov_env('MYSQLDUMP_BIN', 'mysqldump'));
$latestMeta = dr_backup_latest_meta($backupViewDir);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $run = dr_backup_run((string)($_POST['label'] ?? 'manual'));
    $msg = $run['ok'] ? 'Backup selesai: ' . (string)($run['meta']['file_masked'] ?? '-') : (string)($run['message'] ?? 'Backup gagal');
    $type = $run['ok'] ? 'success' : (((string)($run['level'] ?? '') === 'warn') ? 'warning' : 'danger');
    $latestMeta = dr_backup_latest_meta($backupViewDir);
}

$baseProject = rmi_layout_base_project();
rmi_header('DR Backup DB', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'DR Backup DB']]);
?>
<div class="card p-3">
  <?php if ($msg !== ''): ?><div class="alert alert-<?= opsgov_h($type) ?>"><?= opsgov_h($msg) ?></div><?php endif; ?>
  <div class="small mb-2">Backup database aman berbasis konfigurasi runtime (`ENV` atau config existing project) + `BACKUP_PATH`. Tanpa hardcode credential.</div>
  <div class="small text-muted mb-2">DB aktif: host <?= opsgov_h((string)$dbViewCfg['host']) ?>:<?= opsgov_h((string)$dbViewCfg['port']) ?>, db <?= opsgov_h((string)$dbViewCfg['name']) ?>, user <?= opsgov_h((string)$dbViewCfg['user']) ?>.</div>
  <div class="small mb-2">
    Readiness:
    DB <?= !empty($readiness['db_ok']) ? tools_badge('OK') : tools_badge('WARN') ?> •
    Backup dir <?= !empty($readiness['backup_dir_writable']) ? tools_badge('OK') : tools_badge('WARN') ?> •
    Lock dir (<code>storage/locks</code>) <?= !empty($readiness['locks_dir_writable']) ? tools_badge('OK') : tools_badge('FAIL') ?> •
    Dump tool <?= !empty($readiness['mysqldump_available']) ? tools_badge('OK') : tools_badge('WARN') ?>
  </div>
  <?php if (!empty($readiness['backup_dir_writable']) && str_contains((string)$readiness['backup_dir_masked'], 'storage/backups/dr_exports')): ?>
  <div class="small text-muted mb-2">Menggunakan fallback <code>storage/backups/dr_exports</code> (exports/evidence/backups tidak writable). Set <code>BACKUP_PATH</code> di .env untuk path kustom.</div>
  <?php endif; ?>
  <div class="small text-muted mb-2">
    Mode: <?= opsgov_h((string)$readiness['backup_mode']) ?> • Path: <code><?= opsgov_h((string)$readiness['backup_dir_masked']) ?></code>
    <?php if (!empty($readiness['db_error_masked'])): ?> • DB err: <code><?= opsgov_h((string)$readiness['db_error_masked']) ?></code><?php endif; ?>
  </div>
  <form method="post" class="d-flex gap-2 flex-wrap">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <input class="form-control form-control-sm" name="label" placeholder="label drill (opsional)" style="max-width:280px">
    <button class="btn btn-sm btn-rmi">Run Backup</button>
  </form>
  <?php if ($latestMeta): ?>
    <hr>
    <div class="small fw-semibold mb-1">Last Backup</div>
    <div class="small text-muted">At: <?= opsgov_h(tools_fmt_ts((string)($latestMeta['created_at'] ?? ''))) ?> • size: <?= opsgov_h(number_format(((int)($latestMeta['size_bytes'] ?? 0))/1024, 1)) ?> KB</div>
    <div class="small text-muted">File: <code><?= opsgov_h((string)($latestMeta['file_masked'] ?? '-')) ?></code></div>
    <div class="small text-muted">Mode: <?= !empty($latestMeta['native_fallback']) ? 'native fallback' : 'mysqldump' ?> • SHA256: <code><?= opsgov_h((string)($latestMeta['sha256'] ?? '-')) ?></code></div>
  <?php endif; ?>
</div>
<?php rmi_footer(); ?>
