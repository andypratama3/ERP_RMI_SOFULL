<?php
/**
 * Cek konfigurasi DB — upload ke root project, buka di browser, HAPUS setelah selesai.
 * URL: https://10.10.60.20/ERP_RMI_SOFULL/tools/nas/check_db_config.php
 */
header('Content-Type: text/plain; charset=utf-8');
$root = dirname(dirname(__DIR__)); // project root (dari tools/nas/)
chdir($root);

echo "=== ERP DB Config Check ===\n\n";
echo "Root: $root\n";
echo "config.php exists: " . (is_file($root . '/config.php') ? 'YES' : 'NO') . "\n";
echo "config-db.php exists: " . (is_file($root . '/config-db.php') ? 'YES' : 'NO') . "\n";
echo "config-db.example.php exists: " . (is_file($root . '/config-db.example.php') ? 'YES' : 'NO') . "\n";

$cfgFile = is_file($root . '/config-db.php') ? $root . '/config-db.php' : $root . '/config-db.example.php';
$cfg = is_file($cfgFile) ? require $cfgFile : null;

if (is_array($cfg)) {
    echo "\nConfig source: " . basename($cfgFile) . "\n";
    echo "host: " . ($cfg['host'] ?? '?') . "\n";
    echo "port: " . ($cfg['port'] ?? '?') . "\n";
    echo "name: " . ($cfg['name'] ?? '?') . "\n";
    echo "user: " . ($cfg['user'] ?? '?') . "\n";
    echo "pass length: " . strlen((string)($cfg['pass'] ?? '')) . " chars\n";

    $host = (string)($cfg['host'] ?? '127.0.0.1');
    $port = (int)($cfg['port'] ?? 3306);
    $name = (string)($cfg['name'] ?? 'erp_rmi_sofull');
    $user = (string)($cfg['user'] ?? 'root');
    $pass = (string)($cfg['pass'] ?? '');

    echo "\nTest connection...\n";
    try {
        $conn = new mysqli($host, $user, $pass, $name, $port);
        $conn->set_charset('utf8mb4');
        echo "OK — Connected!\n";
        $conn->close();
    } catch (Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
} else {
    echo "\nNo config found. Create config-db.php or ensure config-db.example.php exists.\n";
}

echo "\n=== HAPUS file ini setelah selesai (keamanan) ===\n";
