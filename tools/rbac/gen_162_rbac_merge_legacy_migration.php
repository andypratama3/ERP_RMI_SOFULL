<?php
declare(strict_types=1);

/**
 * Generate sql/migrations/162_rbac_merge_legacy_mirror.sql from config/rbac_legacy_merge_map.php
 *
 * Usage: php tools/rbac/gen_162_rbac_merge_legacy_migration.php
 */

$root = dirname(__DIR__, 2);
$mapPath = $root . '/config/rbac_legacy_merge_map.php';
$outPath = $root . '/sql/migrations/162_rbac_merge_legacy_mirror.sql';

if (!is_file($mapPath)) {
    fwrite(STDERR, "Missing {$mapPath}\n");
    exit(1);
}

/** @var array<string,string> $map */
$map = require $mapPath;
if (!is_array($map)) {
    fwrite(STDERR, "Map must be array\n");
    exit(1);
}

$norm = static function (string $s): string {
    $s = strtoupper(trim($s));
    return preg_replace('/\s+/', '_', $s) ?? $s;
};

$pairs = [];
foreach ($map as $old => $new) {
    $o = $norm((string)$old);
    $n = $norm((string)$new);
    if ($o === '' || $n === '') {
        continue;
    }
    $pairs[] = [$o, $n];
}

$lines = [];
$lines[] = '-- 162: Merge legacy / mirror RBAC codes → kanonik (seluruh modul)';
$lines[] = '-- Sumber: config/rbac_legacy_merge_map.php';
$lines[] = '-- WAJIB: Sync Permissions di RBAC Center dulu agar kode target ada di rbac_permissions.';
$lines[] = '-- Jalankan di NAS: mysql ... < sql/migrations/162_rbac_merge_legacy_mirror.sql';
$lines[] = '';
$lines[] = 'SET NAMES utf8mb4;';
$lines[] = '';
$lines[] = 'START TRANSACTION;';
$lines[] = '';

foreach ($pairs as [$old, $new]) {
    $lines[] = "-- {$old} → {$new}";
    $lines[] = 'INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)';
    $lines[] = "SELECT d.dept_code, d.role_code, '{$new}', 1, NOW()";
    $lines[] = 'FROM rbac_dept_role_permissions d';
    $lines[] = "WHERE d.perm_code = '{$old}' AND d.allow_flag = 1";
    $lines[] = 'ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));';
    $lines[] = '';
    $lines[] = 'INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)';
    $lines[] = "SELECT u.user_id, '{$new}', 1, NOW()";
    $lines[] = 'FROM rbac_user_permissions u';
    $lines[] = "WHERE u.perm_code = '{$old}' AND u.allow_flag = 1";
    $lines[] = 'ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));';
    $lines[] = '';
}

$oldList = array_unique(array_map(static fn($p) => $p[0], $pairs));
sort($oldList);
$lines[] = 'DELETE FROM rbac_dept_role_permissions WHERE perm_code IN (';
$lines[] = "  '" . implode("',\n  '", $oldList) . "'";
$lines[] = ');';
$lines[] = '';
$lines[] = 'DELETE FROM rbac_user_permissions WHERE perm_code IN (';
$lines[] = "  '" . implode("',\n  '", $oldList) . "'";
$lines[] = ');';
$lines[] = '';
$lines[] = 'DELETE FROM rbac_permissions WHERE perm_code IN (';
$lines[] = "  '" . implode("',\n  '", $oldList) . "'";
$lines[] = ');';
$lines[] = '';
$lines[] = 'COMMIT;';
$lines[] = '';

file_put_contents($outPath, implode("\n", $lines));
echo "Wrote {$outPath} (" . count($pairs) . " pairs, " . count($oldList) . " legacy codes)\n";
