<?php
/**
 * Jalankan migration 147: hrl_reg_alkes_case_stage_log
 * CLI: php tools/run_migration_147.php
 * Atau akses via browser (untuk NAS tanpa CLI): tools/run_migration_147.php
 */
declare(strict_types=1);

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    require_once __DIR__ . '/../_shared/bootstrap.php';
    require_once __DIR__ . '/../master/auth.php';
    require_login();
    if (!function_exists('auth_is_admin') || !auth_is_admin()) {
        http_response_code(403);
        die('Admin only.');
    }
}

require_once __DIR__ . '/../_shared/db.php';
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

$ok = false;
$msg = '';

try {
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_case_stage_log'");
    if ($chk && $chk->fetch()) {
        $msg = 'Tabel hrl_reg_alkes_case_stage_log sudah ada. Tidak perlu migrasi.';
        $ok = true;
    } else {
        $sql = file_get_contents(__DIR__ . '/../sql/migrations/147_hrl_reg_alkes_case_stage_log.sql');
        if ($sql) {
            $pdo->exec($sql);
            $msg = 'Tabel hrl_reg_alkes_case_stage_log berhasil dibuat.';
            $ok = true;
        } else {
            $msg = 'File migration tidak ditemukan.';
        }
    }
} catch (PDOException $e) {
    $msg = 'Error: ' . $e->getMessage();
}

if ($cli) {
    echo $msg . "\n";
    exit($ok ? 0 : 1);
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration 147</title></head>
<body>
<p><?= htmlspecialchars($msg) ?></p>
<p><a href="../hrl_reg_alkes/reg_alkes_control_tower.php">← Reg Alkes Control Tower</a></p>
</body>
</html>
