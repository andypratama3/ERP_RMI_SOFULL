<?php
/**
 * DB Diagnostic — CLI. Test PDO connect using same config as app.
 * Output: JSON {ok, driver, host, db, message(masked)}
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/_shared/db.php';

$out = ['ok' => false, 'driver' => 'pdo_mysql', 'host' => null, 'db' => null, 'message' => null];

try {
    $cfg = function_exists('rmi_db_config') ? rmi_db_config() : [];
    $host = (string)($cfg['host'] ?? '127.0.0.1');
    $port = (int)($cfg['port'] ?? 3306);
    $name = (string)($cfg['name'] ?? 'erp_rmi_sofull');
    $user = (string)($cfg['user'] ?? 'root');
    $pass = (string)($cfg['pass'] ?? '');

    $out['host'] = $host . ':' . $port;
    $out['db'] = $name;

    $pdo = rmi_db_pdo();
    if (!$pdo instanceof PDO) {
        $out['message'] = 'rmi_db_pdo returned non-PDO';
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }
    $pdo->query('SELECT 1');
    $out['ok'] = true;
    $out['message'] = 'ok';
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (function_exists('tools_mask_sensitive')) {
        $msg = tools_mask_sensitive($msg);
    } elseif (str_contains($msg, $root)) {
        $msg = str_replace($root, '[APP_ROOT]', $msg);
    }
    $out['message'] = $msg;
}

echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($out['ok'] ? 0 : 1);
