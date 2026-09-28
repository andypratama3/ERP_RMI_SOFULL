<?php
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
require_once $root . '/_shared/db.php';
require_once $root . '/_shared/rbac.php';

$logFile = $root . '/storage/logs/rbac_apply.log';
@mkdir(dirname($logFile), 0775, true);

$log = static function (string $m) use ($logFile): void {
    @file_put_contents($logFile, date(DateTimeInterface::ATOM) . ' ' . $m . PHP_EOL, FILE_APPEND);
};

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$log('RBAC_APPLY_START');

// 1) Ensure permissions + dept-role baseline.
rbac_ensure_tables($pdo);
rbac_seed_permissions($pdo, true);
rbac_apply_baseline($pdo);
$log('RBAC_SEED_OK');

// 2) Ensure user scope table.
$pdo->exec("
CREATE TABLE IF NOT EXISTS rbac_user_scope (
  user_id INT NOT NULL PRIMARY KEY,
  allowed_office_codes_json JSON NULL,
  allowed_depo_codes_json JSON NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$log('RBAC_USER_SCOPE_TABLE_OK');

// 3) Derive scopes from username suffix.
$st = $pdo->query("SELECT id, username, role, level, department, status FROM master_system_login WHERE deleted_at IS NULL");
$users = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$officeCodes = ['BGR','BDG','BKS','TGR','SLO','SMG'];
$depoCodes = ['JGY','KAL'];

$up = $pdo->prepare("
INSERT INTO rbac_user_scope(user_id, allowed_office_codes_json, allowed_depo_codes_json)
VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE
  allowed_office_codes_json=VALUES(allowed_office_codes_json),
  allowed_depo_codes_json=VALUES(allowed_depo_codes_json),
  updated_at=NOW()
");

$warn = 0;
$updated = 0;
foreach ($users as $u) {
    $id = (int)($u['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $username = strtoupper((string)($u['username'] ?? ''));
    $dept = strtoupper((string)($u['department'] ?? ''));
    $role = strtolower((string)($u['role'] ?? ''));
    $level = strtoupper((string)($u['level'] ?? ''));

    $off = [];
    $dep = [];
    if ($dept === 'SYS' || $role === 'sys' || $level === 'SYS') {
        $off = ['ALL'];
        $dep = ['ALL'];
    } elseif (preg_match('/_([A-Z]{3})$/', $username, $m)) {
        $suffix = strtoupper((string)$m[1]);
        if (in_array($suffix, $officeCodes, true)) {
            $off = [$suffix];
        } elseif (in_array($suffix, $depoCodes, true)) {
            $dep = [$suffix];
        } else {
            $warn++;
            $log("WARN_SCOPE_UNKNOWN_SUFFIX user={$username} suffix={$suffix}");
        }
    } else {
        $warn++;
        $log("WARN_SCOPE_NO_SUFFIX user={$username}");
    }

    $up->execute([
        $id,
        json_encode($off, JSON_UNESCAPED_SLASHES),
        json_encode($dep, JSON_UNESCAPED_SLASHES),
    ]);
    $updated++;
}

$summary = [
    'ok' => true,
    'updated_users' => $updated,
    'warnings' => $warn,
];
$log('RBAC_APPLY_DONE users=' . $updated . ' warnings=' . $warn);
echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
