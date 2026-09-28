<?php
/**
 * seed_rbac_all_modules.php — Idempotent RBAC seed V2 Granular.
 *
 * Perubahan V2:
 * - Semua permission sekarang granular: VIEW / CREATE / EDIT / DELETE / APPROVE
 * - Alias CRUD dipertahankan sebagai backward-compat (tidak dihapus dari registry)
 * - Dept|Role mapping diperbarui menggunakan permission granular
 * - rbac_seed_permissions() SELALU dijalankan dengan upsert=true
 * - rbac_apply_baseline() SELALU dijalankan (upsert — tidak hapus rule custom)
 *
 * Usage:
 *   php tools/rbac/seed_rbac_all_modules.php           # dry-run
 *   php tools/rbac/seed_rbac_all_modules.php --apply --i-understand  # apply
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

$args = $_SERVER['argv'] ?? [];
$dryRun = !in_array('--apply', $args, true) || !in_array('--i-understand', $args, true);
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
$outPath = $logsDir . '/rbac_seed_last.json';

$pdo = null;
try {
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'run_at' => date(DateTimeInterface::ATOM),
        'actor' => $actor,
        'dry_run' => $dryRun,
        'error' => 'DB connection failed: ' . $e->getMessage(),
    ];
    ts_write_json($outPath, $payload);
    fwrite(STDERR, "FAIL: " . $payload['error'] . "\n");
    exit(1);
}

// Repair schema (unique indexes) + ensure tables exist
rbac_schema_repair($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS rbac_permissions (
    perm_code   VARCHAR(80) PRIMARY KEY,
    perm_name   VARCHAR(120) NOT NULL,
    module      VARCHAR(40) NOT NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS rbac_dept_role_permissions (
    dept_code VARCHAR(40) NOT NULL,
    role_code VARCHAR(40) NOT NULL,
    perm_code VARCHAR(80) NOT NULL,
    allow_flag TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (dept_code, role_code, perm_code),
    CONSTRAINT fk_rbac_seed_perm FOREIGN KEY (perm_code) REFERENCES rbac_permissions(perm_code)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS rbac_user_permissions (
    user_id BIGINT NOT NULL,
    perm_code VARCHAR(80) NOT NULL,
    allow_flag TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, perm_code),
    CONSTRAINT fk_rbac_seed_perm2 FOREIGN KEY (perm_code) REFERENCES rbac_permissions(perm_code)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Legacy seed minimal — mirror underscore lain dipindah ke kanonik (config/rbac_legacy_merge_map.php + migrasi 162).
$v1Perms = [
    ['MASTER_WRITE', 'Master Write', 'MASTER', 'Legacy master write — tinjau matrix; prefer granular MASTER.*'],
];

$permCount = 0;
$mapCount = 0;

if (!$dryRun) {
    $insPerm = $pdo->prepare(
        "INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
         VALUES (?,?,?,?,1)
         ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1"
    );
    foreach ($v1Perms as $p) {
        $code = rbac_norm_code((string)$p[0]);
        try {
            $insPerm->execute([$code, (string)$p[1], rbac_norm_code((string)$p[2]), (string)$p[3]]);
            $permCount++;
        } catch (Throwable $e) {
            // ignore
        }
    }
}

$permSet = [];
try {
    $st = $pdo->query("SELECT perm_code FROM rbac_permissions");
    foreach ($st ? $st->fetchAll(PDO::FETCH_ASSOC) ?: [] : [] as $r) {
        $permSet[rbac_norm_code((string)($r['perm_code'] ?? ''))] = true;
    }
} catch (Throwable $e) {
    $permSet = [];
}

$allV1Codes = array_map(fn($p) => rbac_norm_code((string)$p[0]), $v1Perms);

$finCentralCount = 0;
if (!$dryRun) {
    // --- V2: Seed granular permission registry (upsert, idempotent) ---
    rbac_seed_permissions($pdo, true);
    $permCount += (int)($GLOBALS['_rbac_seed_count'] ?? 0);

    // --- V2: Apply granular Dept|Role baseline (upsert — tidak hapus rule custom) ---
    rbac_apply_baseline($pdo);
    $mapCount++; // baseline applied (count tidak presisi, hanya penanda)

    // --- FIN Central: Assign critical cash-out approval perms ONLY to MgrFIN_BGR ---
    if (function_exists('rbac_seed_fin_central_approver')) {
        $finCentralCount = rbac_seed_fin_central_approver($pdo);
        echo "FIN Central: {$finCentralCount} perm(s) assigned to MgrFIN_BGR\n";
    }
}

$payload = [
    'ok' => true,
    'run_at' => date(DateTimeInterface::ATOM),
    'actor' => $actor,
    'dry_run' => $dryRun,
    'permissions_upserted' => $permCount,
    'dept_role_mappings_upserted' => $mapCount,
    'fin_central_perms_assigned' => $finCentralCount,
    'v1_perms_count' => count($v1Perms),
];

if ($writeLast) {
    ts_write_json($outPath, $payload);
}
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
