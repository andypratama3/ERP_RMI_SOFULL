<?php
/**
 * Debug: cek kenapa Tools 403 masih muncul.
 * Akses: /tools/remote_check.php (login required)
 * HAPUS file ini setelah selesai (keamanan).
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../master/auth.php';
require_login();
require_role(['ADMIN', 'SUPERADMIN', 'SYS']);

require_once __DIR__ . '/../_shared/env.php';
if (function_exists('rmi_env_load')) { rmi_env_load(); }

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
$allowFile = __DIR__ . '/.allow_remote';

$fromGetenv = getenv('TOOLS_ALLOW_REMOTE');
$fromEnv = $_ENV['TOOLS_ALLOW_REMOTE'] ?? null;
$fromServer = $_SERVER['TOOLS_ALLOW_REMOTE'] ?? null;
$allowFileExists = is_file($allowFile);

$allowRemote = (bool)($fromGetenv ?: $fromEnv ?: $fromServer);
if (!$allowRemote && $allowFileExists) {
    $allowRemote = true;
}

echo "=== Tools Remote Access Debug ===\n\n";
echo "REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? '(kosong)') . "\n";
echo ".env path: $envPath\n";
echo ".env exists: " . (is_file($envPath) ? 'YA' : 'TIDAK') . "\n";
echo ".env readable: " . (is_readable($envPath) ? 'YA' : 'TIDAK') . "\n";
echo ".allow_remote exists: " . ($allowFileExists ? 'YA' : 'TIDAK') . "\n\n";
echo "TOOLS_ALLOW_REMOTE:\n";
echo "  getenv: " . ($fromGetenv === false ? '(false)' : var_export($fromGetenv, true)) . "\n";
echo "  \$_ENV: " . var_export($fromEnv, true) . "\n";
echo "  \$_SERVER: " . var_export($fromServer, true) . "\n\n";
echo "allowRemote = " . ($allowRemote ? 'true' : 'false') . "\n";
echo "\nJika allowRemote=false, tambah TOOLS_ALLOW_REMOTE=1 di .env atau: touch tools/.allow_remote\n";
