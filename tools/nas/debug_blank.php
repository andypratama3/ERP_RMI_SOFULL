<?php
/**
 * debug_blank.php — Tampilkan error untuk halaman blank
 * 
 * CARA PAKAI DI NAS:
 * 1. Copy file ini ke root project: /volume4/web/ERP_RMI_SOFULL/debug_blank.php
 * 2. Buka: http://[IP-NAS]/ERP_RMI_SOFULL/debug_blank.php
 * 3. Lihat error yang muncul
 * 4. HAPUS file ini setelah selesai debug (keamanan)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$root = __DIR__;
echo "<h2>ERP Debug — Halaman Blank</h2>";
echo "<pre>";

echo "1. Root: $root\n";
echo "2. .env exists: " . (is_file($root . '/.env') ? 'YES' : 'NO') . "\n";
if (is_file($root . '/.env')) {
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $pass = rmi_env('ERP_DB_PASS') ?: rmi_env('DB_PASSWORD') ?: '';
    echo "   ERP_DB_PASS loaded: " . ($pass !== '' ? 'YES (length ' . strlen($pass) . ')' : 'NO - INI PENYEBAB "using password: NO"!') . "\n";
}
echo "3. config.php exists: " . (is_file($root . '/config.php') ? 'YES' : 'NO') . "\n";
echo "4. index.php exists: " . (is_file($root . '/index.php') ? 'YES' : 'NO') . "\n";
echo "5. PHP: " . PHP_VERSION . "\n";

// Load config dan coba koneksi DB
$_GET['debug'] = '1';
try {
    require_once $root . '/config.php';
    echo "6. Config loaded: OK\n";
} catch (Throwable $e) {
    echo "6. Config ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
    exit;
}

// Cek DB
if (isset($conn) && $conn instanceof mysqli) {
    echo "7. DB mysqli: " . ($conn->ping() ? 'OK' : 'FAIL') . "\n";
} elseif (function_exists('rmi_db_pdo')) {
    try {
        $pdo = rmi_db_pdo();
        echo "7. DB PDO: OK\n";
    } catch (Throwable $e) {
        echo "7. DB PDO ERROR: " . $e->getMessage() . "\n";
    }
} else {
    echo "7. DB: belum di-test\n";
}

echo "\n8. Coba load index.php...\n";
ob_start();
try {
    include $root . '/index.php';
    $out = ob_get_clean();
    echo "Output length: " . strlen($out) . " bytes\n";
    if (strlen($out) < 100) echo "Output: " . htmlspecialchars($out) . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
echo "<p><strong>HAPUS file debug_blank.php setelah selesai!</strong></p>";
