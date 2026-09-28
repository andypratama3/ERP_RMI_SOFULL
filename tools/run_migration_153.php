<?php
/**
 * Migration 153: RFQ config seed — currency rates & PQP email
 *
 * CLI: php tools/run_migration_153.php
 * Web: tools/run_migration_153.php (Admin only)
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
    $sql = file_get_contents(__DIR__ . '/../sql/migrations/153_rfq_config_seed.sql');
    if ($sql) {
        $pdo->exec($sql);
        $msg = 'Migration 153 OK: RFQ config (rate_CNY, rate_IDR, rate_EUR, PQP_EMAIL, CRM_EMAIL) seeded.';
        $ok = true;
    } else {
        $msg = 'File migration tidak ditemukan.';
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
<html><head><meta charset="utf-8"><title>Migration 153</title></head>
<body>
<p><?= htmlspecialchars($msg) ?></p>
<p><a href="../purchases/pqp_rfq.php">← RFQ</a> | <a href="../master_system_config.php">System Config</a></p>
</body>
</html>
