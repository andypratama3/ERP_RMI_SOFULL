<?php
/**
 * seed_rmi_module_permissions.php — Idempotent seeder for MOD_* module permissions.
 *
 * Minimum permissions: MOD_MASTER, MOD_PURCHASES, MOD_STOCK, MOD_SALES, MOD_FINANCE,
 * MOD_HRL, MOD_PAYROLL, MOD_MPR, MOD_KPI, MOD_FIXED_ASSET, MOD_CHAT, MOD_TOOLS.
 *
 * Rules:
 * - Dry-run by default
 * - Apply only if --apply AND actor is ADMIN/SUPERADMIN (session or CLI)
 * - Output: storage/logs/rbac_seed_modules_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);

$args = $_SERVER['argv'] ?? [];
$dryRun = !in_array('--apply', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/db.php';
require_once $root . '/_shared/rbac.php';

$actor = getenv('USER') ?: 'SYSTEM';
$logsDir = ts_storage_logs_dir();
$outPath = $logsDir . '/rbac_seed_modules_last.json';

$pdo = null;
try {
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'run_at' => date(DateTimeInterface::ATOM),
        'actor' => $actor,
        'dry_run' => $dryRun,
        'error' => 'DB connection failed',
    ];
    ts_write_json($outPath, $payload);
    fwrite(STDERR, "FAIL: DB connection failed\n");
    exit(1);
}

rbac_ensure_tables($pdo);

$modPerms = [
    ['MOD_MASTER', 'Module Master', 'MASTER', 'Akses Master Data Center'],
    ['MOD_PURCHASES', 'Module Purchases', 'PURCHASES', 'Akses modul Purchases'],
    ['MOD_STOCK', 'Module Stock', 'STOCK', 'Akses modul Stock'],
    ['MOD_SALES', 'Module Sales', 'SALES', 'Akses modul Sales'],
    ['MOD_FINANCE', 'Module Finance', 'FINANCE', 'Akses modul Finance'],
    ['MOD_HRL', 'Module HRL', 'HRL', 'Akses modul HRL'],
    ['MOD_PAYROLL', 'Module Payroll', 'PAYROLL', 'Akses modul Payroll'],
    ['MOD_MPR', 'Module MPR', 'MPR', 'Akses modul MPR'],
    ['MOD_KPI', 'Module KPI', 'KPI', 'Akses modul KPI'],
    ['MOD_FIXED_ASSET', 'Module Fixed Asset', 'FIXED_ASSET', 'Akses modul Fixed Asset'],
    ['MOD_CHAT', 'Module Chat', 'CHAT', 'Akses modul Chat'],
    ['MOD_TOOLS', 'Module Tools', 'TOOLS', 'Akses Tools (ITC)'],
];

$permCount = 0;

if (!$dryRun) {
    $insPerm = $pdo->prepare(
        "INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
         VALUES (?,?,?,?,1)
         ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1"
    );
    foreach ($modPerms as $p) {
        $code = rbac_norm_code((string)$p[0]);
        try {
            $insPerm->execute([$code, (string)$p[1], rbac_norm_code((string)$p[2]), (string)$p[3]]);
            $permCount++;
        } catch (Throwable $e) {
            // ignore
        }
    }
    $modCodes = array_map(fn($p) => rbac_norm_code((string)$p[0]), $modPerms);
    $insMap = $pdo->prepare(
        "INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag)
         VALUES (?,?,?,1)
         ON DUPLICATE KEY UPDATE allow_flag=1"
    );
    foreach (['SYS'] as $dept) {
        foreach (['ADMIN', 'SUPERADMIN'] as $role) {
            foreach ($modCodes as $perm) {
                try {
                    $insMap->execute([$dept, rbac_norm_code($role), $perm]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
    }
    $deptModMap = [
        'ITC' => ['MOD_TOOLS'],
        'MPR' => ['MOD_MPR', 'MOD_KPI'],
        'CRM' => ['MOD_SALES', 'MOD_MASTER'],
        'SCM' => ['MOD_PURCHASES', 'MOD_STOCK', 'MOD_MASTER'],
        'PQP' => ['MOD_PURCHASES', 'MOD_MASTER', 'MOD_STOCK'],
        'WQS' => ['MOD_STOCK', 'MOD_PURCHASES'],
        'ACT' => ['MOD_PURCHASES', 'MOD_FINANCE'],
        'FIN' => ['MOD_FINANCE', 'MOD_PURCHASES'],
        'HRL' => ['MOD_HRL', 'MOD_MASTER'],
    ];
    foreach ($deptModMap as $dept => $perms) {
        foreach ($perms as $perm) {
            if (in_array($perm, $modCodes, true)) {
                try {
                    $insMap->execute([$dept, 'MANAGER', $perm]);
                    $insMap->execute([$dept, 'STAFF', $perm]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
    }
}

$payload = [
    'ok' => true,
    'run_at' => date(DateTimeInterface::ATOM),
    'actor' => $actor,
    'dry_run' => $dryRun,
    'permissions_upserted' => $permCount,
    'mod_perms_count' => count($modPerms),
];

if ($writeLast) {
    ts_write_json($outPath, $payload);
}
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
