<?php
/**
 * Verifikasi Sumber Data — ERP vs Tools
 *
 * Pastikan ERP Web dan Tools membaca database yang sama.
 * Bisa dijalankan via CLI atau Web (login admin).
 *
 * CLI: cd /volume4/web/ERP_RMI_SOFULL && php tools/audit/verify_data_source.php
 * Web: https://10.10.60.20/ERP_RMI_SOFULL/tools/audit/verify_data_source.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../tools_remote_check.php';
    require_once __DIR__ . '/../../master/auth.php';
    require_login();
    $role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
    $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
    if (!in_array($role, ['ADMIN','SUPERADMIN'], true) && !in_array($level, ['ADMIN','SUPERADMIN'], true)) {
        http_response_code(403);
        die('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/_shared/db.php';

echo "=== VERIFIKASI SUMBER DATA ERP_RMI_SOFULL ===\n\n";

// 1. Config source
$cfg = function_exists('rmi_db_config') ? rmi_db_config() : [];
$cfgFile = $root . '/config-db.php';
if (!is_file($cfgFile)) {
    $cfgFile = $root . '/config-db.example.php';
}
echo "1. Config file: " . ($cfgFile ? basename($cfgFile) : 'NONE') . "\n";
echo "   Path: " . $root . "\n\n";

// 2. DB config (masked)
echo "2. DB Config (masked):\n";
echo "   host: " . ($cfg['host'] ?? '?') . "\n";
echo "   port: " . ($cfg['port'] ?? '?') . "\n";
echo "   name: " . ($cfg['name'] ?? '?') . "\n";
echo "   user: " . ($cfg['user'] ?? '?') . "\n";
echo "   pass: " . (isset($cfg['pass']) && $cfg['pass'] !== '' ? '****' : '(empty)') . "\n\n";

// 3. PDO connection
try {
    $pdo = rmi_db_pdo();
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "3. Connection: OK\n";
    echo "   DATABASE(): {$db}\n";
    echo "   VERSION(): {$ver}\n\n";
} catch (Throwable $e) {
    echo "3. Connection: FAIL - " . $e->getMessage() . "\n\n";
    exit(1);
}

// 4. Sample data (real system check)
echo "4. Sample data (real system):\n";
$tables = ['master_customers', 'sales_do', 'master_products'];
foreach ($tables as $t) {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "   {$t}: {$n} rows\n";
    } catch (Throwable $e) {
        echo "   {$t}: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n5. Kesimpulan:\n";
echo "   - Tools dan ERP memakai config dari: " . ($root . '/config-db.php') . "\n";
echo "   - Database: {$db}\n";
echo "   - Jika angka di atas masuk akal → data real system.\n";
echo "\n=== SELESAI ===\n";
