<?php
/**
 * PHP Driver Check — CLI. Test PDO MySQL availability.
 * Output: storage/logs/php_driver_check_last.txt
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$logsDir = $root . '/storage/logs';
@mkdir($logsDir, 0775, true);

$out = [];
$out[] = 'PHP: ' . (defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : (getenv('ERP_PHP_BIN') ?: 'php'));
$out[] = 'Version: ' . PHP_VERSION;
$out[] = 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'YES' : 'NO');
$out[] = 'mysqli: ' . (extension_loaded('mysqli') ? 'YES' : 'NO');

$pdoOk = false;
if (extension_loaded('pdo_mysql')) {
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $cfg = function_exists('rmi_db_config') ? rmi_db_config() : [];
    $host = (string)($cfg['host'] ?? '127.0.0.1');
    $port = (int)($cfg['port'] ?? 3306);
    $name = (string)($cfg['name'] ?? 'information_schema');
    $user = (string)($cfg['user'] ?? 'root');
    $pass = (string)($cfg['pass'] ?? '');
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->query('SELECT 1');
        $pdoOk = true;
        $out[] = 'PDO connect: OK';
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, $root)) $msg = str_replace($root, '[APP_ROOT]', $msg);
        $out[] = 'PDO connect: FAIL - ' . $msg;
    }
} else {
    $out[] = 'PDO connect: SKIP (no pdo_mysql)';
}

$out[] = 'Result: ' . ($pdoOk ? 'PASS' : 'FAIL');
$text = implode("\n", $out);
$path = $logsDir . '/php_driver_check_last.txt';
@file_put_contents($path, $text . "\n");

echo $text . "\n";
exit($pdoOk ? 0 : 1);
