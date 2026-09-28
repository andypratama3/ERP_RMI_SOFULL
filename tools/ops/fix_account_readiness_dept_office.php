<?php
/**
 * One-time fix: Set default department & office_code for ACTIVE users missing them.
 * Default: department=ITC, office_code=BGR (per office-codes rule).
 *
 * Run: php tools/ops/fix_account_readiness_dept_office.php (dari NAS)
 * Atau: Buka /tools/ops/fix_account_readiness_dept_office.php sebagai Admin.
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
if (PHP_SAPI === 'cli') {
    // CLI: minimal bootstrap (no auth/config.php) untuk hindari mysqli/PDO driver issue
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) {
        rmi_env_load();
    }
    require_once $root . '/_shared/db.php';
    if (!function_exists('rmi_db_pdo')) {
        fwrite(STDERR, "Error: rmi_db_pdo not found.\n");
        exit(1);
    }
    $pdo = rmi_db_pdo();
} else {
    require_once __DIR__ . '/../../master/auth.php';
    require_once __DIR__ . '/../tools_access_helpers.php';
    tools_require_access('ops/fix_account_readiness_dept_office.php');
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
$defaultDept = 'ITC';
$defaultOffice = 'BGR';

$st = $pdo->query(
    "SELECT id, username, department, office_code
     FROM master_system_login
     WHERE UPPER(COALESCE(status,'')) = 'ACTIVE'
       AND (COALESCE(TRIM(department),'') = '' OR COALESCE(TRIM(office_code),'') = '')"
);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$up = $pdo->prepare(
    "UPDATE master_system_login
     SET department = COALESCE(NULLIF(TRIM(department),''), ?),
         office_code = COALESCE(NULLIF(TRIM(office_code),''), ?),
         updated_at = NOW()
     WHERE id = ?"
);

foreach ($rows as $r) {
    $dept = trim((string)($r['department'] ?? ''));
    $office = trim((string)($r['office_code'] ?? ''));
    $newDept = $dept !== '' ? $dept : $defaultDept;
    $newOffice = $office !== '' ? $office : $defaultOffice;
    $up->execute([$newDept, $newOffice, (int)$r['id']]);
    $updated++;
}

echo json_encode([
    'ok' => true,
    'scanned' => count($rows),
    'updated' => $updated,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error: " . $msg . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit(1);
}
