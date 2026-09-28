<?php
/**
 * tools/rbac/seed_rmi_rbac.php
 *
 * Idempotent RBAC seed untuk RBAC_RMI_COMPLETE_ENTERPRISE.
 * - Pastikan tabel permission & role mapping ada.
 * - Insert permission codes jika belum ada.
 * - Assign default mapping role->permission.
 * - SYS_ADMIN & SYS_SUPERADMIN punya semua tools.* + admin pages.
 *
 * Jalankan: php tools/rbac/seed_rmi_rbac.php
 * Atau via NAS: ./tools/nas/erp.sh php tools/rbac/seed_rmi_rbac.php
 *
 * Output: storage/logs/rbac_seed_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);

require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/db.php';
require_once $root . '/_shared/rbac.php';

$actor = getenv('USER') ?: 'SYSTEM';
$logsDir = ts_storage_logs_dir();
$outPath = $logsDir . '/rbac_seed_last.json';

$pdo = null;
try {
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'run_at' => date(DateTimeInterface::ATOM),
        'actor' => $actor,
        'error' => 'DB connection failed: ' . $e->getMessage(),
    ];
    ts_write_json($outPath, $payload);
    fwrite(STDERR, "FAIL: " . $payload['error'] . "\n");
    exit(1);
}

// Ensure tables exist
rbac_ensure_tables($pdo);

// Additional enterprise permissions (idempotent upsert)
$enterprisePerms = [
    ['TOOLS.RUN.SMOKE', 'Tools Run Smoke', 'TOOLS', 'Jalankan smoke HTTP test (SYS only)'],
    ['TOOLS.RUN.CONTRACT', 'Tools Run Contract Check', 'TOOLS', 'Jalankan contract check (SYS only)'],
    ['TOOLS.RUN.CUTOVER', 'Tools Run Cutover Checks', 'TOOLS', 'Jalankan cutover checks (SYS only)'],
    ['TOOLS.RUN.BACKUP', 'Tools Run Backup', 'TOOLS', 'Jalankan backup (SYS only)'],
    ['TOOLS.RUN.RESTORE', 'Tools Run Restore', 'TOOLS', 'Jalankan restore (SYS only)'],
    ['TOOLS.DOWNLOAD.EVIDENCE', 'Tools Download Evidence', 'TOOLS', 'Download evidence pack (SYS + AUDITOR)'],
];

$insPerm = $pdo->prepare(
    "INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
     VALUES (?,?,?,?,1)
     ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1"
);

$permCount = 0;
foreach ($enterprisePerms as $p) {
    $code = rbac_norm_code((string)$p[0]);
    try {
        $insPerm->execute([$code, (string)$p[1], rbac_norm_code((string)$p[2]), (string)$p[3]]);
        $permCount++;
    } catch (Throwable $e) {
        // ignore
    }
}

// Map SYS|ADMIN and SYS|SUPERADMIN to all tools permissions
$adminRoles = ['ADMIN', 'SUPERADMIN', 'SYS_ADMIN', 'SYS_SUPERADMIN'];
$toolsPerms = [
    'TOOLS.VIEW', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 'TOOLS.ENTERPRISE_AUDIT_EXPORT',
    'TOOLS.PURCHASES_M2_APPLY', 'TOOLS.ITC_RESET_PASSWORD',
    'TOOLS.RUN.SMOKE', 'TOOLS.RUN.CONTRACT', 'TOOLS.RUN.CUTOVER',
    'TOOLS.RUN.BACKUP', 'TOOLS.RUN.RESTORE', 'TOOLS.DOWNLOAD.EVIDENCE',
];

// Load existing perm codes for FK
$permSet = [];
try {
    $st = $pdo->query("SELECT perm_code FROM rbac_permissions");
    foreach ($st ? $st->fetchAll(PDO::FETCH_ASSOC) ?: [] : [] as $r) {
        $permSet[rbac_norm_code((string)($r['perm_code'] ?? ''))] = true;
    }
} catch (Throwable $e) {
    $permSet = [];
}

$insMap = $pdo->prepare(
    "INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag)
     VALUES (?,?,?,1)
     ON DUPLICATE KEY UPDATE allow_flag=1"
);

$mapCount = 0;
foreach (['SYS', 'ITC'] as $dept) {
    foreach ($adminRoles as $role) {
        foreach ($toolsPerms as $perm) {
            $perm = rbac_norm_code($perm);
            if (!isset($permSet[$perm])) {
                continue;
            }
            try {
                $insMap->execute([$dept, rbac_norm_code($role), $perm]);
                $mapCount++;
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}

// ITC MANAGER/STAFF: tools.view, tools.download.evidence
$itcStaffPerms = ['TOOLS.VIEW', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 'TOOLS.ITC_RESET_PASSWORD', 'TOOLS.DOWNLOAD.EVIDENCE'];
foreach (['MANAGER', 'STAFF'] as $role) {
    foreach ($itcStaffPerms as $perm) {
        $perm = rbac_norm_code($perm);
        if (!isset($permSet[$perm])) {
            continue;
        }
        try {
            $insMap->execute(['ITC', $role, $perm]);
            $mapCount++;
        } catch (Throwable $e) {
            // ignore
        }
    }
}

$payload = [
    'ok' => true,
    'run_at' => date(DateTimeInterface::ATOM),
    'actor' => $actor,
    'permissions_upserted' => $permCount,
    'dept_role_mappings_upserted' => $mapCount,
    'enterprise_perms' => array_column($enterprisePerms, 0),
];

ts_write_json($outPath, $payload);
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
