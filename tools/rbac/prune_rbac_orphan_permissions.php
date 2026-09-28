<?php
/**
 * Hapus rbac_permissions yang perm_code tidak ada di config/rbac_permissions.php.
 * Matrix & user override terkait ikut terhapus jika FK ON DELETE CASCADE aktif.
 *
 * CLI (NAS): php tools/rbac/prune_rbac_orphan_permissions.php [--dry-run] [--execute]
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../_shared/app_init.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../../_shared/rbac.php';

$dry = in_array('--dry-run', $argv, true) || !in_array('--execute', $argv, true);

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : null;
if (!$pdo) {
    fwrite(STDERR, "ERROR: tidak bisa koneksi DB.\n");
    exit(1);
}

try {
    $r = rbac_prune_permissions_not_in_config($pdo, $dry);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

$codes = $r['codes'] ?? [];
$n = count($codes);
if ($dry) {
    echo $n === 0
        ? "DRY-RUN: tidak ada orphan (DB ⊆ config).\n"
        : "DRY-RUN: {$n} perm_code akan dihapus:\n";
    foreach ($codes as $c) {
        echo "  {$c}\n";
    }
    echo "\nJalankan lagi dengan --execute untuk menghapus.\n";
    exit(0);
}

echo 'Dihapus: ' . (int)($r['deleted'] ?? 0) . " baris rbac_permissions.\n";
exit(0);
