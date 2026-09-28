<?php
/**
 * Cek kesiapan funnel: migration 147, stage log, avg days
 * CLI (NAS): ./tools/nas/erp.sh php tools/cek_funnel_ready.php
 * Web: {APP_URL}/tools/cek_funnel_ready.php (tanpa login — PHP web punya pdo_mysql)
 */
declare(strict_types=1);

$cli = (PHP_SAPI === 'cli');

try {
    require_once __DIR__ . '/../_shared/db.php';
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
} catch (Throwable $e) {
    if ($cli) {
        echo "Error: " . $e->getMessage() . "\n";
        echo "CLI di NAS: gunakan ./tools/nas/erp.sh php tools/cek_funnel_ready.php\n";
        echo "Atau buka via browser: {APP_URL}/tools/cek_funnel_ready.php\n";
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<pre>Error: ' . htmlspecialchars($e->getMessage()) . "\n\n";
        echo "CLI di NAS: gunakan ./tools/nas/erp.sh php tools/cek_funnel_ready.php</pre>";
    }
    exit(1);
}

$checks = [];

// 1. Tabel hrl_reg_alkes_case_stage_log
$chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_case_stage_log'");
$hasTable = $chk && $chk->fetch();
$checks['migration_147'] = $hasTable ? 'OK' : 'BELUM - jalankan: ./tools/nas/erp.sh php tools/run_migration_147.php';

// 2. Jumlah record stage log
$logCount = 0;
if ($hasTable) {
    $logCount = (int)$pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_case_stage_log")->fetchColumn();
}
$checks['stage_log_records'] = $logCount;

// 3. Record dengan exited_at (untuk avg days)
$exitedCount = 0;
if ($hasTable) {
    $exitedCount = (int)$pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_case_stage_log WHERE exited_at IS NOT NULL")->fetchColumn();
}
$checks['stage_log_exited'] = $exitedCount . ' (avg days akan tampil jika > 0)';

// 4. Reg Alkes cases (tabel tidak punya deleted_at)
$caseCount = 0;
$chkCases = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_cases'");
if ($chkCases && $chkCases->fetch()) {
    $caseCount = (int)$pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases")->fetchColumn();
}
$checks['reg_alkes_cases'] = $caseCount;

$lines = [];
$lines[] = "=== Funnel Readiness Check ===";
foreach ($checks as $k => $v) {
    $lines[] = sprintf("%-25s : %s", $k, $v);
}
$lines[] = "";
$lines[] = "Avg days Reg Alkes tampil jika: migration 147 OK + ada case yang pindah stage (exited_at terisi).";
$lines[] = "Lihat: docs/VERIFIKASI_FUNNEL_CHECKLIST.md";

if ($cli) {
    echo implode("\n", $lines) . "\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre>' . htmlspecialchars(implode("\n", $lines)) . '</pre>';
}
