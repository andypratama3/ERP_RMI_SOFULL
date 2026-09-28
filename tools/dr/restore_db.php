<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function dr_restore_run(string $filePath): array
{
    $root = opsgov_root();
    $dbCfg = opsgov_db_runtime_config();
    $env = [
        'host' => (string)$dbCfg['host'],
        'port' => (int)$dbCfg['port'],
        'user' => (string)$dbCfg['user'],
        'pass' => (string)$dbCfg['pass'],
        'name' => (string)$dbCfg['name'],
        'mysql' => opsgov_env('MYSQL_BIN', 'mysql'),
    ];
    if ($env['user'] === '' || $env['name'] === '' || $filePath === '') {
        return ['ok' => false, 'message' => 'ENV DB atau file backup belum siap.'];
    }
    $real = realpath($filePath);
    if (!$real || !is_file($real)) {
        return ['ok' => false, 'message' => 'File backup tidak ditemukan.'];
    }
    $backupBase = realpath($root . '/storage/backups');
    if (!$backupBase || !str_starts_with($real, $backupBase . DIRECTORY_SEPARATOR)) {
        return ['ok' => false, 'message' => 'File backup di luar direktori yang diizinkan.'];
    }
    $ext = strtolower((string)pathinfo($real, PATHINFO_EXTENSION));
    if (!in_array($ext, ['sql', 'gz'], true)) {
        return ['ok' => false, 'message' => 'Format backup tidak didukung. Gunakan .sql atau .gz'];
    }
    $tmpSql = $real;
    $cleanupTmp = false;
    if (str_ends_with(strtolower($real), '.gz')) {
        $tmpSql = sys_get_temp_dir() . '/dr_restore_' . date('Ymd_His') . '.sql';
        $raw = @file_get_contents('compress.zlib://' . $real);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'message' => 'Gagal membaca arsip .gz backup'];
        }
        @file_put_contents($tmpSql, $raw);
        $cleanupTmp = true;
    }
    if (!is_file($tmpSql) || (int)@filesize($tmpSql) <= 0) {
        return ['ok' => false, 'message' => 'SQL restore kosong/invalid'];
    }

    $started = microtime(true);
    $cmd = escapeshellcmd($env['mysql'])
        . ' -h ' . escapeshellarg($env['host'])
        . ' -P ' . (int)$env['port']
        . ' -u ' . escapeshellarg($env['user'])
        . ' --password=' . escapeshellarg($env['pass'])
        . ' ' . escapeshellarg($env['name'])
        . ' < ' . escapeshellarg($tmpSql);
    $o = []; $code = 1;
    @exec($cmd . ' 2>&1', $o, $code);
    $elapsed = round((microtime(true) - $started), 3);
    if ($cleanupTmp && is_file($tmpSql)) @unlink($tmpSql);
    if ($code !== 0) {
        return ['ok' => false, 'message' => 'Restore gagal. cek mysql client/env.', 'duration_sec' => $elapsed];
    }

    $healthOk = false;
    try {
        $pdo = rmi_db_pdo();
        $healthOk = (bool)$pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $e) {
        $healthOk = false;
    }
    $rtoSec = $elapsed;
    $rpoSeconds = max(0, time() - (int)@filemtime($real));
    $row = [
        'state_version' => 1,
        'ts' => date(DateTimeInterface::ATOM),
        'backup_file_masked' => opsgov_mask($real),
        'restore_duration_sec' => $elapsed,
        'health_ok' => $healthOk,
        'rto_sec' => $rtoSec,
        'rpo_sec' => $rpoSeconds,
    ];
    $logPath = $root . '/tools/logs/dr_restore_log.jsonl';
    @mkdir(dirname($logPath), 0775, true);
    @file_put_contents($logPath, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    $requestId = 'req-' . date('YmdHis') . '-' . substr(sha1($real . microtime(true)), 0, 10);
    ts_append_run_history('dr_restore_run', 'OK', [
        'module' => 'tools.dr',
        'action' => 'restore_apply',
        'result' => 'OK',
        'source' => 'tools/dr/restore_db.php',
        'rto_sec' => $rtoSec,
        'rpo_sec' => $rpoSeconds,
        'request_id' => $requestId,
    ]);
    @file_put_contents($root . '/storage/logs/dr_drill.jsonl', json_encode([
        'state_version' => 1,
        'type' => 'restore_apply',
        'result' => 'OK',
        'rto_minutes' => round($rtoSec / 60, 2),
        'rpo_minutes' => round($rpoSeconds / 60, 2),
        'actor_username' => (string)($_SESSION['username'] ?? 'SYSTEM'),
        'request_id' => $requestId,
        'ts' => date(DateTimeInterface::ATOM),
        'notes_masked' => opsgov_mask('restore from ' . $real),
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return ['ok' => true, 'data' => $row];
}

if (PHP_SAPI === 'cli') {
    $file = '';
    foreach (($_SERVER['argv'] ?? []) as $a) {
        if (strpos((string)$a, '--file=') === 0) $file = substr((string)$a, 7);
    }
    echo json_encode(dr_restore_run($file), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$msg = ''; $type = 'info';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $run = dr_restore_run((string)($_POST['backup_file'] ?? ''));
    $msg = $run['ok'] ? 'Restore selesai. RTO=' . (string)($run['data']['rto_sec'] ?? '-') . 's, RPO=' . (string)($run['data']['rpo_sec'] ?? '-') . 's' : (string)($run['message'] ?? 'Restore gagal');
    $type = $run['ok'] ? 'success' : 'danger';
}

$backupDir = opsgov_root() . '/storage/backups';
$cand = glob(rtrim($backupDir, '/') . '/*.sql*') ?: [];
$cand = array_merge($cand, glob(rtrim($backupDir, '/') . '/dr_packages/*/*.sql*') ?: []);
rsort($cand);
$baseProject = rmi_layout_base_project();
rmi_header('DR Restore DB', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'DR Restore DB']]);
?>
<div class="card p-3">
  <?php if ($msg !== ''): ?><div class="alert alert-<?= opsgov_h($type) ?>"><?= opsgov_h($msg) ?></div><?php endif; ?>
  <div class="small mb-2">Restore database dari backup (.sql/.gz). Wajib admin-only.</div>
  <form method="post" class="d-flex gap-2 flex-wrap">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <select class="form-select form-select-sm" name="backup_file" style="max-width:520px">
      <option value="">-- pilih file backup --</option>
      <?php foreach ($cand as $f): ?><option value="<?= opsgov_h($f) ?>"><?= opsgov_h(opsgov_mask($f)) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-warning" onclick="return confirm('Lanjut restore DB? Pastikan maintenance window.');">Run Restore</button>
  </form>
</div>
<?php rmi_footer(); ?>
