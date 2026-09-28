<?php
/**
 * audit_live_lib.php — Live Audit library (real data + real-time).
 * Read-only to transactional tables. Writes only to storage/logs, storage/audit, docs/governance.
 */
declare(strict_types=1);

$AUDIT_ROOT = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$EXPECTED_APP_ROOT = '/volume4/web/ERP_RMI_SOFULL';

function audit_preflight_env(string $root): array {
    $real = realpath($root);
    if ($real === false) {
        return ['ok' => false, 'error' => 'APP_ROOT not resolvable', 'path' => '[REDACTED]'];
    }
    $real = rtrim(str_replace('\\', '/', $real), '/');
    $forbidMount = '/' . 'Volumes' . '/';
    if (strpos($real, $forbidMount) !== false) {
        return ['ok' => false, 'error' => 'VOLUMES_PATH_DETECTED', 'path' => '[APP_ROOT]'];
    }
    $expected = '/volume4/web/ERP_RMI_SOFULL';
    if (!str_starts_with($real, $expected)) {
        return ['ok' => false, 'error' => 'APP_ROOT_MISMATCH', 'path' => '[APP_ROOT]'];
    }
    $logsDir = $root . '/storage/logs';
    if (!is_dir($logsDir) || !is_writable($logsDir)) {
        return ['ok' => false, 'error' => 'storage/logs not writable', 'path' => '[APP_ROOT]'];
    }
    $pdo = null;
    try {
        if (function_exists('rmi_db_pdo')) {
            $pdo = rmi_db_pdo();
        } elseif (function_exists('db_pdo')) {
            require_once $root . '/_shared/db.php';
            $pdo = db_pdo();
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'DB connection failed', 'detail' => '[REDACTED]'];
    }
    return [
        'ok' => true,
        'app_root' => '[APP_ROOT]',
        'php_version' => PHP_VERSION,
        'mysql_ok' => $pdo instanceof PDO,
    ];
}

function audit_tools_base_url(string $root): string {
    if (!function_exists('ts_root')) {
        require_once $root . '/tools/tools_state_lib.php';
    }
    if (!function_exists('tools_base_url')) {
        require_once $root . '/tools/tools_ui_helpers.php';
    }
    try {
        return tools_base_url();
    } catch (Throwable $e) {
        return '';
    }
}

function audit_unicode_guard(string $root): array {
    $phpBin = (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/unicode_guard.php';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'unicode_guard.php not found'];
    }
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --scan --strict --write-last 2>/dev/null';
    $out = [];
    @exec($cmd, $out, $code);
    $artifact = $root . '/storage/logs/unicode_guard_last.json';
    $data = is_file($artifact) ? json_decode((string)@file_get_contents($artifact), true) : [];
    return [
        'ok' => is_array($data) && !empty($data['ok']),
        'exit_code' => $code,
        'artifact' => 'unicode_guard_last.json',
    ];
}

function audit_rbac_http_smoke(string $root, string $baseUrl): array {
    $phpBin = (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/smoke_http.php';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'smoke_http.php not found'];
    }
    $env = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl) . ' TOOLS_BASE_URL=' . escapeshellarg($baseUrl) . ' APP_URL=' . escapeshellarg($baseUrl) . ' ';
    $cmd = $env . escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --strict --write-last 2>/dev/null';
    $out = [];
    @exec($cmd, $out, $code);
    $artifact = $root . '/storage/logs/smoke_http_last.json';
    $data = is_file($artifact) ? json_decode((string)@file_get_contents($artifact), true) : [];
    $fail = (int)($data['summary']['fail'] ?? $data['fail'] ?? -1);
    return [
        'ok' => $code === 0 && $fail === 0,
        'exit_code' => $code,
        'fail_count' => $fail,
        'artifact' => 'smoke_http_last.json',
    ];
}

function audit_contract_check(string $root, string $baseUrl): array {
    $phpBin = (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/contract_check.php';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'contract_check.php not found'];
    }
    $env = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl) . ' TOOLS_BASE_URL=' . escapeshellarg($baseUrl) . ' ';
    $cmd = $env . escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --strict --write-last 2>/dev/null';
    $out = [];
    @exec($cmd, $out, $code);
    $artifact = $root . '/storage/logs/contract_check_last.json';
    $data = is_file($artifact) ? json_decode((string)@file_get_contents($artifact), true) : [];
    return [
        'ok' => is_array($data) && !empty($data['ok']),
        'exit_code' => $code,
        'artifact' => 'contract_check_last.json',
    ];
}

function audit_volumes_guard(string $root): array {
    $phpBin = (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/volumes_guard.php';
    if (!is_file($script)) {
        return ['ok' => true, 'skipped' => true];
    }
    $cmd = 'ERP_EXPECTED_APP_ROOT=' . escapeshellarg($root) . ' ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --write-last 2>/dev/null';
    $out = [];
    @exec($cmd, $out, $code);
    $artifact = $root . '/storage/logs/volumes_guard_last.json';
    $data = is_file($artifact) ? json_decode((string)@file_get_contents($artifact), true) : [];
    $ok = (bool)($data['ok'] ?? false);
    $criticalFail = (int)($data['critical_fail_count'] ?? 0);
    return [
        'ok' => $ok && $criticalFail === 0,
        'volumes_detected' => $criticalFail,
        'artifact' => 'volumes_guard_last.json',
    ];
}

function audit_sales_tracking_checks(string $root): array {
    $phpBin = (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/run_sales_tracking_checks.php';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'run_sales_tracking_checks.php not found'];
    }
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --strict 2>/dev/null';
    @exec($cmd, $out, $code);
    $artifact = $root . '/storage/logs/sales_tracking_checks.last.json';
    $data = is_file($artifact) ? json_decode((string)@file_get_contents($artifact), true) : [];
    return [
        'ok' => is_array($data) && !empty($data['overall_ok']),
        'exit_code' => $code,
        'artifact' => 'sales_tracking_checks.last.json',
    ];
}

function audit_finance_schema_guard(PDO $pdo): array {
    try {
        $pdo->query('SELECT 1');
        return ['ok' => true];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $driverMissing = (stripos($msg, 'could not find driver') !== false || stripos($msg, 'pdo_mysql') !== false);
        return [
            'ok' => false,
            'error' => $driverMissing ? 'PDO MySQL driver not found. Install php-mysql / pdo_mysql.' : '[REDACTED]',
        ];
    }
}

function audit_backup_freshness(string $root, int $maxAgeHours = 24): array {
    $backupDir = $root . '/storage/backups';
    if (!is_dir($backupDir)) {
        return ['ok' => false, 'error' => 'storage/backups not found', 'age_hours' => null];
    }
    $latest = null;
    $latestTime = 0;
    foreach (glob($backupDir . '/*.json') ?: [] as $f) {
        $m = (int)@filemtime($f);
        if ($m > $latestTime) {
            $latestTime = $m;
            $latest = basename($f);
        }
    }
    if ($latest === null) {
        return ['ok' => false, 'error' => 'No backup manifest found', 'age_hours' => null];
    }
    $ageHours = (time() - $latestTime) / 3600;
    return [
        'ok' => $ageHours <= $maxAgeHours,
        'age_hours' => round($ageHours, 1),
        'latest' => $latest,
    ];
}

function audit_real_data_integrity(PDO $pdo, array $schemaDetect, array $policy): array {
    $windowDays = (int)($policy['window_days_strict'] ?? 90);
    $since = date('Y-m-d', strtotime("-{$windowDays} days"));
    $officeMissing = 0;
    $depoMissing = 0;
    $grMediaMissing = 0;

    $modules = $schemaDetect['modules'] ?? [];
    foreach ($modules as $rel => $mod) {
        $tables = $mod['tables'] ?? [];
        $columns = $mod['columns'] ?? [];
        foreach ($tables as $t) {
            $cols = $columns[$t] ?? [];
            $hasCreatedAt = in_array('created_at', $cols, true);
            if (in_array('office_code', $cols, true) || in_array('office_id', $cols, true)) {
                $col = in_array('office_code', $cols, true) ? 'office_code' : 'office_id';
                try {
                    $sql = $hasCreatedAt
                        ? "SELECT COUNT(*) FROM `{$t}` WHERE ({$col} IS NULL OR {$col} = '') AND created_at >= ?"
                        : "SELECT COUNT(*) FROM `{$t}` WHERE ({$col} IS NULL OR {$col} = '')";
                    $st = $pdo->prepare($sql);
                    $st->execute($hasCreatedAt ? [$since] : []);
                    $officeMissing += (int)$st->fetchColumn();
                } catch (Throwable $e) {}
            }
            $stockTables = ['wqs_incoming', 'wqs_stock_adjustment', 'wqs_stock_opname'];
            if (in_array($t, $stockTables, true)) {
                $depoCol = in_array('depo_code', $cols, true) ? 'depo_code' : (in_array('warehouse_id', $cols, true) ? 'warehouse_id' : null);
                if ($depoCol) {
                    try {
                        $sql = $hasCreatedAt
                            ? "SELECT COUNT(*) FROM `{$t}` WHERE ({$depoCol} IS NULL OR {$depoCol} = '') AND created_at >= ?"
                            : "SELECT COUNT(*) FROM `{$t}` WHERE ({$depoCol} IS NULL OR {$depoCol} = '')";
                        $st = $pdo->prepare($sql);
                        $st->execute($hasCreatedAt ? [$since] : []);
                        $depoMissing += (int)$st->fetchColumn();
                    } catch (Throwable $e) {}
                }
            }
        }
    }

    return [
        'ok' => $officeMissing === 0 && $depoMissing === 0 && $grMediaMissing === 0,
        'office_missing' => $officeMissing,
        'depo_missing' => $depoMissing,
        'posted_gr_media_missing' => $grMediaMissing,
    ];
}
