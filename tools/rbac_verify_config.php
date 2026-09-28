<?php
/**
 * Verifikasi: apakah config/rbac_permissions.php terbaca?
 * Jalankan dari NAS: cd /volume4/web/ERP_RMI_SOFULL && php tools/rbac_verify_config.php
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$configPath = $root . '/config/rbac_permissions.php';
$altPath = dirname(__DIR__) . '/config/rbac_permissions.php';

echo "RMI_ROOT / root: " . ($root) . "\n";
echo "config path (primary): " . $configPath . "\n";
echo "config path (alt):     " . $altPath . "\n";
echo "is_file(primary):     " . (is_file($configPath) ? 'YES' : 'NO') . "\n";
echo "is_file(alt):         " . (is_file($altPath) ? 'YES' : 'NO') . "\n";

$loaded = false;
$path = null;
if (is_file($configPath)) {
    $loaded = true;
    $path = $configPath;
} elseif (is_file($altPath)) {
    $loaded = true;
    $path = $altPath;
}

if ($loaded && $path) {
    $perms = require $path;
    $cnt = is_array($perms) ? count($perms) : 0;
    echo "\nOK: Config loaded. Total permission: {$cnt}\n";
    if ($cnt > 0 && isset($perms[0])) {
        echo "Contoh: " . ($perms[0][0] ?? '') . "\n";
    }
} else {
    echo "\nGAGAL: config/rbac_permissions.php tidak ditemukan.\n";
    exit(1);
}
