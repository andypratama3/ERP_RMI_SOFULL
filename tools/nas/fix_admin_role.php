<?php
/**
 * Fix role admin → ADMIN (sekali pakai)
 * 
 * Upload ke root project, buka di browser, HAPUS setelah selesai.
 * URL: https://10.10.60.20/ERP_RMI_SOFULL/fix_admin_role.php
 */
header('Content-Type: text/plain; charset=utf-8');
$root = dirname(dirname(__DIR__)); // project root (dari tools/nas/)
chdir($root);

require_once $root . '/_shared/app_init.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();
require_once $root . '/_shared/db.php';

echo "=== Fix Admin Role ===\n\n";

try {
    $pdo = rmi_db_pdo();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "Database: $dbName\n\n";

    // 1. Cek master_system_login
    $st = $pdo->query("SELECT id, username, role, level, department FROM master_system_login WHERE username IN ('admin','superadmin')");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "master_system_login (sebelum):\n";
    foreach ($rows as $r) {
        echo "  {$r['username']}: role={$r['role']} level={$r['level']}\n";
    }

    // 2. Update master_system_login
    $n1 = $pdo->exec("UPDATE master_system_login SET role='admin', level='ADMIN', department='SYS', updated_at=NOW() WHERE username='admin'");
    $n2 = $pdo->exec("UPDATE master_system_login SET role='owner', level='SUPERADMIN', department='SYS', updated_at=NOW() WHERE username='superadmin'");
    echo "\nUpdated master_system_login: admin=$n1, superadmin=$n2\n";

    // 3. Cek setelah update
    $st = $pdo->query("SELECT id, username, role, level, department FROM master_system_login WHERE username IN ('admin','superadmin')");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "\nmaster_system_login (sesudah):\n";
    foreach ($rows as $r) {
        echo "  {$r['username']}: role={$r['role']} level={$r['level']}\n";
    }

    echo "\n=== SELESAI ===\n";
    echo "PENTING: Session lama masih menyimpan role STAFF.\n";
    echo "1. Buka URL ini untuk LOGOUT: " . ($_SERVER['REQUEST_SCHEME'] ?? 'https') . "://" . ($_SERVER['HTTP_HOST'] ?? '') . "/ERP_RMI_SOFULL/master/logout.php\n";
    echo "2. Login lagi: admin / 1234\n";
    echo "3. HAPUS file fix_admin_role.php (keamanan)\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
