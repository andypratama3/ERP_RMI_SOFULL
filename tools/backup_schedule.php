<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_lib/bootstrap.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_access_helpers.php';
tools_require_access('backup_schedule.php');
require_login();
// Backup schedule is SYS-only (TOOLS.BACKUP_MANAGE is not granted to ITC).
if (function_exists('require_any_permission')) {
    require_once __DIR__ . '/../_shared/rbac.php';
    require_any_permission(['TOOLS.BACKUP_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

require_once __DIR__ . '/tools_remote_check.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function bs_parse_kv_file(string $file): array {
    $out = [];
    if (!is_file($file)) return $out;
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $k = trim((string)$parts[0]);
        $v = trim((string)$parts[1]);
        $out[$k] = trim($v, "\"'");
    }
    return $out;
}

function bs_exec_with_timeout(string $cmd, int $timeoutSec = 90): array {
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($proc)) {
        return ['exit_code' => 1, 'output' => ['ERROR: cannot start process']];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $start = time();
    $stdout = '';
    $stderr = '';
    $timedOut = false;

    while (true) {
        $status = proc_get_status($proc);
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        if (!$status['running']) {
            break;
        }
        if ((time() - $start) >= $timeoutSec) {
            proc_terminate($proc, 15);
            $timedOut = true;
            usleep(200000);
            $status2 = proc_get_status($proc);
            if ($status2['running']) {
                proc_terminate($proc, 9);
            }
            break;
        }
        usleep(120000);
    }

    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($timedOut) {
        $code = 124;
        $stderr .= "\nProcess timeout after {$timeoutSec}s";
    }

    $merged = trim($stdout . "\n" . $stderr);
    $lines = $merged !== '' ? preg_split('/\R/', $merged) : [];
    return ['exit_code' => (int)$code, 'output' => array_values(array_filter($lines, static fn($x) => trim((string)$x) !== ''))];
}

function bs_validate_binary_path(string $path): array {
    $path = trim($path);
    if ($path === '') return [true, ''];
    if (!str_starts_with($path, '/')) return [false, 'Path harus absolute path (mulai dengan /).'];
    if (preg_match('/[`;\n\r\|&$><]/', $path)) return [false, 'Path mengandung karakter berbahaya.'];
    if (str_contains($path, '$(') || str_contains($path, ')')) return [false, 'Path mengandung pola command substitution.'];
    if (!preg_match('/^[A-Za-z0-9_\/\.\-]+$/', $path)) return [false, 'Path mengandung karakter tidak diizinkan.'];
    if (!file_exists($path)) return [false, 'File binary tidak ditemukan.'];
    if (!is_executable($path)) return [false, 'File binary tidak executable.'];
    return [true, ''];
}

function bs_next_run_2300(string $timezone): string {
    try {
        $tz = new DateTimeZone($timezone !== '' ? $timezone : 'Asia/Jakarta');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Jakarta');
    }
    $now = new DateTime('now', $tz);
    $next = clone $now;
    $next->setTime(23, 0, 0);
    if ($next <= $now) {
        $next->modify('+1 day');
    }
    return $next->format('Y-m-d H:i:s T');
}

function bs_tail_lines(string $file, int $maxLines = 200): array {
    if (!is_file($file) || !is_readable($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
    if (count($lines) <= $maxLines) return $lines;
    return array_slice($lines, -$maxLines);
}

function bs_read_cron_status(): array {
    $marker = 'ERP_RMI_SOFULL_DAILY_BACKUP_2300';
    $tzMarker = 'ERP_RMI_SOFULL_DAILY_BACKUP_2300_TZ';
    // Synology/web PATH sering terbatas; tambah /usr/bin:/usr/sbin
    $pathEnv = 'PATH=/usr/bin:/usr/sbin:' . (getenv('PATH') ?: '') . ' ';
    $res = bs_exec_with_timeout($pathEnv . 'crontab -l 2>&1', 8);
    $raw = implode("\n", $res['output']);
    return [
        'present' => stripos($raw, $marker) !== false,
        'tz_present' => stripos($raw, $tzMarker) !== false,
        'raw' => $res['output'],
    ];
}

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$storageBackups = $projectRoot . '/storage/backups';
$storageLogs = $projectRoot . '/storage/logs';
$logFile = $storageLogs . '/backup_daily_2300.log';
$runtimeEnvFile = $storageBackups . '/backup_runtime.env';
$stateFile = $storageBackups . '/autobackup_schedule_2300.state';
$installScript = $projectRoot . '/tools/install_daily_backup_2300.sh';
$uninstallScript = $projectRoot . '/tools/uninstall_daily_backup_2300.sh';
$backupScript = $projectRoot . '/tools/backup_now.sh';

$mutatingActions = ['install', 'uninstall', 'run_now', 'save_runtime_bins'];
$action = trim((string)($_POST['action'] ?? 'check'));
if ($action === '') $action = 'check';

$output = [];
$exitCode = 0;
$errorMessage = '';
$username = (string)($_SESSION['username'] ?? 'unknown');
$requestId = function_exists('erp_request_id') ? erp_request_id() : substr(sha1((string)microtime(true)), 0, 24);

$runtimeCfg = bs_parse_kv_file($runtimeEnvFile);
$cfgMysqldump = (string)($runtimeCfg['MYSQLDUMP_BIN'] ?? '');
$cfgMysql = (string)($runtimeCfg['MYSQL_BIN'] ?? '');
$cfgDbHost = (string)($runtimeCfg['ERP_DB_HOST'] ?? $runtimeCfg['DB_HOST'] ?? tools_get_env_db('HOST'));
$cfgDbPort = (string)($runtimeCfg['ERP_DB_PORT'] ?? $runtimeCfg['DB_PORT'] ?? tools_get_env_db('PORT'));
$cfgDbName = (string)($runtimeCfg['ERP_DB_NAME'] ?? $runtimeCfg['DB_NAME'] ?? $runtimeCfg['DB_DATABASE'] ?? tools_get_env_db('NAME'));
$cfgDbUser = (string)($runtimeCfg['ERP_DB_USER'] ?? $runtimeCfg['DB_USER'] ?? $runtimeCfg['DB_USERNAME'] ?? tools_get_env_db('USER'));
$cfgDbPass = (string)($runtimeCfg['ERP_DB_PASS'] ?? $runtimeCfg['DB_PASS'] ?? $runtimeCfg['DB_PASSWORD'] ?? tools_get_env_db('PASS'));

if (in_array($action, $mutatingActions, true)) {
    require_post();
    verify_csrf();
}

if ($action === 'install') {
    $res = bs_exec_with_timeout('/bin/bash ' . escapeshellarg($installScript), 20);
    $exitCode = $res['exit_code'];
    $output = $res['output'];
    if ($exitCode === 0) {
        @mkdir($storageLogs, 0775, true);
        @touch($logFile);
        $state = bs_parse_kv_file($stateFile);
        $state['state_version'] = '1';
        $state['last_action'] = 'install';
        $state['last_action_by'] = $username;
        $state['last_action_at'] = gmdate('c');
        $lines = [];
        foreach ($state as $k => $v) $lines[] = $k . '=' . $v;
        @file_put_contents($stateFile, implode("\n", $lines) . "\n");
        ts_append_run_history('backup_scheduler_enable', 'ok', ['actor_username' => $username, 'source' => 'backup_schedule.php']);
    }
    try {
        $pdo = rmi_db_pdo();
        erp_audit_ensure($pdo);
        audit_event($pdo, 'BACKUP_SCHEDULER_ENABLE', 'TOOLS_BACKUP', 'SCHEDULER', '2300', 'Enable scheduler 23:00 WIB', [
            'request_id' => $requestId,
            'actor_username' => $username,
            'status' => $exitCode === 0 ? 'ok' : 'failed',
            'output_lines' => count($output),
        ]);
    } catch (Throwable $e) {}
} elseif ($action === 'uninstall') {
    $res = bs_exec_with_timeout('/bin/bash ' . escapeshellarg($uninstallScript), 20);
    $exitCode = $res['exit_code'];
    $output = $res['output'];
    if ($exitCode === 0) {
        $state = bs_parse_kv_file($stateFile);
        $state['state_version'] = '1';
        $state['last_action'] = 'disable';
        $state['last_action_by'] = $username;
        $state['last_action_at'] = gmdate('c');
        $state['enabled'] = '0';
        $lines = [];
        foreach ($state as $k => $v) $lines[] = $k . '=' . $v;
        @file_put_contents($stateFile, implode("\n", $lines) . "\n");
        ts_append_run_history('backup_scheduler_disable', 'ok', ['actor_username' => $username, 'source' => 'backup_schedule.php']);
    }
    try {
        $pdo = rmi_db_pdo();
        erp_audit_ensure($pdo);
        audit_event($pdo, 'BACKUP_SCHEDULER_DISABLE', 'TOOLS_BACKUP', 'SCHEDULER', '2300', 'Disable scheduler 23:00 WIB', [
            'request_id' => $requestId,
            'actor_username' => $username,
            'status' => $exitCode === 0 ? 'ok' : 'failed',
            'output_lines' => count($output),
        ]);
    } catch (Throwable $e) {}
} elseif ($action === 'run_now') {
    @mkdir($storageLogs, 0775, true);
    @touch($logFile);
    $cmd = '/bin/bash ' . escapeshellarg($backupScript) . ' --label web_test_now';
    $res = bs_exec_with_timeout($cmd, 240);
    $exitCode = $res['exit_code'];
    $output = $res['output'];
    $append = '[' . gmdate('c') . '] RUN_TEST_NOW by ' . $username . ' exit=' . $exitCode . PHP_EOL;
    $append .= implode(PHP_EOL, $output) . PHP_EOL;
    @file_put_contents($logFile, $append, FILE_APPEND);
    ts_append_run_history('backup_run', $exitCode === 0 ? 'ok' : 'fail', ['actor_username' => $username, 'source' => 'backup_schedule.php', 'exit_code' => $exitCode]);
    try {
        $pdo = rmi_db_pdo();
        erp_audit_ensure($pdo);
        audit_event($pdo, 'BACKUP_SCHEDULER_RUN_TEST', 'TOOLS_BACKUP', 'SCHEDULER', '2300', 'Run test now from scheduler UI', [
            'request_id' => $requestId,
            'actor_username' => $username,
            'status' => $exitCode === 0 ? 'ok' : 'failed',
            'duration_ms' => null,
            'output_lines' => count($output),
        ]);
    } catch (Throwable $e) {}
} elseif ($action === 'save_runtime_bins') {
    $inputMysqldump = trim((string)($_POST['mysqldump_bin'] ?? ''));
    $inputMysql = trim((string)($_POST['mysql_bin'] ?? ''));
    $inputDbHost = trim((string)($_POST['db_host'] ?? ''));
    $inputDbPort = trim((string)($_POST['db_port'] ?? ''));
    $inputDbName = trim((string)($_POST['db_name'] ?? ''));
    $inputDbUser = trim((string)($_POST['db_user'] ?? ''));
    $inputDbPass = trim((string)($_POST['db_pass'] ?? ''));
    if ($inputDbPass === '' && $cfgDbPass !== '') {
        // Keep existing secret when field intentionally left blank.
        $inputDbPass = $cfgDbPass;
    }

    [$okDump, $errDump] = bs_validate_binary_path($inputMysqldump);
    [$okMysql, $errMysql] = bs_validate_binary_path($inputMysql);
    if (!$okDump) $errorMessage = 'MYSQLDUMP_BIN: ' . $errDump;
    if ($errorMessage === '' && !$okMysql) $errorMessage = 'MYSQL_BIN: ' . $errMysql;
    if ($errorMessage === '' && $inputDbPort !== '' && (!ctype_digit($inputDbPort) || (int)$inputDbPort < 1 || (int)$inputDbPort > 65535)) {
        $errorMessage = 'ERP_DB_PORT tidak valid.';
    }
    if ($errorMessage === '' && preg_match('/[`;\n\r\|&$><]/', $inputDbHost . $inputDbName . $inputDbUser)) {
        $errorMessage = 'Field DB mengandung karakter berbahaya.';
    }

    if ($errorMessage !== '') {
        $exitCode = 1;
        $output[] = 'ERROR: ' . $errorMessage;
    } else {
        @mkdir($storageBackups, 0775, true);
        $rows = ["# Managed by tools/backup_schedule.php"];
        if ($inputMysqldump !== '') $rows[] = 'MYSQLDUMP_BIN="' . addcslashes($inputMysqldump, "\\\"") . '"';
        if ($inputMysql !== '') $rows[] = 'MYSQL_BIN="' . addcslashes($inputMysql, "\\\"") . '"';
        if ($inputDbHost !== '') $rows[] = 'ERP_DB_HOST="' . addcslashes($inputDbHost, "\\\"") . '"';
        if ($inputDbPort !== '') $rows[] = 'ERP_DB_PORT="' . addcslashes($inputDbPort, "\\\"") . '"';
        if ($inputDbName !== '') $rows[] = 'ERP_DB_NAME="' . addcslashes($inputDbName, "\\\"") . '"';
        if ($inputDbUser !== '') $rows[] = 'ERP_DB_USER="' . addcslashes($inputDbUser, "\\\"") . '"';
        if ($inputDbPass !== '') $rows[] = 'ERP_DB_PASS="' . addcslashes($inputDbPass, "\\\"") . '"';
        if (@file_put_contents($runtimeEnvFile, implode("\n", $rows) . "\n") === false) {
            $exitCode = 1;
            $output[] = 'ERROR: gagal menyimpan konfigurasi runtime.';
        } else {
            @chmod($runtimeEnvFile, 0600);
            $cfgMysqldump = $inputMysqldump;
            $cfgMysql = $inputMysql;
            $cfgDbHost = $inputDbHost;
            $cfgDbPort = $inputDbPort;
            $cfgDbName = $inputDbName;
            $cfgDbUser = $inputDbUser;
            $cfgDbPass = $inputDbPass;
            $output[] = 'Config runtime tersimpan.';
            ts_append_run_history('backup_runtime_config_save', 'ok', ['actor_username' => $username, 'source' => 'backup_schedule.php']);
            try {
                $pdo = rmi_db_pdo();
                erp_audit_ensure($pdo);
                audit_event($pdo, 'BACKUP_RUNTIME_CONFIG_SAVED', 'TOOLS_BACKUP', 'RUNTIME_CONFIG', 'backup_runtime.env', 'Save runtime binaries/db config', [
                    'request_id' => $requestId,
                    'actor_username' => $username,
                    'status' => 'ok',
                    'has_mysqldump_bin' => $inputMysqldump !== '',
                    'has_mysql_bin' => $inputMysql !== '',
                    'db_host' => $inputDbHost,
                    'db_port' => $inputDbPort,
                    'db_name' => $inputDbName,
                    'db_user' => $inputDbUser,
                ]);
            } catch (Throwable $e) {}
        }
    }
} elseif ($action !== 'check') {
    $exitCode = 1;
    $output[] = 'ERROR: action tidak dikenali.';
}

if ($action === 'check') {
    try {
        $pdo = rmi_db_pdo();
        erp_audit_ensure($pdo);
        audit_event($pdo, 'BACKUP_SCHEDULER_CHECK_STATUS', 'TOOLS_BACKUP', 'SCHEDULER', '2300', 'Check scheduler status', [
            'request_id' => $requestId,
            'actor_username' => $username,
            'status' => 'ok',
        ]);
    } catch (Throwable $e) {}
}

$state = bs_parse_kv_file($stateFile);
$cron = bs_read_cron_status();
$schedulerEnabled = (($state['enabled'] ?? '0') === '1');
$cronPresent = (bool)$cron['present'];
$timezone = (string)($state['timezone'] ?? 'Asia/Jakarta');
$nextRun = bs_next_run_2300($timezone);
$lastRun = (string)($state['last_run_at'] ?? '');
$lastStatus = (string)($state['last_status'] ?? '');

if ($lastRun === '' && is_file($logFile)) {
    $tail = bs_tail_lines($logFile, 300);
    foreach (array_reverse($tail) as $line) {
        if (preg_match('/^\[([0-9T:\-]+Z)\]\s+BACKUP_DONE\s+status=([a-z]+)/i', (string)$line, $m)) {
            $lastRun = $m[1];
            $lastStatus = strtolower($m[2]);
            break;
        }
    }
}

$latestBackupDir = '';
$latestBackupName = '';
$latestBackupSize = 0;
$latestBackupCreated = '';
foreach (glob($storageBackups . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [] as $dir) {
    $mtime = @filemtime($dir) ?: 0;
    if ($latestBackupCreated === '' || $mtime > strtotime($latestBackupCreated)) {
        $latestBackupDir = $dir;
        $latestBackupName = basename($dir);
        $latestBackupCreated = gmdate('Y-m-d\TH:i:s\Z', $mtime);
    }
}
if ($latestBackupDir !== '') {
    $files = @glob($latestBackupDir . '/*') ?: [];
    foreach ($files as $f) {
        if (is_file($f)) $latestBackupSize += (int)@filesize($f);
    }
}

$diskFree = @disk_free_space($projectRoot);
$diskFreeText = is_numeric($diskFree) ? number_format(((float)$diskFree) / 1024 / 1024 / 1024, 2) . ' GB' : '-';
$logLines = bs_tail_lines($logFile, 200);
$logMessage = (!is_file($logFile) || count($logLines) === 0)
    ? 'Belum ada eksekusi backup. Klik Run Test Now untuk mencoba.'
    : implode("\n", $logLines);

$maskedOutput = [];
foreach ($output as $line) $maskedOutput[] = tools_mask_sensitive((string)$line);
$maskedOutputText = implode("\n", $maskedOutput);
$runtimeValid = ($cfgMysqldump !== '' && $cfgMysql !== '' && $cfgDbHost !== '' && $cfgDbName !== '' && $cfgDbUser !== '');
$backupHealth = 'ATTENTION';
if ($schedulerEnabled && $cronPresent && $runtimeValid && $lastStatus !== 'failed') {
    $backupHealth = 'HEALTHY';
}
if (!$runtimeValid || !$cronPresent || $lastStatus === 'failed') {
    $backupHealth = 'CRITICAL';
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
$cutoverLink = tools_cutover_one_pager_link($baseProject);
rmi_header('Autobackup Scheduler 23:00 WIB', [
    'active'      => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Autobackup Scheduler',
    ],
]);
?>
<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="fw-semibold">Autobackup Scheduler (23:00 WIB)</div>
    <div class="d-flex gap-2 flex-wrap">
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="action" value="install">
        <button type="submit" class="btn btn-sm btn-primary">Enable 23:00 WIB</button>
      </form>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <button type="submit" class="btn btn-sm btn-outline-light">Check Status</button>
      </form>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="action" value="run_now">
        <button type="submit" class="btn btn-sm btn-outline-info">Run Test Now</button>
      </form>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="action" value="uninstall">
        <button type="submit" class="btn btn-sm btn-outline-danger">Disable Scheduler</button>
      </form>
      <a class="btn btn-sm btn-outline-light" href="<?= h($cutoverLink) ?>">Cutover One Pager</a>
      <a class="btn btn-sm btn-outline-light" href="index.php">Kembali</a>
    </div>
  </div>
  <div class="rmi-muted mt-2">Zona waktu scheduler: <code><?= h($timezone) ?></code> · <?= tools_badge($backupHealth) ?></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Scheduler</div><div class="fw-semibold"><?= tools_badge($schedulerEnabled ? 'ENABLED' : 'DISABLED') ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Cron Entry</div><div class="fw-semibold"><?= tools_badge($cronPresent ? 'PRESENT' : 'MISSING') ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Next Run</div><div class="fw-semibold"><?= h(tools_fmt_ts($nextRun)) ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Disk Free</div><div class="fw-semibold"><?= h($diskFreeText) ?></div></div></div>
  <div class="col-md-6"><div class="rmi-card p-3"><div class="rmi-muted">Last Run</div><div class="fw-semibold"><?= h(tools_fmt_ts($lastRun)) ?></div><div class="rmi-muted">Status: <?= ($lastStatus !== '' && strtolower($lastStatus) === 'ok') ? tools_badge('OK') : ($lastStatus !== '' ? tools_badge('FAIL') : tools_badge('UNKNOWN')) ?></div></div></div>
  <div class="col-md-6"><div class="rmi-card p-3"><div class="rmi-muted">Last Backup</div><div class="fw-semibold"><?= h($latestBackupName !== '' ? $latestBackupName : '-') ?></div><div class="rmi-muted">Size: <?= h($latestBackupSize > 0 ? number_format($latestBackupSize / 1024 / 1024, 2) . ' MB' : '-') ?> • Created: <?= h(tools_fmt_ts($latestBackupCreated)) ?></div></div></div>
</div>

<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Runtime Config (Binary + DB)</div>
  <div class="rmi-muted mb-3">Path binary wajib absolute, tanpa karakter shell berbahaya, file harus executable.</div>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
    <input type="hidden" name="action" value="save_runtime_bins">
    <div class="col-12 col-lg-6">
      <label class="form-label">MYSQLDUMP_BIN</label>
      <input type="text" class="form-control" name="mysqldump_bin" value="<?= h($cfgMysqldump) ?>" placeholder="/usr/bin/mysqldump">
    </div>
    <div class="col-12 col-lg-6">
      <label class="form-label">MYSQL_BIN</label>
      <input type="text" class="form-control" name="mysql_bin" value="<?= h($cfgMysql) ?>" placeholder="/usr/bin/mysql">
    </div>
    <div class="col-md-3">
      <label class="form-label">ERP_DB_HOST</label>
      <input type="text" class="form-control" name="db_host" value="<?= h($cfgDbHost) ?>" placeholder="127.0.0.1">
    </div>
    <div class="col-md-2">
      <label class="form-label">ERP_DB_PORT</label>
      <input type="text" class="form-control" name="db_port" value="<?= h($cfgDbPort) ?>" placeholder="3306">
    </div>
    <div class="col-md-3">
      <label class="form-label">ERP_DB_NAME</label>
      <input type="text" class="form-control" name="db_name" value="<?= h($cfgDbName) ?>" placeholder="ERP_RMI_SOFULL">
    </div>
    <div class="col-md-2">
      <label class="form-label">ERP_DB_USER</label>
      <input type="text" class="form-control" name="db_user" value="<?= h($cfgDbUser) ?>" placeholder="root">
    </div>
    <div class="col-md-2">
      <label class="form-label">ERP_DB_PASS</label>
      <input type="password" class="form-control" name="db_pass" value="" placeholder="Kosongkan untuk pakai nilai tersimpan">
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-sm btn-outline-light">Simpan Config Runtime</button>
      <span class="rmi-muted ms-2">Config file: <code><?= h(tools_mask_sensitive($runtimeEnvFile)) ?></code></span>
    </div>
  </form>
</div>

<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2"><?= $exitCode === 0 ? 'Command selesai' : 'Command error' ?></div>
  <pre style="white-space:pre-wrap"><?= h($maskedOutputText !== '' ? $maskedOutputText : '(tidak ada output)') ?></pre>
</div>

<div class="rmi-card p-3">
  <div class="d-flex justify-content-between align-items-center">
    <div class="fw-semibold">Log (last 200 lines)</div>
    <div class="rmi-muted"><code><?= h(tools_mask_sensitive($logFile)) ?></code></div>
  </div>
  <pre class="mt-2" style="white-space:pre-wrap"><?= h(tools_mask_sensitive($logMessage)) ?></pre>
</div>

<?php rmi_footer(); ?>
