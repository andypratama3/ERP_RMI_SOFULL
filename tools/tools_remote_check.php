<?php
/**
 * Tools remote access check — shared logic untuk semua halaman tools.
 * Jika akses dari luar localhost dan tidak diizinkan, keluar dengan 403.
 *
 * Diizinkan jika salah satu:
 * - REMOTE_ADDR = 127.0.0.1 atau ::1 (localhost)
 * - TOOLS_ALLOW_REMOTE=1 di .env
 * - File tools/.allow_remote ada
 * - APP_ENV=production
 * - IP LAN (192.168.x, 10.x, 172.16-31.x)
 */
declare(strict_types=1);

$__toolsRoot = __DIR__;
$__projectRoot = realpath($__toolsRoot . '/..') ?: dirname($__toolsRoot);
$__envPath = $__projectRoot . DIRECTORY_SEPARATOR . '.env';
$__env = $__projectRoot . '/_shared/env.php';
if (is_file($__env)) {
    require_once $__env;
    if (function_exists('rmi_env_load') && is_file($__envPath)) {
        rmi_env_load($__envPath);
    }
}

$allowRemote = (bool)(getenv('TOOLS_ALLOW_REMOTE') ?: ($_ENV['TOOLS_ALLOW_REMOTE'] ?? $_SERVER['TOOLS_ALLOW_REMOTE'] ?? (defined('TOOLS_ALLOW_REMOTE') ? TOOLS_ALLOW_REMOTE : false)));
if (!$allowRemote && is_file($__toolsRoot . '/.allow_remote')) {
    $allowRemote = true;
}
$appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '');
if (!$allowRemote && strtolower(trim((string)$appEnv)) === 'production') {
    $allowRemote = true;
}
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!$allowRemote && $remote !== '' && (str_starts_with($remote, '192.168.') || str_starts_with($remote, '10.') || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $remote))) {
    $allowRemote = true;
}
if (!$allowRemote && $remote !== '' && !in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n\n/tools hanya boleh diakses dari localhost.\n";
    echo "Untuk akses dari NAS/LAN:\n";
    echo "  1) Set APP_ENV=production di .env (NAS)\n";
    echo "  2) Atau TOOLS_ALLOW_REMOTE=1 di .env\n";
    echo "  3) Atau buat file kosong: tools/.allow_remote\n";
    exit;
}
